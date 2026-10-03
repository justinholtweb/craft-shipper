<?php

namespace justinholtweb\shipper\models;

use craft\base\Model;

/**
 * One shipping rate quoted by ShipStation.
 */
class Rate extends Model
{
    public string $rateId = '';
    public string $carrierId = '';
    public string $carrierCode = '';
    public string $carrierName = '';
    public string $serviceCode = '';
    public string $serviceName = '';

    /**
     * Shipping amount in the quote's currency, with any configured markup already applied.
     */
    public float $amount = 0.0;

    /**
     * Surcharges ShipStation quotes alongside the base amount.
     */
    public float $otherAmount = 0.0;

    public string $currency = 'USD';
    public ?int $deliveryDays = null;
    public ?string $estimatedDeliveryDate = null;
    public bool $trackable = false;

    /**
     * The Commerce shipping method handle this rate is offered under. Handles may not contain
     * hyphens, so the carrier and service codes are normalised into underscores.
     */
    public function getHandle(): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $this->carrierCode . '_' . $this->serviceCode);
        $slug = trim((string)$slug, '_');

        return 'shipper_' . ($slug !== '' ? $slug : 'rate');
    }

    /**
     * What the customer sees at checkout.
     */
    public function getName(): string
    {
        // ShipStation's service names often carry the carrier already ("UPS® Ground"), which
        // would otherwise reach the checkout as "UPS UPS® Ground".
        $carrier = trim($this->carrierName);
        $service = trim($this->serviceName);
        $bare = static fn(string $value) => strtolower(preg_replace('/[^a-z0-9]/i', '', $value));

        if ($carrier !== '' && str_starts_with($bare($service), $bare($carrier))) {
            $carrier = '';
        }

        $name = trim($carrier . ' ' . $service);

        return $name !== '' ? $name : ($this->serviceCode ?: 'Shipping');
    }

    /**
     * Base plus surcharges — what Commerce should charge.
     */
    public function getTotal(): float
    {
        return round($this->amount + $this->otherAmount, 2);
    }
}
