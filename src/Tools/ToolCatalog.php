<?php

namespace GP247\Mcp\Tools;

use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopProduct;

/**
 * Single registry of the tools MCP can expose (Unit "Tool catalog v1" + 1.1.0).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 */
final class ToolCatalog
{
    /** @var array<string, ToolDefinition>|null */
    private static ?array $tools = null;

    /**
     * All tool definitions keyed by name.
     *
     * @return array<string, ToolDefinition>
     */
    public static function all(): array
    {
        if (self::$tools !== null) {
            return self::$tools;
        }

        $read = ToolDefinition::READ;
        $definitions = [
            new ToolDefinition('store_info', $read, null, true),
            new ToolDefinition('search_products', $read, 'product', true),
            new ToolDefinition('get_product', $read, 'product', true),
            new ToolDefinition('list_categories', $read, 'category', true),
            new ToolDefinition('low_stock_products', $read, 'product', true),
            new ToolDefinition('search_orders', $read, 'order', true),
            new ToolDefinition('get_order', $read, 'order', true),
            new ToolDefinition('search_customers', $read, 'customer', true),
            new ToolDefinition('get_customer', $read, 'customer', true),
            new ToolDefinition('sales_summary', $read, 'report/product', true),
            new ToolDefinition('update_order_status', ToolDefinition::WRITE, 'order/detail/{id}', false),
            // 1.1.0 (mod 20261102T211527): seams of gp247/shop 3.6.0 and gp247/core 3.7.0. Which write
            // tools need a confirm code is decided by ADR-plugin-mcp-server-write-tool-policy, in each handler.
            new ToolDefinition('recent_changes', $read, ToolDefinition::EVENTS_SCREEN, true, [
                'GP247\Core\Api\Sync\ApiEvents', 'GP247\Core\Api\Sync\EventType::readableBy', 'GP247\Core\Api\Sync\EventPayload::fromRow',
            ]),
            new ToolDefinition('update_shipping_status', ToolDefinition::WRITE, 'order/detail/{id}', false, [ShopOrder::class . '::changeShippingStatus']),
            new ToolDefinition('add_order_note', ToolDefinition::WRITE, 'order/detail/{id}', false, [ShopOrder::class . '::addNote']),
            new ToolDefinition('adjust_stock', ToolDefinition::WRITE, 'product', false, [ShopProduct::class . '::adjustStock']),
        ];

        $map = [];
        foreach ($definitions as $definition) {
            $map[$definition->name] = $definition;
        }

        return self::$tools = $map;
    }

    /**
     * Definition by name, or null for an unknown tool.
     */
    public static function find(string $name): ?ToolDefinition
    {
        return self::all()[$name] ?? null;
    }

    /**
     * Definition by name; throws for an unknown tool (programming error).
     */
    public static function get(string $name): ToolDefinition
    {
        return self::find($name) ?? throw new \InvalidArgumentException('Unknown MCP tool: ' . $name);
    }

    /**
     * @return string[] Tool names.
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }
}
