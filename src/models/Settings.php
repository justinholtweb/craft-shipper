<?php

namespace justinholtweb\shipper\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use justinholtweb\shipper\Plugin;

/**
 * Shipper settings.
 *
 * Nothing here is ever marked `required`: a fresh install has to be able to save the settings
 * screen before the ShipStation credentials exist, and Craft validates plugin settings wholesale.
 */
class Settings extends Model
{
    // Connection
    // -------------------------------------------------------------------------

    /**
     * HTTP Basic username ShipStation should send. Env-parseable.
     */
    public string $username = '';

    /**
     * HTTP Basic password ShipStation should send. Env-parseable.
     */
    public string $password = '';

    /**
     * Shared secret accepted as an `auth_key` query param, for servers where Apache eats the
     * Authorization header. Env-parseable.
     */
    public string $authKey = '';

    // Export
    // -------------------------------------------------------------------------

    /**
     * Commerce order status handles to export. Empty means every completed order.
     *
     * @var string[]
     */
    public array $exportStatusHandles = [];

    /**
     * Orders per page. ShipStation pages through with `?page=`.
     */
    public int $pageSize = 100;

    /**
     * Only export completed orders (never live carts).
     */
    public bool $exportOnlyCompleted = true;

    /**
     * Which order identifier ShipStation sees as the order number.
     * One of `reference`, `number`, `shortNumber`, `id`.
     */
    public string $orderNumberSource = 'reference';

    /**
     * Send the first product image with each line item.
     */
    public bool $includeProductImages = true;

    /**
     * Named image transform to apply to those images.
     */
    public ?string $imageTransform = null;

    /**
     * Handle of the custom field on Craft addresses holding a phone number. Craft 5 moved
     * addresses out of Commerce and dropped the phone attribute, so this has to be configured.
     */
    public string $phoneFieldHandle = '';

    /**
     * Export cart-level discounts as their own adjustment line item.
     */
    public bool $includeDiscountLine = true;

    /**
     * Object templates rendered against the order for ShipStation's three custom fields (Pro).
     * CustomField1 defaults to the coupon code.
     */
    public string $customField1 = '{{ object.couponCode }}';
    public string $customField2 = '';
    public string $customField3 = '';

    // Shipment notifications
    // -------------------------------------------------------------------------

    /**
     * Commerce order status handle to move an order to once everything has shipped.
     */
    public string $shippedStatusHandle = '';

    /**
     * Move the order to a status at all when a shipment arrives.
     */
    public bool $updateStatusOnShipment = true;

    /**
     * Count shipped items and only complete the order once every item has shipped (Pro).
     */
    public bool $partialShipmentsEnabled = true;

    /**
     * Optional status for an order that is partly shipped (Pro).
     */
    public string $partiallyShippedStatusHandle = '';

    /**
     * Leave an order-history note on the order recording the tracking number.
     */
    public bool $addOrderHistoryNote = true;

    // Status mapping (Pro)
    // -------------------------------------------------------------------------

    /**
     * ShipStation status key => Commerce order status handles that map onto it.
     *
     * @var array<string, string[]>
     */
    public array $statusMap = [];

    // REST API (Pro)
    // -------------------------------------------------------------------------

    /**
     * ShipStation API v2 key (`API-Key` header). Env-parseable.
     */
    public string $apiKey = '';

    /**
     * ShipStation API v1 key/secret, used only for `/stores/refreshstore`. Env-parseable.
     */
    public string $legacyApiKey = '';
    public string $legacyApiSecret = '';

    /**
     * The ShipStation store id to refresh. Blank refreshes every refreshable store.
     */
    public string $shipStationStoreId = '';

    // Live rates (Pro)
    // -------------------------------------------------------------------------

    public bool $liveRatesEnabled = false;

    /**
     * ShipStation carrier ids to quote. Empty quotes every connected carrier.
     *
     * @var string[]
     */
    private array $_rateCarrierIds = [];

    /**
     * Service codes to keep. Empty keeps everything the carriers return.
     *
     * @var string[]
     */
    private array $_rateServiceCodes = [];

    /**
     * Origin address for rate requests.
     *
     * @var array<string, string>
     */
    public array $shipFrom = [];

    /**
     * `none`, `percent` or `flat`.
     */
    public string $rateMarkupType = 'none';

    public float $rateMarkupAmount = 0.0;

    /**
     * Seconds to cache a quote for an identical cart.
     */
    public int $rateCacheDuration = 300;

    /**
     * Weight in the store's units to assume for a line item that has none.
     */
    public float $defaultItemWeight = 0.0;

    /**
     * ShipStation package code for the parcel.
     */
    public string $packageCode = 'package';

