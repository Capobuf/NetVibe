<?php

use Illuminate\Support\Facades\Process;

test('remote agent script has valid syntax', function () {
    $result = Process::run('php -l docker/remote-agent/remote_scanner.php');
    
    expect($result->successful())->toBeTrue();
    expect($result->output())->toContain('No syntax errors detected');
});

test('remote agent script fails gracefully without env vars', function () {
    // Run script without setting env vars, with --once flag
    $result = Process::path(base_path())
        ->run('php docker/remote-agent/remote_scanner.php --once');
    
    expect($result->exitCode())->toBe(1);
    expect($result->errorOutput())->toContain('[CRITICAL]');
    expect($result->errorOutput())->toContain('not set');
});
