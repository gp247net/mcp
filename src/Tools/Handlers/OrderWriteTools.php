<?php

namespace GP247\Mcp\Tools\Handlers;

use GP247\Mcp\Services\ConfirmationService;
use GP247\Mcp\Services\StoreContext;
use GP247\Mcp\Services\ToolException;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopShippingStatus;
use Illuminate\Support\Facades\DB;

/**
 * Order write tools of 1.1.0 — shipping status (two steps) and internal notes (one step) —
 * each through the single seam the admin screen and the REST API use
 * (ShopOrder::changeShippingStatus, ShopOrder::addNote). ADR-plugin-mcp-server-write-tool-policy.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-shipping-status-write
 * @aidlc-story US-plugin-mcp-server-order-note-write
 * @aidlc-adr ADR-plugin-mcp-server-write-tool-policy
 */
class OrderWriteTools extends HandlerSupport
{
    /** Longest note accepted by add_order_note. */
    public const MAX_NOTE = 1000;

    /**
     * @param StoreContext        $stores        Store scope (L4).
     * @param ConfirmationService $confirmations Two-step confirm codes.
     */
    public function __construct(StoreContext $stores, private readonly ConfirmationService $confirmations)
    {
        parent::__construct($stores);
    }

    /**
     * update_shipping_status: preview (no confirm_code) or apply (valid confirm_code).
     *
     * @param array<string, mixed> $args Validated: order_id, shipping_status, note?, confirm_code?
     * @return array<string, mixed>
     * @throws ToolException Unknown status, bad code, seam refusal, order out of scope.
     */
    public function updateShippingStatus(array $args, string $storeId, AdminUser $actor, int|string $tokenId): array
    {
        $order = $this->findOrder((string) $args['order_id'], $storeId);
        $statuses = ShopShippingStatus::getIdAll();
        $target = (int) $args['shipping_status'];
        if (!array_key_exists($target, $statuses)) {
            throw ToolException::trans('error_bad_shipping_status', ['status' => $target]);
        }
        $current = (int) $order->shipping_status;
        $state = static fn (int $id): array => ['id' => $id, 'name' => (string) ($statuses[$id] ?? '')];
        if ($current === $target) {
            // WHY no code: there is nothing to approve, so nothing may be written later either.
            return ['applied' => false, 'changed' => false, 'order_id' => (string) $order->id, 'shipping_status' => $state($current)];
        }
        // WHY not the note: the code binds the approved change; the note is commentary.
        $change = ['order_id' => (string) $order->id, 'shipping_status' => $target];

        if (($args['confirm_code'] ?? '') === '') {
            return [
                'applied' => false,
                'preview' => ['order_id' => (string) $order->id, 'from' => $state($current), 'to' => $state($target)],
                'confirm_code' => $this->confirmations->issue($tokenId, 'update_shipping_status', $change),
                'expires_in_seconds' => ConfirmationService::TTL_SECONDS,
            ];
        }
        if (!$this->confirmations->consume((string) $args['confirm_code'], $tokenId, 'update_shipping_status', $change)) {
            throw ToolException::trans('error_confirm_code');
        }
        $note = $this->plainText($args['note'] ?? null, 500);

        return DB::transaction(function () use ($order, $target, $note, $actor, $state): array {
            // WHY lockForUpdate: the seam does not lock; two confirmations must not both write history.
            $locked = ShopOrder::query()->lockForUpdate()->find($order->id);
            if ($locked === null) {
                throw ToolException::notFound();
            }
            $from = (int) $locked->shipping_status;
            if ($from === $target) {
                // WHY: changed meanwhile to the approved target - the seam would write nothing.
                return ['applied' => false, 'changed' => false, 'order_id' => (string) $locked->id, 'shipping_status' => $state($target)];
            }
            // WHY e(): the order history is rendered as HTML on the admin order screen.
            $content = 'MCP: change <b>shipping_status</b> from \'' . $from . '\' to \'' . $target . '\''
                . ($note !== '' ? ' — ' . e($note) : '');
            $refusal = $locked->changeShippingStatus($target, ['admin_id' => $actor->getKey(), 'content' => $content]);
            if ($refusal !== null) {
                throw new ToolException((string) gp247_language_render($refusal));
            }

            return ['applied' => true, 'order_id' => (string) $locked->id, 'shipping_status' => $state($target)];
        });
    }

    /**
     * add_order_note: append one internal note to the order history (no confirmation — it changes
     * no money, stock or status).
     *
     * @param array<string, mixed> $args Validated: order_id, note.
     * @return array<string, mixed>
     * @throws ToolException Empty note after cleaning, order out of scope.
     */
    public function addNote(array $args, string $storeId, AdminUser $actor): array
    {
        $order = $this->findOrder((string) $args['order_id'], $storeId);
        // WHY no e() here: the seam escapes the note itself.
        $note = $this->plainText((string) $args['note'], self::MAX_NOTE);
        if ($note === '') {
            throw ToolException::trans('error_note_empty');
        }
        $order->addNote($note, ['admin_id' => $actor->getKey(), 'source' => 'MCP']);

        return ['applied' => true, 'order_id' => (string) $order->id, 'note_length' => mb_strlen($note)];
    }
}
