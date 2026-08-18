# Release Notes for Shipper

## 5.0.0

Initial release.

### Added

- ShipStation Custom Store endpoint: `export` serves the orders XML, `shipnotify` records
  shipments and advances the order.
- HTTP Basic **or** `auth_key` query-parameter authentication, so the connection survives servers
  that strip the `Authorization` header.
- Shipments stored as first-class data — no Matrix field, entry type or field handles to configure.
- Shipments index in the control panel, and a panel on Commerce's own order screen showing
  tracking, export history, **Preview XML** and **Sync now**.
- Tracking URLs for 20+ carriers, matched on either the friendly name or the carrier code.
- `craft.shipper.*` Twig API: `shipments()`, `latestShipment()`, `isShipped()`, `progress()`,
  `trackingUrl()`, `knownCarriers()`.
- Console commands for export preview, order counts, rate quotes, store refresh, connection tests
  and log housekeeping.
- **Pro:** connection log with request and response payloads.
- **Pro:** partial shipments — per-item counting, an optional partly-shipped status, and
  idempotent handling of ShipStation's hourly retries.
- **Pro:** two-way status mapping between Commerce and ShipStation.
- **Pro:** object-template custom fields for ShipStation's `CustomField1`–`3`.
- **Pro:** "Sync now" via ShipStation's `stores/refreshstore`, so orders reach ShipStation by being
  pulled rather than pushed — no duplicates.
- **Pro:** live carrier rates at checkout via the ShipStation v2 API, cached per cart signature,
  with markup, carrier and service filters, and a fail-open guarantee.
