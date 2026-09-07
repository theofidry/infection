# Mutator performance workbook

Convert the append-only mutator-performance JSONL report into an Excel workbook:

```bash
php devTools/mutator-performance-workbook/build.php \
    var/mutator-performance.jsonl \
    var/mutator-performance.xlsx
```

The output contains `Summary`, `Metrics`, `Observations`, `Mutations`, `Reviews`, and `Lists` worksheets. The converter refuses
to replace an existing workbook so that completed reviews cannot be overwritten accidentally.

`Reviews` contains one row per unique mutation, independently of the number of recorded runs. `Observations` retains
one row per run and mutation. The hidden `Lists` worksheet documents the accepted review classifications; OpenSpout
does not support Excel data-validation rules, so the workbook does not currently provide classification dropdowns.
Cell values which exceed Excel's 32,767-character limit are marked and shortened; the JSONL remains the authoritative
source for the complete diff or process output.

`Metrics` calculates syntactic validity, review-driven actionability and unresolved proportions, evaluation workload,
recorded process runtime, and outcome instability. Metrics which need baselines, memory instrumentation, run metadata,
sampling information, or independent reviews are marked unavailable together with the missing input.
