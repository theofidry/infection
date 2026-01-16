<?php

namespace Infection\Report\Factory;

use Infection\Configuration\Entry\Logs;
use Infection\Report\Reporter;

interface ReporterFactory
{
    public function create(Logs $logConfig): Reporter;
}
