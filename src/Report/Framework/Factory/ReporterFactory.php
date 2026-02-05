<?php

declare(strict_types=1);

namespace Infection\Report\Framework\Factory;

use Infection\Configuration\Entry\Logs;
use Infection\Reporter\Reporter;

interface ReporterFactory
{
    public function create(Logs $logConfig): Reporter;
}
