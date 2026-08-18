<?php

namespace justinholtweb\shipper\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderHistory;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeInterface;
use justinholtweb\shipper\db\Table;
use justinholtweb\shipper\models\Shipment;
use justinholtweb\shipper\Plugin;
use justinholtweb\shipper\records\ShipmentRecord;

/**
 * Shipments recorded against Commerce orders.
 *
 * **The invariant:** `record()` is the only place a shipment row is created. shipnotify, the CP's
 * manual add and any API sync all land here, so idempotency, item counting and the fully-shipped
 * decision are made once and cannot disagree.
 */
class Shipments extends Component
{
    /**
     * Record a shipment against an order.
     *
     * @param array{
     *     carrier?: string|null,
     *     carrierCode?: string|null,
     *     service?: string|null,
     *     trackingNumber?: string|null,
     *     shipDate?: DateTimeInterface|null,
     *     cost?: float|null,
     *     items?: array,
     *     source?: string,
     *     rawPayload?: string|null,
     * } $data
     * @return array{shipment: Shipment|null, duplicate: bool, fullyShipped: bool, statusChanged: bool}
     */
    public function record(Order $order, array $data): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $carrier = trim((string)($data['carrier'] ?? ''));
        $trackingNumber = trim((string)($data['trackingNumber'] ?? ''));
        $items = array_values($data['items'] ?? []);

        $shipmentKey = $this->buildShipmentKey($trackingNumber, $carrier, $data);

        $existing = ShipmentRecord::findOne([
            'orderId' => $order->id,
            'shipmentKey' => $shipmentKey,
        ]);

        if ($existing !== null) {
            // ShipStation retries hourly and carries no notification id. Counting these items a
            // second time would complete an order that is only partly shipped.
            return [
                'shipment' => $this->recordToModel($existing),
                'duplicate' => true,
                'fullyShipped' => $this->isFullyShipped($order),
                'statusChanged' => false,
            ];
        }

        $shipDate = $data['shipDate'] ?? new DateTime();

        $record = new ShipmentRecord();
        $record->orderId = (int)$order->id;
        $record->storeId = $order->storeId;
        $record->shipmentKey = $shipmentKey;
        $record->carrier = $carrier !== '' ? $carrier : null;
        $record->carrierCode = ($data['carrierCode'] ?? null) ?: null;
        $record->service = ($data['service'] ?? null) ?: null;
        $record->trackingNumber = $trackingNumber !== '' ? $trackingNumber : null;
        $record->shipDate = Db::prepareDateForDb($shipDate);
        $record->cost = isset($data['cost']) ? (string)$data['cost'] : null;
        $record->items = Json::encode($items);
        $record->source = $data['source'] ?? 'shipnotify';
        $record->rawPayload = $settings->logPayloads ? ($data['rawPayload'] ?? null) : null;

        if (!$record->save()) {
            Craft::error('Shipper could not save a shipment: ' . Json::encode($record->getErrors()), __METHOD__);

            return [
                'shipment' => null,
                'duplicate' => false,
                'fullyShipped' => false,
                'statusChanged' => false,
            ];
        }

        $shipment = $this->recordToModel($record);
        $shippedQty = $shipment->getShippedQty();

        // The row (and with it the idempotency key) is committed before the order is saved. The
        // save fires status emails and every third-party handler on them; if one of those fatals,
        // the retry must not land as a second shipment.
        $this->bumpOrderState($order->id, $shippedQty);

        $fullyShipped = $this->decideFullyShipped($order, $items);
        $statusChanged = $this->applyStatus($order, $shipment, $fullyShipped);

