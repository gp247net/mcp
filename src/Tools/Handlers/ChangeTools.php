<?php

namespace GP247\Mcp\Tools\Handlers;

use GP247\Mcp\Services\ToolException;
use GP247\Core\Api\Sync\ApiEvents;
use GP247\Core\Api\Sync\EventPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * recent_changes (1.1.0): the core event log (api_event, gp247/core 3.7.0) read with the same
 * conditions as GET api/v2/core/events — readable types only, the caller's store, events
 * younger than the settle window held back — but by time window, the way an agent asks
 * ("what changed since yesterday"). Payload = the core EventPayload.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-recent-changes-read
 */
class ChangeTools extends HandlerSupport
{
    /** Largest page of events in one call. */
    public const MAX_LIMIT = 100;
    /** Page size when the caller gives none. */
    public const DEFAULT_LIMIT = 50;
    /** Window when the caller gives no `since`. */
    public const DEFAULT_HOURS = 24;

    /**
     * Events of the readable types since a time, oldest first.
     *
     * @param array<string, mixed> $args      Validated: since? (ISO 8601 / Y-m-d H:i:s), types? (string[]), after? (int), limit? (int)
     * @param string[]             $readable  Event types the caller may read (ToolAuthorizer::readableEventTypes).
     * @return array<string, mixed>
     * @throws ToolException Unparseable `since`.
     */
    public function recent(array $args, string $storeId, array $readable): array
    {
        if (!ApiEvents::enabled() || !ApiEvents::ready()) {
            return ['enabled' => false, 'hint' => (string) trans('gp247-mcp::lang.events_disabled_hint')];
        }
        $types = $readable;
        if (!empty($args['types'])) {
            $types = array_values(array_intersect($readable, array_map('strval', (array) $args['types'])));
        }
        $limit = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));
        $since = $this->since($args['since'] ?? null);
        $settle = max(0, (int) config('gp247-config.api.event_settle_seconds', 5));

        $query = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_event')
            ->where('id', '>', (int) ($args['after'] ?? 0))
            ->whereIn('type', $types ?: ['-'])
            ->where('occurred_at', '>=', $since)
            ->where('occurred_at', '<=', now()->subSeconds($settle));
        // WHY ROOT unfiltered: same rule as the REST event reader (ROOT = every store).
        if ($storeId !== (string) GP247_STORE_ID_ROOT) {
            $query->where('store_id', $storeId);
        }
        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        return [
            'enabled' => true,
            'since' => $since->toIso8601String(),
            'events' => $rows->map(fn ($row) => EventPayload::fromRow($row))->all(),
            'next_after' => $rows->isNotEmpty() ? (int) $rows->last()->id : (int) ($args['after'] ?? 0),
            'has_more' => $hasMore,
        ];
    }

    /**
     * Start of the window: given time, default 24 hours ago, never before the retention of the log.
     *
     * @throws ToolException
     *
     * @aidlc-story US-plugin-mcp-server-recent-changes-read
     */
    private function since(?string $value): Carbon
    {
        $floor = now()->subDays(max(1, (int) config('gp247-config.api.event_days', 30)));
        if ($value === null || $value === '') {
            return now()->subHours(self::DEFAULT_HOURS);
        }
        try {
            // WHY setTimezone: occurred_at is stored in app time; a bound Carbon keeps the client offset.
            $since = Carbon::parse($value)->setTimezone((string) config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            throw ToolException::trans('error_bad_date', ['value' => mb_substr($value, 0, 30)]);
        }

        return $since->lessThan($floor) ? $floor : $since;
    }
}
