<?php

namespace GP247\Mcp\Tools\Handlers;

use GP247\Mcp\Services\ToolException;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderStatus;

/**
 * Customer read tools. Scoping mirrors CustomerManager::baseQuery(); contact details go
 * through the shared PII masker; the password hash and remember token are never selected.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-customer-read
 */
class CustomerTools extends HandlerSupport
{
    private const COLUMNS = ['id', 'first_name', 'last_name', 'email', 'phone', 'address1', 'address2', 'address3',
        'postcode', 'city', 'district', 'country', 'company', 'status', 'store_id', 'created_at'];

    /**
     * search_customers: exact e-mail / phone match or name contains.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function search(array $args, string $storeId): array
    {
        $query = $this->stores->constrain(ShopCustomer::query()->select(self::COLUMNS), $storeId);
        $keyword = trim((string) ($args['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = $this->like($keyword);
            // WHY exact match on e-mail/phone: lets an agent look a customer up without the
            // tool ever returning partial matches of other people's contact details.
            $query->where(fn ($q) => $q->where('email', $keyword)->orWhere('phone', $keyword)
                ->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }
        $page = $query->orderByDesc('created_at')->orderBy('id')
            ->paginate($this->perPage($args), ['*'], 'page', $this->page($args));

        return $this->paged($page, fn (ShopCustomer $c) => $this->summary($c));
    }

    /**
     * get_customer: profile plus order statistics.
     *
     * @return array<string, mixed>
     */
    public function get(string $customerId, string $storeId): array
    {
        $customer = ShopCustomer::query()->select(self::COLUMNS)->find($customerId);
        if ($customer === null || !$this->stores->contains($storeId, $customer->store_id)) {
            throw ToolException::notFound();
        }
        $masker = $this->masker();
        $orders = $this->stores->constrain(ShopOrder::query(), $storeId)->where('customer_id', $customer->id);
        $spent = (clone $orders)->where('status', ShopOrderStatus::DONE)
            ->selectRaw('currency, sum(total) as amount')->groupBy('currency')->get()
            ->map(fn ($row) => ['currency' => (string) $row->currency, 'amount' => (float) $row->amount])->all();

        return $this->summary($customer) + [
            'phone' => $masker->phone($customer->phone),
            'address1' => $masker->address($customer->address1),
            'address2' => $masker->address($customer->address2),
            'address3' => $masker->address($customer->address3),
            'postcode' => $masker->address($customer->postcode),
            'city' => (string) $customer->city,
            'district' => (string) $customer->district,
            'country' => (string) $customer->country,
            'company' => $this->untrusted($customer->company, 200),
            'order_count' => (clone $orders)->count(),
            'spent_done_orders' => $spent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ShopCustomer $customer): array
    {
        return [
            'id' => (string) $customer->id,
            'name' => trim($customer->first_name . ' ' . $customer->last_name),
            'email' => $this->masker()->email($customer->email),
            'status' => (int) $customer->status,
            'store_id' => (string) $customer->store_id,
            'created_at' => (string) $customer->created_at,
        ];
    }
}
