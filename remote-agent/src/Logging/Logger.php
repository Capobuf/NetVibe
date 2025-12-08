<?php

declare(strict_types=1);

namespace RemoteAgent\Logging;

/**
 * Simple Logger for Remote Agent
 *
 * Log Levels:
 * - DEBUG   (0): Very detailed information for diagnosing problems. Use for development and troubleshooting.
 * - INFO    (1): Confirmation that things are working as expected. Startup messages, normal operations.
 * - WARNING (2): Something unexpected happened or may indicate a future problem. Recoverable errors, retries.
 * - ERROR   (3): A serious problem; the operation failed or cannot proceed. Non-fatal errors, missing resources.
 * - CRITICAL (4): Fatal error; the application cannot continue operating. Use sparingly.
 *
 * Example usage:
 *   $logger = new Logger('debug');
 *   $logger->debug('Attempting connection...');
 *   $logger->info('Server config loaded');
 *   $logger->warning('Timeout detected, retrying...');
 *   $logger->error('Failed to authenticate');
 *   $logger->critical('Cannot initialize drivers');
 */
class Logger
{
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    // Numeric level mappings for filtering
    private const LEVEL_MAP = [
        self::DEBUG => 0,
        self::INFO => 1,
        self::WARNING => 2,
        self::ERROR => 3,
        self::CRITICAL => 4,
    ];

    private string $currentLevel;
    private int $currentLevelNumeric;
    private $remoteSink = null;
    private int $remoteMinLevelNumeric;

    /**
     * @param string $level Log level: 'debug', 'info', 'warning', 'error', 'critical'
     */
    public function __construct(string $level = 'info')
    {
        $this->setLevel($level);
        // By default, mirror the local level for remote sink threshold if a sink is configured later
        $this->remoteMinLevelNumeric = $this->currentLevelNumeric;
    }

    /**
     * Log a debug message
     * Use for: Detailed diagnostic information (variable values, function entry/exit, detailed flow)
     */
    public function debug(string $message): void
    {
        $this->log(self::DEBUG, $message);
    }

    /**
     * Log an info message
     * Use for: General informational messages (startup, config loaded, normal operations)
     */
    public function info(string $message): void
    {
        $this->log(self::INFO, $message);
    }

    /**
     * Log a warning message
     * Use for: Warning conditions (retries, timeouts, unexpected but recoverable situations)
     */
    public function warning(string $message): void
    {
        $this->log(self::WARNING, $message);
    }

    /**
     * Log an error message
     * Use for: Error conditions (authentication failures, connection errors, missing data)
     */
    public function error(string $message): void
    {
        $this->log(self::ERROR, $message);
    }

    /**
     * Log a critical message
     * Use for: Critical conditions (fatal errors, cannot continue)
     */
    public function critical(string $message): void
    {
        $this->log(self::CRITICAL, $message);
    }

    /**
     * Check if a level should be logged based on current level
     */
    public function shouldLog(string $level): bool
    {
        $levelUpper = strtoupper($level);
        if (!isset(self::LEVEL_MAP[$levelUpper])) {
            return false;
        }

        return self::LEVEL_MAP[$levelUpper] >= $this->currentLevelNumeric;
    }

    /**
     * Get current log level
     */
    public function getCurrentLevel(): string
    {
        return $this->currentLevel;
    }

    /**
    * Dynamically adjust the current log level (e.g. when the control plane pushes a new level)
    */
    public function setLevel(string $level): void
    {
        $levelUpper = strtoupper($level);

        if (!isset(self::LEVEL_MAP[$levelUpper])) {
            $levelUpper = self::INFO;
        }

        $this->currentLevel = $levelUpper;
        $this->currentLevelNumeric = self::LEVEL_MAP[$levelUpper];
    }

    /**
     * Configure a remote sink callback that will receive log entries that pass the given threshold.
     *
     * @param callable $sink function (string $level, string $message): void
     * @param string $minLevel minimum level to send remotely (defaults to current level)
     */
    public function setRemoteSink(callable $sink, string $minLevel = null): void
    {
        $this->remoteSink = $sink;
        $threshold = $minLevel ?? $this->currentLevel;
        $thresholdUpper = strtoupper($threshold);
        $this->remoteMinLevelNumeric = self::LEVEL_MAP[$thresholdUpper] ?? self::LEVEL_MAP[self::INFO];
    }

    /**
     * Update the remote sink threshold without changing the sink itself.
     */
    public function setRemoteThreshold(string $minLevel): void
    {
        $minUpper = strtoupper($minLevel);
        $this->remoteMinLevelNumeric = self::LEVEL_MAP[$minUpper] ?? $this->remoteMinLevelNumeric;
    }

    /**
     * Internal log method
     */
    private function log(string $level, string $message): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $formatted = sprintf('[%s] [%s] %s', $timestamp, $level, $message);
        
        // Output to stderr for all logs (consistent with Docker container logs)
        fwrite(STDERR, $formatted . "\n");

        // Forward to remote sink if configured and passes threshold
        if ($this->remoteSink && (self::LEVEL_MAP[$level] ?? 99) >= $this->remoteMinLevelNumeric) {
            try {
                ($this->remoteSink)($level, $message);
            } catch (\Throwable $e) {
                // Never let remote sink failures break local logging
            }
        }
    }
}
