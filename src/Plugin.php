<?php

namespace justinholtweb\shipper;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\RegisterAvailableShippingMethodsEvent;
use craft\commerce\services\ShippingMethods;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\shipper\models\Settings;
use justinholtweb\shipper\services\Api;
use justinholtweb\shipper\services\Export;
use justinholtweb\shipper\services\Log;
use justinholtweb\shipper\services\Rates;
use justinholtweb\shipper\services\Shipments;
use justinholtweb\shipper\services\Statuses;
use justinholtweb\shipper\shipping\LiveRateShippingMethod;
use justinholtweb\shipper\twig\ShipperVariable;
use yii\base\Event;

/**
 * Shipper — ShipStation integration for Craft Commerce.
 *
 * @property-read Export $export
 * @property-read Shipments $shipments
 * @property-read Statuses $statuses
 * @property-read Log $log
 * @property-read Api $api
 * @property-read Rates $rates
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'shipper';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'export' => ['class' => Export::class],
                'shipments' => ['class' => Shipments::class],
                'statuses' => ['class' => Statuses::class],
                'log' => ['class' => Log::class],
                'api' => ['class' => Api::class],
                'rates' => ['class' => Rates::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerGarbageCollection();

        // The plugin can be installed while Commerce is disabled or mid-upgrade; everything below
        // touches an order, so it all has to wait for Commerce to actually be there.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEditPanel();

        if ($this->isPro()) {
            $this->_registerLiveRates();
        }
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * Whether this install is licensed for the Pro feature set.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getExport(): Export
    {
        return $this->get('export');
    }

    public function getShipments(): Shipments
    {
        return $this->get('shipments');
    }

    public function getStatuses(): Statuses
    {
        return $this->get('statuses');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getRates(): Rates
    {
        return $this->get('rates');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('shipper/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('shipper', 'Shipper');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('shipper-viewShipments')) {
            $subNav['shipments'] = [
                'label' => Craft::t('shipper', 'Shipments'),
                'url' => 'shipper/shipments',
            ];
        }

        if ($this->isPro() && $user->checkPermission('shipper-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('shipper', 'Log'),
                'url' => 'shipper/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('shipper', 'Settings'),
                'url' => 'settings/plugins/shipper',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('shipper', ShipperVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('shipper', 'Shipper'),
                    'permissions' => [
                        'shipper-viewShipments' => [
                            'label' => Craft::t('shipper', 'View shipments'),
                            'nested' => [
                                'shipper-manageShipments' => [
                                    'label' => Craft::t('shipper', 'Add and delete shipments'),
                                ],
                            ],
                        ],
                        'shipper-viewLog' => [
                            'label' => Craft::t('shipper', 'View the connection log'),
                            'nested' => [
                                'shipper-manageLog' => [
                                    'label' => Craft::t('shipper', 'Clear and prune the connection log'),
                                ],
                            ],
                        ],
                        'shipper-syncOrders' => [
                            'label' => Craft::t('shipper', 'Trigger a ShipStation sync'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * The endpoint is public, so the log grows with every request anyone makes to it; retention is
     * enforced on Craft's garbage collection, not only when somebody remembers the prune button.
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->getLog()->prune();
        });
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['shipper'] = 'shipper/shipments/index';
                $event->rules['shipper/shipments'] = 'shipper/shipments/index';
                $event->rules['shipper/shipments/<shipmentId:\d+>'] = 'shipper/shipments/detail';
                $event->rules['shipper/log'] = 'shipper/log/index';
                $event->rules['shipper/log/<entryId:\d+>'] = 'shipper/log/detail';
            }
        );
    }

    /**
     * Shipments and the sync button appear on Commerce's own order edit screen.
     */
    private function _registerOrderEditPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('shipper-viewShipments')) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('shipper/_order-panel', [
                'order' => $order,
                'shipments' => $this->getShipments()->getShipmentsForOrder($order->id),
                'state' => $this->getShipments()->getOrderState($order->id),
                'isPro' => $this->isPro(),
                'canSync' => Craft::$app->getUser()->checkPermission('shipper-syncOrders'),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * One Commerce shipping method per live rate ShipStation quotes for this cart.
     */
    private function _registerLiveRates(): void
    {
        Event::on(
            ShippingMethods::class,
            ShippingMethods::EVENT_REGISTER_AVAILABLE_SHIPPING_METHODS,
            static function(RegisterAvailableShippingMethodsEvent $event) {
                $plugin = Plugin::getInstance();

                if (!$plugin->getSettings()->liveRatesEnabled) {
                    return;
                }

                // Checkout fails open: a cache outage or a malformed quote must leave the store's
                // own methods in place, never 500 the cart.
                try {
                    $methods = $event->getShippingMethods();

                    foreach ($plugin->getRates()->getRatesForOrder($event->order) as $rate) {
                        $methods->push(new LiveRateShippingMethod([
                            'rate' => $rate,
                            'storeId' => $event->order->storeId,
                        ]));
                    }

                    $event->setShippingMethods($methods);
                } catch (\Throwable $e) {
                    Craft::warning('Shipper live rates skipped: ' . $e->getMessage(), __METHOD__);
                }
            }
        );
    }
}
