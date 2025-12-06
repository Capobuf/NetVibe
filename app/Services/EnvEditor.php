<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Minimal, testable EnvEditor implementation.
 * - Allows injecting an env file path (for tests)
 * - Performs backup before write
 * - Writes atomically using tempfile + rename
 */
class EnvEditor
{
    public function __construct(protected ?string $path = null)
    {
        $this->path = $this->path ?? base_path('.env');
    }

    public function setKey(string $key, string $value): bool
    {
        return $this->setKeys([$key => $value]);
    }

    /**
     * Set multiple key => value pairs. Returns true on success, throws on failure.
     */
    public function setKeys(array $pairs): bool
    {
        $envPath = $this->path;

        $dir = dirname($envPath);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException('Unable to create env folder');
            }
        }

        $content = '';
        if (file_exists($envPath)) {
            $content = file_get_contents($envPath);
            // backup
            $bak = $envPath . '.bak';
            if (false === @copy($envPath, $bak)) {
                // best effort, ignore backup failure
            }
            // ensure file is writable by process
            if (! is_writable($envPath)) {
                throw new RuntimeException('Env file exists but is not writable');
            }
        }

        // ensure directory is writable when creating new file
        if (! file_exists($envPath) && ! is_writable(dirname($envPath))) {
            throw new RuntimeException('Env directory is not writable');
        }

        $lines = $content === '' ? [] : explode("\n", $content);

        foreach ($pairs as $k => $v) {
            $escaped = $this->escapeValue($v);
            $found = false;
            foreach ($lines as &$line) {
                if (str_starts_with($line, "$k=")) {
                    $line = "$k=$escaped";
                    $found = true;
                    break;
                }
            }

            unset($line);

            if (! $found) {
                $lines[] = "$k=$escaped";
            }
        }

        $new = implode("\n", $lines);

        // atomic write
        $tmp = $this->createTempFile();
        if (false === $tmp) {
            throw new RuntimeException('Unable to create temp file');
        }

        if (false === $this->writeTempFile($tmp, $new)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write env temp file');
        }

        if (! $this->atomicReplace($tmp, $envPath)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to atomically write env file');
        }

        return true;
    }

    protected function escapeValue(string $v): string
    {
        // if contains spaces or special chars wrap in double quotes
        if ($v === '' || preg_match('/[\\s"\\\\]/', $v)) {
            // escape double quotes and backslashes inside
            $inner = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
            return '"' . $inner . '"';
        }

        return $v;
    }

    // ---- protected wrappers for easier testing ----
    protected function createTempFile(): string|false
    {
        return tempnam(sys_get_temp_dir(), 'envtmp_');
    }

    protected function writeTempFile(string $path, string $contents): bool
    {
        return false !== @file_put_contents($path, $contents);
    }

    protected function atomicReplace(string $tmp, string $dest): bool
    {
        return @rename($tmp, $dest);
    }
}
