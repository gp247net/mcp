<?php

namespace GP247\Mcp\Support;

/**
 * Report an exception without ever letting the reporter itself break the request.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-endpoint
 */
final class SafeReport
{
    /**
     * Send the throwable to gp247_report() (or Laravel's report()) and swallow reporter errors.
     */
    public static function throwable(\Throwable $e): void
    {
        try {
            if (function_exists('gp247_report')) {
                gp247_report($e);
            } else {
                report($e);
            }
        } catch (\Throwable) {
            // WHY: reporting is best-effort; a broken reporter must not turn a handled error into a 500.
        }
    }
}
