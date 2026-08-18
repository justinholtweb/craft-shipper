<?php

namespace justinholtweb\shipper\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use justinholtweb\shipper\Plugin;
use yii\console\ExitCode;

/**
 * Inspect what ShipStation would receive.
 *
 * `shipper/export/preview` runs the same builder the endpoint does, so a payload that looks wrong
 * here is wrong in production too — and one that looks right cannot differ.
 */
class ExportController extends Controller
{
    /**
     * Start of the export window, as anything `strtotime()` understands. Defaults to 7 days ago.
     */
    public ?string $start = null;

    /**
     * End of the export window. Defaults to now.
     */
    public ?string $end = null;

    /**
     * Page of results.
     */
    public int $page = 1;

    /**
     * A single order, by reference, number or ID. Overrides the date window.
     */
    public ?string $order = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['start', 'end', 'page', 'order']);
    }

    /**
     * Print the XML ShipStation would be served.
     */
    public function actionPreview(): int
    {
        if (!Plugin::commerceIsReady()) {
            $this->stderr("Craft Commerce is not installed or enabled.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $plugin = Plugin::getInstance();

        if ($this->order !== null) {
            $order = $plugin->getExport()->findOrderByNumber($this->order);

            if ($order === null) {
                $this->stderr("No order found matching '{$this->order}'.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $this->stdout($plugin->getExport()->previewOrder($order) . "\n");

            return ExitCode::OK;
        }

        $start = new DateTime($this->start ?? '-7 days');
        $end = new DateTime($this->end ?? 'now');

        $result = $plugin->getExport()->buildExport($start, $end, $this->page);

        $this->stdout($result['xml'] . "\n");
        $this->stderr(sprintf(
            "\n%d orders on page %d of %d (%d matching in total).\n",
            $result['count'],
            $this->page,
            $result['pages'],
            $result['total']
        ), Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * How many orders currently match the export settings.
     */
    public function actionCount(): int
    {
        if (!Plugin::commerceIsReady()) {
            $this->stderr("Craft Commerce is not installed or enabled.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $query = Plugin::getInstance()->getExport()->createQuery(
            $this->start !== null ? new DateTime($this->start) : null,
            $this->end !== null ? new DateTime($this->end) : null
        );

        $count = (int)$query->count();

        $this->stdout("{$count} orders match the current export settings.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * List the orders that would be exported, one per line.
     */
    public function actionList(): int
    {
        if (!Plugin::commerceIsReady()) {
            $this->stderr("Craft Commerce is not installed or enabled.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $plugin = Plugin::getInstance();

        $query = $plugin->getExport()->createQuery(
            $this->start !== null ? new DateTime($this->start) : null,
            $this->end !== null ? new DateTime($this->end) : null
        );

        /** @var Order $order */
        foreach ($query->limit(200)->all() as $order) {
            $state = $plugin->getShipments()->getOrderState((int)$order->id);

            $this->stdout(sprintf(
                "%-16s %-20s %-12s exported %dx\n",
                $plugin->getExport()->orderNumber($order),
                $order->getOrderStatus()?->handle ?? '—',
                $order->dateUpdated?->format('Y-m-d') ?? '—',
                $state['exportCount']
            ));
        }

        return ExitCode::OK;
    }
}
