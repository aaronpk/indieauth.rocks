<?php

declare(strict_types=1);

namespace IndieAuthRocks\Logging;

use Throwable;
use IndieAuthRocks\Config\SiteConfig;

/**
 * Appends diagnostics to a file on disk.
 *
 * This class deliberately depends on nothing but a path. The failures most worth
 * logging are the ones that happen while the application is still coming up —
 * a missing site.json, an unreadable data directory — so a logger that needed
 * configuration would be silent exactly when it mattered.
 *
 * If the chosen file cannot be written, output falls back to error_log() rather
 * than being dropped.
 */
final class ErrorLog
{
    /** Rotate once the file passes this size, keeping one previous generation. */
    private const MAX_BYTES = 8_388_608;

    private ?bool $writable = null;

    public function __construct(private readonly string $path)
    {
    }

    public static function fromEnvironment(): self
    {
        return new self(self::resolvePath());
    }

    /**
     * Where the log goes: $LOG_FILE if set, otherwise <root>/logs/app.log.
     * If that cannot be written, entries fall back to error_log().
     */
    public static function resolvePath(): string
    {
        $configured = getenv('LOG_FILE');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return SiteConfig::root() . '/logs/app.log';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exception(Throwable $e, ?string $requestId = null, ?string $request = null): void
    {
        $this->write('ERROR', $this->describe($e), $requestId, $request, $e);
    }

    public function warning(string $message, ?string $requestId = null, ?string $request = null): void
    {
        $this->write('WARN', $message, $requestId, $request);
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function write(
        string $level,
        string $message,
        ?string $requestId = null,
        ?string $request = null,
        ?Throwable $throwable = null,
    ): void {
        $line = sprintf(
            '[%s] %s%s%s %s',
            gmdate('c'),
            str_pad($level, 5),
            $requestId === null ? '' : ' req=' . $requestId,
            $request === null ? '' : ' ' . $request,
            $message,
        );

        // Stack traces are indented so a single entry stays visually one block
        // and `grep '^\['` lists just the headlines.
        if ($throwable !== null) {
            foreach (explode("\n", $throwable->getTraceAsString()) as $frame) {
                $line .= "\n    " . $frame;
            }

            $previous = $throwable->getPrevious();
            while ($previous !== null) {
                $line .= "\n    caused by " . $this->describe($previous);
                $previous = $previous->getPrevious();
            }
        }

        $this->append($line . "\n");
    }

    public function isWritable(): bool
    {
        if ($this->writable !== null) {
            return $this->writable;
        }

        $directory = dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            return $this->writable = false;
        }

        if (is_file($this->path)) {
            return $this->writable = is_writable($this->path);
        }

        return $this->writable = is_writable($directory);
    }

    private function append(string $entry): void
    {
        if (!$this->isWritable()) {
            error_log('indieauth.rocks (log file unwritable at ' . $this->path . '): ' . trim($entry));

            return;
        }

        $this->rotateIfLarge();

        $created = !is_file($this->path);

        // LOCK_EX keeps concurrent workers from interleaving trace lines.
        if (@file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX) === false) {
            $this->writable = false;
            error_log('indieauth.rocks (could not append to ' . $this->path . '): ' . trim($entry));

            return;
        }

        // The log can contain request paths and usernames, so it is no more
        // readable than the rest of the data directory.
        if ($created) {
            @chmod($this->path, 0o600);
        }
    }

    private function rotateIfLarge(): void
    {
        if (!is_file($this->path)) {
            return;
        }

        $size = @filesize($this->path);
        if ($size === false || $size < self::MAX_BYTES) {
            return;
        }

        @rename($this->path, $this->path . '.1');
    }

    private function describe(Throwable $e): string
    {
        return sprintf('%s: %s at %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
    }
}
