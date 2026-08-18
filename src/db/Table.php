<?php

namespace justinholtweb\shipper\db;

/**
 * Shipper's database tables.
 */
abstract class Table
{
    public const SHIPMENTS = '{{%shipper_shipments}}';
    public const ORDERSTATE = '{{%shipper_orderstate}}';
    public const LOG = '{{%shipper_log}}';
}
