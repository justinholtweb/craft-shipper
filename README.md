# Shipper

ShipStation integration for Craft Commerce 5. Orders go out, tracking comes back, and every
request either end makes is on a screen you can read.

Shipper connects Commerce to a ShipStation **Custom Store**: ShipStation polls an endpoint on your
site for orders, and posts back a shipment notification when a label is bought. Shipper stores
those shipments as **its own data** — there is no field to build, no Matrix to configure, no
handles to type into settings. Install it, paste a URL into ShipStation, done.

## Requirements

- Craft CMS 5.3+
- Craft Commerce 5.0+
- PHP 8.2+

## Installation

```sh
composer require justinholtweb/craft-shipper
php craft plugin/install shipper
```

Then open **Settings → Plugins → Shipper**, set a username and password, and copy the
**URL to Custom XML Page** into ShipStation's custom store setup.

## Editions

| | Lite (free) | Pro ($99, then $79/year) |
|---|---|---|
| Custom Store export + shipment notifications | ✅ | ✅ |
| Shipments stored as data, with a CP index | ✅ | ✅ |
| Tracking links for 20+ carriers | ✅ | ✅ |
| Panel on Commerce's order screen | ✅ | ✅ |
| `craft.shipper.*` Twig API | ✅ | ✅ |
| Shipped-status mapping | ✅ | ✅ |
| Console commands (export, log, sync test) | ✅ | ✅ |
| `rates/quote` and `sync/refresh` commands | — | ✅ |
| **Connection log** with request/response payloads | — | ✅ |
| **Partial shipments** — per-item counting | — | ✅ |
| **Status mapping** — Commerce statuses to ShipStation's, plus a partly-shipped status | — | ✅ |
| **Custom field** object templates | — | ✅ |
| **Sync now** — force a ShipStation re-import | — | ✅ |
| **Live carrier rates** at checkout | — | ✅ |

## How it connects

ShipStation drives the conversation. It calls one endpoint with an `action`:

- `?action=export&start_date=&end_date=&page=` — Shipper answers with the orders XML.
- `?action=shipnotify&order_number=&carrier=&service=&tracking_number=` — Shipper records the
  shipment and moves the order on.

Authentication is HTTP Basic, which is what ShipStation's store settings offer. Apache commonly
strips the `Authorization` header, so Shipper **also** accepts an `auth_key` query parameter —
append it to the URL and the connection works regardless of what the server does with headers.

### What ShipStation is told

Order number and ID, dates, status, payment method, shipping method, currency, totals, tax,
shipping cost, customer notes, internal notes, custom fields, the customer with bill-to and ship-to
addresses, and every shippable line item with SKU, name, image, weight, quantity, unit price and
its options. Cart-level discounts go across as an adjustment line so the totals reconcile. A single
unit of a single product also carries its dimensions.

Non-shippable purchasables are left out, and an order with nothing shippable is skipped rather than
sent and rejected.

## Partial shipments

ShipStation sends the items in each shipment. Pro counts them, and only completes the order once
every shippable unit has gone. Until then the order can sit in a "partly shipped" status of your
choosing.

Retries are handled properly: ShipStation re-sends a notification hourly and includes no
notification ID, so Shipper keys a shipment on its tracking number plus carrier. A retry — even one
that differs only in carrier casing — is recognised and ignored rather than counted twice.

## Live rates (Pro)

With a ShipStation API key, Shipper offers real carrier rates as Commerce shipping methods. Quotes
are cached against a signature of the cart, so re-costing the same cart is free and changing it
re-quotes.

If ShipStation is slow or unreachable, **no live methods appear and checkout carries on** with
whatever shipping methods your store defines. A carrier outage can never stop a customer paying.

## Twig

```twig
{% for shipment in craft.shipper.shipments(order) %}
    {{ shipment.getLabel() }}
    {% set url = craft.shipper.trackingUrl(shipment.carrier, shipment.trackingNumber) %}
    {% if url %}<a href="{{ url }}">{{ shipment.trackingNumber }}</a>{% endif %}
{% endfor %}

{% set progress = craft.shipper.progress(order) %}
{{ progress.shipped }} of {{ progress.total }} items shipped
```

`craft.shipper.latestShipment(order)`, `craft.shipper.isShipped(order)` and
`craft.shipper.knownCarriers()` are available too.

## Console

```sh
php craft shipper/export/preview            # the exact XML ShipStation would be served
php craft shipper/export/preview --order=1042
php craft shipper/export/count              # how many orders match your settings
php craft shipper/export/list
php craft shipper/rates/quote --verbose     # quote a cart through the checkout code path
php craft shipper/sync/refresh              # ask ShipStation to re-import now
php craft shipper/sync/test
php craft shipper/sync/carriers
php craft shipper/log/list
php craft shipper/log/prune                 # also runs on Craft's garbage collection
```

`shipper/export/preview` runs the same builder the endpoint does, so a payload that looks right
there cannot differ in production.

## Debugging a quiet store

If ShipStation says it imported nothing, open **Shipper → Log** on Pro, or run
`php craft shipper/log/list` on Lite. Every request it made is there with its status code and
timing, and on Pro the full payload both ways. A 401 means the
credentials do not match; an empty export means your status filter excludes everything, which
**How many orders match?** on the settings screen will confirm in one click.

## License

Proprietary. See `LICENSE.md`.
