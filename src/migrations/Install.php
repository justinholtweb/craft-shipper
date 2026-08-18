<?php

namespace justinholtweb\shipper\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\shipper\db\Table;

/**
 * Shipper install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::ORDERSTATE);
        $this->dropTableIfExists(Table::SHIPMENTS);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::SHIPMENTS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'storeId' => $this->integer(),
            // ShipStation sends no notification id, so the shipment identity is the tracking
            // number plus the lower-cased carrier. Hourly retries land on the same key.
            'shipmentKey' => $this->string(255)->notNull(),
            'carrier' => $this->string(255),
            'carrierCode' => $this->string(64),
            'service' => $this->string(255),
            'trackingNumber' => $this->string(255),
            'shipDate' => $this->dateTime(),
            'cost' => $this->decimal(14, 4),
            'items' => $this->text(),
            'source' => $this->string(32)->notNull()->defaultValue('shipnotify'),
            'rawPayload' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::ORDERSTATE, [
            'orderId' => $this->integer()->notNull(),
            'dateFirstExported' => $this->dateTime(),
            'dateLastExported' => $this->dateTime(),
            'exportCount' => $this->integer()->notNull()->defaultValue(0),
            'shippedQty' => $this->integer()->notNull()->defaultValue(0),
            'dateShipped' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[orderId]])',
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(32)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'statusCode' => $this->integer(),
            'durationMs' => $this->integer(),
            'ip' => $this->string(45),
            'summary' => $this->string(255),
            'message' => $this->text(),
            'request' => $this->mediumText(),
            'response' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::SHIPMENTS, ['orderId'], false);
        $this->createIndex(null, Table::SHIPMENTS, ['trackingNumber'], false);
        // The idempotency guarantee: a retried shipnotify cannot insert twice.
        $this->createIndex(null, Table::SHIPMENTS, ['orderId', 'shipmentKey'], true);

        $this->createIndex(null, Table::LOG, ['action'], false);
        $this->createIndex(null, Table::LOG, ['level'], false);
        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::SHIPMENTS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::ORDERSTATE, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
    }
}
