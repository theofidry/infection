<?php

declare(strict_types=1);

namespace Infection\Report;

final class NullReporter implements Reporter
{
    public function report(): void
    {
        // Do nothing.
    }
}