<?php

declare(strict_types=1);

namespace Infection\Report;

use Infection\Reporter\Reporter;

final class NullReporter implements Reporter
{
    public function report(): void
    {
        // Do nothing.
    }
}
