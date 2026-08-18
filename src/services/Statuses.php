<?php

namespace justinholtweb\shipper\services;

use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderStatus;
use craft\commerce\Plugin as Commerce;
use justinholtweb\shipper\Plugin;

/**
 * Translation between Commerce order statuses and ShipStation's.
 *
 * By default Shipper sends the Commerce status handle straight through and lets the merchant map
 * it inside ShipStation's own store settings — the same bargain WooCommerce strikes. The Pro
 * status map moves that mapping to this side for anyone who would rather keep it in Craft.
 */
class Statuses extends Component
{
    /**
     * The statuses a ShipStation store understands natively.
     */
    public const SHIPSTATION_STATUSES = [
        'awaiting_payment',
        'awaiting_shipment',
        'shipped',
        'on_hold',
        'cancelled',
    ];

    /**
     * What to put in `<OrderStatus>` for this order.
     */
    public function shipStationStatusForOrder(Order $order): string
    {
        $status = $order->getOrderStatus();
        $handle = $status?->handle ?? '';

        if ($handle === '') {
            return '';
        }

        $mapped = $this->shipStationStatusForHandle($handle);

        return $mapped ?? $handle;
    }

    /**
     * The ShipStation status a Commerce status handle is mapped onto, or null when it is not
     * mapped and should pass through unchanged.
     */
    public function shipStationStatusForHandle(string $handle): ?string
    {
        if (!Plugin::getInstance()->isPro()) {
            return null;
        }

        $map = Plugin::getInstance()->getSettings()->statusMap;

        foreach (self::SHIPSTATION_STATUSES as $key) {
            $handles = $map[$key] ?? [];

            if (is_array($handles) && in_array($handle, $handles, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The Commerce status an order should move to once everything has shipped.
     */
    public function getShippedStatus(?int $storeId = null): ?OrderStatus
    {
        return $this->statusByHandle(Plugin::getInstance()->getSettings()->shippedStatusHandle, $storeId);
    }

    /**
     * The Commerce status for an order that is only partly shipped, when one is configured.
     */
    public function getPartiallyShippedStatus(?int $storeId = null): ?OrderStatus
    {
        if (!Plugin::getInstance()->isPro()) {
            return null;
        }

        return $this->statusByHandle(Plugin::getInstance()->getSettings()->partiallyShippedStatusHandle, $storeId);
    }

    /**
     * Every Commerce order status, as `handle => name`, for settings dropdowns.
     *
     * @return array<string, string>
     */
    public function getStatusOptions(?int $storeId = null): array
    {
        $commerce = Commerce::getInstance();

        if ($commerce === null) {
            return [];
        }

        $options = [];

        foreach ($commerce->getOrderStatuses()->getAllOrderStatuses($storeId) as $status) {
            $options[$status->handle] = $status->name;
        }

        return $options;
    }

    private function statusByHandle(string $handle, ?int $storeId = null): ?OrderStatus
    {
        $handle = trim($handle);

        if ($handle === '') {
            return null;
        }

        $commerce = Commerce::getInstance();

        if ($commerce === null) {
            return null;
        }

        return $commerce->getOrderStatuses()->getOrderStatusByHandle($handle, $storeId);
    }
}
