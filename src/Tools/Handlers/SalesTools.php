<?php

namespace GP247\Mcp\Tools\Handlers;

use GP247\Mcp\Services\ToolException;
use GP247\Mcp\Support\Settings;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Models\ShopPaymentStatus;
use GP247\Shop\Models\ShopShippingStatus;

/**
 * sales_summary and store_info. Revenue uses the same definition as the admin report
 * (AdminOrder::getSumAmountOrder: sum of total of Done orders, per currency) restricted to a date range;
 * amounts of different currencies are never added together.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-sales-summary
 */
class SalesTools extends HandlerSupport
{
    /**
     * sales_summary.
     *
     * @param array<string, mixed> $args from, to (Y-m-d), top (1..20)
     * @return array<string, mixed>
     */
    public function summary(array $args, string $storeId): array
    {
        $from = $this->date($args['from'] ?? null) ?? now()->subDays(29)->startOfDay();
        $to = $this->date($args['to'] ?? null, true) ?? now()->endOfDay();
        if ($to->lessThan($from)) {
            throw ToolException::trans('error_bad_range');
        }
        $maxDays = Settings::int('max_range_days', 1, 3660);
        if ($from->diffInDays($to) > $maxDays) {
            throw ToolException::trans('error_range_too_long', ['days' => $maxDays]);
        }

        $orders = $this->stores->constrain(ShopOrder::query(), $storeId)->whereBetween('created_at', [$from, $to]);
        $done = (clone $orders)->where('status', ShopOrderStatus::DONE);
        $revenue = (clone $done)->selectRaw('currency, sum(total) as amount, count(*) as orders')
            ->groupBy('currency')->get()
            ->map(fn ($row) => [
                'currency' => (string) $row->currency,
                'amount' => (float) $row->amount,
                'orders' => (int) $row->orders,
                'average_order' => (int) $row->orders > 0 ? round((float) $row->amount / (int) $row->orders, 4) : 0.0,
            ])->values()->all();

        $orderTable = (new ShopOrder)->getTable();
        $top = max(1, min(20, (int) ($args['top'] ?? 5)));
        $topProducts = ShopOrderDetail::query()
            ->whereIn('order_id', (clone $done)->select($orderTable . '.id'))
            ->selectRaw('product_id, sku, max(name) as name, sum(qty) as qty')
            ->groupBy('product_id', 'sku')
            ->orderByDesc('qty')
            ->limit($top)
            ->get()
            ->map(fn ($row) => ['product_id' => (string) $row->product_id, 'sku' => (string) $row->sku, 'name' => (string) $row->name, 'qty' => (float) $row->qty])
            ->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue' => $revenue,
            'orders' => (clone $orders)->count(),
            'done_orders' => (clone $done)->count(),
            'new_orders' => (clone $orders)->where('status', ShopOrderStatus::NEW)->count(),
            'canceled_orders' => (clone $orders)->where('status', ShopOrderStatus::CANCELED)->count(),
            'top_products' => $topProducts,
            'note' => 'revenue = sum of total of Done orders, grouped by order currency',
        ];
    }

    /**
     * store_info: identity of the store and the status code tables.
     *
     * @return array<string, mixed>
     */
    public function storeInfo(string $storeId): array
    {
        $map = static fn (array $list): array => array_map(
            static fn ($id, $name) => ['id' => (int) $id, 'name' => (string) $name],
            array_keys($list),
            array_values($list)
        );

        return [
            'store_id' => $storeId,
            'name' => function_exists('gp247_store_info') ? (string) gp247_store_info('title', '', $storeId) : '',
            'base_currency' => function_exists('gp247_base_currency_code') ? (string) gp247_base_currency_code() : '',
            'timezone' => (string) config('app.timezone'),
            'locale' => function_exists('gp247_get_locale') ? (string) gp247_get_locale() : app()->getLocale(),
            'order_statuses' => $map(ShopOrderStatus::getIdAll()),
            'payment_statuses' => $map(ShopPaymentStatus::getIdAll()),
            'shipping_statuses' => $map(ShopShippingStatus::getIdAll()),
        ];
    }
}
