<?php

namespace justinholtweb\shipper\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use DateTime;
use justinholtweb\shipper\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Shipments in the control panel.
 */
class ShipmentsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('shipper-viewShipments');

        return true;
    }

    public function actionIndex(): Response
    {
        $search = Craft::$app->getRequest()->getParam('search');
        $shipments = Plugin::getInstance()->getShipments()->getRecentShipments(200, $search);

        $orderIds = array_values(array_unique(array_map(static fn($shipment) => $shipment->orderId, $shipments)));
        $orders = [];

        if ($orderIds !== []) {
            foreach (Order::find()->id($orderIds)->status(null)->limit(null)->all() as $order) {
                $orders[$order->id] = $order;
            }
        }

        return $this->renderTemplate('shipper/shipments/_index', [
            'shipments' => $shipments,
            'orders' => $orders,
            'search' => $search,
            'isPro' => Plugin::getInstance()->isPro(),
        ]);
    }

    public function actionDetail(int $shipmentId): Response
    {
        $shipment = Plugin::getInstance()->getShipments()->getShipmentById($shipmentId);

        if ($shipment === null) {
            throw new NotFoundHttpException('Shipment not found');
        }

        $order = Order::find()->id($shipment->orderId)->status(null)->one();

        return $this->renderTemplate('shipper/shipments/_detail', [
            'shipment' => $shipment,
            'order' => $order,
            'canManage' => Craft::$app->getUser()->checkPermission('shipper-manageShipments'),
        ]);
    }

    /**
     * Record a shipment by hand — a label bought outside ShipStation, or a shipnotify that never
     * arrived. Goes through the same service the endpoint does.
     */
    public function actionAdd(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('shipper-manageShipments');

        $request = Craft::$app->getRequest();
        $orderId = (int)$request->getRequiredBodyParam('orderId');

        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException('Order not found');
        }

        $shipDateParam = $request->getBodyParam('shipDate');
        $shipDate = null;

        if (is_array($shipDateParam)) {
            // Craft's date field posts an array, which no scalar cast will accept.
            $shipDate = \craft\helpers\DateTimeHelper::toDateTime($shipDateParam) ?: null;
        } elseif (is_string($shipDateParam) && $shipDateParam !== '') {
            $shipDate = \craft\helpers\DateTimeHelper::toDateTime($shipDateParam) ?: null;
        }

        $result = Plugin::getInstance()->getShipments()->record($order, [
            'carrier' => (string)$request->getBodyParam('carrier', ''),
            'carrierCode' => (string)$request->getBodyParam('carrier', ''),
            'service' => (string)$request->getBodyParam('service', ''),
            'trackingNumber' => (string)$request->getBodyParam('trackingNumber', ''),
            'shipDate' => $shipDate instanceof DateTime ? $shipDate : new DateTime(),
            'source' => 'manual',
        ]);

        if ($result['shipment'] === null) {
            return $this->asFailure(Craft::t('shipper', 'Couldn’t record the shipment.'));
        }

        if ($result['duplicate']) {
            return $this->asSuccess(Craft::t('shipper', 'That shipment was already recorded.'));
        }

        return $this->asSuccess(Craft::t('shipper', 'Shipment recorded.'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('shipper-manageShipments');

        $shipmentId = (int)Craft::$app->getRequest()->getRequiredBodyParam('shipmentId');

        if (!Plugin::getInstance()->getShipments()->deleteShipmentById($shipmentId)) {
            return $this->asFailure(Craft::t('shipper', 'Couldn’t delete the shipment.'));
        }

        return $this->asSuccess(Craft::t('shipper', 'Shipment deleted.'));
    }

    /**
     * Show exactly what ShipStation would receive for one order.
     */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();

        $orderId = (int)Craft::$app->getRequest()->getRequiredParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException('Order not found');
        }

        // The preview is the customer's whole record — addresses, email, phone — so seeing
        // shipments is not enough; the user has to be allowed to see the order itself.
        if (!Craft::$app->getElements()->canView($order)) {
            throw new ForbiddenHttpException('User is not permitted to view this order');
        }

        $xml = Plugin::getInstance()->getExport()->previewOrder($order);

        return $this->asJson([
            'success' => true,
            'xml' => $xml,
        ]);
    }

    /**
     * Ask ShipStation to re-import from the custom store now.
     */
    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('shipper-syncOrders');

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('Syncing requires Shipper Pro.');
        }

        $result = Plugin::getInstance()->getApi()->refreshStore();

        if (!$result['success']) {
            return $this->asFailure($result['message']);
        }

        return $this->asSuccess($result['message']);
    }
}
