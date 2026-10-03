---
title: Templating
slug: templating
order: 40
summary: The craft.shipper Twig API for showing shipments, tracking links and shipping progress on the front end.
---

`craft.shipper` gives your templates the shipments recorded against an order, with no fields to
read. It's available in Lite and Pro.

## Methods

| Method | Returns |
|---|---|
| `craft.shipper.shipments(order)` | Every shipment on the order, oldest ship date first. Takes an order or an order ID. Returns an empty array if there are none |
| `craft.shipper.latestShipment(order)` | The most recent shipment, or `null` |
| `craft.shipper.isShipped(order)` | `true` once the shipped-item count covers every shippable item on the order. Takes an order |
| `craft.shipper.progress(order)` | `{ shipped, total, remaining, complete }`, in units of shippable items. Takes an order |
| `craft.shipper.trackingUrl(carrier, trackingNumber)` | A public tracking URL, or `null` if the carrier isn't one Shipper knows |
| `craft.shipper.knownCarriers()` | The carrier keys Shipper can build tracking URLs for |

## The shipment object

| Property or method | What it is |
|---|---|
| `carrier` | The carrier as ShipStation reported it, such as `UPS` |
| `carrierCode` | The carrier code, when there is one |
| `service` | The service, such as `UPS® Ground` |
| `trackingNumber` | The tracking number, or `null` |
| `shipDate` | A `DateTime`, or `null` |
| `source` | `shipnotify` or `manual` |
| `getLabel()` | Carrier and service together: *UPS Ground*. Falls back to the tracking number |
| `getTrackingUrl()` | The public tracking URL, or `null` |
| `getItems()` | The items in this shipment: an array of `{ lineItemId, sku, name, qty }`. Empty if ShipStation didn't say |
| `getShippedQty()` | The total quantity in this shipment |
| `dateCreated` | When Shipper recorded it |

`rawPayload` is always `null` in shipments you get from `craft.shipper`. The raw ShipStation
payload contains the ship-to address, and a front-end template never needs it.

## Load the order safely

Every method takes the order you give it. It doesn't check who's asking. Pass an order your
template has already loaded and verified, never an ID taken straight from the query string. With
`craft.shipper.shipments(craft.app.request.getParam('id'))`, anyone could list anyone's shipments
by counting upwards.

Look the order up by its `number`, the long unguessable hash Commerce puts in order links. Better
still, also check that it belongs to the logged-in customer:

```twig
{% set number = craft.app.request.getQueryParam('number') %}
{% set order = number ? craft.orders()
    .number(number)
    .isCompleted(true)
    .one() : null %}

{% if not order or (currentUser and order.customerId != currentUser.id) %}
    {% exit 404 %}
{% endif %}
```

## An order status page

```twig
{% set shipments = craft.shipper.shipments(order) %}
{% set progress = craft.shipper.progress(order) %}

<h2>Shipping</h2>

{% if shipments is empty %}
    <p>We're preparing your order. You'll get an email when it ships.</p>
{% else %}
    {% if not progress.complete and progress.shipped > 0 %}
        <p>{{ progress.shipped }} of {{ progress.total }} items have shipped.
           The rest will follow.</p>
    {% endif %}

    <ul>
        {% for shipment in shipments %}
            <li>
                <strong>{{ shipment.getLabel() }}</strong>
                {% if shipment.shipDate %}
                    shipped {{ shipment.shipDate|date('M j, Y') }}
                {% endif %}

                {% set url = shipment.getTrackingUrl() %}
                {% if url %}
                    — <a href="{{ url }}" rel="noopener noreferrer" target="_blank">Track {{ shipment.trackingNumber }}</a>
                {% elseif shipment.trackingNumber %}
                    — tracking number {{ shipment.trackingNumber }}
                {% endif %}

                {% if shipment.getItems() is not empty %}
                    <ul>
                        {% for item in shipment.getItems() %}
                            <li>{{ item.name ?: item.sku }} × {{ item.qty }}</li>
                        {% endfor %}
                    </ul>
                {% endif %}
            </li>
        {% endfor %}
    </ul>
{% endif %}
```

## A "track my parcel" link

```twig
{% set shipment = craft.shipper.latestShipment(order) %}
{% if shipment and shipment.getTrackingUrl() %}
    <a href="{{ shipment.getTrackingUrl() }}">Track your parcel</a>
{% endif %}
```

## In an email

Status emails are rendered with the order, so the same calls work in a Commerce email template:

```twig
{% for shipment in craft.shipper.shipments(order) %}
    {{ shipment.getLabel() }}: {{ shipment.trackingNumber }}
    {% if shipment.getTrackingUrl() %}{{ shipment.getTrackingUrl() }}{% endif %}
{% endfor %}
```

The shipment is recorded before the order's status changes, so a **shipped** email sent because of
a ShipStation notification already sees that shipment. The note Shipper writes is also available
as `order.message` in that email.

## Tracking URLs

`trackingUrl()` and `getTrackingUrl()` match the carrier on its name or code with punctuation and
case ignored, so `DHL Express`, `dhl_express` and `DHLExpress` all match. A name that starts with
a known carrier also matches, such as `UPS Ground Saver`. Known carriers:

UPS, USPS (including Stamps.com, Endicia and FirstMile), FedEx, DHL Express, DHL Global Mail, DHL
eCommerce, OnTrac, LaserShip, Canada Post, Purolator, Royal Mail, Australia Post, New Zealand Post,
Globegistics, APC, SEKO, GSO and Amazon Shipping.

For any other carrier you get `null`, not a guessed link that 404s. Show the tracking number as
text in that case:

```twig
{% set url = craft.shipper.trackingUrl(shipment.carrier, shipment.trackingNumber) %}
{{ url ? tag('a', { href: url, text: shipment.trackingNumber }) : shipment.trackingNumber }}
```

## How progress is counted

`progress()` and `isShipped()` count **items**. They use the quantities ShipStation lists in each
shipment notification, against the order's shippable line items. Item counts are kept on Lite as
well as Pro.

Two consequences:

- A shipment that completes the order counts everything as shipped, even when it doesn't itemise
  it. That covers a notification where ShipStation sent no items, a shipment recorded by hand, and
  every shipment on Lite or with **Partial shipments** off. `progress()` and the order's status
  always agree.
- An order with nothing shippable has a `total` of 0. `isShipped()` returns `true` for it, and
  `progress().complete` returns `false`.
