<?php

namespace justinholtweb\shipper\controllers;

use Craft;
use craft\web\Controller;
use DOMDocument;
use justinholtweb\shipper\helpers\Xml;
use justinholtweb\shipper\models\LogEntry;
use justinholtweb\shipper\Plugin;
use SimpleXMLElement;
use yii\web\Response;

/**
 * The ShipStation Custom Store endpoint.
 *
 * ShipStation calls this with `?action=export` to pull orders and `?action=shipnotify` to report a
 * shipment. Both are anonymous and CSRF-exempt by necessity — the caller is ShipStation's servers,
 * not a browser with a session — so authentication here is the only thing between the public and
 * the order book.
 */
class ApiController extends Controller
{
    public $enableCsrfValidation = false;

    protected array|bool|int $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Craft's own "is this action allowed" checks run first.
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->response->getHeaders()->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return true;
    }

    /**
     * The single entry point ShipStation is pointed at.
     */
    public function actionProcess(): Response
    {
        $started = microtime(true);
        $request = Craft::$app->getRequest();
        $action = (string)$request->getParam('action', '');

        if (!Plugin::commerceIsReady()) {
            return $this->fail('export', 503, 'Craft Commerce is not installed or enabled.', $started);
        }

        if (!$this->authenticate()) {
            // Deliberately vague: an endpoint that explains *which* half of the credentials was
            // wrong is an oracle for anybody probing it.
            return $this->fail($action ?: 'export', 401, 'Invalid ShipStation credentials.', $started);
        }

        return match ($action) {
            'export' => $this->handleExport($started),
            'shipnotify' => $this->handleShipNotify($started),
            default => $this->fail('export', 400, "Unknown action '{$action}'. Expected 'export' or 'shipnotify'.", $started),
        };
    }

    // Authentication
    // =========================================================================

    /**
     * Accept either HTTP Basic — which is what ShipStation's store settings offer — or an
     * `auth_key` query param, because Apache commonly strips the Authorization header and the
     * merchant has no way to tell that is what happened.
     */
    private function authenticate(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        // An endpoint with nothing configured must reject everything rather than serve the order
        // book to the world for however long it takes someone to notice.
        if (!$settings->hasCredentials()) {
            return false;
        }

        $request = Craft::$app->getRequest();

        $expectedKey = $settings->getParsedAuthKey();

        if ($expectedKey !== '') {
            $providedKey = (string)$request->getQueryParam('auth_key', '');

            if ($providedKey !== '' && hash_equals($expectedKey, $providedKey)) {
                return true;
            }
        }

        [$expectedUsername, $expectedPassword] = $settings->getBasicCredentials();

        if ($expectedUsername === '' || $expectedPassword === '') {
            return false;
        }

        [$username, $password] = $request->getAuthCredentials();

        return hash_equals($expectedUsername, (string)$username)
            && hash_equals($expectedPassword, (string)$password);
    }

    // Export
    // =========================================================================

    private function handleExport(float $started): Response
    {
        $request = Craft::$app->getRequest();

        $start = Xml::parseDate((string)$request->getQueryParam('start_date', ''));
        $end = Xml::parseDate((string)$request->getQueryParam('end_date', ''));
        $page = max(1, (int)$request->getQueryParam('page', 1));

        try {
            $result = Plugin::getInstance()->getExport()->buildExport($start, $end, $page);
        } catch (\Throwable $e) {
            Craft::error('Shipper export failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);

            return $this->fail('export', 500, 'Export failed: ' . $e->getMessage(), $started);
        }

        Plugin::getInstance()->getLog()->write('export', [
            'level' => LogEntry::LEVEL_INFO,
            'statusCode' => 200,
            'durationMs' => $this->elapsed($started),
            'summary' => Craft::t('shipper', 'Exported {count} of {total} orders (page {page} of {pages})', [
                'count' => $result['count'],
                'total' => $result['total'],
                'page' => $page,
                'pages' => $result['pages'],
            ]),
            'request' => $request->getAbsoluteUrl(),
            'response' => $result['xml'],
        ]);

        return $this->xml($result['xml']);
    }

    // Ship notify
    // =========================================================================

    private function handleShipNotify(float $started): Response
    {
        $request = Craft::$app->getRequest();
        $body = $request->getRawBody();

        $orderNumber = trim((string)$request->getQueryParam('order_number', ''));
        $carrier = trim((string)$request->getQueryParam('carrier', ''));
        $service = trim((string)$request->getQueryParam('service', ''));
        $trackingNumber = trim((string)$request->getQueryParam('tracking_number', ''));

        $xml = $this->parseXml($body);

        // The posted XML carries the authoritative OrderID; the query param is only the number
        // ShipStation was originally given, which a merchant may since have reconfigured.
        $order = null;
        $xmlOrderId = isset($xml->OrderID) ? (int)$xml->OrderID : 0;

        if ($xmlOrderId > 0) {
            $order = \craft\commerce\elements\Order::find()->id($xmlOrderId)->status(null)->one();
        }

        if ($order === null && $orderNumber !== '') {
            $order = Plugin::getInstance()->getExport()->findOrderByNumber($orderNumber);
        }

        if ($order === null) {
            return $this->fail(
                'shipnotify',
                404,
                "No order found for order_number '{$orderNumber}'" . ($xmlOrderId ? " or OrderID {$xmlOrderId}" : '') . '.',
                $started,
                $body
            );
        }

        $shipDate = isset($xml->ShipDate) ? Xml::parseDate((string)$xml->ShipDate) : null;
        $items = $this->parseItems($xml);

        try {
            $result = Plugin::getInstance()->getShipments()->record($order, [
                'carrier' => $carrier,
                'carrierCode' => $carrier,
                'service' => $service,
                'trackingNumber' => $trackingNumber,
                'shipDate' => $shipDate ? new \DateTime('@' . $shipDate->getTimestamp()) : null,
                'items' => $items,
                'source' => 'shipnotify',
                'rawPayload' => $body,
            ]);
        } catch (\Throwable $e) {
            Craft::error('Shipper shipnotify failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);

            return $this->fail('shipnotify', 500, 'Could not record the shipment: ' . $e->getMessage(), $started, $body);
        }

        if ($result['shipment'] === null) {
            return $this->fail('shipnotify', 500, 'Could not record the shipment.', $started, $body);
        }

        $summary = $result['duplicate']
            ? Craft::t('shipper', 'Duplicate shipment for order {number} ignored', ['number' => $orderNumber ?: $order->id])
            : Craft::t('shipper', 'Recorded shipment for order {number} ({carrier} {tracking})', [
                'number' => $orderNumber ?: $order->id,
                'carrier' => $carrier,
                'tracking' => $trackingNumber,
            ]);

        Plugin::getInstance()->getLog()->write('shipnotify', [
            'level' => LogEntry::LEVEL_INFO,
            'statusCode' => 200,
            'durationMs' => $this->elapsed($started),
            'summary' => $summary,
            'request' => $request->getAbsoluteUrl() . "\n\n" . $body,
            'response' => 'OK',
        ]);

        // ShipStation only checks the status code, but a body makes the endpoint testable by hand.
        $this->response->format = Response::FORMAT_JSON;
        $this->response->data = [
            'success' => true,
            'duplicate' => $result['duplicate'],
            'fullyShipped' => $result['fullyShipped'],
            'statusChanged' => $result['statusChanged'],
        ];

        return $this->response;
    }

    /**
     * Parse ShipStation's posted XML with entities and network access off.
     */
    private function parseXml(string $body): ?SimpleXMLElement
    {
        if (trim($body) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();

            // LIBXML_NONET blocks external fetches; the DOCTYPE check below covers the rest of
            // the XXE surface, which is the reason not to simply reach for simplexml_load_string.
            if (!$document->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }

            if ($document->doctype !== null) {
                Craft::warning('Shipper rejected a shipnotify payload carrying a DOCTYPE.', __METHOD__);

                return null;
            }

            return simplexml_import_dom($document);
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return array<int, array{lineItemId: int|null, sku: string, name: string, qty: int}>
     */
    private function parseItems(?SimpleXMLElement $xml): array
    {
        if ($xml === null || !isset($xml->Items)) {
            return [];
        }

        $items = [];

        foreach ($xml->Items->Item as $item) {
            $qty = (int)$item->Quantity;

            if ($qty <= 0) {
                continue;
            }

            $lineItemId = isset($item->LineItemID) ? (int)$item->LineItemID : 0;

            $items[] = [
                'lineItemId' => $lineItemId > 0 ? $lineItemId : null,
                'sku' => trim((string)$item->SKU),
                'name' => trim((string)$item->Name),
                'qty' => $qty,
            ];
        }

        return $items;
    }

    // Responses
    // =========================================================================

    private function xml(string $xml): Response
    {
        $this->response->format = Response::FORMAT_RAW;
        $this->response->getHeaders()->set('Content-Type', 'text/xml; charset=utf-8');
        $this->response->data = $xml;

        return $this->response;
    }

    private function fail(string $action, int $statusCode, string $message, float $started, ?string $body = null): Response
    {
        Plugin::getInstance()->getLog()->write($action, [
            'level' => $statusCode >= 500 ? LogEntry::LEVEL_ERROR : LogEntry::LEVEL_WARNING,
            'statusCode' => $statusCode,
            'durationMs' => $this->elapsed($started),
            'summary' => $message,
            'request' => Craft::$app->getRequest()->getAbsoluteUrl() . ($body !== null ? "\n\n" . $body : ''),
        ]);

        $this->response->setStatusCode($statusCode);
        $this->response->format = Response::FORMAT_JSON;
        $this->response->data = [
            'success' => false,
            'error' => $message,
        ];

        return $this->response;
    }

    private function elapsed(float $started): int
    {
        return (int)round((microtime(true) - $started) * 1000);
    }
}
