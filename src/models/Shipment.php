<?php

namespace justinholtweb\shipper\models;

use craft\base\Model;
use craft\helpers\Json;
use DateTime;
use justinholtweb\shipper\helpers\Tracking;

/**
 * One shipment recorded against a Commerce order.
 */
class Shipment extends Model
{
    public ?int $id = null;
    public ?int $orderId = null;
    public ?int $storeId = null;

    /**
     * Identity of this shipment for retry purposes: tracking number + lower-cased carrier.
     */
    public string $shipmentKey = '';

    public ?string $carrier = null;
    public ?string $carrierCode = null;
    public ?string $service = null;
    public ?string $trackingNumber = null;
    public ?DateTime $shipDate = null;
    public ?float $cost = null;

    /**
     * `shipnotify`, `manual` or `api`.
     */
    public string $source = 'shipnotify';

    public ?string $rawPayload = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * Items in this shipment: `[['lineItemId' => int|null, 'sku' => string, 'name' => string, 'qty' => int], …]`.
     *
     * @var array<int, array{lineItemId: int|null, sku: string, name: string, qty: int}>
     */
    private array $_items = [];

    /**
     * @param mixed $items JSON string or array.
     */
    public function setItems(mixed $items): void
    {
        if (is_string($items)) {
            $items = $items !== '' ? Json::decodeIfJson($items) : [];
        }

        $this->_items = is_array($items) ? array_values($items) : [];
    }

    /**
     * @return array<int, array{lineItemId: int|null, sku: string, name: string, qty: int}>
     */
    public function getItems(): array
    {
        return $this->_items;
    }

    /**
     * Total quantity shipped in this shipment. Zero means ShipStation sent no item breakdown,
     * which the caller has to read as "the whole order".
     */
    public function getShippedQty(): int
    {
        return array_sum(array_map(static fn(array $item) => (int)($item['qty'] ?? 0), $this->_items));
    }

    /**
     * Public carrier tracking URL, when the carrier is one we know how to build a URL for.
     */
    public function getTrackingUrl(): ?string
    {
        if ($this->trackingNumber === null || $this->trackingNumber === '') {
            return null;
        }

        return Tracking::url($this->carrierCode ?: $this->carrier, $this->trackingNumber);
    }

    /**
     * A short human label: "UPS Ground" or just "UPS".
     */
    public function getLabel(): string
    {
        return trim(($this->carrier ?? '') . ' ' . ($this->service ?? '')) ?: ($this->trackingNumber ?? '');
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['orderId', 'shipmentKey'], 'required'],
            [['orderId', 'storeId'], 'integer'],
            [['cost'], 'number'],
            [['source'], 'in', 'range' => ['shipnotify', 'manual', 'api']],
        ];
    }
}
