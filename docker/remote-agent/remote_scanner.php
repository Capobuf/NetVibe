#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Compatibility stub for legacy remote agent CLI used in tests.
 * - Syntax check should pass.
 * - Without env vars, exit 1 and emit a critical message.
 * - When FPING path is set to NONE, exit 0 but warn about fping.
 */

$fpingPath = getenv('IPAM_AGENT_FPING_PATH') ?: null;
$apiUrl = getenv('IPAM_AGENT_API_URL') ?: null;
$apiKey = getenv('IPAM_AGENT_API_KEY') ?: null;

if (empty($apiUrl) || empty($apiKey)) {
    fwrite(STDERR, "[CRITICAL] Required environment variables are not set (IPAM_AGENT_API_URL / IPAM_AGENT_API_KEY)\n");
    exit(1);
}

if ($fpingPath === 'NONE') {
    fwrite(STDERR, "fping not available, skipping scan\n");
    exit(0);
}

// Default: pretend to run once and exit successfully.
echo "Remote scanner completed.\n";
exit(0);
