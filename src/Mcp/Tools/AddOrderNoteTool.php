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
 * add_order_note tool (one step: a note changes no money, stock or status) — 1.1.0.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-order-note-write
 * @aidlc-adr ADR-plugin-mcp-server-write-tool-policy
 */
#[Name('add_order_note')]
#[Description('Add one internal note to the history of an order (shown to staff on the order screen, labelled [MCP]). Plain text, up to 1000 characters. Does not change status, payment or stock. Do not copy customer personal data into notes unless the user asks.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
class AddOrderNoteTool extends Gp247Tool
{
    protected const TOOL = 'add_order_note';

    protected const RECORD_ARGUMENT = 'order_id';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->string()->description('Order id')->required(),
            'note' => $schema->string()->max(OrderWriteTools::MAX_NOTE)->description('Note text (plain text)')->required(),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'order_id' => 'required|string|max:64',
            'note' => 'required|string|max:' . OrderWriteTools::MAX_NOTE,
        ];
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(OrderWriteTools::class)->addNote($arguments, $context->storeId, $context->user);
    }
}