        return [
            'shipment' => $shipment,
            'duplicate' => false,
            'fullyShipped' => $fullyShipped,
            'statusChanged' => $statusChanged,
        ];
    }

    /**
     * @return Shipment[]
     */
    public function getShipmentsForOrder(int $orderId): array
    {
        $rows = $this->createQuery()
            ->where(['orderId' => $orderId])
            ->orderBy(['shipDate' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => new Shipment($row), $rows);
    }

    public function getShipmentById(int $id): ?Shipment
    {
        $row = $this->createQuery()->where(['id' => $id])->one();

        return $row ? new Shipment($row) : null;
    }

    /**
     * Recent shipments across all orders, for the CP index.
     *
     * @return Shipment[]
     */
    public function getRecentShipments(int $limit = 100, ?string $search = null): array
    {
        $query = $this->createQuery()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $query->andWhere([
                'or',
                ['like', 'trackingNumber', $term, false],
                ['like', 'carrier', $term, false],
                ['like', 'service', $term, false],
            ]);
        }

        return array_map(fn(array $row) => new Shipment($row), $query->all());
    }

    public function deleteShipmentById(int $id): bool
    {
        $record = ShipmentRecord::findOne(['id' => $id]);

        if ($record === null) {
            return false;
        }

        $orderId = (int)$record->orderId;
        $qty = 0;

        $items = $record->items ? Json::decodeIfJson($record->items) : [];

        if (is_array($items)) {
            foreach ($items as $item) {
                $qty += (int)($item['qty'] ?? 0);
            }
        }

        if (!$record->delete()) {
            return false;
        }

        $this->bumpOrderState($orderId, -$qty);

        return true;
    }

    /**
     * Per-order sync state: when it was exported, how much has shipped.
     *
     * @return array{dateFirstExported: ?string, dateLastExported: ?string, exportCount: int, shippedQty: int, dateShipped: ?string}
     */
    public function getOrderState(int $orderId): array
    {
        $row = (new Query())
            ->select(['dateFirstExported', 'dateLastExported', 'exportCount', 'shippedQty', 'dateShipped'])
            ->from([Table::ORDERSTATE])
            ->where(['orderId' => $orderId])
            ->one();

        return [
            'dateFirstExported' => $row['dateFirstExported'] ?? null,
            'dateLastExported' => $row['dateLastExported'] ?? null,
            'exportCount' => (int)($row['exportCount'] ?? 0),
            'shippedQty' => (int)($row['shippedQty'] ?? 0),
            'dateShipped' => $row['dateShipped'] ?? null,
        ];
    }

    /**
     * Total quantity on the order that ShipStation is expected to ship.
     */
    public function getShippableQty(Order $order): int
    {
        $qty = 0;

        foreach ($order->getLineItems() as $lineItem) {
            $purchasable = $lineItem->getPurchasable();

            if ($purchasable !== null && method_exists($purchasable, 'getIsShippable') && !$purchasable->getIsShippable()) {
                continue;
            }

            $qty += (int)$lineItem->qty;
        }

        return $qty;
    }

    public function isFullyShipped(Order $order): bool
    {
        $shippable = $this->getShippableQty($order);

        if ($shippable === 0) {
            return true;
        }

        return $this->getOrderState((int)$order->id)['shippedQty'] >= $shippable;
    }

    // Private
    // =========================================================================

    private function createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'orderId', 'storeId', 'shipmentKey', 'carrier', 'carrierCode', 'service',
                'trackingNumber', 'shipDate', 'cost', 'items', 'source', 'rawPayload',
                'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::SHIPMENTS]);
    }

    private function recordToModel(ShipmentRecord $record): Shipment
    {
        // craft\base\Model::__construct() typecasts and converts datetime attributes itself, so
        // the raw row goes in untouched.
        return new Shipment($record->toArray([
            'id', 'orderId', 'storeId', 'shipmentKey', 'carrier', 'carrierCode', 'service',
            'trackingNumber', 'shipDate', 'cost', 'items', 'source', 'rawPayload',
            'dateCreated', 'dateUpdated', 'uid',
        ]));
    }

    /**
     * The identity of a shipment for retry purposes.
     *
     * ShipStation sends no notification id, so a tracking number plus the lower-cased carrier is
     * the key — a retry that differs only in casing ("UPS" then "ups") still matches. Label-less
     * shipments fall back to a hash of the shipment's contents, which still collapses an
     * identical retry instead of leaving dedup off entirely.
     */
    private function buildShipmentKey(string $trackingNumber, string $carrier, array $data): string
    {
        if ($trackingNumber !== '') {
            return substr($trackingNumber . '|' . strtolower($carrier), 0, 255);
        }

        $shipDate = $data['shipDate'] ?? null;

        return 'nolabel|' . sha1(implode('|', [
            strtolower($carrier),
            strtolower((string)($data['service'] ?? '')),
            $shipDate instanceof DateTimeInterface ? $shipDate->format('Y-m-d') : '',
            Json::encode(array_values($data['items'] ?? [])),
        ]));
    }

    /**
     * Whether this shipment completes the order.
     *
     * ShipStation may send no item breakdown at all, which the Custom Store contract means as
     * "the whole order shipped" — that is also what happens when partial shipments are off.
     */
    private function decideFullyShipped(Order $order, array $items): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($items === []) {
            return true;
        }

        if (!$settings->partialShipmentsEnabled || !Plugin::getInstance()->isPro()) {
            return true;
        }

        return $this->isFullyShipped($order);
    }

    private function bumpOrderState(int $orderId, int $qtyDelta): void
    {
        $now = Db::prepareDateForDb(new DateTime());
        $db = Craft::$app->getDb();

        $exists = (new Query())
            ->from([Table::ORDERSTATE])
            ->where(['orderId' => $orderId])
            ->exists();

        if (!$exists) {
            $db->createCommand()->insert(Table::ORDERSTATE, [
                'orderId' => $orderId,
                'exportCount' => 0,
                'shippedQty' => max(0, $qtyDelta),
                'dateShipped' => $qtyDelta > 0 ? $now : null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            return;
        }

        $db->createCommand()->update(Table::ORDERSTATE, [
            // GREATEST keeps the counter from going negative when a shipment is deleted twice.
            'shippedQty' => new \yii\db\Expression('GREATEST(0, [[shippedQty]] + :delta)', [':delta' => $qtyDelta]),
            'dateShipped' => $qtyDelta > 0 ? $now : new \yii\db\Expression('[[dateShipped]]'),
            'dateUpdated' => $now,
        ], ['orderId' => $orderId])->execute();
    }

    /**
     * Move the order to its shipped (or partly-shipped) status and leave a note.
     */
    private function applyStatus(Order $order, Shipment $shipment, bool $fullyShipped): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $statuses = Plugin::getInstance()->getStatuses();

        $note = $this->buildNote($shipment, $fullyShipped);

        if (!$settings->updateStatusOnShipment) {
            $this->addNoteOnly($order, $note);

            return false;
        }

        $target = $fullyShipped
            ? $statuses->getShippedStatus($order->storeId)
            : $statuses->getPartiallyShippedStatus($order->storeId);

        if ($target === null || (int)$order->orderStatusId === (int)$target->id) {
            $this->addNoteOnly($order, $note);

            return false;
        }

        $order->orderStatusId = (int)$target->id;
        // Commerce turns this into the order history entry — and the status email — on save.
        $order->message = $note;

        if (!Craft::$app->getElements()->saveElement($order, false)) {
            Craft::error(
                'Shipper could not update order ' . $order->id . ': ' . Json::encode($order->getErrors()),
                __METHOD__
            );

            return false;
        }

        return true;
    }

    /**
     * Leave a tracking note without moving the order's status. Written straight to the order
     * history so it does not masquerade as a status change and fire status emails.
     */
    private function addNoteOnly(Order $order, string $note): void
    {
        if (!Plugin::getInstance()->getSettings()->addOrderHistoryNote || !$order->isCompleted) {
            return;
        }

        $commerce = Commerce::getInstance();

        if ($commerce === null) {
            return;
        }

        $history = new OrderHistory();
        $history->orderId = (int)$order->id;
        $history->prevStatusId = $order->orderStatusId;
        $history->newStatusId = $order->orderStatusId;
        $history->message = $note;
        $history->userName = 'ShipStation';

        if (!$commerce->getOrderHistories()->saveOrderHistory($history)) {
            Craft::warning('Shipper could not record an order history note.', __METHOD__);
        }
    }

    private function buildNote(Shipment $shipment, bool $fullyShipped): string
    {
        $items = $shipment->getItems();

        $descriptions = [];

        foreach ($items as $item) {
            $sku = trim((string)($item['sku'] ?? ''));
            $name = trim((string)($item['name'] ?? ''));
            $label = $name !== '' ? $name : $sku;

            if ($label === '') {
                continue;
            }

            if ($sku !== '' && $name !== '') {
                $label .= " ({$sku})";
            }

            $descriptions[] = $label . ' × ' . (int)($item['qty'] ?? 0);
        }

        $carrier = $shipment->carrier ?: Craft::t('shipper', 'ShipStation');
        $tracking = $shipment->trackingNumber ?: Craft::t('shipper', 'no tracking number');
        $date = $shipment->shipDate?->format('Y-m-d') ?? '';

        if ($descriptions !== []) {
            return Craft::t('shipper', '{items} shipped via {carrier} on {date} with tracking number {tracking}.', [
                'items' => implode(', ', $descriptions),
                'carrier' => $carrier,
                'date' => $date,
                'tracking' => $tracking,
            ]);
        }

        return Craft::t('shipper', 'Shipped via {carrier} on {date} with tracking number {tracking}.', [
            'carrier' => $carrier,
            'date' => $date,
            'tracking' => $tracking,
        ]);
    }
}
