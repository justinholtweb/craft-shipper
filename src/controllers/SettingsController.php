<?php

namespace justinholtweb\shipper\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\StringHelper;
use craft\web\Controller;
use justinholtweb\shipper\Plugin;
use yii\web\Response;

/**
 * Ajax helpers behind the settings screen.
 *
 * They post through `Craft.sendActionRequest` rather than their own `<form>`: the plugin settings
 * screen is already a form, and a nested one does not merely fail — the parser drops the inner
 * tag and keeps its children, so a second `action` input ends up in the page form.
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    /**
     * Mint a fresh auth key. Not saved here — it goes into the form for the admin to save with
     * the rest of the settings, so a mistyped key never locks out a working connection.
     */
    public function actionGenerateKey(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return $this->asJson([
            'success' => true,
            'key' => StringHelper::UUID() . '-' . bin2hex(random_bytes(8)),
        ]);
    }

    /**
     * Check the v2 API key by listing carriers.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $result = Plugin::getInstance()->getApi()->testConnection();

        return $this->asJson([
            'success' => $result['success'],
            'message' => $result['message'],
        ]);
    }

    /**
     * The carriers connected to the account, for the live-rates carrier picker.
     */
    public function actionCarriers(): Response
    {
        $this->requireAcceptsJson();

        return $this->asJson([
            'success' => true,
            'carriers' => Plugin::getInstance()->getApi()->getCarriers(),
        ]);
    }

    /**
     * How many orders the current export settings would hand to ShipStation, so an admin can see
     * the effect of a status filter before pointing ShipStation at it.
     */
    public function actionExportCount(): Response
    {
        $this->requireAcceptsJson();

        if (!Plugin::commerceIsReady()) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('shipper', 'Craft Commerce is not installed or enabled.'),
            ]);
        }

        $count = (int)Plugin::getInstance()->getExport()->createQuery()->count();

        return $this->asJson([
            'success' => true,
            'count' => $count,
            'message' => Craft::t('shipper', '{count, plural, =1{1 order currently matches} other{# orders currently match}} your export settings.', ['count' => $count]),
        ]);
    }

    /**
     * Quote live rates against a real cart, through the same code path checkout uses.
     */
    public function actionQuote(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $orderId = (int)Craft::$app->getRequest()->getBodyParam('orderId', 0);

        $order = $orderId > 0
            ? Order::find()->id($orderId)->status(null)->one()
            : Order::find()->isCompleted(false)->orderBy(['dateUpdated' => SORT_DESC])->one();

        if ($order === null) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('shipper', 'No cart or order was found to quote against.'),
            ]);
        }

        $result = Plugin::getInstance()->getRates()->debugQuote($order);

        return $this->asJson([
            'success' => $result['rates'] !== [],
            'orderId' => $order->id,
            'rates' => array_map(static fn($rate) => [
                'name' => $rate->getName(),
                'handle' => $rate->getHandle(),
                'total' => $rate->getTotal(),
                'currency' => $rate->currency,
                'deliveryDays' => $rate->deliveryDays,
            ], $result['rates']),
            'errors' => $result['errors'],
        ]);
    }
}
