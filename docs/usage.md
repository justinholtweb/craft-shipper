---
title: Usage
slug: usage
order: 30
summary: How orders are exported, how shipments come back, partial shipments, the log, the CP screens, live rates, Sync now and the console.
---

## How export works

ShipStation polls your **URL to Custom XML Page** on its own schedule, and whenever you ask it to
update the store. Each poll is a `GET` with `action=export`, a `start_date` and `end_date` window
in UTC, and a `page` number.

Shipper answers with the orders whose **last-updated date** falls inside that window and that
match your export settings:

- completed orders only, unless **Only completed orders** is off
- only the statuses ticked under **Export order statuses**, or every status if none are ticked

Results are sorted oldest-updated first and split into pages of **Orders per page**. The response
tells ShipStation how many pages there are, and ShipStation asks for each in turn.

Each order carries its number, ID, dates, status, payment gateway handle, shipping method, currency,
totals, tax and shipping cost, customer note, internal notes, custom fields, the customer's email,
the bill-to and ship-to addresses, and one item per shippable line item. Each item has its SKU,
description, image, weight, quantity, unit price and options. Dates are sent in UTC.

A few rules decide what goes into each order:

- **Non-shippable line items are left out.** An order with nothing shippable on it, such as a
  digital-only order, is skipped entirely. ShipStation would reject an order with no items.
- **Discounts** go across as one **Total Discount** adjustment line (SKU `total-discount`), so the
  totals reconcile. Item prices are sent before discount. Turn this off with **Send discounts as a
  line**.
- **Weights** are converted from your Commerce weight unit. Gram and kilogram stores send grams,
  ounce stores send ounces, and everything else sends pounds.
- **Dimensions** are sent only when the order is one unit of one product. For anything else
  ShipStation would size the box off the wrong item.
- **Internal notes** are the first 20 messages from the order's history, joined with `|`.

Because the window is on the last-updated date, an order is sent again whenever it changes. A
status change in Commerce reaches ShipStation on its next poll. ShipStation matches orders it
already has by order number, so a re-sent order updates rather than duplicates.

Every time an order is served, Shipper counts it. The ShipStation panel on the order screen shows
**Sent to ShipStation 3 times, most recently …**, or **Not yet picked up by ShipStation.**

## Shipment notifications

When a label is bought, ShipStation posts to the same URL with `action=shipnotify`, the order
number, carrier, service and tracking number. The XML body carries the ship date and, usually,
the items in the shipment.

Shipper:

1. Finds the order. It uses the `OrderID` in the posted XML if there is one, then tries the order
   number as a reference, number, short number and ID. Changing **Order number** while orders are
   in flight doesn't strand them.
