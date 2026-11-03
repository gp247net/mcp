<?php

namespace GP247\Mcp\Support;

use Illuminate\Http\JsonResponse;

/**
 * Error responses MCP answers at the edge of the MCP endpoint, in the GP247 REST API
 * error envelope `{error, msg, code}`. The core renders every exception under `api/core/*`
 * (the endpoint included) with that envelope, so a refusal built here must use the
 * very same body — a 401 that differs from the core one tells a locked account from a bad token.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-endpoint
 * @aidlc-adr ADR-plugin-mcp-server-endpoint-placement
 */
final class EdgeResponse
{
    private const CORE_RESPONDER = '\GP247\Core\Api\Support\ApiErrorResponder';

    /**
     * 401 with the exact body the core answers for a missing or invalid token.
     *
     * @return JsonResponse
     */
    public static function unauthenticated(): JsonResponse
    {
        if (class_exists(self::CORE_RESPONDER)) {
            $response = call_user_func([self::CORE_RESPONDER, 'unauthenticated']);
        } else {
            // WHY: core < 3.2.3 renders the auth exception as Laravel does; match that body.
            $response = response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $response->headers->set('WWW-Authenticate', 'Bearer');

        return $response;
    }

    /**
     * Refusal with a stable machine code.
     *
     * @param int                   $status  HTTP status.
     * @param string                $code    Machine code, e.g. "forbidden_origin".
     * @param string                $msg     Short English message (no internals).
     * @param array<string, string> $headers Extra headers (Retry-After…).
     * @return JsonResponse
     */
    public static function refuse(int $status, string $code, string $msg, array $headers = []): JsonResponse
    {
        if (class_exists(self::CORE_RESPONDER)) {
            return call_user_func([self::CORE_RESPONDER, 'respond'], $status, $code, $msg, $headers);
        }

        return response()->json(['error' => 1, 'msg' => $msg, 'code' => $code], $status, $headers);
    }

    /**
     * Whether the master REST API switch of the API management screen is on. Group switches
     * (v1/v2) are deliberately ignored: the endpoint path sits under the v1 prefix, which a
     * fresh install turns off. Core without the switch (< 3.3.1) counts as on.
     *
     * @return bool
     */
    public static function apiMasterSwitchOn(): bool
    {
        $switch = '\GP247\Core\Api\Support\ApiSwitch';
        if (!class_exists($switch)) {
            return true;
        }
        try {
            $states = call_user_func([$switch, 'states']);
        } catch (\Throwable $e) {
            SafeReport::throwable($e);

            // WHY closed: an unreadable switch must not keep an emergency stop from working.
            return false;
        }

        return (bool) ($states[constant($switch . '::MASTER')] ?? true);
    }
}
