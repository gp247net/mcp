<?php

namespace GP247\Mcp\Tools\Handlers;

use GP247\Mcp\Services\ConfirmationService;
use GP247\Mcp\Services\StoreContext;
use GP247\Mcp\Services\ToolException;
use GP247\Mcp\Support\Settings;
use GP247\Core\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Models\ShopOrderTransaction;
use GP247\Shop\Models\ShopPaymentStatus;
use GP247\Shop\Models\ShopShippingStatus;

/**
 * Order tools. Reads mirror OrderManager::baseQuery(); the only write goes through
 * ShopOrder::changeStatus() — the single order-status seam (stock, history, events, hooks).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-order-read
 * @aidlc-story US-plugin-mcp-server-order-status-write
 */
class OrderTools extends HandlerSupport
{
    private const SORTS = ['created_at', 'total', 'id', 'status'];

    public function __construct(StoreContext $stores, private readonly ConfirmationService $confirmations)
    {
        parent::__construct($stores);
    }

    /**
     * search_orders.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function search(array $args, string $storeId): array
    {
        $query = $this->stores->constrain(ShopOrder::query(), $storeId);
        foreach (['status', 'payment_status', 'shipping_status'] as $column) {
            if (isset($args[$column]) && $args[$column] !== '') {
                $query->where($column, (int) $args[$column]);
            }
        }
        if ($from = $this->date($args['from'] ?? null)) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $this->date($args['to'] ?? null, true)) {
            $query->where('created_at', '<=', $to);
        }
        $keyword = trim((string) ($args['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = $this->like($keyword);
            $revealPii = Settings::allowPii();
            $query->where(function ($q) use ($like, $keyword, $revealPii) {
                $q->where('id', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like);
                // WHY exact unless contact details are revealed: a substring match would let an agent
                // rebuild a masked e-mail one character at a time.
                $revealPii ? $q->orWhere('email', 'like', $like) : $q->orWhere('email', $keyword);
            });
        }
        $sort = in_array($args['sort'] ?? '', self::SORTS, true) ? $args['sort'] : 'created_at';
        $direction = ($args['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = $query->orderBy($sort, $direction)->orderBy('id')
            ->paginate($this->perPage($args), ['*'], 'page', $this->page($args));

        return $this->paged($page, fn (ShopOrder $o) => $this->summary($o));
    }

    /**
     * get_order: lines, totals, history, payments.
     *
     * @return array<string, mixed>
     */
    public function get(string $orderId, string $storeId): array
    {
        $order = $this->findOrder($orderId, $storeId);
        $order->loadMissing(['details', 'history']);
        $masker = $this->masker();

        return $this->summary($order) + [
            'locked' => $order->isLocked(),
            'customer' => [
                'id' => (string) $order->customer_id,
                'name' => trim($order->first_name . ' ' . $order->last_name),
                'email' => $masker->email($order->email),
                'phone' => $masker->phone($order->phone),
                'address1' => $masker->address($order->address1),
                'address2' => $masker->address($order->address2),
                'address3' => $masker->address($order->address3),
                'postcode' => $masker->address($order->postcode),
                'city' => (string) $order->city,
                'district' => (string) $order->district,
                'country' => (string) $order->country,
            ],
            'payment_method' => (string) $order->payment_method,
            'shipping_method' => (string) $order->shipping_method,
            'lines' => $order->details->map(fn ($line) => [
                'product_id' => (string) $line->product_id,
                'sku' => (string) $line->sku,
                'name' => (string) $line->name,
                'attribute' => $this->untrusted(is_string($line->attribute) ? $line->attribute : json_encode($line->attribute), 500),
                'qty' => (float) $line->qty,
                'price' => (float) $line->price,
                'total_price' => (float) $line->total_price,
                'tax' => (float) $line->tax,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'tax' => (float) $order->tax,
                'shipping' => (float) $order->shipping,
                'discount' => (float) $order->discount,
                'other_fee' => (float) $order->other_fee,
                'total' => (float) $order->total,
                'received' => (float) $order->received,
                'balance' => (float) $order->balance,
            ],
            'history' => $order->history->sortBy('add_date')->map(fn ($h) => [
                'date' => (string) $h->add_date,
                'status' => (int) $h->order_status_id,
                'by' => $h->admin_id && (string) $h->admin_id !== '0' ? 'admin' : ($h->customer_id && (string) $h->customer_id !== '0' ? 'customer' : 'system'),
                'note' => $this->untrusted($h->content, 500),
            ])->values()->all(),
            'payments' => $this->payments($order),
            'untrusted_text' => ['comment' => $this->untrusted($order->comment)],
        ];
    }

    /**
     * update_order_status: preview (no confirm_code) or apply (valid confirm_code).
     *
     * @param array<string, mixed> $args Validated: order_id, status, note?, confirm_code?
     * @return array<string, mixed>
     */
    public function updateStatus(array $args, string $storeId, AdminUser $actor, int|string $tokenId): array
    {
        $order = $this->findOrder((string) $args['order_id'], $storeId);
        $statuses = ShopOrderStatus::getIdAll();
        $target = (int) $args['status'];
        if (!array_key_exists($target, $statuses)) {
            throw ToolException::trans('error_bad_status', ['status' => $target]);
        }
        $note = mb_substr(trim((string) ($args['note'] ?? '')), 0, 500);
        // WHY not the note: the code binds the approved change (token + order + target status);
        // the history note is commentary and may be completed on the confirm call.
        $change = ['order_id' => (string) $order->id, 'status' => $target];
        $current = (int) $order->status;

        if (($args['confirm_code'] ?? '') === '') {
            return [
                'applied' => false,
                'preview' => [
                    'order_id' => (string) $order->id,
                    'from' => ['id' => $current, 'name' => (string) ($statuses[$current] ?? '')],
                    'to' => ['id' => $target, 'name' => (string) $statuses[$target]],
                    'locked' => $order->isLocked(),
                    'restock' => $current !== ShopOrderStatus::CANCELED && $target === ShopOrderStatus::CANCELED,
                    'takes_stock_back' => $current === ShopOrderStatus::CANCELED && $target !== ShopOrderStatus::CANCELED,
                ],
                'confirm_code' => $this->confirmations->issue($tokenId, 'update_order_status', $change),
                'expires_in_seconds' => ConfirmationService::TTL_SECONDS,
            ];
        }

        if (!$this->confirmations->consume((string) $args['confirm_code'], $tokenId, 'update_order_status', $change)) {
            throw ToolException::trans('error_confirm_code');
        }

        return DB::transaction(function () use ($order, $target, $note, $actor, $statuses): array {
            // WHY lockForUpdate: serialises concurrent confirmations; the second one sees the new
            // status and changeStatus() becomes a no-op instead of moving stock twice.
            $locked = ShopOrder::query()->lockForUpdate()->find($order->id);
            if ($locked === null) {
                throw ToolException::notFound();
            }
            $from = (int) $locked->status;
            $content = 'MCP: change <b>status</b> from \'' . $from . '\' to \'' . $target . '\''
                . ($note !== '' ? ' — ' . e($note) : '');
            $refusal = $locked->changeStatus($target, ['admin_id' => $actor->getKey(), 'content' => $content]);
            if ($refusal !== null) {
                throw new ToolException((string) gp247_language_render($refusal));
            }

            return ['applied' => true, 'order_id' => (string) $locked->id, 'status' => ['id' => $target, 'name' => (string) $statuses[$target]]];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ShopOrder $order): array
    {
        $statuses = ShopOrderStatus::getIdAll();
        $payments = ShopPaymentStatus::getIdAll();
        $shippings = ShopShippingStatus::getIdAll();

        return [
            'id' => (string) $order->id,
            'created_at' => (string) $order->created_at,
            'status' => ['id' => (int) $order->status, 'name' => (string) ($statuses[(int) $order->status] ?? '')],
            'payment_status' => ['id' => (int) $order->payment_status, 'name' => (string) ($payments[(int) $order->payment_status] ?? '')],
            'shipping_status' => ['id' => (int) $order->shipping_status, 'name' => (string) ($shippings[(int) $order->shipping_status] ?? '')],
            'customer_name' => trim($order->first_name . ' ' . $order->last_name),
            'email' => $this->masker()->email($order->email),
            'total' => (float) $order->total,
            'currency' => (string) $order->currency,
            'store_id' => (string) $order->store_id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payments(ShopOrder $order): array
    {
        if (!class_exists(ShopOrderTransaction::class)) {
            return [];
        }

        return ShopOrderTransaction::query()->where('order_id', $order->id)->orderBy('paid_at')->limit(200)->get()
            ->map(fn ($t) => [
                'type' => (string) $t->type,
                'amount' => (float) $t->amount,
                'currency' => (string) $t->currency,
                'method' => (string) $t->method,
                'paid_at' => (string) $t->paid_at,
            ])->all();
    }
}
