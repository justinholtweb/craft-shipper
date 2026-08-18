<?php

namespace justinholtweb\shipper\shipping;

use Craft;
use craft\commerce\base\ShippingMethod;
use craft\commerce\base\ShippingRuleInterface;
use craft\commerce\elements\Order;
use Illuminate\Support\Collection;
use justinholtweb\shipper\models\Rate;

/**
 * One Commerce shipping method per rate ShipStation quotes for the cart.
 *
 * These are not stored anywhere — they exist for the life of the request that quoted them, which
 * is why `getId()` is null and the handle is derived from the carrier and service codes.
 */
class LiveRateShippingMethod extends ShippingMethod
{
    public ?Rate $rate = null;

    public ?int $storeId = null;

    public function getType(): string
    {
        return Craft::t('shipper', 'ShipStation');
    }

    /**
     * Not a Commerce-managed method, so there is no Commerce ID to give.
     */
    public function getId(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return $this->rate?->getName() ?? Craft::t('shipper', 'Shipping');
    }

    public function getHandle(): string
    {
        return $this->rate?->getHandle() ?? 'shipper_rate';
    }

    public function getCpEditUrl(): string
    {
        return '';
    }

    public function getIsEnabled(): bool
    {
        return $this->rate !== null;
    }

    /**
     * @return Collection<ShippingRuleInterface>
     */
    public function getShippingRules(): Collection
    {
        if ($this->rate === null) {
            return collect();
        }

        return collect([new LiveRateShippingRule(['rate' => $this->rate])]);
    }

    public function getPriceForOrder(Order $order): float
    {
        return $this->rate?->getTotal() ?? 0.0;
    }

    /**
     * A live rate applies whenever it was quoted for this cart — the quote itself is the match.
     */
    public function matchOrder(Order $order): bool
    {
        return $this->rate !== null;
    }

    public function getMatchingShippingRule(Order $order): ?ShippingRuleInterface
    {
        if (!$this->matchOrder($order)) {
            return null;
        }

        return $this->getShippingRules()->first();
    }
}
