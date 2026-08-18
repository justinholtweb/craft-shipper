<?php

namespace justinholtweb\shipper\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\models\LineItem;
use craft\db\Query;
use craft\elements\Address;
use craft\elements\Asset;
use craft\helpers\Db;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use justinholtweb\shipper\db\Table;
use justinholtweb\shipper\helpers\Units;
use justinholtweb\shipper\helpers\Xml;
use justinholtweb\shipper\Plugin;

/**
 * Turns Commerce orders into the ShipStation Custom Store payload.
 *
 * **The invariant:** `buildOrderElement()` is the only place an order becomes ShipStation XML.
 * The web endpoint, the console preview command and the CP "Preview XML" action all come through
 * here, so what a merchant previews is byte-identical to what ShipStation receives.
 */
class Export extends Component
{
    /**
     * Order histories for the page currently being exported, keyed by order id.
     *
     * Reading them per order is one query per order — 100 extra round trips on a default page,
     * which is exactly the shape of slowness that makes ShipStation time out on a big window.
     *
     * @var array<int, string[]>|null
     */
    private ?array $_notes = null;

    /**
     * Build a page of the export.
     *
     * @return array{xml: string, count: int, pages: int, total: int}
     */
    public function buildExport(?DateTimeInterface $start, ?DateTimeInterface $end, int $page = 1): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $pageSize = max(1, $settings->pageSize);
        $page = max(1, $page);

        $query = $this->createQuery($start, $end);
        $total = (int)$query->count();
        $pages = $total > 0 ? (int)ceil($total / $pageSize) : 1;

        $orders = (clone $query)
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        $document = Xml::document();
        $ordersElement = $document->appendChild($document->createElement('Orders'));
        $ordersElement->setAttribute('page', (string)$page);
        $ordersElement->setAttribute('pages', (string)$pages);

        $exported = [];

        $this->primeNotes(array_map(static fn(Order $order) => (int)$order->id, $orders));

        foreach ($orders as $order) {
            $element = $this->buildOrderElement($document, $order);

            if ($element === null) {
                continue;
            }

            $ordersElement->appendChild($element);
            $exported[] = (int)$order->id;
        }

        $this->markExported($exported);
        $this->_notes = null;

