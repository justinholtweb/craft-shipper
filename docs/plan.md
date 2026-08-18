# Shipper — build plan

ShipStation integration for Craft Commerce 5. `justinholtweb/craft-shipper`, handle `shipper`,
namespace `justinholtweb\shipper`, version 5.0.0.

## Why it exists

`fostercommerce/shipstationconnect` ($59) already connects Commerce 5 to a ShipStation Custom
Store. Shipper's edge is everything around the endpoint:

| | Foster | Shipper |
|---|---|---|
| Tracking storage | you hand-build a Matrix field + type 6 handles into settings | own table, zero content modelling |
| Connection log | none | every request, with payloads and replay (Pro) |
| Partial shipments | any shipnotify completes the order | per-item counting, completes when all items ship (Pro) |
| Status mapping | one `shippedStatusHandle` | Commerce → ShipStation *and* back (Pro) |
| Auth | HTTP Basic only (documented Apache breakage) | Basic **or** `auth_key` query param |
| Live rates | none | ShipStation API v2 rates at checkout (Pro) |
| Console | none | export preview, sync, quote, prune |
| Front end | none | `craft.shipper.*` tracking API |

## Editions

- **Lite (free)** — custom-store export + shipnotify, shipments table + CP index, tracking URLs,
  Twig API, shipped-status mapping, order-edit panel.
- **Pro ($99)** — connection log + replay, partial shipments, two-way status mapping, REST API
  ("Sync now" via `/stores/refreshstore`), live checkout rates, console commands, custom field
  mapping, per-store config.

## The two invariants

1. **`services\Export::buildOrderElement()` is the only place an order becomes ShipStation XML.**
   The endpoint, the console preview and the CP "Preview XML" action all go through it, so what a
   merchant previews is byte-identical to what ShipStation receives.
2. **`services\Shipments::record()` is the only place a shipment row is created.** shipnotify, the
   CP manual add and any API sync all land there, so idempotency, item counting and the
   fully-shipped decision are decided once.

## Protocol (confirmed from two independent implementations)

Verified by reading WooCommerce's `woocommerce-shipstation-integration` 5.3.3 export/shipnotify
classes and Foster's `OrdersController`/`Xml` — they agree, so the contract is not guesswork.

### `GET …?action=export&start_date=&end_date=&page=`

Dates are **UTC, `MM/dd/yyyy HH:mm`**. Response is `text/xml`:

```xml
<Orders page="1" pages="3">
  <Order>
    <OrderNumber><![CDATA[1042]]></OrderNumber>
    <OrderID>17</OrderID>
    <OrderDate>08/18/2026 14:05</OrderDate>
    <OrderStatus><![CDATA[processing]]></OrderStatus>
    <LastModified>08/18/2026 14:06</LastModified>
    <PaymentMethod><![CDATA[stripe]]></PaymentMethod>
    <ShippingMethod><![CDATA[Ground]]></ShippingMethod>
    <CurrencyCode>USD</CurrencyCode>
    <OrderTotal>84.50</OrderTotal>
    <TaxAmount>4.50</TaxAmount>
    <ShippingAmount>10.00</ShippingAmount>
    <CustomerNotes><![CDATA[…]]></CustomerNotes>
    <InternalNotes><![CDATA[…]]></InternalNotes>
    <CustomField1><![CDATA[SUMMER10]]></CustomField1>
    <Customer>
      <CustomerCode><![CDATA[a@b.com]]></CustomerCode>
      <BillTo>  <Name/><Company/><Phone/><Email/> </BillTo>
      <ShipTo>  <Name/><Company/><Address1/><Address2/><City/><State/><PostalCode/><Country/><Phone/> </ShipTo>
    </Customer>
    <Items>
      <Item>
        <LineItemID>55</LineItemID>
        <SKU><![CDATA[TEE-L]]></SKU>
        <Name><![CDATA[T-Shirt]]></Name>
        <ImageUrl><![CDATA[…]]></ImageUrl>
        <Weight>12</Weight><WeightUnits>Ounces</WeightUnits>
        <Quantity>2</Quantity><UnitPrice>20.00</UnitPrice>
        <Options><Option><Name/><Value/></Option></Options>
      </Item>
    </Items>
  </Order>
</Orders>
```

Text nodes are CDATA except numerics and dates. `<Adjustment>true</Adjustment>` marks a discount
line. `WeightUnits` ∈ Pounds|Ounces|Grams. Optional `<Dimensions>` (Length/Width/Height/
DimensionUnits) only when the shipment is a single unit of a single product.

### `POST …?action=shipnotify&order_number=&carrier=&service=&tracking_number=`

XML body carries `ShipDate`, optionally `OrderID` (authoritative over `order_number`) and `Items`
with `LineItemID`/`SKU`/`Name`/`Quantity`. Respond 200 on success.

**Idempotency**: ShipStation retries hourly and sends no notification id, so the shipment key is
`trackingNumber|lower(carrier)`. Woo learned this the hard way (SHIPSTN-53/165) — the retry
otherwise re-counts items and can complete a partly-shipped order.

**Auth**: ShipStation's store config offers username/password → HTTP Basic. Apache commonly drops
the header (Foster documents the `CGIPassAuth` workaround), so Shipper also accepts an `auth_key`
query param the way WooCommerce does. Either satisfies the endpoint; both are `hash_equals`.

## REST API (Pro)

- **v1** `https://ssapi.shipstation.com`, Basic auth (key:secret). Only used for
  `POST /stores/refreshstore?storeId=` — "Sync now" makes ShipStation *pull* immediately instead of
  pushing a duplicate order via `createorder`.
- **v2** `https://api.shipstation.com/v2`, `API-Key` header. Used for `GET /carriers` and
  `POST /rates` (live checkout rates). Schema mirrors ShipEngine's published OpenAPI: rate_options
  {carrier_ids, service_codes, package_types, calculate_tax_amount, preferred_currency}, shipment
  {ship_to, ship_from, packages[{weight{value,unit}, dimensions{unit,length,width,height}}]},
  response `rate_response.rates[]` {rate_id, carrier_id, carrier_code, carrier_friendly_name,
  service_code, service_type, shipping_amount{amount,currency}, other_amount, delivery_days,
  estimated_delivery_date, error_messages[]}.

Weight units v2: pound|ounce|gram|kilogram. Dimension units: inch|centimeter.

## Data model

- `{{%shipper_shipments}}` — orderId FK (cascade), storeId, shipmentKey, carrier, carrierCode,
  service, trackingNumber, shipDate, cost, items JSON, source, rawPayload. Unique (orderId, shipmentKey).
- `{{%shipper_orderstate}}` — orderId PK, dateFirstExported, dateLastExported, exportCount,
  shippedQty, dateShipped.
- `{{%shipper_log}}` — action, level, statusCode, durationMs, ip, summary, message, request,
  response, dateCreated.

Rate quotes cache in Craft's cache component, keyed on a cart signature — no table.

## Build order

1. Plugin, editions, settings, install migration, records
2. Units + tracking-URL + XML helpers
3. `Export` (the invariant) → `ApiController` export action
4. `Shipments` + `Statuses` → shipnotify action
5. `Log` + CP log screen (Pro)
6. `Api` + `Rates` + `LiveRateShippingMethod` (Pro)
7. CP: shipments index, settings, order-edit panel
8. Console, Twig variable, translations, icon
9. `tests/integration/checks.php` in the plugin-testing harness
