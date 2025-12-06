<?php

use Illuminate\Support\Facades\Process;
use Tests\Factories\RemoteAgentFactory;

// (Keep global RefreshDatabase for Feature tests. These tests will be run against MariaDB in CI/local.)

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

test('remote agent warns when fping is missing and env configured', function () {
    // Ensure push logs disabled so the test doesn't attempt external HTTP calls
    $result = Process::path(base_path())
        ->env([
            'IPAM_AGENT_API_URL' => 'http://127.0.0.1',
            'IPAM_AGENT_API_KEY' => 'dummy',
            'IPAM_AGENT_PUSH_LOGS' => 'false',
            // Provide deterministic subnets via factory
            'IPAM_AGENT_TEST_SUBNETS' => RemoteAgentFactory::makeSubnets(['192.168.254.0/30']),
            // Tell agent to treat fping as missing
            'IPAM_AGENT_FPING_PATH' => 'NONE'
        ])
        ->run('php docker/remote-agent/remote_scanner.php --once');

    expect($result->exitCode())->toBe(0);
    // Expect an error message about fping missing (written to stderr via pushLog/error_log)
    expect($result->errorOutput())->toMatch('/fping/i');
});
