<?php

namespace justinholtweb\shipper\records;

use craft\db\ActiveRecord;
use justinholtweb\shipper\db\Table;

/**
 * @property int $id
 * @property string $action
 * @property string $level
 * @property int|null $statusCode
 * @property int|null $durationMs
 * @property string|null $ip
 * @property string|null $summary
 * @property string|null $message
 * @property string|null $request
 * @property string|null $response
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
