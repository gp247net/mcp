<?php

namespace GP247\Mcp\Commands;

/**
 * Install the gp247/mcp data on a site (called by gp247:install after the shop). Same steps
 * as gp247:mcp-update: MCP owns no table, so installing never drops or overwrites data and
 * the endpoint stays disabled until the site owner turns it on.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-package-install
 * @aidlc-adr ADR-plugin-mcp-server-package-distribution
 */
class McpInstall extends McpUpdate
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:mcp-install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install gp247/mcp data (settings, menu, permissions; endpoint stays off)';
}
