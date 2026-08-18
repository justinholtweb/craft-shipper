<?php

namespace justinholtweb\shipper\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\shipper\Plugin;
use yii\console\ExitCode;

/**
 * Ask ShipStation to re-import from the custom store.
 */
class SyncController extends Controller
{
    /**
     * The ShipStation store id to refresh. Omit to refresh every refreshable store.
     */
    public ?string $store = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['store']);
    }

    /**
     * Trigger a store refresh.
     */
    public function actionRefresh(): int
    {
        if (!Plugin::getInstance()->isPro()) {
            $this->stderr("Syncing requires Shipper Pro.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $result = Plugin::getInstance()->getApi()->refreshStore($this->store);

        if (!$result['success']) {
            $this->stderr($result['message'] . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout($result['message'] . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Check the ShipStation API credentials.
     */
    public function actionTest(): int
    {
        $result = Plugin::getInstance()->getApi()->testConnection();

        $this->stdout($result['message'] . "\n", $result['success'] ? Console::FG_GREEN : Console::FG_RED);

        return $result['success'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * List the carriers connected to the ShipStation account.
     */
    public function actionCarriers(): int
    {
        $carriers = Plugin::getInstance()->getApi()->getCarriers();

        if ($carriers === []) {
            $this->stderr("No carriers returned. Check the API key and the log.\n", Console::FG_YELLOW);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($carriers as $carrier) {
            $this->stdout(sprintf(
                "%-20s %-16s %s\n",
                $carrier['carrier_id'],
                $carrier['carrier_code'],
                $carrier['friendly_name'] ?: $carrier['nickname']
            ));
        }

        return ExitCode::OK;
    }
}
