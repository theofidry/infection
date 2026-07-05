# PHPat Architecture Tests

This directory contains architecture fitness and convention rules written with PHPat. These are
not PHPUnit tests: they are registered in `devTools/phpstan.neon` and run as part of
`make phpstan` and `make autoreview`.

The root `*Test.php` files describe project-level rules. Keep them focused on the rule itself:
which classes are selected, what they should or should not do, and the high-level reason for
the constraint.

Reusable selection logic lives under `Selector/`. Simple selectors identify broad groups of
classes, such as source code, test code, extension points, or integration tests. More specific
selectors encode project conventions that are too involved to express inline in a PHPat rule.

Shared selector utilities live under `Selector/Support/`. This includes small helpers for
common project concepts and an analyser that inspects ASTs to retrieve information that PHPat
or PHPStan reflections do not expose directly.

Selector and support behaviour is covered by regular PHPUnit tests under
`tests/phpunit/Architecture/PHPat/`. When a rule needs non-trivial selection logic, prefer
extracting and testing a selector instead of growing the PHPat rule class.
