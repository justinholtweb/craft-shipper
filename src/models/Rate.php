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
     * Shipping amount in the quote's currency, before any configured markup.
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
        $name = trim($this->carrierName . ' ' . $this->serviceName);

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