2. Records the shipment against the order: carrier, service, tracking number, ship date and items.
3. Adds the shipped items to the order's running count.
4. Decides whether the order is now fully shipped. See
   [Partial shipments](#partial-shipments-pro).
5. Moves the order to **Shipped order status**, or **Partly shipped status** on Pro, and records a
   note.
6. Answers `200`.

The shipment is saved before the order is. Saving the order sends status emails and runs every
other plugin's order-save handlers. If one of those fails, ShipStation's retry still finds the
shipment already recorded and doesn't add a second one.

Posted XML that contains a `DOCTYPE` is refused, and external entities are never loaded.

### Retries and duplicates

ShipStation retries a notification every hour until it gets a `200`, and sends no notification ID.
Shipper identifies each shipment by its **tracking number plus carrier**, ignoring case in the
carrier. A repeat of a shipment it already has gets a `200` and changes nothing. The log records it
as *Duplicate shipment for order … ignored*.

A shipment with no tracking number is identified by a hash of its carrier, service, ship date and
items instead, so an identical retry is still recognised.

The identity is shown as **Shipment key** on the shipment's detail page.

## Partial shipments (Pro)

With **Partial shipments** on, Shipper adds up the quantities in every shipment on an order and
compares them with the order's shippable quantity:

- **Some items shipped.** The order moves to **Partly shipped status**, if you set one. If you
  didn't, it stays where it is and gets a note.
- **Everything shipped.** The order moves to **Shipped order status**.

If ShipStation sends a shipment with no item list, Shipper reads that as "the whole order
shipped". That's what the Custom Store contract means by it.

On Lite, or with **Partial shipments** off, the first notification completes the order, however
many items it lists.

Because retries are recognised, an hourly re-send never counts the same items twice. It can't
push a partly shipped order over the line.

## Order history notes

Each recorded shipment produces a note like:

> T-Shirt (TEE-L) × 2 shipped via UPS on 2026-08-18 with tracking number 1Z999AA10123456784.

Or, when ShipStation sent no items:

> Shipped via UPS on 2026-08-18 with tracking number 1Z999AA10123456784.

Where the note goes depends on whether the order's status changes:

- **The status changes.** The note becomes the message on that status change. Commerce writes it
  to the order history and sends that status's emails as usual, so your shipped email can include
  `order.message`.
- **The status doesn't change.** For example, no shipped status is set, **Update the order status**
  is off, or the order is already in the target status. In that case, the note is written straight
  to the order history, by *ShipStation*, without changing the status. No status email is sent.
  This only happens when **Leave a note on the order** is on.

## Shipments in the control panel

### Shipper → Shipments

The 200 most recent shipments across all orders: order, carrier, service, tracking number (linked
to the carrier's tracking page where Shipper knows the carrier), ship date, item count and
source. The source is `shipnotify` for ShipStation and `manual` for one recorded by hand. Search
matches tracking number, carrier and service.

Open a shipment to see its items, its **Shipment key**, and the raw payload ShipStation posted.
Users with **Add and delete shipments** can delete it from there.

Deleting a shipment takes its items off the order's shipped count. It doesn't move the order's
status back. Change that in Commerce if you need to.

### The panel on the order screen

Commerce's order edit screen gets a **ShipStation** panel showing:

- every shipment on the order, with tracking links
- how many times the order has been sent to ShipStation, and when it was last sent
- **Preview XML**, which shows exactly what ShipStation receives for this order. It's built by
  the same code the endpoint uses, so the two can't differ. It needs permission to view the order
  as well as **View shipments**.
- **Sync now** (Pro), for users with **Trigger a ShipStation sync**

### Recording a shipment by hand

Shipper has a `shipper/shipments/add` action for labels bought outside ShipStation, or for a
notification that never arrived. It takes `orderId`, `carrier`, `service`, `trackingNumber` and
`shipDate`, needs **Add and delete shipments**, and goes through the same code as a ShipStation
notification. It's de-duplicated the same way and moves the order the same way.

There's no form for it in the control panel in this version. Post to it from your own CP template
or module with `Craft.sendActionRequest('POST', 'shipper/shipments/add', {data: {...}})`.

A shipment recorded this way has no item list. It counts as "the whole order shipped", and the
shipped-item count that `craft.shipper.progress()` reports is brought up to match.

## The connection log (Pro)

**Shipper → Log** lists every request ShipStation made to your endpoint and every call Shipper made
to ShipStation:

| Action | What it is |
|---|---|
| `export` | ShipStation pulled orders |
| `shipnotify` | ShipStation reported a shipment |
| `rates` | Shipper quoted live rates |
| `refresh` | Shipper asked ShipStation to re-import (**Sync now**) |
| `carriers` | Shipper listed carriers |

Each row has the time, HTTP status, how long it took and a summary, such as *Exported 12 of 12
orders (page 1 of 1)*. Filter by action or level (info, warning, error). Open a row to see the
caller's IP, the full request and the full response. An auth key in a logged URL is shown as
`auth_key=***`. Request and response bodies are stored only while **Keep payloads** is on, and are
cut off at 64 KB.

When the endpoint itself fails with a `500`, ShipStation only gets a generic *Export failed.* or
*Could not record the shipment.*. The underlying error is in the log entry's message.

Lite writes the same summaries, without payloads, and keeps them for a week. There's no screen for
them. Read them with `php craft shipper/log/list`.

## Live rates at checkout (Pro)

With **Enable live rates** on and an **API key (v2)** set, every cart is quoted through
ShipStation's rates API. Each rate is offered as its own Commerce shipping method, alongside the
shipping methods you've set up yourself.

- **What's quoted.** The whole cart goes as one parcel. Its weight is the sum of the shippable line
  items, using **Default item weight** for any item with no weight. The parcel goes from your
  **Ship from** address to the cart's shipping address. A cart with no shipping address, or nothing
  shippable, isn't quoted. If the total weight comes to zero, a nominal weight is sent so carriers
  still quote, and you correct it when you buy the label.
- **What's offered.** Rates ShipStation flags as errors, or quotes at zero, are dropped. The rest
  are sorted cheapest first. Each is named after the carrier and service, such as *UPS Ground*,
  with a description like *UPS Ground — about 3 business days*. The price is ShipStation's
  shipping amount plus surcharges. **Markup** is added to the shipping amount.
- **Filtering.** **Carriers** and **Services** narrow what's quoted. Empty means everything.
- **Caching.** A quote is cached for **Quote cache (seconds)**, keyed on what can change it: store,
  currency, address, and each line's SKU, quantity and weight. Re-costing the same cart is free.
  Changing the cart or the address quotes again.
- **Completed orders are never re-quoted**, so a paid order's shipping can't change after the fact.

### It fails open

If ShipStation is slow, unreachable, or returns an error, the cart gets no live rates and checkout
carries on with your own shipping methods. Shipper waits **Rate timeout (seconds)** at most. A
ShipStation outage can't stop a customer from paying. Failures are logged under `rates`.

Keep at least one ordinary Commerce shipping method that fits every cart. If you don't, a customer
whose quote fails will have nothing to choose.

## Sync now (Pro)

**Sync now** asks ShipStation to re-import from your custom store immediately, instead of waiting
for its next poll. You'll find it on **Shipper → Shipments** and on the order screen's panel. It
calls the v1 API's `stores/refreshstore` endpoint, so it needs the **Legacy API key (v1)** and
**secret**, plus the **Trigger a ShipStation sync** permission.

It doesn't push orders. ShipStation still pulls them from the endpoint, so the result is the same
as a normal poll, just sooner.

## Console commands

| Command | Edition | What it does |
|---|---|---|
| `shipper/export/preview` | Lite | Prints the XML ShipStation would be served for the last 7 days. `--start`, `--end` and `--page` change the window. `--order=1042` prints one order instead, by reference, number or ID |
| `shipper/export/count` | Lite | How many orders match the export settings. Takes `--start` and `--end` |
| `shipper/export/list` | Lite | Up to 200 matching orders, one per line, with status, last-updated date and export count. Takes `--start` and `--end` |
| `shipper/log/list` | Lite | The 50 most recent log entries. Filter with `--action_filter=export` and `--level=error` |
| `shipper/log/prune` | Lite | Deletes entries older than the retention setting, or `--days=N` |
| `shipper/log/clear` | Lite | Deletes the whole log, after asking |
| `shipper/sync/test` | Lite | Checks the **API key (v2)** by listing carriers |
| `shipper/sync/carriers` | Lite | Lists the carriers on the ShipStation account, with the IDs **Carriers** expects |
| `shipper/rates/quote` | Pro | Quotes the most recent cart through the checkout code. `--order=` picks another. `--verbose` prints the request payload |
| `shipper/sync/refresh` | Pro | Same as **Sync now**. `--store=` refreshes one ShipStation store |

`--start` and `--end` take anything PHP's date parser understands: `"-3 days"`, `"2026-08-18
14:00"`.

```sh
php craft shipper/export/preview --order=1042
php craft shipper/export/count --start="-30 days"
php craft shipper/rates/quote --verbose
php craft shipper/log/list --level=error
```

The two `shipper/sync/…` checks run on Lite, but the **API key (v2)** they use can only be set
on the settings screen on Pro, or in `config/shipper.php`.
