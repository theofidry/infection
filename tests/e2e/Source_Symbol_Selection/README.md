# Source symbol selection

Verifies that the positional selector `Calculator::add` limits mutation generation to that method.
Both methods are covered: `add()` has one `Plus` mutation and `addThree()` has two.
Only the mutation in `add()` should be generated and killed.
