<?php

namespace justinholtweb\shipper\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\Json;
use justinholtweb\shipper\helpers\Units;
use justinholtweb\shipper\models\Rate;
use justinholtweb\shipper\Plugin;

/**
 * Live carrier rates from ShipStation, offered as Commerce shipping methods at checkout.
 *
 * Rates are quoted per cart and memoized twice: once per request (a cart is re-costed several
 * times during a single checkout render) and once in Craft's cache, keyed on a **cart signature**
 * rather than the order number — memoizing on the number would answer a recalculated cart from a
 * stale quote.
 *
 * Every failure path returns an empty list. A carrier outage must never be able to stop a
 * customer checking out; they fall back to whatever shipping methods the store defines itself.
 */
class Rates extends Component
{
    /**
     * @var array<string, Rate[]>
     */
    private array $_memo = [];

    /**
     * @return Rate[]
     */
    public function getRatesForOrder(Order $order): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->liveRatesEnabled || !Plugin::getInstance()->isPro()) {
            return [];
        }

        if ($settings->getParsedApiKey() === '') {
            return [];
        }

        // A completed order must keep the price it was placed at; re-quoting it could change a
        // paid order's shipping cost underneath the customer.
        if ($order->isCompleted) {
            return [];
        }

        $signature = $this->signature($order);

        if (isset($this->_memo[$signature])) {
            return $this->_memo[$signature];
        }

        $cacheKey = 'shipper.rates.' . $signature;
        $cache = Craft::$app->getCache();

        $cached = $settings->rateCacheDuration > 0 ? $cache->get($cacheKey) : false;

        if (is_array($cached)) {
            $rates = array_map(static fn(array $config) => new Rate($config), $cached);

            return $this->_memo[$signature] = $rates;
        }

        $payload = $this->buildPayload($order);

        if ($payload === null) {
            return $this->_memo[$signature] = [];
        }

        $result = Plugin::getInstance()->getApi()->getRates($payload);
        $rates = $this->mapRates($result['rates']);

        if ($settings->rateCacheDuration > 0 && $rates !== []) {
            $cache->set(
                $cacheKey,
                array_map(static fn(Rate $rate) => $rate->toArray(), $rates),
                $settings->rateCacheDuration
            );
        }

        return $this->_memo[$signature] = $rates;
    }

    /**
     * Quote rates and report everything about the attempt, for the settings screen's test button
     * and the console command. Same code path as checkout, so a passing test means a working
     * checkout.
     *
     * @return array{rates: Rate[], errors: string[], payload: array|null}
     */
    public function debugQuote(Order $order): array
    {
        $payload = $this->buildPayload($order);

        if ($payload === null) {
            return [
                'rates' => [],
                'errors' => [Craft::t('shipper', 'The order has no shipping address, or no shippable items.')],
                'payload' => null,
            ];
        }

        $result = Plugin::getInstance()->getApi()->getRates($payload);

        return [
            'rates' => $this->mapRates($result['rates']),
            'errors' => $result['errors'],
            'payload' => $payload,
        ];
    }

    /**
     * The v2 rate request for this cart, or null when the cart cannot be quoted.
     */
    public function buildPayload(Order $order): ?array
    {
        $settings = Plugin::getInstance()->getSettings();
        $shipTo = $order->getShippingAddress();

        if ($shipTo === null || trim((string)$shipTo->countryCode) === '') {
            return null;
        }

        $package = $this->buildPackage($order);

        if ($package === null) {
            return null;
        }

        $shipment = [
            'ship_to' => [
                'name' => trim((string)$shipTo->fullName) ?: 'Customer',
                'address_line1' => (string)$shipTo->addressLine1,
                'address_line2' => (string)$shipTo->addressLine2,
                'city_locality' => (string)$shipTo->locality,
                'state_province' => (string)$shipTo->administrativeArea,
                'postal_code' => (string)$shipTo->postalCode,
                'country_code' => strtoupper((string)$shipTo->countryCode),
                'address_residential_indicator' => 'unknown',
            ],
            'ship_from' => $this->buildShipFrom(),
            'packages' => [$package],
        ];

        $rateOptions = [];

        if ($settings->rateCarrierIds !== []) {
            $rateOptions['carrier_ids'] = array_values($settings->rateCarrierIds);
        }

        if ($settings->rateServiceCodes !== []) {
            $rateOptions['service_codes'] = array_values($settings->rateServiceCodes);
        }

        $rateOptions['preferred_currency'] = strtolower((string)($order->currency ?: 'usd'));

        return [
            'shipment' => $shipment,
            'rate_options' => $rateOptions,
        ];
    }

    // Private
    // =========================================================================

    /**
     * One parcel holding the whole cart. Splitting a cart into real parcels is a packing problem
     * ShipStation cannot solve from a rate call either — the merchant re-packs in ShipStation
     * when the label is bought.
     */
    private function buildPackage(Order $order): ?array
    {
        $settings = Plugin::getInstance()->getSettings();
        [$weightUnit, $apiWeightUnit] = Units::apiWeightUnit();
        $storeWeightUnit = Units::storeWeightUnit();

        $weight = 0.0;
        $shippableItems = 0;

        foreach ($order->getLineItems() as $lineItem) {
            $purchasable = $lineItem->getPurchasable();

            if ($purchasable !== null && method_exists($purchasable, 'getIsShippable') && !$purchasable->getIsShippable()) {
                continue;
            }

            $shippableItems++;
            $itemWeight = (float)$lineItem->weight;

            if ($itemWeight <= 0.0) {
                $itemWeight = $settings->defaultItemWeight;
            }

            $weight += $itemWeight * (int)$lineItem->qty;
        }

        if ($shippableItems === 0) {
            return null;
        }

        $converted = Units::convertWeight($weight, $storeWeightUnit, $weightUnit);

        // Carriers reject a zero-weight parcel outright; a nominal weight gets a usable quote and
        // is corrected when the merchant weighs the real box in ShipStation.
        if ($converted <= 0.0) {
            $converted = match ($apiWeightUnit) {
                'gram' => 100.0,
                'kilogram' => 0.1,
                'ounce' => 4.0,
                default => 0.25,
            };
        }

        return [
            'package_code' => $settings->packageCode ?: 'package',
            'weight' => [
                'value' => round($converted, 3),
                'unit' => $apiWeightUnit,
            ],
        ];
    }

    private function buildShipFrom(): array
    {
        $from = Plugin::getInstance()->getSettings()->shipFrom;

        return [
            'name' => (string)($from['name'] ?? ''),
            'company_name' => (string)($from['company'] ?? ''),
            'phone' => (string)($from['phone'] ?? ''),
            'address_line1' => (string)($from['address1'] ?? ''),
            'address_line2' => (string)($from['address2'] ?? ''),
            'city_locality' => (string)($from['city'] ?? ''),
            'state_province' => (string)($from['state'] ?? ''),
            'postal_code' => (string)($from['postalCode'] ?? ''),
            'country_code' => strtoupper((string)($from['countryCode'] ?? 'US')),
        ];
    }

    /**
     * @return Rate[]
     */
    private function mapRates(array $rows): array
    {
        $rates = [];

        foreach ($rows as $row) {
            // ShipStation returns unquotable services in the same array, flagged with errors.
            if (!empty($row['error_messages'])) {
                continue;
            }

            $amount = (float)($row['shipping_amount']['amount'] ?? 0);
            $other = (float)($row['other_amount']['amount'] ?? 0);

            if ($amount <= 0.0 && $other <= 0.0) {
                continue;
            }

            $rate = new Rate([
                'rateId' => (string)($row['rate_id'] ?? ''),
                'carrierId' => (string)($row['carrier_id'] ?? ''),
                'carrierCode' => (string)($row['carrier_code'] ?? ''),
                'carrierName' => (string)($row['carrier_friendly_name'] ?? $row['carrier_nickname'] ?? ''),
                'serviceCode' => (string)($row['service_code'] ?? ''),
                'serviceName' => (string)($row['service_type'] ?? ''),
                'amount' => $this->applyMarkup($amount),
                'otherAmount' => $other,
                'currency' => strtoupper((string)($row['shipping_amount']['currency'] ?? 'USD')),
                'deliveryDays' => isset($row['delivery_days']) ? (int)$row['delivery_days'] : null,
                'estimatedDeliveryDate' => $row['estimated_delivery_date'] ?? null,
                'trackable' => (bool)($row['trackable'] ?? false),
            ]);

            $rates[$rate->getHandle()] = $rate;
        }

        // Cheapest first, which is the order customers expect to read them in.
        $rates = array_values($rates);
        usort($rates, static fn(Rate $a, Rate $b) => $a->getTotal() <=> $b->getTotal());

        return $rates;
    }

    private function applyMarkup(float $amount): float
    {
        $settings = Plugin::getInstance()->getSettings();

        return match ($settings->rateMarkupType) {
            'percent' => round($amount * (1 + ($settings->rateMarkupAmount / 100)), 2),
            'flat' => round($amount + $settings->rateMarkupAmount, 2),
            default => $amount,
        };
    }

    /**
     * Everything that can change a quote. Deliberately not the order number: the same cart
     * re-costed must hit the cache, and a changed cart must miss it.
     */
    private function signature(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $address = $order->getShippingAddress();

        $items = [];

        foreach ($order->getLineItems() as $lineItem) {
            $items[] = [$lineItem->getSku(), (int)$lineItem->qty, (float)$lineItem->weight];
        }

        return sha1(Json::encode([
            'store' => $order->storeId,
            'currency' => $order->currency,
            'country' => $address?->countryCode,
            'state' => $address?->administrativeArea,
            'postal' => $address?->postalCode,
            'city' => $address?->locality,
            'line1' => $address?->addressLine1,
            'items' => $items,
            // Everything that shapes the quote or its price. Without these a changed markup or
            // carrier filter would not apply until the old quotes expired.
            'settings' => [
                $settings->rateCarrierIds,
                $settings->rateServiceCodes,
                $settings->rateMarkupType,
                $settings->rateMarkupAmount,
                $settings->defaultItemWeight,
                $settings->packageCode,
                $settings->shipFrom,
            ],
        ]));
    }
}
