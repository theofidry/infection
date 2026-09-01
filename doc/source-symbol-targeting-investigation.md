# Target a class, method, or line

Related: [#2237](https://github.com/infection/infection/issues/2237) and
[#3498](https://github.com/infection/infection/issues/3498), areas 1 and 3.

## Recommendation

Proceed with this feature as AST eligibility selection, independently of positional source
and test-path classification. It does not require changes to tracing, mutant evaluation, or
process creation. The proof of concept confirms that positional inputs can carry a typed
source selector separately from source and test paths, and that an enriched AST can match a
short or fully qualified class name, a method, and an absolute source line.

The canonical use case combines a test path with a source symbol:

```bash
bin/infection tests/phpunit/Differ Differ::diff UnifiedDiffOutputBuilder
```

`tests/phpunit/Differ` limits the tests that execute. The remaining arguments independently
limit the source nodes that may be mutated among the code covered by those tests. A selector
always refers to mutation source code, never to a symbol declared in the selected tests. A
source path plus a selector, such as `bin/infection src/Differ Differ::diff`, remains valid
but is not the main motivation for the feature.

The working branch is `poc/source-symbol-targeting`.

## Proof-of-concept status

Implemented:

- `SourceSymbolSelector` represents a class, optional method, and optional absolute line.
- `SourceSymbolSelectorParser` parses positional selectors without invoking the project
  autoloader.
- `PositionalPathsClassifier` returns selectors in a third `ClassifiedPaths` bucket;
  existing files take precedence, preventing Windows paths from being parsed as symbols.
- `SourceSymbolMatcher` matches context supplied by the selection visitor. A short class
  name matches every declaration with that name; a qualified name matches the complete
  resolved name. Inclusive node ranges cover multiline nodes and method signatures.
- `ExcludeNonSelectedSourceNodesVisitor` makes non-matching nodes ineligible. It maintains
  class and method stacks while traversing, avoiding repeated walks through node parents.
- `NodeTraverserFactory` optionally installs the selector visitor after name, parent,
  reflection, mutability, and changed-line enrichment, and before `AddTestsVisitor`.
- `ConfigurationFactory` carries positional selectors independently from source filters and
  test-framework arguments. The container passes them to `NodeTraverserFactory`, so normal
  mutation generation applies them to every collected source AST.
- Multiple selectors use union semantics: a node remains selected when any selector matches.
- Every selector is tracked across all enriched source ASTs. Mutation generation fails with
  `SourceSymbolNotFound` after processing the source set if any selector matched nothing.
- The `Source_Symbol_Selection` e2e scenario exercises a test path, a method selector, and a
  second class selector together.
- `debug:dump-ast --source-selector` exercises the parser, matcher, and real enrichment
  traversal. It displays line numbers and eligibility without running mutant processes.

Not yet implemented:

- no positional `run` help or infection/site documentation has been added.

## How to evaluate the proof of concept

Run the focused tests:

```bash
vendor/phpunit/phpunit/phpunit \
    tests/phpunit/Configuration/SourceSymbolSelectorParser/SourceSymbolSelectorParserTest.php \
    tests/phpunit/Configuration/PositionalPathsClassifier/PositionalPathsClassifierTest.php \
    tests/phpunit/Source/Matcher/SourceSymbolMatcher/SourceSymbolMatcherTest.php \
    tests/phpunit/PhpParser/Visitor/ExcludeNonSelectedSourceNodesVisitor/ExcludeNonSelectedSourceNodesVisitorTest.php \
    tests/phpunit/PhpParser/NodeTraverserFactoryTest.php \
    tests/phpunit/Command/Debug/DumpAstCommand/DumpAstCommandTest.php
```

To inspect the feature manually, target line 42 of the `greet` method in the command
fixture:

```bash
./bin/infection debug:dump-ast \
    tests/phpunit/Command/Debug/DumpAstCommand/EchoGreeter.php \
    --configuration=tests/phpunit/Command/Debug/DumpAstCommand/infection.json5 \
    '--source-selector=EchoGreeter::greet::42'
```

The matching method and nodes whose ranges contain line 42 are eligible; nodes outside that
selection are ineligible. Remove the line suffix to preview the whole method. The file
remains explicit because this diagnostic command parses one provided source file. This does
not imply that production selection should resolve a class name to a file before enrichment.

The production positional form now constrains a normal mutation run:

```bash
./bin/infection tests/phpunit/Differ Differ::diff UnifiedDiffOutputBuilder
```

This runs the tests under `tests/phpunit/Differ`, then generates mutations only for covered
source nodes within every matching `Differ::diff` method or `UnifiedDiffOutputBuilder` class.
The equivalent fully qualified method selector is `Infection\Differ\Differ::diff`.

## Grammar established by the proof of concept

Accepted forms are:

```text
Vendor\Package\Class
\Vendor\Package\Class
Class
Class::method
Class::32
Vendor\Package\Class::method
Vendor\Package\Class::32
Vendor\Package\Class::method::32
```

The leading namespace separator is normalized away. A numeric coordinate is an absolute,
one-based source-file line. `__invoke` is an ordinary method name. A single-colon form such
as `Class::method:32` is rejected rather than accepted as an alias. Mutator suffixes and
namespaced functions are outside the first grammar.

Bare short class names are selectors. This deliberately changes the old extensionless
source-filter heuristic: `Differ` now means a class selector, while `Differ.php` remains a
file filter. Existing filesystem entries still take precedence, including paths containing
backslashes or drive-letter colons.

A short selector deliberately has union semantics. `Differ::diff` matches both
`App\Text\Differ::diff` and `App\Image\Differ::diff` when both declarations occur in source
ASTs considered by Infection. A fully qualified selector narrows the match when desired.
Ambiguity is not an error and requires no global symbol-to-file lookup.

Traits and enums use the same named class-like AST representation. Anonymous classes cannot
be selected by name. The nearest class-like scope applies to code inside an anonymous class,
so its nodes do not accidentally match the enclosing named class.

## Verified architecture

`PositionalPathsClassifier` is the existing extension seam. Positional source paths become
source filters and positional test paths become test-framework arguments. Source selectors
are independent and always constrain mutation source. The deprecated `--filter` remains
file-only and should not gain symbol syntax.

Test selection, source collection, and in-file constraints are distinct. A positional test
path restricts the initial tests and therefore their coverage. Source files are collected
through `Configuration\SourceFilter` and `Source\Collector`; line constraints implement
`SourceLineMatcher` and are consumed by `ExcludeUnchangedLinesVisitor`. The final mutation
set is the intersection of source-symbol selection, coverage, changed lines, ignores,
mutability, and the other existing eligibility rules.

The selector visitor runs after `NameResolver`, using its fully qualified class names, but
owns its lexical class and method stacks. It pushes a declaration before matching that
declaration and pops it on leave. It resets both stacks in `beforeTraverse()` because visitor
instances may be reused. This provides constant-time context lookup per node without storing
duplicate symbol-context attributes on every AST node. `ParentConnectingVisitor` remains
available to other enrichment consumers but is not needed for source-symbol matching.

The selector visitor belongs before `AddTestsVisitor`, preserving lazy coverage attachment:
nodes rejected by source selection do not need covering-test lookups. Line matching uses
inclusive `[startLine, endLine]` ranges; checking only a node's starting line would miss
multiline expressions. A method is pushed before its declaration node is matched, keeping
signature nodes eligible for function-signature mutators.

The matcher records every selector that finds a structural AST match, independently of the
node's existing eligibility. After all source files have been streamed through mutation
generation, `MutationGenerator` reports every still-unmatched selector together. Validation
therefore cannot fail before generation without adding a separate AST preflight pass; when a
request mixes valid and invalid selectors, mutants for valid selectors may already have been
yielded before the exception is raised.

## Next implementation slices

1. Add positional CLI help and
   infection/site examples. A CLI-only first release requires no schema change.

## Risks and decisions still open

- AST-time matching naturally selects methods declared directly in the matching class.
  Whether inherited methods should be selectable through the child class remains open and
  would require a different resolution mechanism.
- Eligibility remains a boolean and cannot explain whether coverage, ignores, or a selector
  excluded a node. The separately proposed selection-reasons work would improve diagnostics.
- Mutator-qualified selectors, namespaced functions, and ignore configuration are separate
  features and should not be added to the first grammar.

This is a durable user-facing CLI contract with credible grammar alternatives, so it remains
an ADR candidate. Search `adr/` and update or supersede an existing decision before proposing
a new record; do not write the ADR as part of this proof of concept.
