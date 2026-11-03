<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Tools\Handlers\OrderWriteTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * update_shipping_status tool (two steps: preview, then confirm) — 1.1.0.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-shipping-status-write
 * @aidlc-adr ADR-plugin-mcp-server-write-tool-policy
 */
#[Name('update_shipping_status')]
#[Description('Change the shipping status of one order (shipping status ids come from store_info), recorded in the order history. Two steps: call WITHOUT confirm_code to get a preview and a confirm_code; show the preview to the user; only after the user agrees, call again with the same order_id and shipping_status plus confirm_code (valid 5 minutes, single use). The current status answers changed=false.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateShippingStatusTool extends Gp247Tool
{
    protected const TOOL = 'update_shipping_status';

    protected const RECORD_ARGUMENT = 'order_id';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->string()->description('Order id')->required(),
            'shipping_status' => $schema->integer()->description('Target shipping status id (see store_info)')->required(),
            'note' => $schema->string()->max(500)->description('Optional note added to the order history'),
            'confirm_code' => $schema->string()->description('Code returned by the preview call'),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'order_id' => 'required|string|max:64',
            'shipping_status' => 'required|integer|min:0',
            'note' => 'nullable|string|max:500',
            'confirm_code' => 'nullable|string|max:64',
        ];
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(OrderWriteTools::class)->updateShippingStatus($arguments, $context->storeId, $context->user, $context->token->getKey());
    }
}
