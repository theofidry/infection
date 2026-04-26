# PHPat investigation

## Context

- `phpat/phpat` is locked at `0.12.4`.
- It is already wired in `devTools/phpstan.neon`.
- There is already one PHPat rule: `devTools/PHPat/SrcShouldNotDependOnTestsTest.php`.
- Relevant PHPat surface here: selectors on namespace/classname/filepath/extends/implements/includes/attributes/modifiers; assertions on depend/extend/implement/include/applyAttribute/construct/final/abstract/readonly/interface/invokable/name/one-public-method.

## Existing AutoReview rules to migrate

- `tests/phpunit/AutoReview/ProjectCode/ProjectCodeTest::test_all_test_classes_are_trait_abstract_or_final`
  - Good fit.
  - PHPat can express: test classes excluding abstract/trait/interface should be `final`.
  - This is the cleanest migration.

- `tests/phpunit/AutoReview/ProjectCode/ProjectCodeTest::test_non_extension_points_are_traits_interfaces_abstracts_or_finals`
  - Mostly fit if restated as: concrete non-extension-point source classes should be `final`.
  - Need exclusions for `EXTENSION_POINTS` and `NON_FINAL_EXTENSION_CLASSES`.
  - Caveat: current PHPUnit test also accepts docblock `@final`; PHPat only sees language `final`. Migrating this rule hardens it.

- `tests/phpunit/AutoReview/Mutator/MutatorTest::test_only_configurable_mutators_have_a_config`
  - Partial fit only.
  - PHPat can enforce `*Config` classes in `src/Mutator` implement `MutatorConfig`.
  - PHPat cannot verify the owning mutator constructor signature or the `getConfigClassName()` match.

## Keep as PHPUnit / not worth moving

- `ProjectCodeTest::test_all_concrete_classes_have_tests`
  - PHPat has no good source-to-test pairing primitive.

- `ProjectCodeTest::test_non_extension_points_are_internal`
  - Current rule is docblock `@internal`; PHPat has no docblock assertion.

- `ProjectCodeTest::test_source_classes_do_not_expose_public_properties`
  - No property visibility/property count assertion.

- `BuildConfigYmlTest`, `MakefileTest`, `test_infection_bin_is_executable`
  - Outside PHPat scope.

- `Event/SubscriberTest`
  - Current rule checks public method signature plus event-type-based method naming.
  - PHPat cannot express that.

- `MutatorTest::test_mutators_do_not_declare_public_methods`
  - PHPat only has "one public method" / "one public method named". Not enough for the current allow-list.

- `MutatorTest::test_mutators_have_a_definition`
  - No static method return-value assertion.

- `MutatorTest::test_configurable_mutators_declare_a_mutator_config`
  - No constructor signature / `getConfigClassName()` relation assertion.

- `IntegrationGroupTest`
  - PHPat can assert an attribute, but not discover "tests doing IO" from function-call scanning.

- `EnvManipulationTest`
  - PHPat can assert a trait include, but not discover "tests touching env" from code scanning.

## New PHPat rules worth adding first

- Commands
  - All concrete `*Command` classes under `src/Command` should extend `BaseCommand`.
  - All concrete `*Command` classes under `src/Command` should be `final`.

- Mutator config classes
  - `src/Mutator/**/**Config.php` should implement `MutatorConfig`.
  - Same classes should be `final`.

- Config entry value objects
  - `src/Configuration/Entry` excluding `Logs` should be `final`.
  - Same set excluding `Logs` should be `readonly`.
  - Same set should not depend on `Infection\Command`, `Infection\Console`, `Infection\Container`, `Infection\Reporter`, `Infection\Logger`, `Infection\Event`, `Infection\Process`.

- Mutator isolation
  - `Infection\Mutator` should not depend on `Infection\Command`, `Infection\Console`, `Infection\Container`, `Infection\Reporter`.
  - Good guardrail: current imports stay mostly in `PhpParser`, `Reflection`, `Mutation`, `Source`, `Framework`.

- Environment isolation
  - `Infection\Environment` should not depend on `Infection\Command`, `Infection\Console`, `Infection\Container`, `Infection\Reporter`.

- Throwable namespaces
  - Classes under `Infection\TestFramework\Coverage\Locator\Throwable` excluding interfaces should implement `ReportLocationThrowable`.
  - Classes under `.../Throwable` namespaces should otherwise be exception/throwable-only; PHPat can enforce this with `shouldNot()->exist()` on the complement set.

- Test rules in PHPat
  - `Infection\Tests\**\*Test` excluding abstract/trait/interface should be `final`.
  - If you want a simpler replacement for part of `IntegrationGroupTest`: classes extending a known integration base class, or selected by filepath/namespace, can be required to apply `#[Group('integration')]`. This is weaker than current IO scanning, but much cheaper to maintain.

## Suggested order

1. Migrate the two cleanest rules: test classes final; non-extension concrete classes final.
2. Add low-risk new rules: command inheritance/finality; mutator config implements interface; config entry final/readonly.
3. Add negative dependency rules: `Configuration\Entry`, `Mutator`, `Environment`.
4. Keep the dynamic scanners and signature/docblock checks in PHPUnit.

## Sketches

```php
PHPat::rule()
    ->classes(Selector::inNamespace('Infection\\Tests'))
    ->excluding(Selector::isAbstract(), Selector::isTrait(), Selector::isInterface())
    ->should()->beFinal();

PHPat::rule()
    ->classes(
        Selector::AllOf(
            Selector::inNamespace('Infection\\Command'),
            Selector::classname('.*Command$', true),
        ),
    )
    ->excluding(Selector::classname(Infection\Command\BaseCommand::class))
    ->should()->extend()->classes(Selector::classname(Infection\Command\BaseCommand::class));

PHPat::rule()
    ->classes(
        Selector::AllOf(
            Selector::inNamespace('Infection\\Mutator'),
            Selector::classname('.*Config$', true),
        ),
    )
    ->should()->implement()->classes(Selector::classname(Infection\Mutator\MutatorConfig::class));
```
