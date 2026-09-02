# PHPUnit test suite bootstrap

This scenario verifies support for the test suite-specific bootstrap attribute introduced in
PHPUnit 12.3, reproducing the configuration reported in
https://github.com/infection/infection/issues/2941.

The project has a root bootstrap and separate unit and integration suite bootstraps. Both suites
cover the same generated mutation, which should escape. If Infection fails to resolve the suite
bootstrap paths in the temporary initial configuration, the initial run fails. If Infection drops
the bootstraps from the mutant configuration, the resulting test failures incorrectly kill the
mutant.
