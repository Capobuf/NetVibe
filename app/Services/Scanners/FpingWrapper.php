<?php

namespace App\Services\Scanners;

use Symfony\Component\Process\Process;

class FpingWrapper
{
    protected string $fpingPath = '/usr/sbin/fping';

    /**
     * Scan a subnet in CIDR notation
     * 
     * @param string $cidr CIDR notation (e.g., "192.168.1.0/24")
     * @param int $timeout Timeout in milliseconds
     * @return array Array of alive IP addresses
     */
    public function scanSubnet(string $cidr, int $timeout = 1000): array
    {
        $command = [
            $this->fpingPath,
            '-g', $cidr,     // Generate mode with CIDR
            '-t', $timeout,  // Timeout in ms
            '-r', '1',       // Retry count
            '-a',            // Show alive hosts only
            '-q',            // Quiet mode
        ];

        $process = new Process($command);
        $process->setTimeout(300); // 5 minutes max
        $process->run();

        return $this->parseAliveHosts($process->getOutput());
    }

    /**
     * Scan a range of IP addresses
     * 
     * @param string $startIp Starting IP
     * @param string $endIp Ending IP
     * @param int $timeout Timeout in milliseconds
     * @return array Array of alive IP addresses
     */
    public function scanRange(string $startIp, string $endIp, int $timeout = 1000): array
    {
        $command = [
            $this->fpingPath,
            '-g',           // Generate mode
            $startIp,
            $endIp,
            '-t', $timeout,
            '-r', '1',
            '-a',           // Alive only
            '-q',           // Quiet
        ];

        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        return $this->parseAliveHosts($process->getOutput());
    }

    /**
     * Parse fping output for alive hosts
     * 
     * @param string $output fping command output
     * @return array Array of IP addresses
     */
    protected function parseAliveHosts(string $output): array
    {
        $lines = explode("\n", trim($output));
        $alive = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // fping -a output: just IP addresses, one per line
            if (filter_var($line, FILTER_VALIDATE_IP)) {
                $alive[] = $line;
            }
        }

        return $alive;
    }

    /**
     * Check if fping is available
     * 
     * @return bool True if fping is installed
     */
    public function isAvailable(): bool
    {
        return file_exists($this->fpingPath) && is_executable($this->fpingPath);
    }
}
