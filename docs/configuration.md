---
title: Configuration
slug: configuration
order: 20
summary: Connecting ShipStation, every setting on the settings screen, environment variables, status mapping and the Pro API keys.
---

## Connecting ShipStation

ShipStation talks to Shipper through a **Custom Store**. ShipStation polls one URL on your site
for orders, and posts to the same URL when a label is bought.

1. In Craft, open **Shipper → Settings**. Copy the **URL to Custom XML Page** from the
   **Connection** section. It's Craft's action URL for `shipper/api/process`, so it looks like
   `https://example.com/actions/shipper/api/process` or
   `https://example.com/index.php?p=actions/shipper/api/process`, depending on your
   `omitScriptNameInUrls` setting.
2. Set a **Username** and **Password**, and save.
3. In ShipStation, add a new store and choose **Custom Store**.
4. Paste the URL, and enter the same username and password.
5. Map ShipStation's order statuses to the status strings Shipper sends. By default these are
   your Commerce order status handles: `new`, `processing`, `shipped`, and so on. See
   [Status mapping](#status-mapping).
6. Save the store in ShipStation and let it test the connection.

ShipStation calls the URL with `?action=export` to pull orders and `?action=shipnotify` to report
shipments. You don't need to add either. ShipStation appends them itself.

### Username and password, or an auth key

ShipStation sends the username and password as HTTP Basic auth, in the `Authorization` header.
Many Apache setups strip that header before PHP sees it. When that happens, every request fails
with a `401` and nothing in ShipStation tells you why.

For that case, Shipper also accepts an **Auth key** as a query parameter:

1. Press **Generate a key** under **Auth key**. A random key is put into the field. It isn't
   applied until you save.
2. Save the settings. The **URL to Custom XML Page** now ends in `?auth_key=…`.
3. Paste that full URL into ShipStation. ShipStation still requires a username and password in
   its form, so enter the ones you set.

Shipper accepts a request that passes either check. A correct `auth_key` gets in even if the
`Authorization` header was stripped. Both comparisons are constant-time.

**Prefer HTTP Basic wherever your server passes the `Authorization` header through.** Use the auth
key only as a fallback. A query parameter is part of the URL, and URLs end up in places headers
don't: your web server's access log, any proxy or CDN logs in front of it, and monitoring tools.
Shipper masks the key in its own connection log (`auth_key=***`), but it can't do that for your
web server. If you do use an auth key, treat the full URL as a secret. Anyone who has it can read
your orders.

To fix Apache instead, see [Troubleshooting](troubleshooting#401-invalid-shipstation-credentials).

## Settings

**Shipper → Settings** is for admins only, and only appears where `allowAdminChanges` is on.
Sections are listed as they appear on the screen. On Lite, Pro settings are either greyed out or
hidden.

### Connection

| Setting | Config key | Default | What it does |
|---|---|---|---|
| URL to Custom XML Page | — | — | Read-only. The URL to paste into ShipStation. Includes `?auth_key=…` once an auth key is saved |
| Username | `username` | blank | The HTTP Basic username ShipStation sends. Accepts an environment variable |
| Password | `password` | blank | The matching password. Accepts an environment variable |
| Auth key | `authKey` | blank | Optional. Accepted as an `auth_key` query parameter in place of HTTP Basic. Accepts an environment variable |

If none of the three are set, a warning appears under the URL and the endpoint rejects every
request.

### Export

| Setting | Config key | Default | What it does |
|---|---|---|---|
| Export order statuses | `exportStatusHandles` | none checked | Which Commerce order statuses ShipStation sees. With nothing checked, every completed order is exported |
| Only completed orders | `exportOnlyCompleted` | on | Keeps live carts out of ShipStation. Turning it off exports abandoned carts too |
| Order number | `orderNumberSource` | Order reference | What ShipStation shows as the order number: `reference`, `number` (the long hash), `shortNumber` (first 7 characters) or `id`. An order with no reference falls back to its number |
| Orders per page | `pageSize` | `100` | How many orders go in each page of the export, 1–500. Lower it if ShipStation times out on large windows |
| Send product images | `includeProductImages` | on | Sends the first image found in any Assets field on the variant, or failing that the product. The asset needs a public URL |
| Image transform | `imageTransform` | blank | Optional named transform handle for those images |
| Phone field handle | `phoneFieldHandle` | blank | Craft 5 addresses have no phone attribute. If you added a custom field for one, give its handle here and it's sent as the bill-to and ship-to phone |
| Send discounts as a line | `includeDiscountLine` | on | Adds a **Total Discount** adjustment line so ShipStation's order total matches Craft's |

**How many orders match?** counts the orders that match these settings right now, ignoring the
date window. Save your changes first: it counts with the saved settings, not what's on screen.

#### Custom fields (Pro)

ShipStation has three free-text fields per order. On Pro, each is an object template rendered
against the order. `object` and `order` both refer to it.

| Setting | Config key | Default |
|---|---|---|
| CustomField1 | `customField1` | `{{ object.couponCode }}` |
| CustomField2 | `customField2` | blank |
| CustomField3 | `customField3` | blank |

A blank template leaves the field out. If a template throws an error, that field is sent empty
and a warning is written to Craft's log. The rest of the export carries on.

```twig
{{ order.email }}
{{ order.paymentCurrency }} / {{ order.customer.fullName ?? '' }}
{{ order.giftMessage ?? '' }}
```

On Lite, `CustomField1` is always the coupon code and the other two are not sent.

### Shipment notifications

| Setting | Config key | Default | What it does |
|---|---|---|---|
| Shipped order status | `shippedStatusHandle` | — | Where an order goes once everything on it has shipped. Leave it blank and orders are never moved |
| Update the order status | `updateStatusOnShipment` | on | Turn off to record tracking without moving the order, so no status emails are sent |
| Leave a note on the order | `addOrderHistoryNote` | on | Records the carrier, tracking number and items in the order history when the status does *not* change |
| Partial shipments (Pro) | `partialShipmentsEnabled` | on | Counts the items in each shipment and only moves the order to the shipped status once every item has shipped. Off, or on Lite, the first notification completes the order |
| Partly shipped status (Pro) | `partiallyShippedStatusHandle` | — | Optional status for an order with some, but not all, items shipped |

When a shipment does change the status, the same note becomes the message on that status change.
Commerce sends that status's emails as usual. See [Usage](usage#order-history-notes).

### Status mapping (Pro)

See [Status mapping](#status-mapping) below. Config key: `statusMap`.

### ShipStation API (Pro)

See [ShipStation API](#shipstation-api-pro) below.

| Setting | Config key | Default | What it does |
|---|---|---|---|
| API key (v2) | `apiKey` | blank | Used for live rates and listing carriers. Accepts an environment variable |
| Legacy API key (v1) | `legacyApiKey` | blank | Used only for **Sync now**. Accepts an environment variable |
| Legacy API secret (v1) | `legacyApiSecret` | blank | The matching secret. Accepts an environment variable |
| ShipStation store ID | `shipStationStoreId` | blank | Which store **Sync now** refreshes. Blank refreshes every refreshable store on the account |

**Test connection** checks the v2 key by listing your carriers.

### Live rates at checkout (Pro)

| Setting | Config key | Default | What it does |
|---|---|---|---|
| Enable live rates | `liveRatesEnabled` | off | Offers ShipStation's carrier rates as Commerce shipping methods |
| Carriers | `rateCarrierIds` | empty | ShipStation carrier IDs to quote, such as `se-123456`. Empty quotes every connected carrier |
| Services | `rateServiceCodes` | empty | Service codes to keep, such as `usps_priority_mail`. Empty offers everything quoted |
| Ship from | `shipFrom` | blank | The origin address carriers quote from: name, company, phone, address 1 and 2, city, state / province, postal code, country code. Country defaults to `US` |
| Markup | `rateMarkupType` | None | `none`, `percent` or `flat` |
| Markup amount | `rateMarkupAmount` | `0` | Percentage or flat amount added to each rate's shipping charge |
| Default item weight | `defaultItemWeight` | `0` | Weight assumed for a line item with none, in your store's weight units |
| Package code | `packageCode` | `package` | ShipStation package code for the parcel |
| Quote cache (seconds) | `rateCacheDuration` | `300` | How long an identical cart reuses its quote. `0` turns caching off |
| Rate timeout (seconds) | `rateTimeout` | `8` | How long checkout waits on ShipStation before carrying on without live rates, 1–60 |

`php craft shipper/sync/carriers` lists your carrier IDs. **Quote the most recent cart** runs a
real quote against the most recently updated cart and shows the result next to the button.

### Logging

| Setting | Config key | Default | What it does |
|---|---|---|---|
| Log connections | `loggingEnabled` | on | Records every request ShipStation makes, and every call Shipper makes to ShipStation |
| Keep payloads (Pro) | `logPayloads` | on | Stores request and response bodies, truncated at 64 KB each. These can contain customer names and addresses |
| Keep for (days) | `logRetentionDays` | `30` | How long entries are kept. `0` keeps everything. On Lite this is fixed at 7 days |

Retention is enforced during Craft's garbage collection, so you don't need a cron job for it.
`php craft shipper/log/prune` and the **Prune old entries** button on the log screen run the same
pruning on demand.

## Environment variables

**Username**, **Password**, **Auth key**, **API key (v2)**, **Legacy API key (v1)** and **Legacy
API secret (v1)** all accept environment variables. Plugin settings are saved to project config,
so use them. Otherwise your ShipStation credentials end up in `config/project/` and in version
control.

```sh
# .env
SHIPPER_USERNAME="shipstation"
SHIPPER_PASSWORD="a-long-random-string"
SHIPPER_AUTH_KEY=""
SHIPSTATION_API_KEY="…"
SHIPSTATION_V1_KEY="…"
SHIPSTATION_V1_SECRET="…"
```

Then type `$SHIPPER_USERNAME` and so on into the fields. They autosuggest.

## The config file

Any setting can be fixed in `config/shipper.php`, using the config keys above. Values in the file
override whatever is saved on the settings screen. That's Craft's standard behaviour for plugin
settings.

```php
<?php

use craft\helpers\App;

return [
    'username' => App::env('SHIPPER_USERNAME'),
    'password' => App::env('SHIPPER_PASSWORD'),
    'exportStatusHandles' => ['processing'],
    'shippedStatusHandle' => 'shipped',
    'partiallyShippedStatusHandle' => 'partlyShipped',
    'rateCarrierIds' => ['se-123456', 'se-234567'],
    'rateServiceCodes' => ['usps_priority_mail', 'ups_ground'],
    'shipFrom' => [
        'name' => 'Warehouse',
        'address1' => '1 Main St',
        'city' => 'Portland',
        'state' => 'OR',
        'postalCode' => '97201',
        'countryCode' => 'US',
    ],
    'logRetentionDays' => 14,
];
```

`rateCarrierIds` and `rateServiceCodes` take a plain array, or a comma- or space-separated string.

## Status mapping

Each exported order carries an `<OrderStatus>`. ShipStation uses it to decide which of its own
statuses the order goes into, through the status mapping on the Custom Store.

**By default** (and always on Lite), Shipper sends the Commerce status handle unchanged:
`new`, `processing`, `shipped`. In ShipStation's store settings, enter those handles against
**Awaiting Payment**, **Awaiting Shipment**, **Shipped** and the rest.

**On Pro**, you can do the mapping in Craft instead. The **Status mapping** section has one
checkbox list per ShipStation status:

| ShipStation status | String sent |
|---|---|
| Awaiting payment | `awaiting_payment` |
| Awaiting shipment | `awaiting_shipment` |
| On hold | `on_hold` |
| Shipped | `shipped` |
| Cancelled | `cancelled` |

Tick the Commerce statuses that belong to each one. An order in a ticked status is sent with the
string on the right. An order in an unticked status still goes through as its plain handle. Then
enter those strings in ShipStation's mapping. Tick each Commerce status under one ShipStation
status only. If a status is ticked twice, the first match in the order awaiting payment, awaiting
shipment, shipped, on hold, cancelled wins.

The status map only controls what Shipper *sends*. What happens to an order when ShipStation
reports a shipment is set by **Shipped order status** and **Partly shipped status**.

## ShipStation API (Pro)

Shipper uses two ShipStation REST APIs, for two different jobs:

- **API key (v2)** calls `api.shipstation.com/v2` with an `API-Key` header. It quotes live rates
  and lists carriers. Get it from **ShipStation → Account → API Settings**.
- **Legacy API key and secret (v1)** call `ssapi.shipstation.com` with Basic auth. They're used
  for one call only, `POST /stores/refreshstore`, which is behind **Sync now**. The v2 API can't
  trigger a store refresh.

Neither key is needed for the Custom Store connection itself. Orders reach ShipStation only
through the endpoint. Shipper never creates orders in ShipStation through the API, so nothing is
duplicated.

**ShipStation store ID** limits **Sync now** to one store. Leave it blank to refresh every store
on the account that can be refreshed.

## Permissions

| Permission | What it allows |
|---|---|
| **View shipments** | The **Shipper → Shipments** screen, shipment detail pages, and the ShipStation panel on Commerce's order screen. **Preview XML** also needs permission to view that order in Commerce, because the preview contains the customer's addresses, email and phone |
| ↳ **Add and delete shipments** | Deleting a shipment, and recording one by hand |
| **View the connection log** | **Shipper → Log** and its entries, read-only (Pro) |
| ↳ **Clear and prune the connection log** | The **Prune old entries** button, and clearing the log (Pro) |
| **Trigger a ShipStation sync** | The **Sync now** button (Pro) |

The settings screen is for admins only.
