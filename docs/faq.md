---
title: FAQ
slug: faq
order: 60
summary: Common questions about connecting Craft Commerce to ShipStation with Shipper.
---

### What does Shipper do?

It connects Craft Commerce 5 to ShipStation through a Custom Store. ShipStation pulls your orders
from an endpoint on your site, and posts back a notification when a label is bought. Shipper
records that shipment, moves the order to your shipped status, and gives your templates the
tracking details. Pro adds a connection log, partial shipments, status mapping, Sync now and live
carrier rates at checkout.

### Which Craft, Commerce and PHP versions are supported?

Craft CMS 5.3+, Craft Commerce 5.0+ and PHP 8.2+.

### How much does it cost?

Lite is free. Pro is $99 for the first year, then $79 a year for updates. See
[Installation](installation#editions) for what each edition includes.

### What's the licence?

Shipper is licensed under the Craft License, the same terms as Craft's commercial plugins. Each
licence covers one production install. See `LICENSE.md`.

### What can't Lite do?

Lite does the whole Custom Store job: export, shipment notifications, shipments in the CP, tracking
links, the order-screen panel, the Twig API and most console commands. It doesn't have:

- the connection log screen. Lite keeps a week of one-line summaries, readable with
  `php craft shipper/log/list`, without request or response payloads.
- partial shipments. On Lite, the first notification for an order completes it.
- a partly shipped status, status mapping, or custom field templates. On Lite, `CustomField1` is
  always the coupon code.
- Sync now, live rates, `shipper/rates/quote` or `shipper/sync/refresh`.

### Do I need to build a field or Matrix block to store tracking numbers?

No. Shipper stores shipments in its own table. There's no field, Matrix block or entry type to set
up, and no field handles to type into settings. Install it, set a username and password, and paste
the URL into ShipStation.

### How is this different from other ShipStation connectors for Craft?

The Custom Store connection works the same way. The difference is what's built around it:

- No Matrix field or content modelling needed.
- A connection log that shows every request with its payloads (Pro).
- Partial shipments counted per item, with retries recognised so they can't complete an order early
  (Pro).
- An `auth_key` fallback for servers that strip the `Authorization` header.
- Live carrier rates at checkout (Pro).
- Console commands for previewing exactly what ShipStation receives.
- A front-end Twig API for order-status pages.

### Will installing it send my orders to ShipStation straight away?

No. Nothing reaches ShipStation until you add a Custom Store there with Shipper's URL and
credentials. Until you set credentials, the endpoint rejects every request.

### Does Shipper push orders to ShipStation?

No. ShipStation always pulls them from the endpoint. Even **Sync now** only asks ShipStation to
pull immediately. Orders never travel through two routes, so they're never duplicated.

### How often does ShipStation pick up new orders?

On ShipStation's own schedule, and whenever you update the store in ShipStation. On Pro, **Sync
now** triggers a pull immediately.

### Will customers get a shipped email?

Yes, if your shipped order status has an email attached. When a notification moves the order to
**Shipped order status**, Commerce sends that status's emails as usual. Turn **Update the order
status** off if you want tracking recorded without any emails.

### What happens when an order ships in several boxes?

On Pro with **Partial shipments** on, each box is recorded as its own shipment. The order moves to
**Partly shipped status** (if you set one) until every item has shipped, then to **Shipped order
status**. On Lite, the first box completes the order and later ones are recorded alongside it.

### ShipStation sent the same notification three times. Do I have three shipments?

No. ShipStation retries hourly, and Shipper recognises a repeat by its tracking number and carrier.
Repeats are acknowledged and ignored.

### Are digital products sent to ShipStation?

No. Non-shippable line items are left out, and an order with nothing shippable is skipped.

### Can live rates stop a customer checking out?

No. If ShipStation is slow or down, no live rates appear and checkout carries on with your own
shipping methods. Shipper waits **Rate timeout (seconds)** at most, 8 by default. Keep at least one
ordinary shipping method that fits every cart.

### Do live rates account for packing several boxes?

No. A cart is quoted as one parcel holding all its shippable items. You re-pack in ShipStation
when you buy the label.

### Does it work with several Commerce stores?

Shipments record the store their order belongs to, and statuses are looked up for the order's
store. The settings, including the credentials and the export filters, are shared across stores,
so all stores' orders come through one Custom Store.

### Can I keep my ShipStation credentials out of project config?

Yes. Every credential field accepts an environment variable, such as `$SHIPPER_PASSWORD`. See
[Configuration](configuration#environment-variables).

### Should I use the auth key or a username and password?

Use the username and password wherever your server passes the `Authorization` header through. The
auth key is a fallback for servers that strip that header. It works anywhere, but it travels in the
URL, so it ends up in web-server access logs.

### Can I show tracking on the customer's order page?

Yes. `craft.shipper.shipments(order)` gives you every shipment with its tracking link. See
[Templating](templating).

### What happens to my data if I uninstall?

Shipper's three tables are dropped: shipments, export history and the log. Your Commerce orders,
and the order history notes Shipper left on them, stay.
