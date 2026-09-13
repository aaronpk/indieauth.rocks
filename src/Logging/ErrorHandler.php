<?php

declare(strict_types=1);

namespace IndieAuthRocks\Logging;

use Throwable;

/**
 * Catches anything the request lifecycle does not.
 *
 * The Kernel already logs exceptions thrown inside a request. This covers the
 * rest: failures during bootstrap, warnings and notices, and fatal errors that
 * unwind the process without ever reaching a catch block. Those are exactly the
 * cases that otherwise produce a blank 500 with nothing written down anywhere.
 */
final class ErrorHandler
{
    private static bool $installed = false;

    public static function install(ErrorLog $log, bool $debug = false, bool $web = true): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;

        // Never print diagnostics into the response body; they go to the log.
        // Under `php -S` display_errors is on by default, which would otherwise
        // splice PHP notices into rendered HTML.
        if ($web) {
            ini_set('display_errors', '0');
        }
        ini_set('log_errors', '1');

        self::installErrorHandler($log);
        self::installExceptionHandler($log, $debug, $web);
        self::installFatalHandler($log, $debug, $web);
    }

    /** Only for tests, so one case cannot leak handlers into the next. */
    public static function reset(): void
    {
        self::$installed = false;
        restore_error_handler();
        restore_exception_handler();
    }

    private static function installErrorHandler(ErrorLog $log): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($log): bool {
            // Respect the current error_reporting mask, so a deliberately
            // suppressed call (@mkdir, @filesize) stays quiet.
            if ((error_reporting() & $severity) === 0) {
                return true;
            }

            $log->write(
                self::severityLabel($severity),
                sprintf('%s at %s:%d', $message, $file, $line),
            );

            // Handled: PHP's own handler would print to the response body.
            return true;
        });
    }

    private static function installExceptionHandler(ErrorLog $log, bool $debug, bool $web): void
    {
        set_exception_handler(static function (Throwable $e) use ($log, $debug, $web): void {
            $log->exception($e);

            if ($web) {
                self::emitFailurePage($e, $log, $debug);
            } elseif ($debug) {
                fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
            }
        });
    }

    private static function installFatalHandler(ErrorLog $log, bool $debug, bool $web): void
    {
        register_shutdown_function(static function () use ($log, $debug, $web): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $log->write('FATAL', sprintf('%s at %s:%d', $error['message'], $error['file'], $error['line']));

            if ($web && !headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo $debug
                    ? sprintf("Fatal error: %s at %s:%d\n", $error['message'], $error['file'], $error['line'])
                    : "Something went wrong. See " . $log->path() . "\n";
            }
        });
    }

    /**
     * A last-resort page for an exception that escaped the Kernel.
     *
     * Deliberately plain text with no templating: whatever just failed may well
     * be the thing the template renderer needs.
     */
    private static function emitFailurePage(Throwable $e, ErrorLog $log, bool $debug): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');

        if ($debug) {
            printf("%s: %s\n\nat %s:%d\n\n%s\n", $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());

            return;
        }

        printf("The server could not handle this request.\n\nDetails were written to:\n  %s\n", $log->path());
    }

    private static function severityLabel(int $severity): string
    {
        return match ($severity) {
            E_WARNING, E_USER_WARNING, E_COMPILE_WARNING, E_CORE_WARNING => 'WARN',
            E_NOTICE, E_USER_NOTICE                                      => 'NOTE',
            E_DEPRECATED, E_USER_DEPRECATED                              => 'DEPR',
            E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR                   => 'ERROR',
            default                                                      => 'NOTE',
        };
    }
}
