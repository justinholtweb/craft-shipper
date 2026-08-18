# Shipper — Craft CMS 5 Plugin

## Project Overview

Shipper connects Craft Commerce 5 to ShipStation through a **Custom Store**: ShipStation polls an
endpoint on the site for orders, and posts back a shipment notification when a label is bought.
Distributed as `justinholtweb/craft-shipper`. **Lite (free) + Pro ($99).**

## Why it exists

`fostercommerce/shipstationconnect` ($59) already does the custom-store handshake. Shipper's edge
is everything around it: no content modelling (Foster makes you hand-build a Matrix field and type
six handles into settings), a real connection log, partial shipments, two-way status mapping, an
`auth_key` fallback for Apache installs that eat the `Authorization` header, live checkout rates,
console commands, and a front-end tracking API.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks

## Architecture

### Namespace & package

- Namespace: `justinholtweb\shipper`
- Package: `justinholtweb/craft-shipper`
- Handle: `shipper`

### The two invariants

1. **`services\Export::buildOrderElement()` is the only place an order becomes ShipStation XML.**
   The endpoint, `shipper/export/preview` and the CP "Preview XML" action all go through it, so a
   preview is byte-identical to what ShipStation receives.
2. **`services\Shipments::record()` is the only place a shipment row is created.** shipnotify, the
   CP manual add and any API sync all land there, so idempotency, item counting and the
   fully-shipped decision are made once and cannot disagree.

### Data model

- `{{%shipper_shipments}}` — unique on `(orderId, shipmentKey)`; that index *is* the idempotency
  guarantee.
- `{{%shipper_orderstate}}` — per-order export count and shipped quantity.
- `{{%shipper_log}}` — the connection log.

Rate quotes live in Craft's cache keyed on a **cart signature**, not the order number: memoizing
on the number would answer a recalculated cart from a stale quote.

### Protocol notes (verified, not guessed)

The Custom Store contract was read out of two independent implementations that agree —
WooCommerce's `woocommerce-shipstation-integration` 5.3.3 and Foster's `OrdersController`/`Xml`.
ShipStation's own help centre 403s bots.

- Dates both ways are **UTC, `MM/dd/yyyy HH:mm`**. ShipStation also emits a compact
  `MMDDYYYYxHHMM` form that `strtotime()` cannot read — `helpers\Xml::parseDate()` handles both.
- Free text is CDATA; numerics, dates and enumerations are plain text nodes. Mixing them up is the
  usual cause of a store that imports orders with empty fields.
- `WeightUnits` ∈ `Pounds|Ounces|Grams`. The v2 REST API uses a *different* vocabulary
  (`pound|ounce|gram|kilogram`, `inch|centimeter`) — `helpers\Units` keeps the two apart.
- **ShipStation sends no notification ID**, and retries hourly. The shipment key is
  `trackingNumber|lower(carrier)`; a label-less shipment falls back to a hash of its contents.
- The shipment row is committed **before** the order is saved. The save fires status emails and
  every third-party handler on them; if one fatals, the retry must not land as a second shipment.

### REST API split (Pro)

- **v2** (`api.shipstation.com/v2`, `API-Key` header) — rates and carriers.
- **v1** (`ssapi.shipstation.com`, Basic auth) — only `POST /stores/refreshstore`. That is
  deliberate: orders reach ShipStation through the custom store, so "Sync now" makes ShipStation
  *pull*. Pushing via `createorder` would give the merchant two of everything.

## Traps found while building this

- **`_includes/statuses` does not exist in Craft 5.** Importing it from a plugin template throws a
  `TemplateLoaderException` and 500s the settings screen.
- **Commerce refuses an address element it does not own** — `setShippingAddress()` throws "Can not
  set a shipping address on the order that is not owned by the order". Pass an *array* of
  attributes and let Commerce build the owned element.
- **Craft only writes an order history (and sends the status email) when the status actually
  changes** — `Order::_saveOrderHistory()` early-returns otherwise. A tracking note that must not
  masquerade as a status change has to be written straight to `OrderHistories::saveOrderHistory()`.
- **A private property is not a Yii attribute**, so a setting backed by a getter/setter pair is
  never persisted unless `attributes()` is overridden to name it. Needed for the editable-table
  rate lists, which post `[['value' => …], …]` rather than a flat list.
- **`craft\console\Request` has no `getUserIP()`-worthy client**, so anything that may run from a
  console command has to type-check before reaching for web-only request methods.
- **Craft plugin console commands are not reachable via `craft help <handle>`** — they are listed
  under `craft help` and run as `shipper/export/count`.
- **`plugin/switch-edition` is not a Craft console command.** Switching editions from a script
  means `Plugins::switchEdition()` plus `ProjectConfig::saveModifiedConfigData()`.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps, and
`[[project_craft_freeride]]` for the sibling Commerce plugin whose conventions this follows.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-shipper/tests/integration/checks.php   # 119 checks
ddev exec bash -c 'find /var/www/craft-shipper/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The suite switches the plugin to Pro for the bulk of the run, exercises the Lite behaviour in its
own section, and restores the original edition and settings in a `finally` along with every
fixture it created. It includes **live HTTP round-trips** against the real endpoint — auth
rejection, export, shipnotify, retry dedup and XXE — so a green run means the wire protocol works,
not just the units.

**Harness note:** `craft-penny` registers an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed
`ModelEvent` while Craft passes an `ElementEvent`, so **every element save in the harness fatals**
while it is enabled. `checks.php` detaches that handler in-process (never persisted) so fixtures
can be created. That is a real bug in Penny, not in Shipper.

## Coding conventions

- `Craft::t('shipper', '…')` for user-facing strings; `src/translations/en/shipper.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Anything that runs during checkout fails **open**: a ShipStation outage must never be able to
  stop a customer paying
