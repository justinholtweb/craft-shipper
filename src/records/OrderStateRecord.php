<?php

namespace justinholtweb\shipper\records;

use craft\db\ActiveRecord;
use justinholtweb\shipper\db\Table;

/**
 * @property int $orderId
 * @property string|null $dateFirstExported
 * @property string|null $dateLastExported
 * @property int $exportCount
 * @property int $shippedQty
 * @property string|null $dateShipped
 */
class OrderStateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ORDERSTATE;
    }

    public static function primaryKey(): array
    {
        return ['orderId'];
    }
}
