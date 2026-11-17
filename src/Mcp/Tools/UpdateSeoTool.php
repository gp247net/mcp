<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Tools\Handlers\SeoTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool `update_seo` (write): SEO title, description, canonical and robots flags of one
 * piece of content, through gp247/front's SEO panel seam.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-seo-write
 * @aidlc-adr plugin-mcp-server_write-tool-policy
 */
#[Name('update_seo')]
#[Description('Set the SEO title, SEO description or canonical (one language) and/or noindex/nofollow (all languages) of ONE product, category, brand or page. Fields you leave out keep their value; an empty string clears a text. Changing noindex, nofollow or canonical (whether the page can appear in search results) takes two steps: call WITHOUT confirm_code to get a preview and a confirm_code, show it to the user, and only after the user agrees call again with the same arguments plus confirm_code (valid 5 minutes, single use). Changing only title/description applies at once.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateSeoTool extends Gp247Tool
{
    protected const TOOL = 'update_seo';

    /**
     * @param JsonSchema $schema
     * @return array
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['product', 'category', 'brand', 'page'])->description('Content type')->required(),
            'id' => $schema->string()->description('Content id')->required(),
            'lang' => $schema->string()->description('Language code of title/description/canonical (default: first active language)'),
            'title' => $schema->string()->max(255)->description('SEO title'),
            'description' => $schema->string()->max(500)->description('SEO description'),
            'canonical' => $schema->string()->max(500)->description('Absolute http(s) canonical URL, empty to clear'),
            'noindex' => $schema->boolean()->description('Keep the page out of search results'),
            'nofollow' => $schema->boolean()->description('Ask search engines not to follow its links'),
            'confirm_code' => $schema->string()->description('Code returned by the preview call'),
        ];
    }

    /**
     * @return array
     */
    protected function rules(): array
    {
        return [
            'type' => 'required|string|max:50',
            'id' => 'required|string|max:64',
            'lang' => 'nullable|string|max:10',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
            'canonical' => 'nullable|string|max:500',
            'noindex' => 'nullable|boolean',
            'nofollow' => 'nullable|boolean',
            'confirm_code' => 'nullable|string|max:64',
        ];
    }

    /**
     * @param array      $arguments
     * @param McpContext $context
     * @return array
     */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(SeoTools::class)->update($arguments, $context->storeId, $context->token->getKey(), $context->user);
    }
}
