<?php

declare(strict_types=1);

namespace App\Services\Scanners;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Lightweight wrapper around 'fping' to return active hosts from a CIDR.
 */
final class FpingWrapper
{
    /**
     * Scan the given CIDR and return an array of IPv4 addresses discovered as active.
     *
     * @param  string  $cidr
     * @return array<int, string>
     *
     * @throws \RuntimeException when fping fails with a fatal exit code (3 or 4)
     */
    public function scan(string $cidr): array
    {
        // Build the command. Use a short timeout (-t 200) and single retry (-r 1) for speed.
        $process = Process::run([
            'fping',
            '-a',     // show only alive hosts
            '-g',     // expand CIDR into list of addresses
            '-r', '1',
            '-t', '200',
            $cidr,
        ]);

        $exit = $process->exitCode();

        // Only treat exit codes 3 or 4 as fatal errors (invalid usage / syscall failure).
        if ($exit === 3 || $exit === 4) {
            $err = trim($process->errorOutput() ?: $process->output());
            throw new RuntimeException(sprintf('fping failed (code: %d): %s', $exit, $err));
        }

        $stdout = $process->output();
        if ($stdout === null) {
            return [];
        }

        // Split output lines and filter empty values.
        $lines = preg_split('/\r\n|\n|\r/', $stdout);

        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $lines), fn (?string $ip) => $ip !== '' ));
    }
}
