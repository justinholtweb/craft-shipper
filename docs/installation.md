---
title: Installation
slug: installation
order: 10
summary: Requirements, install, the two editions, and upgrading from Lite to Pro.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later, with the `dom`, `simplexml` and `json` extensions
- A ShipStation account

No build step, and no runtime dependencies beyond Craft and Commerce.

## Install

```sh
composer require justinholtweb/craft-shipper
php craft plugin/install shipper
```

Or find **Shipper** in the Craft Plugin Store and install it from there.

Installing creates three tables: shipments, per-order sync state, and the connection log. It adds
no fields, entry types or Matrix blocks, so you don't need to build any content model.

Shipper needs Commerce installed and enabled. If Commerce is missing or disabled, the settings
screen says so, and the ShipStation endpoint answers every request with a `503`.

## Nothing is exposed until you set credentials

A fresh install has no username, password or auth key. While none are set, the endpoint rejects
every request with a `401`. Your order book is never public, not even for the minutes between
installing and configuring.

## Your first ten minutes

1. Open **Settings → Plugins → Shipper** (or **Shipper → Settings**).
2. Under **Connection**, set a **Username** and **Password**. These belong to the endpoint only.
   They are not Craft users.
3. Pick a **Shipped order status** under **Shipment notifications**. Until you do, shipments are
   recorded but orders don't move.
4. Press **How many orders match?** under **Export**. If it says zero, your status filter excludes
   everything.
5. In ShipStation, add a **Custom Store**. Paste in the **URL to Custom XML Page** and the same
   username and password. See [Configuration](configuration#connecting-shipstation).
6. Ask ShipStation to update the store. The order panel on Commerce's order screen should now
   say **Sent to ShipStation 1 times**.

If ShipStation reports a connection error, read [Troubleshooting](troubleshooting) before
changing anything. On Apache, the fix is usually the auth key.

## Editions

Lite is free. Pro is a one-off $99 with a $79/year renewal. It adds the connection log, partial
shipments, status mapping, the ShipStation REST API and live checkout rates.

| | Lite | Pro |
|---|---|---|
| **Price** | **Free** | **$99**, $79/year renewal |
| Custom Store endpoint: order export and shipment notifications | ✅ | ✅ |
| HTTP Basic or `auth_key` authentication | ✅ | ✅ |
| Shipments stored as data, with a CP index | ✅ | ✅ |
| Panel on Commerce's order screen, with **Preview XML** | ✅ | ✅ |
| Tracking links for 20+ carriers | ✅ | ✅ |
| Shipped order status, and a note in the order history | ✅ | ✅ |
| `craft.shipper.*` Twig API | ✅ | ✅ |
| Coupon code sent as `CustomField1` | ✅ | ✅ |
| Console: export preview, count and list; log list and prune | ✅ | ✅ |
| **Connection log** screen, with request and response payloads | — | ✅ |
| **Partial shipments**: items counted, order completes when all have shipped | — | ✅ |
| **Partly shipped status** | — | ✅ |
| **Status mapping**: Commerce statuses sent as ShipStation's own | — | ✅ |
| **Custom field templates** for `CustomField1`–`3` | — | ✅ |
| **Sync now**: ask ShipStation to re-import immediately | — | ✅ |
| **Live carrier rates** at checkout | — | ✅ |
| Console: `shipper/rates/quote`, `shipper/sync/refresh` | — | ✅ |

Lite still writes a connection log, but only the one-line summary of each request, not its
payloads. It keeps a week of it. There is no screen for it in Lite. Read it with
`php craft shipper/log/list`.

## Upgrading from Lite to Pro

Buy Pro in the Plugin Store, or switch the edition in **Settings → Plugins** and add the licence
key there. There's nothing to migrate: Pro uses the tables Lite already created. Every shipment
recorded on Lite is still there.

After switching:

- **Partial shipments** is on by default. From the next notification onwards, an order only
  reaches the **Shipped order status** once every shippable item has been reported shipped.
  Turn it off under **Shipment notifications** if you want the first notification to complete
  the order, as Lite does.
- The **Status mapping** and **Custom fields** sections appear on the settings screen. Both start
  empty, apart from `CustomField1`, which defaults to the coupon code, the same as Lite.
- **Shipper → Log** appears in the CP nav, for users who have the **View the connection log**
  permission.
- Fill in the **ShipStation API** keys if you want **Sync now** or live rates. See
  [Configuration](configuration#shipstation-api-pro).

The edition is stored in project config. If a deploy re-applies a `project.yaml` that still says
Lite, the Pro features stop working without any error. Commit the edition change with the rest of
your config.

## Uninstalling

Uninstalling drops all three tables. Recorded shipments, export history and the log go with them.
Orders in Commerce, and the order history notes Shipper left on them, are not touched.
