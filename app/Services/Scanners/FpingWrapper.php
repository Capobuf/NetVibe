<?php

declare(strict_types=1);

namespace App\Services\Scanners;

use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Fping Wrapper Service
 * 
 * Provides network scanning capabilities using the fping utility.
 * Designed for fast ICMP-based host discovery on CIDR ranges.
 */
class FpingWrapper
{
    /**
     * Scan a subnet and return alive IP addresses.
     * 
     * @param string $cidr CIDR notation (e.g., "192.168.1.0/24")
     * @return array<int, string> Array of alive IP addresses
     * @throws RuntimeException If fping command fails critically
     */
    public function scanSubnet(string $cidr): array
    {
        // Validate CIDR format
        if (!$this->isValidCidr($cidr)) {
            throw new \InvalidArgumentException("Invalid CIDR format: {$cidr}");
        }

        // Build fping command
        // -a: Show alive hosts only
        // -g: Generate target list from CIDR
        // -r 1: Retry once
        // -t 500: Timeout 500ms (balanced for LAN/WAN)
        $command = sprintf(
            'fping -a -g -r 1 -t 500 %s 2>&1',
            escapeshellarg($cidr)
        );

        try {
            $result = Process::run($command);
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                "Fping command failed: {$e->getMessage()}",
                0,
                $e
            );
        }

        // fping returns non-zero exit codes even on success:
        // - 0: All hosts are alive
        // - 1: Some hosts are unreachable
        // - 2: Any IP addresses were not found
        // We only care if the command itself failed (e.g., "command not found")
        $exitCode = $result->exitCode();
        $output = $result->output();

        // Critical failure check (fping not installed, invalid syntax, etc.)
        if ($exitCode > 2 || str_contains($output, 'command not found')) {
            throw new RuntimeException(
                "Fping execution failed with exit code {$exitCode}: {$output}"
            );
        }

        return $this->parseOutput($output);
    }

    /**
     * Parse fping output and extract valid IP addresses.
     * 
     * @param string $output Raw fping stdout
     * @return array<int, string> Array of valid IP addresses
     */
    private function parseOutput(string $output): array
    {
        if (empty(trim($output))) {
            return [];
        }

        // Split by newlines
        $lines = explode("\n", $output);

        // Clean and filter
        $ips = array_map('trim', $lines);
        $ips = array_filter($ips); // Remove empty lines

        // Validate IP addresses
        $validIps = array_filter($ips, function ($ip) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        });

        // Re-index array
        return array_values($validIps);
    }

    /**
     * Validate CIDR notation.
     * 
     * @param string $cidr CIDR string to validate
     * @return bool True if valid CIDR
     */
    private function isValidCidr(string $cidr): bool
    {
        // Basic CIDR validation (IP/prefix)
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$ip, $prefix] = explode('/', $cidr, 2);

        // Validate IP part
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        // Validate prefix (0-32)
        if (!is_numeric($prefix) || (int)$prefix < 0 || (int)$prefix > 32) {
            return false;
        }

        return true;
    }
}
