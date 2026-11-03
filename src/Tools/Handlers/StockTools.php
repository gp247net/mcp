<?php

namespace GP247\Mcp\Tools\Handlers;

use GP247\Mcp\Services\ConfirmationService;
use GP247\Mcp\Services\StoreContext;
use GP247\Mcp\Services\ToolException;
use GP247\Shop\Models\ShopProduct;

/**
 * adjust_stock (1.1.0): receive (+) or issue (−) stock by a delta, in two steps, through
 * ShopProduct::adjustStock — atomic, never below zero, sold counter untouched, `stock.changed`
 * recorded. No absolute overwrite through MCP (ADR-plugin-mcp-server-write-tool-policy).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-stock-adjust-write
 * @aidlc-adr ADR-plugin-mcp-server-write-tool-policy
 */
class StockTools extends HandlerSupport
{
    /**
     * @param StoreContext        $stores        Store scope (L4).
     * @param ConfirmationService $confirmations Two-step confirm codes.
     */
    public function __construct(StoreContext $stores, private readonly ConfirmationService $confirmations)
    {
        parent::__construct($stores);
    }

    /**
     * Preview (no confirm_code) or apply (valid confirm_code) one stock delta.
     *
     * @param array<string, mixed> $args Validated: product_id, delta (≠ 0, ≤ 4 decimals), reason?, confirm_code?
     * @return array<string, mixed>
     * @throws ToolException Product out of scope, negative result, bad code, stock changed meanwhile.
     */
    public function adjust(array $args, string $storeId, int|string $tokenId): array
    {
        $product = ShopProduct::query()->with('descriptions')->find((string) $args['product_id']);
        if ($product === null || !$this->stores->contains($storeId, $product->store_id)) {
            throw ToolException::notFound();
        }
        $delta = round((float) $args['delta'], 4);
        $before = (float) $product->stock;
        $after = round($before + $delta, 4);
        if ($after < 0) {
            throw ToolException::trans('error_stock_negative', ['stock' => $before, 'delta' => $delta]);
        }
        // WHY a string: the fingerprint must not depend on float formatting between the two calls.
        $change = ['product_id' => (string) $product->id, 'delta' => number_format($delta, 4, '.', '')];

        if (($args['confirm_code'] ?? '') === '') {
            return [
                'applied' => false,
                'preview' => [
                    'product_id' => (string) $product->id,
                    'sku' => (string) $product->sku,
                    'name' => $this->untrusted($this->localized($product->descriptions)?->name, 255),
                    'stock_before' => $before,
                    'delta' => $delta,
                    'stock_after' => $after,
                ],
                'confirm_code' => $this->confirmations->issue($tokenId, 'adjust_stock', $change),
                'expires_in_seconds' => ConfirmationService::TTL_SECONDS,
            ];
        }
        if (!$this->confirmations->consume((string) $args['confirm_code'], $tokenId, 'adjust_stock', $change)) {
            throw ToolException::trans('error_confirm_code');
        }
        // WHY no lock: adjustStock is one guarded UPDATE; the preview is informative only.
        if (!ShopProduct::adjustStock($product->id, $delta)) {
            throw ToolException::trans('error_stock_changed');
        }

        return [
            'applied' => true,
            'product_id' => (string) $product->id,
            'delta' => $delta,
            'stock' => (float) ShopProduct::query()->whereKey($product->id)->value('stock'),
        ];
    }
}