    /**
     * Seconds to wait on the rates API before giving up and leaving checkout alone.
     */
    public int $rateTimeout = 8;

    // Logging
    // -------------------------------------------------------------------------

    public bool $loggingEnabled = true;

    /**
     * Store request and response bodies on log rows (Pro). Off keeps only the summary.
     */
    public bool $logPayloads = true;

    /**
     * Days of log history to keep. 0 keeps everything.
     */
    public int $logRetentionDays = 30;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['pageSize'], 'integer', 'min' => 1, 'max' => 500],
            [['rateCacheDuration', 'logRetentionDays'], 'integer', 'min' => 0],
            [['rateTimeout'], 'integer', 'min' => 1, 'max' => 60],
            [['rateMarkupAmount', 'defaultItemWeight'], 'number', 'min' => 0],
            [['orderNumberSource'], 'in', 'range' => ['reference', 'number', 'shortNumber', 'id']],
            [['rateMarkupType'], 'in', 'range' => ['none', 'percent', 'flat']],
            [['exportStatusHandles', 'rateCarrierIds', 'rateServiceCodes', 'statusMap', 'shipFrom'], 'safe'],
            [
                ['username', 'password', 'authKey', 'apiKey', 'legacyApiKey', 'legacyApiSecret'],
                'string',
            ],
        ];
    }

    /**
     * Accepts either a plain list of codes or Craft's editable-table row format, which posts
     * `[['value' => 'se-123'], …]`. Normalising here keeps every consumer reading a flat list.
     */
    public function setRateCarrierIds(mixed $value): void
    {
        $this->_rateCarrierIds = self::normalizeList($value);
    }

    /**
     * @return string[]
     */
    public function getRateCarrierIds(): array
    {
        return $this->_rateCarrierIds;
    }

    public function setRateServiceCodes(mixed $value): void
    {
        $this->_rateServiceCodes = self::normalizeList($value);
    }

    /**
     * @return string[]
     */
    public function getRateServiceCodes(): array
    {
        return $this->_rateServiceCodes;
    }

    /**
     * @return string[]
     */
    private static function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['value'] ?? reset($item);
            }

            if (!is_scalar($item)) {
                continue;
            }

            $item = trim((string)$item);

            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The parsed HTTP Basic credentials ShipStation should be configured with.
     *
     * @return array{0: string, 1: string}
     */
    public function getBasicCredentials(): array
    {
        return [
            (string)App::parseEnv($this->username),
            (string)App::parseEnv($this->password),
        ];
    }

    public function getParsedAuthKey(): string
    {
        return (string)App::parseEnv($this->authKey);
    }

    public function getParsedApiKey(): string
    {
        return (string)App::parseEnv($this->apiKey);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function getLegacyCredentials(): array
    {
        return [
            (string)App::parseEnv($this->legacyApiKey),
            (string)App::parseEnv($this->legacyApiSecret),
        ];
    }

    /**
     * Whether the endpoint can authenticate anybody at all. An endpoint with no credentials
     * configured must reject everything rather than let the world read the order book.
     */
    public function hasCredentials(): bool
    {
        [$username, $password] = $this->getBasicCredentials();

        return ($username !== '' && $password !== '') || $this->getParsedAuthKey() !== '';
    }

    /**
     * The URL to paste into ShipStation's custom store setup.
     */
    public function getEndpointUrl(): string
    {
        $url = UrlHelper::actionUrl('shipper/api/process');
        $authKey = $this->getParsedAuthKey();

        // Only advertise the query-param form when that is the configured mechanism — the Basic
        // credentials go in ShipStation's own username/password fields instead.
        if ($authKey !== '') {
            $url = UrlHelper::urlWithParams($url, ['auth_key' => $authKey]);
        }

        return $url;
    }

    /**
     * Effective retention, respecting the edition (Lite keeps a short tail so the table cannot
     * grow without bound on installs that have no log screen to prune it from).
     */
    public function getEffectiveLogRetentionDays(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin !== null && !$plugin->isPro()) {
            return 7;
        }

        return $this->logRetentionDays;
    }

    /**
     * @inheritdoc
     *
     * The two rate lists are backed by private properties, so Yii would not see them as
     * attributes at all — and Craft persists plugin settings by iterating attributes.
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), ['rateCarrierIds', 'rateServiceCodes']);
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'username' => Craft::t('shipper', 'Username'),
            'password' => Craft::t('shipper', 'Password'),
            'authKey' => Craft::t('shipper', 'Auth key'),
            'pageSize' => Craft::t('shipper', 'Orders per page'),
            'shippedStatusHandle' => Craft::t('shipper', 'Shipped order status'),
        ];
    }
}
