---
title: Troubleshooting
slug: troubleshooting
order: 50
summary: Orders that never import, empty fields, 401s on Apache, duplicate shipments, date windows, missing live rates, and how to read the log.
---

## Start with the log

Most problems between Craft and ShipStation look the same from ShipStation's side: an empty store
and no explanation. Shipper records every request, so start there.

- **Pro:** open **Shipper → Log**. Filter to `export` or `shipnotify` and open the most recent
  entry. You'll see the status code, the full request URL including ShipStation's date window, and
  the full response.
- **Lite:** run `php craft shipper/log/list`. You get the last 50 entries with their status codes
  and summaries, but no payloads.

| Status | Meaning |
|---|---|
| `200` with *Exported 0 of 0 orders* | The connection works. No orders matched. See [Orders not importing](#orders-not-importing) |
| `401` | Credentials didn't match, or the `Authorization` header never arrived. See [401](#401-invalid-shipstation-credentials) |
| `400` | The request had no `action`, or one Shipper doesn't know. Something other than ShipStation is calling the URL, or the URL was pasted with extra text |
| `404` on `shipnotify` | No order matched the number ShipStation sent. See [Shipments not arriving](#shipments-not-arriving) |
| `500` | Shipper failed while building the export or recording a shipment. ShipStation only sees a generic message. The real error is in the log entry's **Message**, and in Craft's own logs |
| `503` | Commerce isn't installed or is disabled |

No entries at all means ShipStation's requests aren't reaching Craft. Check the URL in ShipStation
against **URL to Custom XML Page**, and look for a firewall, basic-auth gate or maintenance page in
front of the site.

## 401: invalid ShipStation credentials

First, confirm the username and password in ShipStation match Shipper's settings exactly. If
they're set from environment variables, check those variables in the environment ShipStation is
actually calling.

If they match and you still get a `401`, your server is almost certainly dropping the
`Authorization` header before PHP sees it. That's common on Apache with PHP running as CGI or
FastCGI. There are two fixes:

**Pass the header through.** This is the better fix, because the credentials stay out of the URL.
In Apache 2.4.13 or later, add this to `web/.htaccess` or your vhost:

```apache
CGIPassAuth On
```

On older Apache, use a rewrite rule instead:

```apache
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

**Or use an auth key.** Press **Generate a key**, save, and paste the new **URL to Custom XML
Page**, which now includes `?auth_key=…`, into ShipStation. That works whatever the server does
with headers. Bear in mind the key is then in every access log line for the endpoint. See
[Configuration](configuration#username-and-password-or-an-auth-key).

### Only one 401 in the log for many failed polls

That's expected. Anyone on the internet can call the endpoint, so rejected requests are logged at
most once a minute. Otherwise a script hammering the URL could fill your database. One `401` entry
can stand for many rejected polls.

### Every request is a 401, even with nothing obviously wrong

If no username and password and no auth key are set, the endpoint rejects everything. The settings
screen shows a warning under the URL when that's the case.

## Orders not importing

The connection works (the log shows `200`), but ShipStation imports nothing, or not the orders you
expected.

1. **Press How many orders match?** on the settings screen, or run `php craft shipper/export/count`.
   Zero means **Export order statuses** excludes everything, or **Only completed orders** is
   filtering out what you were expecting.
2. **Check the date window.** ShipStation only asks for orders updated within a window. Take the
   `start_date` and `end_date` from the log entry's request URL. They're UTC, `MM/dd/yyyy HH:mm`.
   Then count against the same window:

   ```sh
   php craft shipper/export/count --start="2026-08-18 00:00 UTC" --end="2026-08-19 00:00 UTC"
   ```

   An order last updated before the window isn't sent, however old or new it is. Re-save it in
   Commerce, or change its status, and it comes back into range.
3. **Is there anything shippable on it?** An order whose line items are all non-shippable is
   skipped on purpose. `shipper/export/count` counts it, but the export leaves it out. A gap
   between *Exported X of Y* in the log is usually this.
4. **Look at the payload.** `php craft shipper/export/preview --order=1042` prints exactly what
   ShipStation receives for that order. If it's there and ShipStation still ignores it, check the
   status mapping in ShipStation's store settings. The `<OrderStatus>` in the preview has to be
   mapped there, or ShipStation may not import the order.
5. **Timeouts on big windows.** If the log shows long durations on `export`, lower **Orders per
   page**.

### Orders appear twice in ShipStation

ShipStation matches orders by order number. If you change **Order number** after orders have
been imported, ShipStation sees new numbers and imports them again. Pick the setting once, before
going live.

## Empty fields in ShipStation

| Field | Why it's empty |
|---|---|
| Phone | Craft 5 addresses have no phone attribute. Add a custom field for it to your address field layout, and enter its handle under **Phone field handle** |
| Item image | **Send product images** is off, the variant and product have no Assets field with an image, or the asset's volume has no public URL |
| Ship-to address | The order has no shipping address. Shipper falls back to the billing address, so this means neither is set |
| CustomField1–3 | The template rendered empty, or threw an error. A broken template is sent empty and a warning is written to Craft's log. On Lite, only `CustomField1` (the coupon code) is sent |
| Weight | The variant has no weight in Commerce |
| Dimensions | Only sent for a one-unit, one-product order. That's by design |

`php craft shipper/export/preview --order=…` shows the XML for one order, so you can see whether a
field is empty on Craft's side or lost in ShipStation.

## Shipments not arriving

The label is bought in ShipStation but Craft never hears about it.

- **No `shipnotify` entries in the log.** ShipStation isn't posting. Check that the store in
  ShipStation is the Custom Store pointing at this site, and that the order was imported from it,
  not created by hand in ShipStation.
- **`404` on `shipnotify`.** No order matched. Shipper tries the posted `OrderID`, then the order
  number as a reference, number, short number and ID. This usually means the order was deleted, or
  the ShipStation order didn't come from this store.
- **Shipment recorded, order didn't move.** Is **Shipped order status** set? Is **Update the order
  status** on? On Pro with **Partial shipments** on, the order only moves once every item has
  shipped. Until then it goes to **Partly shipped status**, or stays put if that's blank.

ShipStation retries a failed notification hourly, so once the cause is fixed the shipment usually
arrives on its own. If it doesn't, record it by hand. See
[Usage](usage#recording-a-shipment-by-hand).

## Duplicate shipments

ShipStation re-sends notifications. Shipper ignores a repeat of a shipment it already has: same
tracking number and carrier, carrier case ignored. The log shows *Duplicate shipment … ignored*.

Two shipments with **different** tracking numbers are two shipments, which is correct. If a label
was voided and re-bought in ShipStation, both may have been reported. Delete the voided one from
**Shipper → Shipments** (needs **Add and delete shipments**). Deleting removes its items from the
shipped count but doesn't move the order's status back.

## Dates and time zones

ShipStation sends and expects every date in UTC. Shipper converts both ways, whatever your site's
time zone. ShipStation sends dates as `MM/dd/yyyy HH:mm` and sometimes in a compact
`MMDDYYYY…HHMM` form, and Shipper reads both.

If orders near the edge of a window go missing, compare the order's **Date updated** in UTC with
the window in the log entry's request URL. Check the server clock too. A server running minutes
slow can stamp an order outside the window ShipStation asks for.

## Live rates not showing at checkout (Pro)

Live rates fail open. Any problem means the customer simply doesn't see them, and checkout goes on
with your own shipping methods. To find out why:

1. Press **Quote the most recent cart** on the settings screen, or run
   `php craft shipper/rates/quote --verbose`. Both use the checkout code path and show
   ShipStation's own error messages.
2. Check the basics: Pro edition, **Enable live rates** on, **API key (v2)** set. **Test
   connection** should list your carriers.
3. The cart needs a shipping address with a country, and at least one shippable item.
4. Fill in **Ship from** completely. Many carriers refuse to quote without a full origin address.
5. If **Carriers** or **Services** are set, check them against `php craft shipper/sync/carriers`. A
   typo filters out everything.
6. Look for `rates` entries in the log. An error there with no status code is usually a timeout.
   Raise **Rate timeout (seconds)** if ShipStation is consistently slow.

Changes to **Markup**, **Carriers**, **Services**, **Ship from** or the package settings apply
straight away. They're part of the cache key, so a cart is re-quoted rather than answered from a
quote made under the old settings.

## The log is growing

Retention is enforced on Craft's garbage collection, which runs on a share of web requests by
default. If you've turned that off (`gcProbability` set to `0`), run
`php craft shipper/log/prune` or `php craft gc` from cron. On Lite, entries older than seven days
are pruned. On Pro, retention follows **Keep for (days)**, and `0` keeps everything.

## Getting help

Email [justin@justinholt.com](mailto:justin@justinholt.com). Include the output of
`php craft shipper/log/list --level=error`, and for an export problem,
`php craft shipper/export/preview --order=<number>` for one affected order. Remove customer details
before you send it.
