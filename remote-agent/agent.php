#!/usr/bin/env php
<?php
// Minimal entry point to run the agent command without a full framework.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use RemoteAgent\Console\AgentWorkCommand;

$command = new AgentWorkCommand();
$command->run();
