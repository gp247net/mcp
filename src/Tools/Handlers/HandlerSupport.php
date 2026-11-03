<?php

namespace GP247\Mcp\Tools\Handlers;

use GP247\Mcp\Services\PiiMasker;
use GP247\Mcp\Services\StoreContext;
use GP247\Mcp\Services\ToolException;
use GP247\Mcp\Support\Settings;
use GP247\Shop\Models\ShopOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Shared helpers of the tool handlers: paging caps, LIKE escaping, untrusted text, date ranges.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-catalog-read
 */
abstract class HandlerSupport
{
    public const MAX_PER_PAGE = 50;
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_TEXT = 2000;

    public function __construct(protected readonly StoreContext $stores)
    {
    }

    /** Clamp the requested page size. */
    protected function perPage(array $args): int
    {
        return max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? self::DEFAULT_PER_PAGE)));
    }

    /** Requested page (1-based). */
    protected function page(array $args): int
    {
        return max(1, (int) ($args['page'] ?? 1));
    }

    /** Escape LIKE wildcards in user input. */
    protected function like(string $value): string
    {
        return '%' . addcslashes($value, '%_\\') . '%';
    }

    /**
     * Free text written by customers/staff, cut to a safe length. Returned only inside
     * "untrusted_text" blocks so agents treat it as data (NFR-SEC-mcp-server-untrusted-content).
     */
    protected function untrusted(?string $text, int $max = self::MAX_TEXT): string
    {
        $plain = trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return mb_substr($plain, 0, $max);
    }

    /** Masker honouring the site's PII setting. */
    protected function masker(): PiiMasker
    {
        return new PiiMasker(Settings::allowPii());
    }

    /**
     * Page metadata + mapped items.
     *
     * @param callable(mixed): array $map
     * @return array<string, mixed>
     */
    protected function paged(LengthAwarePaginator $page, callable $map): array
    {
        return [
            'items' => array_map($map, $page->items()),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ];
    }

    /**
     * Parse an optional Y-m-d date.
     */
    protected function date(?string $value, bool $endOfDay = false): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            throw ToolException::trans('error_bad_date', ['value' => mb_substr($value, 0, 20)]);
        }
        if ($date === false) {
            throw ToolException::trans('error_bad_date', ['value' => mb_substr($value, 0, 20)]);
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    /**
     * Free text written into shop data by an agent: control characters removed (they break the
     * history view and log lines), trimmed, cut to $max characters. HTML is escaped by the caller/seam.
     *
     * @aidlc-story US-plugin-mcp-server-order-note-write
     */
    protected function plainText(?string $text, int $max): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text));

        return mb_substr($clean, 0, $max);
    }

    /**
     * Order by id inside the store scope; "not found" for an unknown id and for an order of
     * another store alike (no existence disclosure).
     *
     * @throws ToolException
     */
    protected function findOrder(string $orderId, string $storeId): ShopOrder
    {
        $order = ShopOrder::query()->find($orderId);
        if ($order === null || !$this->stores->contains($storeId, $order->store_id)) {
            throw ToolException::notFound();
        }

        return $order;
    }

    /**
     * Pick a description row in the admin locale, falling back to the first one.
     *
     * @param iterable<object> $descriptions
     */
    protected function localized(iterable $descriptions): ?object
    {
        $locale = function_exists('gp247_get_locale') ? gp247_get_locale() : app()->getLocale();
        $first = null;
        foreach ($descriptions as $description) {
            $first ??= $description;
            if (($description->lang ?? null) === $locale) {
                return $description;
            }
        }

        return $first;
    }
}
