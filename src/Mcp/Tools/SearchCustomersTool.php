<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Tools\Handlers\CustomerTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * search_customers tool.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-customer-read
 */
#[Name('search_customers')]
#[Description('Find customers by exact e-mail or phone, or by part of the name. Contact details may be masked by the site owner.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchCustomersTool extends Gp247Tool
{
    protected const TOOL = 'search_customers';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()->max(150),
            'page' => $schema->integer()->min(1),
            'per_page' => $schema->integer()->min(1)->max(50),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return ['keyword' => 'nullable|string|max:150'] + self::pagingRules();
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(CustomerTools::class)->search($arguments, $context->storeId);
    }
}
