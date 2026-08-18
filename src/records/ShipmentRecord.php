<?php

namespace justinholtweb\shipper\records;

use craft\db\ActiveRecord;
use justinholtweb\shipper\db\Table;

/**
 * @property int $id
 * @property int $orderId
 * @property int|null $storeId
 * @property string $shipmentKey
 * @property string|null $carrier
 * @property string|null $carrierCode
 * @property string|null $service
 * @property string|null $trackingNumber
 * @property string|null $shipDate
 * @property string|null $cost
 * @property string|null $items
 * @property string $source
 * @property string|null $rawPayload
 */
class ShipmentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SHIPMENTS;
    }
}
