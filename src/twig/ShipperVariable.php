<?php

namespace justinholtweb\shipper\twig;

use craft\commerce\elements\Order;
use justinholtweb\shipper\helpers\Tracking;
use justinholtweb\shipper\models\Shipment;
use justinholtweb\shipper\Plugin;
use yii\base\Behavior;

/**
 * `craft.shipper.*` — the front-end tracking API.
 *
 * Everything a customer-facing "where is my order" page needs, without a template having to know
 * that shipments live in a table.
 */
class ShipperVariable extends Behavior
{
    /**
     * Shipments recorded against an order.
     *
     * @return Shipment[]
     */
    public function shipments(Order|int|null $order): array
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        if (!$orderId) {
            return [];
        }

        return Plugin::getInstance()->getShipments()->getShipmentsForOrder((int)$orderId);
    }

    /**
     * The most recent shipment on an order, which is what a "track my parcel" link usually wants.
     */
    public function latestShipment(Order|int|null $order): ?Shipment
    {
        $shipments = $this->shipments($order);

        return $shipments === [] ? null : end($shipments);
    }

    /**
     * Whether everything on the order has shipped.
     */
    public function isShipped(Order $order): bool
    {
        return Plugin::getInstance()->getShipments()->isFullyShipped($order);
    }

    /**
     * How much of the order has shipped so far, and how much there is in total.
     *
     * @return array{shipped: int, total: int, remaining: int, complete: bool}
     */
    public function progress(Order $order): array
    {
        $shipments = Plugin::getInstance()->getShipments();
        $total = $shipments->getShippableQty($order);
        $shipped = min($total, $shipments->getOrderState((int)$order->id)['shippedQty']);

        return [
            'shipped' => $shipped,
            'total' => $total,
            'remaining' => max(0, $total - $shipped),
            'complete' => $total > 0 && $shipped >= $total,
        ];
    }

    /**
     * A public carrier tracking URL, or null when the carrier is not one Shipper knows.
     */
    public function trackingUrl(?string $carrier, ?string $trackingNumber): ?string
    {
        return Tracking::url($carrier, $trackingNumber);
    }

    /**
     * Every carrier Shipper can build a tracking URL for.
     *
     * @return string[]
     */
    public function knownCarriers(): array
    {
        return Tracking::knownCarriers();
    }
}
