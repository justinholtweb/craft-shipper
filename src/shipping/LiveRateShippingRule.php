<?php

namespace justinholtweb\shipper\shipping;

use Craft;
use craft\base\Model;
use craft\commerce\base\ShippingRuleInterface;
use craft\commerce\elements\Order;
use justinholtweb\shipper\models\Rate;

/**
 * The single rule behind a live rate: the carrier already did the arithmetic, so the whole price
 * is the base rate and every other component is zero.
 */
class LiveRateShippingRule extends Model implements ShippingRuleInterface
{
    public ?Rate $rate = null;

    public function matchOrder(Order $order): bool
    {
        return $this->rate !== null;
    }

    public function getIsEnabled(): bool
    {
        return $this->rate !== null;
    }

    /**
     * These land on the order's shipping adjustment, which is where anyone reconciling a real
     * order against a ShipStation invoice looks first.
     */
    public function getOptions(): array
    {
        return [
            'shipperRateId' => $this->rate?->rateId,
            'shipperCarrier' => $this->rate?->carrierCode,
            'shipperService' => $this->rate?->serviceCode,
            'shipperDeliveryDays' => $this->rate?->deliveryDays,
        ];
    }

    public function getPercentageRate(?int $shippingCategoryId = null): float
    {
        return 0.0;
    }

    public function getPerItemRate(?int $shippingCategoryId = null): float
    {
        return 0.0;
    }

    public function getWeightRate(?int $shippingCategoryId = null): float
    {
        return 0.0;
    }

    public function getBaseRate(): float
    {
        return $this->rate?->getTotal() ?? 0.0;
    }

    public function getMaxRate(): float
    {
        return 0.0;
    }

    public function getMinRate(): float
    {
        return 0.0;
    }

    public function getDescription(): string
    {
        $days = $this->rate?->deliveryDays;

        if ($days !== null && $days > 0) {
            return Craft::t('shipper', '{name} — about {days} business days', [
                'name' => $this->rate?->getName() ?? '',
                'days' => $days,
            ]);
        }

        return $this->rate?->getName() ?? Craft::t('shipper', 'Shipping');
    }
}
