<?php
/**
 * Shipper integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-shipper/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, orders, shipments, log rows and the plugin
 * settings it overwrites are all restored in a `finally`, pass or fail.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use justinholtweb\shipper\db\Table;
use justinholtweb\shipper\helpers\Tracking;
use justinholtweb\shipper\helpers\Units;
use justinholtweb\shipper\helpers\Xml;
use justinholtweb\shipper\models\Rate;
use justinholtweb\shipper\models\Settings;
use justinholtweb\shipper\models\Shipment;
use justinholtweb\shipper\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$originalSettings = $plugin->getSettings()->toArray();
$originalEdition = Craft::$app->getPlugins()->getPluginInfo(Plugin::HANDLE)['edition'] ?? Plugin::EDITION_LITE;

/**
 * Editions are project config, so switching one has to be flushed like any other config write.
 */
function switchEdition(string $edition): void
{
    Craft::$app->getPlugins()->switchEdition(Plugin::HANDLE, $edition);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent`, but Craft passes an
// `ElementEvent` for that event — so saving *any* element fatals while it is enabled. Nothing to
// do with Shipper; detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

/**
 * Persist settings for the duration of the run. Project config writes are buffered until the
 * request ends, and a bare console script has no request end — so it has to flush them itself.
 */
function applySettings(array $values): void
{
    global $plugin;

    Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

function makeProduct(string $sku, float $price, float $weight): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Shipper fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->weight = $weight;
    $variant->length = 10;
    $variant->width = 5;
    $variant->height = 4;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function makeOrder(array $lines, bool $complete = true): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail('shipper-fixture@example.com');

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty']
        );
    }

    $order->setLineItems($lineItems);

    // Commerce insists an address element is owned by its order, so the attributes go in as an
    // array and Commerce builds the owned element itself.
    $address = [
        'fullName' => 'Dana Fixture',
        'addressLine1' => '742 Evergreen Terrace',
        'locality' => 'Charlotte',
        'administrativeArea' => 'NC',
        'postalCode' => '28202',
        'countryCode' => 'US',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

try {
    // ---------------------------------------------------------------------
    section('Units');

    check('grams convert to pounds', function() {
        return abs(Units::convertWeight(453.59237, 'g', 'lb') - 1.0) < 0.0001 ?: 'got ' . Units::convertWeight(453.59237, 'g', 'lb');
    });

    check('pounds convert to ounces', function() {
        return abs(Units::convertWeight(1, 'lb', 'oz') - 16.0) < 0.0001 ?: 'got ' . Units::convertWeight(1, 'lb', 'oz');
    });

    check('same unit is a no-op', fn() => Units::convertWeight(12.5, 'kg', 'kg') === 12.5);

    check('an unknown unit passes the value through untouched', fn() => Units::convertWeight(9.0, 'stones', 'lb') === 9.0);

    check('inches convert to centimetres', function() {
        return abs(Units::convertLength(1, 'in', 'cm') - 2.54) < 0.0001 ?: 'got ' . Units::convertLength(1, 'in', 'cm');
    });

    check('the XML weight label is one ShipStation accepts', function() {
        [, $label] = Units::xmlWeightUnit();

        return in_array($label, ['Pounds', 'Ounces', 'Grams'], true) ?: "got $label";
    });

    check('the API weight unit is one the v2 API accepts', function() {
        [, $unit] = Units::apiWeightUnit();

        return in_array($unit, ['pound', 'ounce', 'gram', 'kilogram'], true) ?: "got $unit";
    });

    check('the API dimension unit is one the v2 API accepts', function() {
        [, $unit] = Units::apiDimensionUnit();

        return in_array($unit, ['inch', 'centimeter'], true) ?: "got $unit";
    });

    // ---------------------------------------------------------------------
    section('Tracking URLs');

    check('UPS builds a tracking URL', function() {
        $url = Tracking::url('UPS', '1Z999AA10123456784');

        return $url === 'https://www.ups.com/track?loc=en_US&tracknum=1Z999AA10123456784' ?: "got " . var_export($url, true);
    });

    check('a carrier code with an underscore matches', fn() => Tracking::url('stamps_com', '9400111899223') !== null);

    check('a friendly name with a space matches', fn() => Tracking::url('DHL Express', '1234567890') !== null);

    check('a longer carrier name falls back to its prefix', fn() => Tracking::url('UPS Ground Saver', '1Z999') !== null);

    check('an unknown carrier returns null rather than a broken link', fn() => Tracking::url('Pigeon Post', '123') === null);

    check('an empty tracking number returns null', fn() => Tracking::url('UPS', '') === null);

    check('the tracking number is URL-encoded', function() {
        $url = Tracking::url('UPS', 'AB CD/1');

        return str_contains((string)$url, 'AB%20CD%2F1') ?: "got " . var_export($url, true);
    });

    // ---------------------------------------------------------------------
    section('XML helper');

    check('dates render in ShipStation’s UTC MM/dd/yyyy HH:mm format', function() {
        $date = new DateTime('2026-08-18 14:05:00', new DateTimeZone('UTC'));

        return Xml::date($date) === '08/18/2026 14:05' ?: 'got ' . Xml::date($date);
    });

    check('a local-timezone date is converted to UTC before formatting', function() {
        $date = new DateTime('2026-08-18 10:05:00', new DateTimeZone('America/New_York'));

        return Xml::date($date) === '08/18/2026 14:05' ?: 'got ' . Xml::date($date);
    });

    check('a standard date string parses', function() {
        $parsed = Xml::parseDate('08/18/2026 14:05');

        return $parsed?->format('Y-m-d H:i') === '2026-08-18 14:05' ?: 'got ' . var_export($parsed?->format('Y-m-d H:i'), true);
    });

    check('ShipStation’s compact MMDDYYYYxHHMM form parses', function() {
        $parsed = Xml::parseDate('08182026x1405');

        return $parsed?->format('Y-m-d H:i') === '2026-08-18 14:05' ?: 'got ' . var_export($parsed?->format('Y-m-d H:i'), true);
    });

    check('an empty date parses to null', fn() => Xml::parseDate('') === null);

    check('control characters are stripped so one bad note cannot break the document', function() {
        $cleaned = Xml::clean("safe\x08text");

        return $cleaned === 'safetext' ?: 'got ' . var_export($cleaned, true);
    });

    check('newlines and tabs survive cleaning', fn() => Xml::clean("a\nb\tc") === "a\nb\tc");

    // ---------------------------------------------------------------------
    section('Settings');

    check('an install with no credentials refuses to authenticate anyone', function() {
        $settings = new Settings();

        return $settings->hasCredentials() === false;
    });

    check('a username and password count as credentials', function() {
        $settings = new Settings(['username' => 'u', 'password' => 'p']);

        return $settings->hasCredentials() === true;
    });

    check('an auth key alone counts as credentials', function() {
        $settings = new Settings(['authKey' => 'abc']);

        return $settings->hasCredentials() === true;
    });

    check('a username with no password does not', function() {
        $settings = new Settings(['username' => 'u']);

        return $settings->hasCredentials() === false;
    });

    check('carrier ids accept a plain list', function() {
        $settings = new Settings();
        $settings->setRateCarrierIds(['se-1', 'se-2']);

        return $settings->getRateCarrierIds() === ['se-1', 'se-2'] ?: json_encode($settings->getRateCarrierIds());
    });

    check('carrier ids accept Craft’s editable-table row format', function() {
        $settings = new Settings();
        $settings->setRateCarrierIds(['row1' => ['value' => 'se-1'], 'row2' => ['value' => ' se-2 ']]);

        return $settings->getRateCarrierIds() === ['se-1', 'se-2'] ?: json_encode($settings->getRateCarrierIds());
    });

    check('blank and duplicate carrier ids are dropped', function() {
        $settings = new Settings();
        $settings->setRateCarrierIds([['value' => 'se-1'], ['value' => ''], ['value' => 'se-1']]);

        return $settings->getRateCarrierIds() === ['se-1'] ?: json_encode($settings->getRateCarrierIds());
    });

    check('the rate lists are real attributes, so Craft will persist them', function() {
        $attributes = (new Settings())->attributes();

        return in_array('rateCarrierIds', $attributes, true) && in_array('rateServiceCodes', $attributes, true);
    });

    check('no setting is marked required, so a fresh install can still save', function() {
        foreach ((new Settings())->rules() as $rule) {
            if (($rule[1] ?? null) === 'required') {
                return 'a required rule exists on ' . json_encode($rule[0]);
            }
        }

        return true;
    });

    // ---------------------------------------------------------------------
    section('Rate model');

    check('a service name that already carries the carrier is not prefixed twice', function() {
        $doubled = (new Rate(['carrierName' => 'UPS', 'serviceName' => 'UPS® Ground']))->getName();
        $plain = (new Rate(['carrierName' => 'USPS', 'serviceName' => 'Priority Mail']))->getName();

        return $doubled === 'UPS® Ground' && $plain === 'USPS Priority Mail' ?: "$doubled / $plain";
    });

    check('a rate handle is a legal Craft handle', function() {
        $rate = new Rate(['carrierCode' => 'stamps_com', 'serviceCode' => 'usps_priority_mail']);
        $handle = $rate->getHandle();

        return preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $handle) === 1 ?: "got $handle";
    });

    check('a rate total adds surcharges to the base amount', function() {
        $rate = new Rate(['amount' => 10.0, 'otherAmount' => 2.5]);

        return $rate->getTotal() === 12.5 ?: 'got ' . $rate->getTotal();
    });

    check('a rate name falls back to the service code', function() {
        $rate = new Rate(['serviceCode' => 'ups_ground']);

        return $rate->getName() === 'ups_ground' ?: 'got ' . $rate->getName();
    });

    // ---------------------------------------------------------------------
    section('Export');

    // The bulk of the suite exercises the full feature set; the Lite behaviour gets its own
    // section further down, which switches back and forth.
    switchEdition(Plugin::EDITION_PRO);

    applySettings([
        'username' => 'shipper-test',
        'password' => 'shipper-secret',
        'authKey' => 'shipper-key-' . $suffix,
        'exportStatusHandles' => [],
        'exportOnlyCompleted' => true,
        'orderNumberSource' => 'reference',
        'shippedStatusHandle' => '',
        'loggingEnabled' => true,
        'logPayloads' => true,
        'includeDiscountLine' => true,
        'includeProductImages' => false,
        'partialShipmentsEnabled' => true,
        'updateStatusOnShipment' => true,
    ]);

    $productA = makeProduct("SHIP-A-$suffix", 25.00, 1.5);
    $productB = makeProduct("SHIP-B-$suffix", 10.00, 0.5);
    $variantA = $productA->getDefaultVariant();
    $variantB = $productB->getDefaultVariant();

    $order = makeOrder([
        ['variant' => $variantA, 'qty' => 2],
        ['variant' => $variantB, 'qty' => 1],
    ]);

    $export = $plugin->getExport();

    check('the order is completed and has a reference', fn() => $order->isCompleted && $order->reference !== null);

    check('the export query finds the fixture order', function() use ($export, $order) {
        $ids = $export->createQuery()->ids();

        return in_array($order->id, $ids, true) ?: 'order ' . $order->id . ' not in ' . count($ids) . ' results';
    });

    check('a tight UTC window around the fixture finds it, whatever the system timezone', function() use ($export, $order) {
        // ShipStation sends UTC bounds. Handing them to the element query as bare UTC strings got
        // them re-read as system-timezone time, shifting the window by the site's offset.
        $utc = new DateTimeZone('UTC');
        $updated = DateTimeImmutable::createFromInterface($order->dateUpdated)->setTimezone($utc);
        $ids = $export->createQuery($updated->modify('-1 minute'), $updated->modify('+1 minute'))->ids();

        return in_array($order->id, $ids, true)
            ?: 'order ' . $order->id . ' not in window (system timezone ' . Craft::$app->getTimeZone() . ')';
    });

    check('the export sort breaks dateUpdated ties on id, so offset paging cannot skip orders', function() use ($export) {
        $orderBy = $export->createQuery()->orderBy;

        return array_keys($orderBy) === ['commerce_orders.dateUpdated', 'commerce_orders.id'] ?: json_encode($orderBy);
    });

    $xml = $export->previewOrder($order);

    check('the preview is well-formed XML', function() use ($xml) {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc !== false ?: 'could not parse: ' . substr($xml, 0, 200);
    });

    $doc = new SimpleXMLElement($xml);

    check('the root is <Orders> with page attributes', function() use ($doc) {
        return $doc->getName() === 'Orders'
            && (string)$doc['page'] === '1'
            && (string)$doc['pages'] === '1';
    });

    check('one <Order> is present', fn() => count($doc->Order) === 1);

    $orderXml = $doc->Order[0];

    check('OrderNumber is the order reference', function() use ($orderXml, $order) {
        return (string)$orderXml->OrderNumber === (string)$order->reference
            ?: 'got ' . (string)$orderXml->OrderNumber . ' want ' . $order->reference;
    });

    check('OrderID is the element id', fn() => (string)$orderXml->OrderID === (string)$order->id);

    check('OrderDate is in ShipStation’s format', function() use ($orderXml) {
        return preg_match('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', (string)$orderXml->OrderDate) === 1
            ?: 'got ' . (string)$orderXml->OrderDate;
    });

    check('LastModified is in ShipStation’s format', function() use ($orderXml) {
        return preg_match('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', (string)$orderXml->LastModified) === 1
            ?: 'got ' . (string)$orderXml->LastModified;
    });

    check('OrderTotal is a plain two-decimal number', function() use ($orderXml) {
        return preg_match('/^-?\d+\.\d{2}$/', (string)$orderXml->OrderTotal) === 1
            ?: 'got ' . (string)$orderXml->OrderTotal;
    });

    check('OrderTotal matches the order', function() use ($orderXml, $order) {
        return abs((float)$orderXml->OrderTotal - $order->getTotalPrice()) < 0.005
            ?: 'got ' . (string)$orderXml->OrderTotal . ' want ' . $order->getTotalPrice();
    });

    check('the customer block carries the email as CustomerCode', function() use ($orderXml) {
        return (string)$orderXml->Customer->CustomerCode === 'shipper-fixture@example.com'
            ?: 'got ' . (string)$orderXml->Customer->CustomerCode;
    });

    check('ShipTo carries the address', function() use ($orderXml) {
        $shipTo = $orderXml->Customer->ShipTo;

        return (string)$shipTo->Name === 'Dana Fixture'
            && (string)$shipTo->Address1 === '742 Evergreen Terrace'
            && (string)$shipTo->City === 'Charlotte'
            && (string)$shipTo->State === 'NC'
            && (string)$shipTo->PostalCode === '28202'
            && (string)$shipTo->Country === 'US';
    });

    check('both line items are exported', function() use ($orderXml) {
        $skus = [];

        foreach ($orderXml->Items->Item as $item) {
            $skus[] = (string)$item->SKU;
        }

        return count(array_filter($skus, fn($sku) => str_starts_with($sku, 'SHIP-'))) === 2
            ?: json_encode($skus);
    });

    check('each item carries a quantity and a weight unit ShipStation accepts', function() use ($orderXml) {
        foreach ($orderXml->Items->Item as $item) {
            if (!isset($item->WeightUnits)) {
                continue;
            }

            if (!in_array((string)$item->WeightUnits, ['Pounds', 'Ounces', 'Grams'], true)) {
                return 'got ' . (string)$item->WeightUnits;
            }

            if ((int)$item->Quantity < 1) {
                return 'quantity ' . (string)$item->Quantity;
            }
        }

        return true;
    });

    check('UnitPrice is the pre-discount unit price', function() use ($orderXml) {
        foreach ($orderXml->Items->Item as $item) {
            if ((string)$item->SKU === "SHIP-A-$GLOBALS[suffix]") {
                return abs((float)$item->UnitPrice - 25.00) < 0.005 ?: 'got ' . (string)$item->UnitPrice;
            }
        }

        return 'SHIP-A not found';
    });

    check('an order with two distinct products carries no order-level Dimensions', function() use ($orderXml) {
        return !isset($orderXml->Dimensions) ?: 'Dimensions present';
    });

    check('the order number round-trips back to the same order', function() use ($export, $order) {
        $found = $export->findOrderByNumber((string)$order->reference);

        return $found?->id === $order->id ?: 'got ' . var_export($found?->id, true);
    });

    check('an order can also be found by its long number', function() use ($export, $order) {
        $found = $export->findOrderByNumber((string)$order->number);

        return $found?->id === $order->id ?: 'got ' . var_export($found?->id, true);
    });

    check('an unknown order number returns null', fn() => $export->findOrderByNumber('does-not-exist-' . $suffix) === null);

    check('an empty order number returns null', fn() => $export->findOrderByNumber('') === null);

    check('a single-unit single-product order does carry Dimensions', function() use ($export, $variantB) {
        $single = makeOrder([['variant' => $variantB, 'qty' => 1]]);
        $singleDoc = new SimpleXMLElement($export->previewOrder($single));

        return isset($singleDoc->Order[0]->Dimensions) ?: 'no Dimensions element';
    });

    check('a full export page and a single preview agree on InternalNotes', function() use ($export, $order) {
        // The export page batch-loads order histories in one query while the preview reads them
        // off the order; both paths have to produce the same document.
        $preview = new SimpleXMLElement($export->previewOrder($order));
        // Window the page on the fixture: a shared harness holds more completed orders than fit
        // on one page, and the fixture, being the newest, sorts last.
        $since = (clone $order->dateUpdated)->modify('-1 minute');
        $page = new SimpleXMLElement($export->buildExport($since, null, 1)['xml']);

        $fromPage = null;

        foreach ($page->Order as $candidate) {
            if ((string)$candidate->OrderID === (string)$order->id) {
                $fromPage = (string)$candidate->InternalNotes;
                break;
            }
        }

        if ($fromPage === null) {
            return 'order not present in the export page';
        }

        return $fromPage === (string)$preview->Order[0]->InternalNotes
            ?: "page '{$fromPage}' vs preview '" . (string)$preview->Order[0]->InternalNotes . "'";
    });

    check('the notes map is released after a page is built, so the preview stays independent', function() use ($export, $order) {
        $export->buildExport(null, null, 1);
        $preview = new SimpleXMLElement($export->previewOrder($order));

        return isset($preview->Order[0]->InternalNotes) ?: 'InternalNotes missing after an export';
    });

    check('exporting records the order as exported', function() use ($export, $order, $plugin) {
        $export->markExported([$order->id]);
        $state = $plugin->getShipments()->getOrderState((int)$order->id);

        return $state['exportCount'] >= 1 ?: 'exportCount ' . $state['exportCount'];
    });

    check('exporting the same order again increments the count rather than duplicating a row', function() use ($export, $order, $plugin) {
        $before = $plugin->getShipments()->getOrderState((int)$order->id)['exportCount'];
        $export->markExported([$order->id]);
        $after = $plugin->getShipments()->getOrderState((int)$order->id)['exportCount'];

        return $after === $before + 1 ?: "before $before after $after";
    });

    // ---------------------------------------------------------------------
    section('Shipments');

    $shipments = $plugin->getShipments();

    check('the order has three shippable units', function() use ($shipments, $order) {
        return $shipments->getShippableQty($order) === 3 ?: 'got ' . $shipments->getShippableQty($order);
    });

    check('nothing has shipped yet', fn() => $shipments->isFullyShipped($order) === false);

    $partial = $shipments->record($order, [
        'carrier' => 'UPS',
        'service' => 'Ground',
        'trackingNumber' => "1Z-PART-$suffix",
        'shipDate' => new DateTime(),
        'items' => [
            ['lineItemId' => null, 'sku' => "SHIP-A-$suffix", 'name' => 'A', 'qty' => 2],
        ],
        'source' => 'shipnotify',
    ]);

    check('a partial shipment is recorded', fn() => $partial['shipment'] instanceof Shipment && !$partial['duplicate']);

    check('a partial shipment does not complete the order', function() use ($partial) {
        return $partial['fullyShipped'] === false ?: 'reported fully shipped';
    });

    check('the shipped quantity is counted', function() use ($shipments, $order) {
        return $shipments->getOrderState((int)$order->id)['shippedQty'] === 2
            ?: 'got ' . $shipments->getOrderState((int)$order->id)['shippedQty'];
    });

    check('a retried shipment with the same tracking number is a duplicate', function() use ($shipments, $order, $suffix) {
        $retry = $shipments->record($order, [
            'carrier' => 'UPS',
            'service' => 'Ground',
            'trackingNumber' => "1Z-PART-$suffix",
            'items' => [['lineItemId' => null, 'sku' => "SHIP-A-$suffix", 'name' => 'A', 'qty' => 2]],
        ]);

        return $retry['duplicate'] === true ?: 'not reported as a duplicate';
    });

    check('a duplicate does not re-count the items', function() use ($shipments, $order) {
        return $shipments->getOrderState((int)$order->id)['shippedQty'] === 2
            ?: 'got ' . $shipments->getOrderState((int)$order->id)['shippedQty'];
    });

    check('a retry that differs only in carrier casing is still a duplicate', function() use ($shipments, $order, $suffix) {
        $retry = $shipments->record($order, [
            'carrier' => 'ups',
            'trackingNumber' => "1Z-PART-$suffix",
            'items' => [['lineItemId' => null, 'sku' => "SHIP-A-$suffix", 'name' => 'A', 'qty' => 2]],
        ]);

        return $retry['duplicate'] === true ?: 'not reported as a duplicate';
    });

    check('a second, different shipment completes the order', function() use ($shipments, $order, $suffix) {
        $final = $shipments->record($order, [
            'carrier' => 'UPS',
            'service' => 'Ground',
            'trackingNumber' => "1Z-REST-$suffix",
            'items' => [['lineItemId' => null, 'sku' => "SHIP-B-$suffix", 'name' => 'B', 'qty' => 1]],
        ]);

        return $final['fullyShipped'] === true ?: 'not reported fully shipped';
    });

    check('the order now reads as fully shipped', fn() => $shipments->isFullyShipped($order) === true);

    check('both shipments are listed against the order', function() use ($shipments, $order) {
        return count($shipments->getShipmentsForOrder((int)$order->id)) === 2
            ?: 'got ' . count($shipments->getShipmentsForOrder((int)$order->id));
    });

    check('a shipment exposes a tracking URL for a known carrier', function() use ($shipments, $order) {
        $first = $shipments->getShipmentsForOrder((int)$order->id)[0];

        return $first->getTrackingUrl() !== null ?: 'no URL for carrier ' . var_export($first->carrier, true);
    });

    check('a shipment reports the quantity it carried', function() use ($shipments, $order) {
        $first = $shipments->getShipmentsForOrder((int)$order->id)[0];

        return $first->getShippedQty() === 2 ?: 'got ' . $first->getShippedQty();
    });

    check('a label-less shipment still dedupes an identical retry', function() use ($shipments, $variantA) {
        $noLabel = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        $payload = [
            'carrier' => 'Courier',
            'service' => 'Same day',
            'trackingNumber' => '',
            'shipDate' => new DateTime('2026-08-18'),
            'items' => [['lineItemId' => null, 'sku' => 'X', 'name' => 'X', 'qty' => 1]],
        ];

        $first = $shipments->record($noLabel, $payload);
        $second = $shipments->record($noLabel, $payload);

        return $first['duplicate'] === false && $second['duplicate'] === true
            ?: json_encode(['first' => $first['duplicate'], 'second' => $second['duplicate']]);
    });

    check('deleting a shipment gives its quantity back', function() use ($shipments, $order) {
        $before = $shipments->getOrderState((int)$order->id)['shippedQty'];
        $all = $shipments->getShipmentsForOrder((int)$order->id);
        $last = end($all);

        if (!$last instanceof Shipment) {
            return 'no shipment to delete';
        }

        $qty = $last->getShippedQty();
        $shipments->deleteShipmentById((int)$last->id);
        $after = $shipments->getOrderState((int)$order->id)['shippedQty'];

        return $after === $before - $qty ?: "before $before after $after qty $qty";
    });

    check('the shipped quantity never goes negative', function() use ($shipments, $order) {
        $all = $shipments->getShipmentsForOrder((int)$order->id);

        foreach ($all as $shipment) {
            $shipments->deleteShipmentById((int)$shipment->id);
            $shipments->deleteShipmentById((int)$shipment->id);
        }

        return $shipments->getOrderState((int)$order->id)['shippedQty'] >= 0
            ?: 'got ' . $shipments->getOrderState((int)$order->id)['shippedQty'];
    });

    // ---------------------------------------------------------------------
    section('Statuses');

    $statuses = $plugin->getStatuses();

    check('the status options list is keyed by handle', function() use ($statuses) {
        $options = $statuses->getStatusOptions();

        if ($options === []) {
            return 'no order statuses configured in Commerce';
        }

        foreach (array_keys($options) as $handle) {
            if (!is_string($handle) || $handle === '') {
                return 'bad handle ' . var_export($handle, true);
            }
        }

        return true;
    });

    check('an unmapped status passes its Commerce handle through', function() use ($statuses, $order) {
        $sent = $statuses->shipStationStatusForOrder($order);
        $handle = $order->getOrderStatus()?->handle;

        return $sent === (string)$handle ?: "sent '$sent' for handle '$handle'";
    });

    check('the five ShipStation statuses are the documented set', function() {
        return justinholtweb\shipper\services\Statuses::SHIPSTATION_STATUSES === [
            'awaiting_payment', 'awaiting_shipment', 'shipped', 'on_hold', 'cancelled',
        ];
    });

    // ---------------------------------------------------------------------
    section('Log');

    $log = $plugin->getLog();

    check('an entry can be written and read back', function() use ($log, $suffix) {
        $log->write('export', [
            'level' => 'info',
            'statusCode' => 200,
            'durationMs' => 12,
            'summary' => "check-$suffix",
        ]);

        foreach ($log->getEntries(['action' => 'export'], 50) as $entry) {
            if ($entry->summary === "check-$suffix") {
                return true;
            }
        }

        return 'entry not found';
    });

    check('entries can be filtered by level', function() use ($log, $suffix) {
        $log->write('rates', ['level' => 'error', 'summary' => "err-$suffix"]);
        $errors = $log->getEntries(['level' => 'error'], 50);

        foreach ($errors as $entry) {
            if ($entry->level !== 'error') {
                return 'got a ' . $entry->level . ' entry';
            }
        }

        return count($errors) > 0 ?: 'no error entries';
    });

    check('a payload longer than the cap is truncated, not dropped', function() use ($log, $suffix) {
        $log->write('export', [
            'summary' => "big-$suffix",
            'response' => str_repeat('x', justinholtweb\shipper\services\Log::MAX_PAYLOAD + 500),
        ]);

        $row = (new craft\db\Query())
            ->select(['response'])
            ->from([Table::LOG])
            ->where(['summary' => "big-$suffix"])
            ->one();

        if ($row === false || $row['response'] === null) {
            return 'payload not stored';
        }

        return str_contains($row['response'], '[truncated]') ?: 'not truncated (' . strlen($row['response']) . ' bytes)';
    });

    check('pruning with a zero retention keeps everything', fn() => $log->prune(0) === 0);

    check('pruning removes entries older than the window', function() use ($log) {
        Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
            'action' => 'export',
            'level' => 'info',
            'summary' => 'ancient',
            'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime('-90 days')),
            'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-90 days')),
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();

        $deleted = $log->prune(30);

        return $deleted >= 1 ?: "deleted $deleted";
    });

    // ---------------------------------------------------------------------
    section('Rates');

    $rates = $plugin->getRates();

    check('a cart with an address builds a rate payload', function() use ($rates, $variantA) {
        $cart = makeOrder([['variant' => $variantA, 'qty' => 2]], false);
        $payload = $rates->buildPayload($cart);

        if ($payload === null) {
            return 'no payload built';
        }

        return isset($payload['shipment']['ship_to']['postal_code'], $payload['shipment']['packages'][0]['weight']['value'])
            ?: json_encode($payload);
    });

    check('the parcel weight is the sum of the line items', function() use ($rates, $variantA) {
        $cart = makeOrder([['variant' => $variantA, 'qty' => 2]], false);
        $payload = $rates->buildPayload($cart);
        [$unit] = Units::apiWeightUnit();
        $expected = Units::convertWeight(3.0, Units::storeWeightUnit(), $unit);

        return abs($payload['shipment']['packages'][0]['weight']['value'] - $expected) < 0.01
            ?: 'got ' . $payload['shipment']['packages'][0]['weight']['value'] . ' want ' . $expected;
    });

    check('a cart with no shipping address cannot be quoted', function() use ($rates, $storeId) {
        $bare = new Order();
        $bare->storeId = $storeId;
        $bare->number = Commerce::getInstance()->getCarts()->generateCartNumber();

        return $rates->buildPayload($bare) === null ?: 'built a payload without an address';
    });

    check('a completed order is never re-quoted', function() use ($rates, $order) {
        return $rates->getRatesForOrder($order) === [] ?: 'quoted a completed order';
    });

    check('live rates are off unless enabled', function() use ($rates, $variantA) {
        $cart = makeOrder([['variant' => $variantA, 'qty' => 1]], false);

        return $rates->getRatesForOrder($cart) === [] ?: 'returned rates with live rates disabled';
    });

    // ---------------------------------------------------------------------
    section('Endpoint (live HTTP)');

    $client = Craft::createGuzzleClient([
        'base_uri' => 'http://localhost/',
        'timeout' => 30,
        'http_errors' => false,
    ]);

    $authKey = $plugin->getSettings()->getParsedAuthKey();

    // Rejections are logged at most once a minute; a previous run inside that minute must not
    // swallow this one's.
    Craft::$app->getCache()->delete('shipper.log.rejected');

    check('an unauthenticated request is rejected', function() use ($client) {
        $response = $client->get('actions/shipper/api/process', ['query' => ['action' => 'export']]);

        return $response->getStatusCode() === 401 ?: 'got ' . $response->getStatusCode() . ': ' . substr((string)$response->getBody(), 0, 200);
    });

    check('a wrong auth key is rejected', function() use ($client) {
        $response = $client->get('actions/shipper/api/process', [
            'query' => ['action' => 'export', 'auth_key' => 'nope'],
        ]);

        return $response->getStatusCode() === 401 ?: 'got ' . $response->getStatusCode();
    });

    check('wrong HTTP Basic credentials are rejected', function() use ($client) {
        $response = $client->get('actions/shipper/api/process', [
            'query' => ['action' => 'export'],
            'auth' => ['shipper-test', 'wrong'],
        ]);

        return $response->getStatusCode() === 401 ?: 'got ' . $response->getStatusCode();
    });

    check('an unknown action is rejected', function() use ($client, $authKey) {
        $response = $client->get('actions/shipper/api/process', [
            'query' => ['action' => 'nonsense', 'auth_key' => $authKey],
        ]);

        return $response->getStatusCode() === 400 ?: 'got ' . $response->getStatusCode();
    });

    check('a correct auth key gets the export', function() use ($client, $authKey) {
        $response = $client->get('actions/shipper/api/process', [
            'query' => [
                'action' => 'export',
                'auth_key' => $authKey,
                'start_date' => '01/01/2020 00:00',
                'end_date' => '01/01/2099 00:00',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return 'got ' . $response->getStatusCode() . ': ' . substr((string)$response->getBody(), 0, 300);
        }

        return str_contains($response->getHeaderLine('Content-Type'), 'text/xml')
            ?: 'content type ' . $response->getHeaderLine('Content-Type');
    });

    check('correct HTTP Basic credentials also get the export', function() use ($client) {
        $response = $client->get('actions/shipper/api/process', [
            'query' => [
                'action' => 'export',
                'start_date' => '01/01/2020 00:00',
                'end_date' => '01/01/2099 00:00',
            ],
            'auth' => ['shipper-test', 'shipper-secret'],
        ]);

        return $response->getStatusCode() === 200 ?: 'got ' . $response->getStatusCode();
    });

    check('the served export is well-formed XML containing the fixture order', function() use ($client, $authKey, $order) {
        // Windowed on the fixture for the same reason as the page check above.
        $since = (clone $order->dateUpdated)->setTimezone(new DateTimeZone('UTC'))->modify('-1 minute');

        $response = $client->get('actions/shipper/api/process', [
            'query' => [
                'action' => 'export',
                'auth_key' => $authKey,
                'start_date' => $since->format('m/d/Y H:i'),
                'end_date' => '01/01/2099 00:00',
                'page' => 1,
            ],
        ]);

        $body = (string)$response->getBody();
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($parsed === false) {
            return 'unparseable: ' . substr($body, 0, 300);
        }

        foreach ($parsed->Order as $candidate) {
            if ((string)$candidate->OrderID === (string)$order->id) {
                return true;
            }
        }

        return 'fixture order ' . $order->id . ' not in ' . count($parsed->Order) . ' exported orders';
    });

    check('the endpoint refuses a shipnotify for an unknown order', function() use ($client, $authKey) {
        $response = $client->post('actions/shipper/api/process', [
            'query' => [
                'action' => 'shipnotify',
                'auth_key' => $authKey,
                'order_number' => 'no-such-order-xyz',
                'carrier' => 'UPS',
                'tracking_number' => '1Z-NOPE',
            ],
            'body' => '<ShipNotice><ShipDate>08/18/2026 10:00</ShipDate></ShipNotice>',
        ]);

        return $response->getStatusCode() === 404 ?: 'got ' . $response->getStatusCode();
    });

    $httpOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);

    check('a shipnotify over HTTP records a shipment', function() use ($client, $authKey, $httpOrder, $plugin, $suffix) {
        $response = $client->post('actions/shipper/api/process', [
            'query' => [
                'action' => 'shipnotify',
                'auth_key' => $authKey,
                'order_number' => (string)$httpOrder->reference,
                'carrier' => 'FedEx',
                'service' => 'Home Delivery',
                'tracking_number' => "FX-$suffix",
            ],
            'body' => '<ShipNotice><OrderID>' . $httpOrder->id . '</OrderID><ShipDate>08/18/2026 10:00</ShipDate>'
                . '<Items><Item><SKU>SHIP-A-' . $suffix . '</SKU><Name>A</Name><Quantity>1</Quantity></Item></Items>'
                . '</ShipNotice>',
        ]);

        if ($response->getStatusCode() !== 200) {
            return 'got ' . $response->getStatusCode() . ': ' . substr((string)$response->getBody(), 0, 300);
        }

        $recorded = $plugin->getShipments()->getShipmentsForOrder((int)$httpOrder->id);

        return count($recorded) === 1 && $recorded[0]->trackingNumber === "FX-$suffix"
            ?: 'recorded ' . json_encode(array_map(fn($s) => $s->trackingNumber, $recorded));
    });

    check('the shipnotify response reports the order as fully shipped', function() use ($client, $authKey, $httpOrder, $suffix) {
        // Same shipment again — the retry path ShipStation actually exercises.
        $response = $client->post('actions/shipper/api/process', [
            'query' => [
                'action' => 'shipnotify',
                'auth_key' => $authKey,
                'order_number' => (string)$httpOrder->reference,
                'carrier' => 'FedEx',
                'tracking_number' => "FX-$suffix",
            ],
            'body' => '<ShipNotice><OrderID>' . $httpOrder->id . '</OrderID></ShipNotice>',
        ]);

        $data = json_decode((string)$response->getBody(), true);

        return ($data['duplicate'] ?? null) === true ?: 'response: ' . substr((string)$response->getBody(), 0, 200);
    });

    check('a retried shipnotify does not add a second shipment', function() use ($httpOrder, $plugin) {
        return count($plugin->getShipments()->getShipmentsForOrder((int)$httpOrder->id)) === 1
            ?: 'got ' . count($plugin->getShipments()->getShipmentsForOrder((int)$httpOrder->id));
    });

    check('a shipnotify carrying a DOCTYPE is not parsed as XML', function() use ($client, $authKey, $httpOrder, $suffix) {
        $response = $client->post('actions/shipper/api/process', [
            'query' => [
                'action' => 'shipnotify',
                'auth_key' => $authKey,
                'order_number' => (string)$httpOrder->reference,
                'carrier' => 'UPS',
                'tracking_number' => "XXE-$suffix",
            ],
            'body' => '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><ShipNotice><OrderID>'
                . $httpOrder->id . '</OrderID><Items><Item><Name>&xxe;</Name><Quantity>1</Quantity></Item></Items></ShipNotice>',
        ]);

        // The request still succeeds — it is the entity expansion that must not happen.
        if ($response->getStatusCode() !== 200) {
            return 'got ' . $response->getStatusCode();
        }

        $row = (new craft\db\Query())
            ->select(['items'])
            ->from([Table::SHIPMENTS])
            ->where(['trackingNumber' => "XXE-$suffix"])
            ->one();

        if ($row === false) {
            return 'shipment not recorded';
        }

        return !str_contains((string)$row['items'], 'root:') ?: 'entity was expanded';
    });

    check('repeated rejections inside a minute are logged once, so the public URL cannot fill the table', function() use ($client, $plugin) {
        $before = $plugin->getLog()->count();

        for ($i = 0; $i < 5; $i++) {
            $client->get('actions/shipper/api/process', ['query' => ['action' => 'export', 'auth_key' => 'nope']]);
        }

        $added = $plugin->getLog()->count() - $before;

        return $added === 0 ?: "$added rows for 5 rejections already inside the window";
    });

    check('the logged request URL masks the auth key', function() use ($plugin, $authKey) {
        foreach ($plugin->getLog()->getEntries(['action' => 'export'], 50) as $entry) {
            $full = $plugin->getLog()->getEntryById((int)$entry->id);

            if ($full !== null && str_contains((string)$full->request, 'auth_key=')) {
                if (str_contains((string)$full->request, $authKey)) {
                    return 'auth key stored in the log: ' . substr((string)$full->request, 0, 200);
                }

                return str_contains((string)$full->request, 'auth_key=***') ?: substr((string)$full->request, 0, 200);
            }
        }

        return 'no logged export request carried an auth_key';
    });

    check('the endpoint logged its requests', function() use ($plugin) {
        $entries = $plugin->getLog()->getEntries(['action' => 'shipnotify'], 20);

        return count($entries) > 0 ?: 'no shipnotify log entries';
    });

    check('a rejected request was logged as a warning', function() use ($plugin) {
        foreach ($plugin->getLog()->getEntries(['action' => 'export'], 50) as $entry) {
            if ($entry->statusCode === 401) {
                return $entry->level === 'warning' ?: 'level ' . $entry->level;
            }
        }

        return 'no 401 entry logged';
    });

    check('an item-less shipment that completes the order also completes the count', function() use ($variantA, $plugin, $suffix) {
        $bare = makeOrder([['variant' => $variantA, 'qty' => 3]]);
        $plugin->getShipments()->record($bare, [
            'carrier' => 'UPS',
            'trackingNumber' => "BARE-$suffix",
            'items' => [],
            'source' => 'manual',
        ]);

        return $plugin->getShipments()->isFullyShipped($bare)
            ?: 'order completed but shippedQty ' . $plugin->getShipments()->getOrderState((int)$bare->id)['shippedQty'] . ' of 3';
    });

    check('the rate cache key changes when the markup does', function() use ($plugin, $order) {
        $settings = $plugin->getSettings();
        $signature = new ReflectionMethod($plugin->getRates(), 'signature');
        $original = [$settings->rateMarkupType, $settings->rateMarkupAmount];

        try {
            $settings->rateMarkupType = 'percent';
            $settings->rateMarkupAmount = 10;
            $a = $signature->invoke($plugin->getRates(), $order);
            $settings->rateMarkupAmount = 25;
            $b = $signature->invoke($plugin->getRates(), $order);
        } finally {
            [$settings->rateMarkupType, $settings->rateMarkupAmount] = $original;
        }

        return $a !== $b ?: 'same signature for 10% and 25% markup';
    });

    // ---------------------------------------------------------------------
    section('Twig variable');

    $variable = new justinholtweb\shipper\twig\ShipperVariable();

    check('shipments() reads an order’s shipments', function() use ($variable, $httpOrder) {
        return count($variable->shipments($httpOrder)) >= 1;
    });

    check('shipments() accepts a bare id', function() use ($variable, $httpOrder) {
        return count($variable->shipments((int)$httpOrder->id)) >= 1;
    });

    check('shipments() never hands the raw payload, with its ship-to address, to a template', function() use ($variable, $httpOrder, $plugin) {
        $stored = $plugin->getShipments()->getShipmentsForOrder((int)$httpOrder->id);

        if (($stored[0]->rawPayload ?? null) === null) {
            return 'fixture shipment has no raw payload, so this proves nothing';
        }

        foreach ($variable->shipments($httpOrder) as $shipment) {
            if ($shipment->rawPayload !== null) {
                return 'rawPayload exposed';
            }
        }

        return true;
    });

    check('shipments() on null is empty rather than an error', fn() => $variable->shipments(null) === []);

    check('latestShipment() returns the last one', function() use ($variable, $httpOrder) {
        return $variable->latestShipment($httpOrder) instanceof Shipment;
    });

    check('progress() reports shipped, total and remaining', function() use ($variable, $httpOrder) {
        $progress = $variable->progress($httpOrder);

        return $progress['total'] === 1
            && $progress['shipped'] <= $progress['total']
            && $progress['remaining'] === $progress['total'] - $progress['shipped']
            ?: json_encode($progress);
    });

    check('trackingUrl() is exposed to templates', fn() => $variable->trackingUrl('UPS', '1Z1') !== null);

    check('knownCarriers() lists the carriers Shipper can link', fn() => count($variable->knownCarriers()) > 10);

    // ---------------------------------------------------------------------
    section('Edition gating');

    check('managing the log is a separate permission from viewing it', function() {
        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            if (isset($group['permissions']['shipper-viewLog'])) {
                return isset($group['permissions']['shipper-viewLog']['nested']['shipper-manageLog'])
                    ?: json_encode($group['permissions']['shipper-viewLog']);
            }
        }

        return 'no Shipper permissions registered';
    });

    check('a garbage-collection handler is registered to prune the log', function() {
        return yii\base\Event::hasHandlers(craft\services\Gc::class, craft\services\Gc::EVENT_RUN) ?: 'no GC handler';
    });

    check('Lite and Pro are the two editions', function() {
        return Plugin::editions() === [Plugin::EDITION_LITE, Plugin::EDITION_PRO]
            ?: json_encode(Plugin::editions());
    });

    check('the suite is running as Pro', fn() => $plugin->isPro() === true);

    check('Pro honours the configured log retention', function() use ($plugin) {
        return $plugin->getSettings()->getEffectiveLogRetentionDays() === $plugin->getSettings()->logRetentionDays;
    });

    check('the plugin can be switched to Lite', function() {
        switchEdition(Plugin::EDITION_LITE);

        return true;
    });

    check('Lite reports itself as not Pro', fn() => $plugin->isPro() === false);

    check('Lite caps log retention at a week, so the table cannot grow unbounded', function() use ($plugin) {
        return $plugin->getSettings()->getEffectiveLogRetentionDays() === 7
            ?: 'got ' . $plugin->getSettings()->getEffectiveLogRetentionDays();
    });

    check('Lite does not store request payloads', function() use ($plugin, $suffix) {
        $plugin->getLog()->write('export', [
            'summary' => "lite-payload-$suffix",
            'response' => str_repeat('y', 200),
        ]);

        $row = (new craft\db\Query())
            ->select(['response'])
            ->from([Table::LOG])
            ->where(['summary' => "lite-payload-$suffix"])
            ->one();

        return ($row['response'] ?? null) === null ?: 'payload was stored on Lite';
    });

    check('Lite completes an order on the first shipment, however partial', function() use ($plugin, $variantA) {
        $liteOrder = makeOrder([['variant' => $variantA, 'qty' => 5]]);

        $result = $plugin->getShipments()->record($liteOrder, [
            'carrier' => 'UPS',
            'trackingNumber' => 'LITE-' . uniqid(),
            'items' => [['lineItemId' => null, 'sku' => 'X', 'name' => 'X', 'qty' => 1]],
        ]);

        return $result['fullyShipped'] === true ?: 'Lite did not complete the order';
    });

    check('Lite still records the shipment and its tracking', function() use ($plugin, $variantB, $suffix) {
        $liteOrder = makeOrder([['variant' => $variantB, 'qty' => 1]]);

        $plugin->getShipments()->record($liteOrder, [
            'carrier' => 'USPS',
            'trackingNumber' => "LITE-TRACK-$suffix",
            'items' => [],
        ]);

        $recorded = $plugin->getShipments()->getShipmentsForOrder((int)$liteOrder->id);

        return count($recorded) === 1 && $recorded[0]->getTrackingUrl() !== null
            ?: 'got ' . count($recorded) . ' shipments';
    });

    check('Lite quotes no live rates even when they are switched on', function() use ($plugin, $variantA) {
        applySettings(array_merge($plugin->getSettings()->toArray(), ['liveRatesEnabled' => true, 'apiKey' => 'fake']));
        $cart = makeOrder([['variant' => $variantA, 'qty' => 1]], false);

        $rates = $plugin->getRates()->getRatesForOrder($cart);

        applySettings(array_merge($plugin->getSettings()->toArray(), ['liveRatesEnabled' => false, 'apiKey' => '']));

        return $rates === [] ?: 'Lite returned ' . count($rates) . ' rates';
    });

    check('Lite passes the Commerce status through rather than mapping it', function() use ($plugin) {
        return $plugin->getStatuses()->shipStationStatusForHandle('anything') === null;
    });

    check('switching back to Pro restores the Pro behaviour', function() use ($plugin) {
        switchEdition(Plugin::EDITION_PRO);

        return $plugin->isPro() === true;
    });
} finally {
    section('Cleanup');

    $elements = Craft::$app->getElements();

    foreach ($createdOrders as $fixtureOrder) {
        try {
            Craft::$app->getDb()->createCommand()->delete(Table::SHIPMENTS, ['orderId' => $fixtureOrder->id])->execute();
            Craft::$app->getDb()->createCommand()->delete(Table::ORDERSTATE, ['orderId' => $fixtureOrder->id])->execute();
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    try {
        Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    } catch (Throwable $e) {
        echo "  ! could not clear the log: {$e->getMessage()}\n";
    }

    try {
        applySettings($originalSettings);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    try {
        switchEdition($originalEdition);
    } catch (Throwable $e) {
        echo "  ! could not restore the plugin edition: {$e->getMessage()}\n";
    }

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
