<?php

namespace justinholtweb\shipper\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\shipper\Plugin;
use yii\console\ExitCode;

/**
 * Quote live rates from the command line, through the same path checkout uses.
 */
class RatesController extends Controller
{
    /**
     * Order or cart to quote, by reference, number or ID. Defaults to the most recent cart.
     */
    public ?string $order = null;

    /**
     * Print the request payload alongside the quotes.
     */
    public bool $verbose = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['order', 'verbose']);
    }

    /**
     * Quote rates for a cart.
     */
    public function actionQuote(): int
    {
        if (!Plugin::commerceIsReady()) {
            $this->stderr("Craft Commerce is not installed or enabled.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        if (!Plugin::getInstance()->isPro()) {
            $this->stderr("Live rates require Shipper Pro.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $order = $this->order !== null
            ? Plugin::getInstance()->getExport()->findOrderByNumber($this->order)
            : Order::find()->isCompleted(false)->orderBy(['dateUpdated' => SORT_DESC])->one();

        if (!$order instanceof Order) {
            $this->stderr("No cart or order found to quote against.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $result = Plugin::getInstance()->getRates()->debugQuote($order);

        if ($this->verbose && $result['payload'] !== null) {
            $this->stdout(Json::encode($result['payload'], JSON_PRETTY_PRINT) . "\n\n", Console::FG_GREY);
        }

        foreach ($result['errors'] as $error) {
            $this->stderr("! {$error}\n", Console::FG_YELLOW);
        }

        if ($result['rates'] === []) {
            $this->stderr("No rates returned.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($result['rates'] as $rate) {
            $this->stdout(sprintf(
                "%-40s %10s %s%s\n",
                $rate->getName(),
                number_format($rate->getTotal(), 2),
                $rate->currency,
                $rate->deliveryDays ? "  ({$rate->deliveryDays} days)" : ''
            ));
        }

        return ExitCode::OK;
    }
}
