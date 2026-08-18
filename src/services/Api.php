<?php

namespace justinholtweb\shipper\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\shipper\models\LogEntry;
use justinholtweb\shipper\Plugin;

/**
 * ShipStation's REST APIs.
 *
 * Two of them, for two jobs:
 *
 * - **v2** (`api.shipstation.com/v2`, `API-Key` header) quotes rates and lists carriers.
 * - **v1** (`ssapi.shipstation.com`, Basic auth) is used for exactly one call,
 *   `POST /stores/refreshstore`. That is deliberate: Shipper's orders reach ShipStation through
 *   the Custom Store, so "Sync now" asks ShipStation to *pull* immediately. Pushing the same order
 *   through `createorder` would give the merchant two of everything.
 */
class Api extends Component
{
    public const V2_BASE = 'https://api.shipstation.com/v2/';
    public const V1_BASE = 'https://ssapi.shipstation.com/';

    /**
     * Ask ShipStation to re-import from the custom store now.
     *
     * @return array{success: bool, message: string}
     */
    public function refreshStore(?string $storeId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        [$key, $secret] = $settings->getLegacyCredentials();

        if ($key === '' || $secret === '') {
            return [
                'success' => false,
                'message' => Craft::t('shipper', 'No ShipStation API key and secret are configured.'),
            ];
        }

        $storeId = $storeId ?? trim($settings->shipStationStoreId);
        $query = $storeId !== '' ? ['storeId' => $storeId] : [];

        $started = microtime(true);

        try {
            $response = $this->v1Client($key, $secret)->post('stores/refreshstore', [
                'query' => $query,
            ]);

            $body = (string)$response->getBody();

            $this->log('refresh', LogEntry::LEVEL_INFO, $response->getStatusCode(), $started, Craft::t('shipper', 'Store refresh requested'), Json::encode($query), $body);

            return [
                'success' => true,
                'message' => $body !== '' ? $body : Craft::t('shipper', 'Store refresh initiated.'),
            ];
        } catch (\Throwable $e) {
            $message = $this->describe($e);
            $this->log('refresh', LogEntry::LEVEL_ERROR, $this->statusOf($e), $started, $message, Json::encode($query), $this->bodyOf($e));

            return [
                'success' => false,
                'message' => $message,
            ];
        }
    }

    /**
     * The carriers connected to the ShipStation account, for the rate settings screen.
     *
     * @return array<int, array{carrier_id: string, carrier_code: string, friendly_name: string, nickname: string}>
     */
    public function getCarriers(): array
    {
        $started = microtime(true);

        try {
            $response = $this->v2Client()->get('carriers');
            $data = Json::decodeIfJson((string)$response->getBody());

            $carriers = [];

            foreach ($data['carriers'] ?? [] as $carrier) {
                $carriers[] = [
                    'carrier_id' => (string)($carrier['carrier_id'] ?? ''),
                    'carrier_code' => (string)($carrier['carrier_code'] ?? ''),
                    'friendly_name' => (string)($carrier['friendly_name'] ?? ''),
                    'nickname' => (string)($carrier['nickname'] ?? ''),
                ];
            }

            $this->log('carriers', LogEntry::LEVEL_INFO, $response->getStatusCode(), $started, Craft::t('shipper', 'Listed {count} carriers', ['count' => count($carriers)]));

            return $carriers;
        } catch (\Throwable $e) {
            $this->log('carriers', LogEntry::LEVEL_ERROR, $this->statusOf($e), $started, $this->describe($e), null, $this->bodyOf($e));

            return [];
        }
    }

    /**
     * Quote rates for a shipment.
     *
     * Throws nothing: a rate call runs inside checkout, and a ShipStation outage must not be able
     * to stop a customer paying. Callers get an empty list and the reason lands in the log.
     *
     * @return array{rates: array, errors: string[]}
     */
    public function getRates(array $payload): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $started = microtime(true);

        try {
            $response = $this->v2Client($settings->rateTimeout)->post('rates', [
                'json' => $payload,
            ]);

            $data = Json::decodeIfJson((string)$response->getBody());
            $rateResponse = $data['rate_response'] ?? [];
            $rates = $rateResponse['rates'] ?? [];

            $errors = array_map(
                static fn($error) => is_array($error) ? (string)($error['message'] ?? '') : (string)$error,
                $rateResponse['errors'] ?? []
            );

            $this->log(
                'rates',
                $errors === [] ? LogEntry::LEVEL_INFO : LogEntry::LEVEL_WARNING,
                $response->getStatusCode(),
                $started,
                Craft::t('shipper', 'Quoted {count} rates', ['count' => count($rates)]),
                Json::encode($payload),
                (string)$response->getBody()
            );

            return [
                'rates' => $rates,
                'errors' => array_values(array_filter($errors)),
            ];
        } catch (\Throwable $e) {
            $this->log('rates', LogEntry::LEVEL_ERROR, $this->statusOf($e), $started, $this->describe($e), Json::encode($payload), $this->bodyOf($e));

            return [
                'rates' => [],
                'errors' => [$this->describe($e)],
            ];
        }
    }

    /**
     * Whether the v2 credentials work, for the settings screen's "Test connection" button.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if (Plugin::getInstance()->getSettings()->getParsedApiKey() === '') {
            return [
                'success' => false,
                'message' => Craft::t('shipper', 'No ShipStation API key is configured.'),
            ];
        }

        try {
            $response = $this->v2Client(10)->get('carriers');
            $data = Json::decodeIfJson((string)$response->getBody());
            $count = count($data['carriers'] ?? []);

            return [
                'success' => true,
                'message' => Craft::t('shipper', 'Connected. {count} carriers available.', ['count' => $count]),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $this->describe($e),
            ];
        }
    }

    // Private
    // =========================================================================

    private function v2Client(?int $timeout = null): Client
    {
        return Craft::createGuzzleClient([
            'base_uri' => self::V2_BASE,
            'timeout' => $timeout ?? 15,
            'headers' => [
                'API-Key' => Plugin::getInstance()->getSettings()->getParsedApiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    private function v1Client(string $key, string $secret): Client
    {
        return Craft::createGuzzleClient([
            'base_uri' => self::V1_BASE,
            'timeout' => 20,
            'auth' => [$key, $secret],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    private function statusOf(\Throwable $e): ?int
    {
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    private function bodyOf(\Throwable $e): ?string
    {
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return (string)$e->getResponse()->getBody();
        }

        return null;
    }

    /**
     * A message a merchant can act on. ShipStation returns its own error array on a 4xx, which is
     * far more useful than Guzzle's "Client error: `POST …` resulted in a `400 Bad Request`".
     */
    private function describe(\Throwable $e): string
    {
        $body = $this->bodyOf($e);

        if ($body !== null && $body !== '') {
            $decoded = Json::decodeIfJson($body);

            if (is_array($decoded) && !empty($decoded['errors'])) {
                $messages = array_map(
                    static fn($error) => is_array($error) ? (string)($error['message'] ?? '') : (string)$error,
                    $decoded['errors']
                );
                $messages = array_values(array_filter($messages));

                if ($messages !== []) {
                    return implode(' ', $messages);
                }
            }
        }

        return $e->getMessage();
    }

    private function log(string $action, string $level, ?int $status, float $started, string $summary, ?string $request = null, ?string $response = null): void
    {
        Plugin::getInstance()->getLog()->write($action, [
            'level' => $level,
            'statusCode' => $status,
            'durationMs' => (int)round((microtime(true) - $started) * 1000),
            'summary' => $summary,
            'request' => $request,
            'response' => $response,
        ]);
    }
}