        return [
            'xml' => (string)$document->saveXML(),
            'count' => count($exported),
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /**
     * The order query the export runs. Exposed so the console preview and the settings screen's
     * "how many orders would this send?" read the same definition of exportable.
     */
    public function createQuery(?DateTimeInterface $start = null, ?DateTimeInterface $end = null): OrderQuery
    {
        $settings = Plugin::getInstance()->getSettings();

        $query = Order::find();

        if ($settings->exportOnlyCompleted) {
            $query->isCompleted(true);
        }

        // ShipStation asks for everything modified in a window, and re-asks for overlapping
        // windows; it dedupes on its side by OrderNumber.
        if ($start !== null && $end !== null) {
            $query->dateUpdated(['and', '>= ' . Db::prepareDateForDb($start), '<= ' . Db::prepareDateForDb($end)]);
        } elseif ($start !== null) {
            $query->dateUpdated('>= ' . Db::prepareDateForDb($start));
        } elseif ($end !== null) {
            $query->dateUpdated('<= ' . Db::prepareDateForDb($end));
        }

        if ($settings->exportStatusHandles !== []) {
            $query->orderStatus($settings->exportStatusHandles);
        }

        $query->orderBy(['commerce_orders.dateUpdated' => SORT_ASC]);

        return $query;
    }

    /**
     * Render a single order exactly as the endpoint would, wrapped in an `<Orders>` root.
     */
    public function previewOrder(Order $order): string
    {
        $document = Xml::document();
        $ordersElement = $document->appendChild($document->createElement('Orders'));
        $ordersElement->setAttribute('page', '1');
        $ordersElement->setAttribute('pages', '1');

        $element = $this->buildOrderElement($document, $order);

        if ($element !== null) {
            $ordersElement->appendChild($element);
        }

        return (string)$document->saveXML();
    }

    /**
     * Build one `<Order>` element.
     *
     * Returns null when the order has nothing shippable — ShipStation rejects an order with no
     * items, so a digital-only order is skipped rather than sent and bounced.
     */
    public function buildOrderElement(DOMDocument $document, Order $order): ?DOMElement
    {
        $settings = Plugin::getInstance()->getSettings();
        $plugin = Plugin::getInstance();

        $orderElement = $document->createElement('Order');

        Xml::cdata($orderElement, 'OrderNumber', $this->orderNumber($order));
        Xml::text($orderElement, 'OrderID', (string)$order->id);

        $orderDate = $order->datePaid ?? $order->dateOrdered ?? $order->dateCreated;
        Xml::text($orderElement, 'OrderDate', Xml::date($orderDate));
        Xml::cdata($orderElement, 'OrderStatus', $plugin->getStatuses()->shipStationStatusForOrder($order));
        Xml::text($orderElement, 'LastModified', Xml::date($order->dateUpdated ?? $orderDate));

        $gateway = null;

        try {
            $gateway = $order->getGateway();
        } catch (\Throwable) {
            // A gateway that has been removed since the order was placed must not stop the export.
        }

        Xml::cdata($orderElement, 'PaymentMethod', $gateway?->handle ?? '');
        Xml::cdata($orderElement, 'ShippingMethod', $order->shippingMethodName ?? $order->shippingMethodHandle ?? '');
        Xml::text($orderElement, 'CurrencyCode', $order->currency ?? '');
        Xml::text($orderElement, 'OrderTotal', $this->money($order->getTotalPrice()));
        Xml::text($orderElement, 'TaxAmount', $this->money($order->getTotalTax()));
        Xml::text($orderElement, 'ShippingAmount', $this->money($order->getTotalShippingCost()));
        Xml::cdata($orderElement, 'CustomerNotes', (string)($order->message ?? ''));
        Xml::cdata($orderElement, 'InternalNotes', $this->internalNotes($order));

        $this->appendCustomFields($orderElement, $order, $settings->customField1, $settings->customField2, $settings->customField3);

        $this->appendCustomer($orderElement, $order);

        $itemsElement = Xml::child($orderElement, 'Items');
        $shippableCount = 0;
        $dimensionCandidates = [];

        foreach ($order->getLineItems() as $lineItem) {
            if (!$this->itemNeedsShipping($lineItem)) {
                continue;
            }

            $this->appendItem($itemsElement, $order, $lineItem);
            $shippableCount++;

            $dimensionCandidates[] = [
                'length' => (float)$lineItem->length,
                'width' => (float)$lineItem->width,
                'height' => (float)$lineItem->height,
                'qty' => (int)$lineItem->qty,
            ];
        }

        if ($shippableCount === 0) {
            return null;
        }

        if ($settings->includeDiscountLine) {
            $this->appendDiscountLine($itemsElement, $order);
        }

        // Order-level dimensions are only meaningful for a parcel holding exactly one unit of one
        // product; anything else and ShipStation would size the box off the wrong item.
        if (count($dimensionCandidates) === 1 && $dimensionCandidates[0]['qty'] === 1) {
            $this->appendDimensions($orderElement, $dimensionCandidates[0]);
        }

        return $orderElement;
    }

    /**
     * The identifier ShipStation shows as the order number, and sends back on a shipnotify.
     */
    public function orderNumber(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();

        return match ($settings->orderNumberSource) {
            'number' => (string)$order->number,
            'shortNumber' => substr((string)$order->number, 0, 7),
            'id' => (string)$order->id,
            default => (string)($order->reference ?: $order->number),
        };
    }

    /**
     * Resolve an order from whatever ShipStation sends back as `order_number`.
     *
     * ShipStation echoes the value it was given, but merchants change `orderNumberSource` after
     * orders are already in flight, so every form is tried before giving up.
     */
    public function findOrderByNumber(string $orderNumber): ?Order
    {
        $orderNumber = trim($orderNumber);

        if ($orderNumber === '') {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();

        $attempts = match ($settings->orderNumberSource) {
            'number' => ['number', 'reference', 'shortNumber', 'id'],
            'shortNumber' => ['shortNumber', 'reference', 'number', 'id'],
            'id' => ['id', 'reference', 'number', 'shortNumber'],
            default => ['reference', 'number', 'shortNumber', 'id'],
        };

        foreach ($attempts as $attempt) {
            $order = match ($attempt) {
                'reference' => Order::find()->reference($orderNumber)->status(null)->one(),
                'number' => Order::find()->number($orderNumber)->status(null)->one(),
                'shortNumber' => strlen($orderNumber) === 7
                    ? Order::find()->shortNumber($orderNumber)->status(null)->one()
                    : null,
                'id' => ctype_digit($orderNumber)
                    ? Order::find()->id((int)$orderNumber)->status(null)->one()
                    : null,
                default => null,
            };

            if ($order instanceof Order) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Record that these orders have been handed to ShipStation.
     */
    public function markExported(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $db = Craft::$app->getDb();

        $existing = (new Query())
            ->select(['orderId'])
            ->from([Table::ORDERSTATE])
            ->where(['orderId' => $orderIds])
            ->column();
        $existing = array_map('intval', $existing);

        foreach ($orderIds as $orderId) {
            $orderId = (int)$orderId;

            if (in_array($orderId, $existing, true)) {
                $db->createCommand()->update(Table::ORDERSTATE, [
                    'dateLastExported' => $now,
                    'exportCount' => new \yii\db\Expression('[[exportCount]] + 1'),
                    'dateUpdated' => $now,
                ], ['orderId' => $orderId])->execute();

                continue;
            }

            $db->createCommand()->insert(Table::ORDERSTATE, [
                'orderId' => $orderId,
                'dateFirstExported' => $now,
                'dateLastExported' => $now,
                'exportCount' => 1,
                'shippedQty' => 0,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])->execute();
        }
    }

    // Private
    // =========================================================================

    private function appendCustomer(DOMElement $orderElement, Order $order): void
    {
        $customerElement = Xml::child($orderElement, 'Customer');

        Xml::cdata($customerElement, 'CustomerCode', (string)$order->getEmail());

        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress() ?? $billing;

        $billToElement = Xml::child($customerElement, 'BillTo');
        Xml::cdata($billToElement, 'Name', $this->addressName($billing));
        Xml::cdata($billToElement, 'Company', (string)($billing?->organization ?? ''));
        Xml::cdata($billToElement, 'Phone', $this->addressPhone($billing));
        Xml::cdata($billToElement, 'Email', (string)$order->getEmail());

        $shipToElement = Xml::child($customerElement, 'ShipTo');
        Xml::cdata($shipToElement, 'Name', $this->addressName($shipping));
        Xml::cdata($shipToElement, 'Company', (string)($shipping?->organization ?? ''));
        Xml::cdata($shipToElement, 'Address1', (string)($shipping?->addressLine1 ?? ''));
        Xml::cdata($shipToElement, 'Address2', trim(($shipping?->addressLine2 ?? '') . ' ' . ($shipping?->addressLine3 ?? '')));
        Xml::cdata($shipToElement, 'City', (string)($shipping?->locality ?? ''));
        Xml::cdata($shipToElement, 'State', (string)($shipping?->administrativeArea ?? ''));
        Xml::cdata($shipToElement, 'PostalCode', (string)($shipping?->postalCode ?? ''));
        Xml::cdata($shipToElement, 'Country', (string)($shipping?->countryCode ?? ''));
        Xml::cdata($shipToElement, 'Phone', $this->addressPhone($shipping));
    }

    private function appendItem(DOMElement $itemsElement, Order $order, LineItem $lineItem): void
    {
        $itemElement = Xml::child($itemsElement, 'Item');

        Xml::text($itemElement, 'LineItemID', (string)$lineItem->id);
        Xml::cdata($itemElement, 'SKU', $lineItem->getSku());
        Xml::cdata($itemElement, 'Name', $lineItem->getDescription());

        $imageUrl = $this->itemImageUrl($lineItem);

        if ($imageUrl !== null) {
            Xml::cdata($itemElement, 'ImageUrl', $imageUrl);
        }

        [$weightUnit, $weightLabel] = Units::xmlWeightUnit();
        $weight = Units::convertWeight((float)$lineItem->weight, Units::storeWeightUnit(), $weightUnit);

        Xml::text($itemElement, 'Weight', $this->number($weight));
        Xml::text($itemElement, 'WeightUnits', $weightLabel);
        Xml::text($itemElement, 'Quantity', (string)$lineItem->qty);

        // The pre-discount unit price: ShipStation reconciles the order total against the
        // separate discount adjustment line, so discounting here would double-count.
        $unitPrice = $lineItem->qty > 0 ? $lineItem->getSubtotal() / $lineItem->qty : 0.0;
        Xml::text($itemElement, 'UnitPrice', $this->money($unitPrice));

        $options = $lineItem->getOptions();

        if ($options !== []) {
            $optionsElement = Xml::child($itemElement, 'Options');

            foreach ($options as $name => $value) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }

                $optionElement = Xml::child($optionsElement, 'Option');
                Xml::cdata($optionElement, 'Name', (string)$name);
                Xml::cdata($optionElement, 'Value', (string)$value);
            }
        }
    }

    private function appendDiscountLine(DOMElement $itemsElement, Order $order): void
    {
        $discount = $order->getTotalDiscount();

        if ($discount == 0.0) {
            return;
        }

        $itemElement = Xml::child($itemsElement, 'Item');
        Xml::cdata($itemElement, 'SKU', 'total-discount');
        Xml::cdata($itemElement, 'Name', Craft::t('shipper', 'Total Discount'));
        Xml::text($itemElement, 'Adjustment', 'true');
        Xml::text($itemElement, 'Quantity', '1');
        // Commerce already carries discounts as negative adjustments.
        Xml::text($itemElement, 'UnitPrice', $this->money($discount));
    }

    private function appendDimensions(DOMElement $orderElement, array $dimensions): void
    {
        if (($dimensions['length'] + $dimensions['width'] + $dimensions['height']) == 0.0) {
            return;
        }

        [$unit, $label] = Units::xmlDimensionUnit();
        $storeUnit = Units::storeDimensionUnit();

        $dimensionsElement = Xml::child($orderElement, 'Dimensions');
        Xml::text($dimensionsElement, 'Length', $this->number(Units::convertLength($dimensions['length'], $storeUnit, $unit)));
        Xml::text($dimensionsElement, 'Width', $this->number(Units::convertLength($dimensions['width'], $storeUnit, $unit)));
        Xml::text($dimensionsElement, 'Height', $this->number(Units::convertLength($dimensions['height'], $storeUnit, $unit)));
        Xml::text($dimensionsElement, 'DimensionUnits', $label);
    }

    /**
     * ShipStation's three free-text fields, rendered from object templates against the order.
     * Lite gets the coupon code in CustomField1; the templates are a Pro feature.
     */
    private function appendCustomFields(DOMElement $orderElement, Order $order, string $one, string $two, string $three): void
    {
        $isPro = Plugin::getInstance()->isPro();

        $values = [
            'CustomField1' => $isPro ? $one : '{{ object.couponCode }}',
            'CustomField2' => $isPro ? $two : '',
            'CustomField3' => $isPro ? $three : '',
        ];

        foreach ($values as $name => $template) {
            if (trim($template) === '') {
                continue;
            }

            Xml::cdata($orderElement, $name, $this->renderTemplate($template, $order));
        }
    }

    /**
     * A custom field template is merchant-authored and runs on every exported order — a syntax
     * error in one must not take the whole export down.
     */
    private function renderTemplate(string $template, Order $order): string
    {
        try {
            return (string)Craft::$app->getView()->renderObjectTemplate($template, $order, [
                // renderObjectTemplate() calls its subject `object`; `order` is what everyone types.
                'order' => $order,
            ]);
        } catch (\Throwable $e) {
            Craft::warning("Shipper custom field template failed: {$e->getMessage()}", __METHOD__);

            return '';
        }
    }

    /**
     * Load the histories for a whole export page in one query.
     */
    private function primeNotes(array $orderIds): void
    {
        $this->_notes = [];

        if ($orderIds === []) {
            return;
        }

        $rows = (new Query())
            ->select(['orderId', 'message'])
            ->from([CommerceTable::ORDERHISTORIES])
            ->where(['orderId' => $orderIds])
            ->andWhere(['not', ['message' => null]])
            ->andWhere(['not', ['message' => '']])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        foreach ($rows as $row) {
            $this->_notes[(int)$row['orderId']][] = (string)$row['message'];
        }
    }

    private function internalNotes(Order $order): string
    {
        // Primed for an export page; the preview path has no page, so it reads the one order.
        if ($this->_notes !== null) {
            $notes = $this->_notes[(int)$order->id] ?? [];
        } else {
            $notes = [];

            foreach ($order->getHistories() as $history) {
                if (!empty($history->message)) {
                    $notes[] = $history->message;
                }
            }
        }

        return implode(' | ', array_slice($notes, 0, 20));
    }

    private function addressName(?Address $address): string
    {
        if ($address === null) {
            return '';
        }

        $name = trim((string)$address->fullName);

        if ($name !== '') {
            return $name;
        }

        return trim(($address->firstName ?? '') . ' ' . ($address->lastName ?? ''));
    }

    /**
     * Craft 5 moved addresses out of Commerce and dropped the phone attribute, so a phone number
     * only exists if the merchant added a custom field for it and told Shipper the handle.
     */
    private function addressPhone(?Address $address): string
    {
        if ($address === null) {
            return '';
        }

        $handle = trim(Plugin::getInstance()->getSettings()->phoneFieldHandle);

        if ($handle === '') {
            return '';
        }

        try {
            $value = $address->getFieldValue($handle);
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private function itemImageUrl(LineItem $lineItem): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->includeProductImages) {
            return null;
        }

        $purchasable = $lineItem->getPurchasable();

        if ($purchasable === null) {
            return null;
        }

        // Look for the first asset in any of the purchasable's (or its owner's) fields.
        foreach ([$purchasable, method_exists($purchasable, 'getProduct') ? $purchasable->getProduct() : null] as $source) {
            if ($source === null) {
                continue;
            }

            try {
                $fieldLayout = $source->getFieldLayout();
            } catch (\Throwable) {
                continue;
            }

            if ($fieldLayout === null) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $field) {
                if (!$field instanceof \craft\fields\Assets) {
                    continue;
                }

                try {
                    $value = $source->getFieldValue($field->handle);
                } catch (\Throwable) {
                    continue;
                }

                $asset = $value instanceof \craft\elements\db\AssetQuery ? $value->one() : null;

                if ($asset instanceof Asset) {
                    $url = $settings->imageTransform
                        ? $asset->getUrl($settings->imageTransform)
                        : $asset->getUrl();

                    if ($url) {
                        return $url;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Whether this line item is something that goes in a box. Commerce marks non-shippable
     * purchasables with `getIsShippable()`.
     */
    private function itemNeedsShipping(LineItem $lineItem): bool
    {
        $purchasable = $lineItem->getPurchasable();

        if ($purchasable === null) {
            // A purchasable that has since been deleted still shipped something.
            return true;
        }

        if (method_exists($purchasable, 'getIsShippable')) {
            return (bool)$purchasable->getIsShippable();
        }

        return true;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }
}
