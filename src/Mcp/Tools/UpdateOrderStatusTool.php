<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Tools\Handlers\OrderTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * update_order_status tool (two steps: preview, then confirm).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-order-status-write
 * @aidlc-adr ADR-plugin-mcp-server-token-auth
 */
#[Name('update_order_status')]
#[Description('Change the status of one order with the same rules as the admin screen (restock on cancel, history). Two steps: call WITHOUT confirm_code to get a preview and a confirm_code; show the preview to the user; only after the user agrees, call again with the same arguments plus confirm_code (valid 5 minutes, single use).')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent(false)]
class UpdateOrderStatusTool extends Gp247Tool
{
    protected const TOOL = 'update_order_status';

    protected const RECORD_ARGUMENT = 'order_id';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->string()->description('Order id')->required(),
            'status' => $schema->integer()->description('Target order status id (see store_info)')->required(),
            'note' => $schema->string()->max(500)->description('Optional note added to the order history'),
            'confirm_code' => $schema->string()->description('Code returned by the preview call'),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'order_id' => 'required|string|max:64',
            'status' => 'required|integer|min:1',
            'note' => 'nullable|string|max:500',
            'confirm_code' => 'nullable|string|max:64',
        ];
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(OrderTools::class)->updateStatus($arguments, $context->storeId, $context->user, $context->token->getKey());
    }
}
