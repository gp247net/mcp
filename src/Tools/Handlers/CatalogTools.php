<?php

namespace GP247\Mcp\Tools\Handlers;

use GP247\Mcp\Services\ToolException;
use GP247\Mcp\Support\Settings;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopProduct;

/**
 * Catalog read tools. Store scoping mirrors ProductManager / CategoryManager (1-1 store_id).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-catalog-read
 */
class CatalogTools extends HandlerSupport
{
    private const SORTS = ['created_at', 'price', 'stock', 'sku', 'sold'];

    /**
     * search_products: keyword (SKU, alias or name), category, status, paging.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function search(array $args, string $storeId): array
    {
        $query = $this->stores->constrain(ShopProduct::query()->with('descriptions'), $storeId);

        $keyword = trim((string) ($args['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = $this->like($keyword);
            $query->where(function ($q) use ($like) {
                $q->where('sku', 'like', $like)
                    ->orWhere('alias', 'like', $like)
                    ->orWhereHas('descriptions', fn ($d) => $d->where('name', 'like', $like));
            });
        }
        if (($args['category_id'] ?? '') !== '') {
            $category = (string) $args['category_id'];
            $query->whereHas('categories', fn ($q) => $q->where('category_id', $category));
        }
        if (isset($args['status']) && $args['status'] !== '') {
            $query->where('status', (int) $args['status']);
        }

        $sort = in_array($args['sort'] ?? '', self::SORTS, true) ? $args['sort'] : 'created_at';
        $direction = ($args['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = $query->orderBy($sort, $direction)->orderBy('id')
            ->paginate($this->perPage($args), ['*'], 'page', $this->page($args));

        return $this->paged($page, fn (ShopProduct $p) => $this->summary($p));
    }

    /**
     * get_product: one product with localized text, categories and promotion.
     *
     * @return array<string, mixed>
     */
    public function get(string $productId, string $storeId): array
    {
        $product = ShopProduct::query()->with(['descriptions', 'categories', 'promotionPrice'])->find($productId);
        if ($product === null || !$this->stores->contains($storeId, $product->store_id)) {
            throw ToolException::notFound();
        }
        $text = $this->localized($product->descriptions);
        $promotion = $product->promotionPrice;

        return $this->summary($product) + [
            'minimum' => (float) $product->minimum,
            'weight' => (float) $product->weight,
            'date_available' => $product->date_available ? (string) $product->date_available : null,
            'category_ids' => $product->categories->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'promotion' => $promotion && (int) ($promotion->status_promotion ?? 0) === 1 ? [
                'price' => (float) $promotion->price_promotion,
                'start' => $promotion->date_start ? (string) $promotion->date_start : null,
                'end' => $promotion->date_end ? (string) $promotion->date_end : null,
            ] : null,
            'untrusted_text' => [
                'description' => $this->untrusted($text->description ?? ''),
                'content' => $this->untrusted($text->content ?? ''),
            ],
        ];
    }

    /**
     * low_stock_products: stock ≤ threshold, lowest first (physical products only).
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function lowStock(array $args, string $storeId): array
    {
        $threshold = isset($args['threshold']) ? max(0, (int) $args['threshold']) : Settings::int('low_stock_threshold', 0);
        $query = $this->stores->constrain(ShopProduct::query()->with('descriptions'), $storeId)
            ->where('stock', '<=', $threshold)
            ->where('status', 1)
            ->orderBy('stock')
            ->orderBy('id');

        return ['threshold' => $threshold] + $this->paged(
            $query->paginate($this->perPage($args), ['*'], 'page', $this->page($args)),
            fn (ShopProduct $p) => $this->summary($p)
        );
    }

    /**
     * list_categories: flat list with parent ids (the client rebuilds the tree).
     *
     * @return array<string, mixed>
     */
    public function categories(string $storeId): array
    {
        $rows = $this->stores->constrain(ShopCategory::query()->with('descriptions'), $storeId)
            ->orderBy('parent')->orderBy('sort')->limit(1000)->get();

        return [
            'items' => $rows->map(fn (ShopCategory $c) => [
                'id' => (string) $c->id,
                'name' => (string) ($this->localized($c->descriptions)->name ?? $c->alias),
                'alias' => (string) $c->alias,
                'parent' => (string) $c->parent,
                'status' => (int) $c->status,
                'sort' => (int) $c->sort,
                'store_id' => (string) $c->store_id,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ShopProduct $product): array
    {
        return [
            'id' => (string) $product->id,
            'sku' => (string) $product->sku,
            'name' => (string) ($this->localized($product->descriptions)->name ?? $product->alias),
            'alias' => (string) $product->alias,
            'price' => (float) $product->price,
            'stock' => (float) $product->stock,
            'sold' => (float) $product->sold,
            'status' => (int) $product->status,
            'kind' => (int) $product->kind,
            'store_id' => (string) $product->store_id,
        ];
    }
}
