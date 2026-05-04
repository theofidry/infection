<?php

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\NoopLogWriter;

$loader = require __DIR__ . '/../../vendor/autoload.php';

Logging::setLogWriter(new NoopLogWriter());

return $loader;
