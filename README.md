# Byte8 Xero Accounting

Magento 2 connector for **Xero**. Syncs invoices, credit notes, customers, and payments from Magento into Xero in near real time, with full sync-status visibility from the Magento admin.

This module is the per-provider thin client. The heavy lifting (OAuth token custody, Xero API calls, retry, audit) lives in the Byte8 Ledger SaaS — see [Architecture](#architecture) below.

## Features

- **One-click Connect** — pairing-code flow (no OAuth callback wrangling). Generate a code in Magento admin, paste into ledger.byte8.io, done.
- **Outbound sync observers** — `invoice.created` / `invoice.paid` / `creditmemo.created` / `customer.upserted` events fire on every save and queue durably (no inline HTTP — checkout stays snappy).
- **Xero Status chips** in the admin — sortable + filterable column on Sales → Invoices and Sales → Credit Memos grids; "Xero Accounting" info block on every invoice / credit-memo detail page. Operators see what synced without leaving Magento.
- **Per-binding policy** — default income category, default `payment_terms_in_days`, Magento product type → Xero `item_type` map, initial invoice status (`Draft` / `Sent`), payment-method map, invoice-number prefix, sync filters. All configured from the ledger dashboard, not Magento admin.
- **Coexists with the Sage connector** — both modules ship side-by-side `Sage Status` and `Xero Status` columns; JOIN aliases are namespaced so the data lanes don't collide.
- **Idempotent everything** — every event carries a stable idempotency key (`invoice.created:{entity_id}` etc); ledger dedupes via the shared `entity_xref` table. Observer re-fires, duplicate saves, replay all safe.
- **Operator-visible failures** — failed deliveries surface as a banner on the admin config page (not a silent 24h drop).

## Connect flow

1. Stores → Configuration → **Byte8 → Xero** → click **Generate pairing code** (30-min TTL).
2. Click **Open Byte8 Ledger** to land on `ledger.byte8.io`, sign in, paste the code + your Magento URL.
3. Ledger calls back into Magento (`POST /V1/byte8/xero_accounting/setup/pair`) with the api_key. The connection status block flips to "Connected" — you're done.
4. Authorise Xero OAuth from the ledger dashboard (one click, redirects to Xero.com and back).

After connecting, Sales-side observers fire automatically; no per-merchant configuration required for the happy path. Per-tenant sync policy (default income category, payment terms, etc.) is configured from the ledger dashboard, not Magento admin.

## What syncs

| Magento event | Xero entity | Notes |
| --- | --- | --- |
| `invoice.created` | `/v2/invoices` (Sent / Draft) | Posts as outstanding AR; status configurable per binding |
| `invoice.paid` | `/v2/bank_transaction_explanations` | Marks the invoice paid against the configured bank account; mapped per `payment_method_map` policy |
| `creditmemo.created` | `/v2/invoices` (negative-value) | Xero has no first-class credit-note resource on /v2; credit notes are negative-quantity invoices with a `CN-` reference prefix |
| `customer.upserted` | `/v2/contacts` | Single contact per Magento customer (Xero doesn't lock contacts to a single currency) |

What's intentionally NOT synced today: catalog product upsert (Xero's product model is too lightweight for the v1 buyer profile), stock-quantity sync (same reason), inbound polling (Xero → Magento writeback is Enterprise-on-request scope).

## Sync visibility in Magento admin

- **Sales → Invoices** grid — "Xero Status" column with chips: `✓ Synced` / `⏳ Pending` / `⏸ Skipped` / `✗ Failed` / `—`. Sortable + filterable. Hover for Xero URL, skip-reason, or error-code.
- **Sales → Credit Memos** grid — same column.
- **Invoice / Credit Memo detail pages** — "Xero Accounting" info block beside "Order Information" with status, Xero URL, last sync timestamp, skip/error context.

Pending chips appear immediately when an observer fires (write-through to `byte8_entity_sync_state` at enqueue time); terminal status (synced / skipped / failed) lands within 60s once the ledger worker picks up the event.

For full per-event audit trail (with retry, error message, payload diff), use the ledger dashboard at `ledger.byte8.io`.

## Architecture

```
┌─────────────────────┐     ┌──────────────────────┐     ┌────────────────────┐
│  Magento 2          │◄───►│   Byte8 Ledger       │◄───►│   Xero v2     │
│  (this module +     │ JWT │   (SaaS chassis)     │OAuth│   API              │
│   byte8/module-     │     │                      │     │                    │
│   client)           │     │   apps/ledger        │     │                    │
└─────────────────────┘     └──────────────────────┘     └────────────────────┘
```

This module is **thin by design**. It owns:

- Magento-side observers (queue events on save)
- Pairing-code flow + admin config blocks
- Inbound REST endpoint at `/V1/byte8/xero_accounting/setup/pair` (the only provider-namespaced route — canonical entity getters + sync-state callback live in `byte8/module-client`)
- The "Xero Status" admin grid columns + detail blocks reading from `byte8_entity_sync_state`

It does **NOT** own OAuth token custody, Xero API calls, retry logic, dead-letter queues, or per-tenant rate limiting — those all live in the Byte8 Ledger SaaS. This split means the module can stay tiny (no PHP-side OAuth dependencies, no `vendor/` bloat) and a single ledger instance services every connected merchant.

## Requirements

- PHP 8.1+ (8.4 supported)
- Magento Open Source / Adobe Commerce 2.4.4+
- MySQL 8.0+ / MariaDB 10.6+
- A Byte8 Ledger account (pairing code issued from `ledger.byte8.io`)
- Outbound HTTPS to `ledger.byte8.io` (port 443). Provider-side network access (`api.xero.com`) happens from the ledger SaaS — Magento never talks to Xero directly.

## Installation

```bash
composer require byte8/magento-xero-accounting
bin/magento module:enable Byte8_Core Byte8_Client Byte8_XeroAccounting
bin/magento setup:upgrade
bin/magento cache:flush
```

The `byte8/magento-xero-accounting` metapackage pulls in `byte8/module-core` + `byte8/module-client` + this module.

## Console commands

```bash
bin/magento byte8:client:outbox:inspect
bin/magento byte8:client:outbox:requeue <entity_id>
bin/magento byte8:client:outbox:cleanup [--days=30]
```

Outbox triage commands (live in `byte8/module-client` and apply to every Byte8 provider, Xero included). See `module-client/SECURITY.md` for the full operator runbook.

## Configuration

Stores → Configuration → **Byte8** → **Xero**:

- **Connection status** — paired / not paired, dead-letter banner on failed deliveries.
- **Pairing code** — generate / regenerate. 30-min TTL.
- **Open Byte8 Ledger** — quick redirect to the dashboard.
- **Disconnect** — revokes the binding both sides.

All sync policy (default income category, payment terms, product item-type map, invoice status default, payment method map, sync filters, …) is configured from the ledger dashboard, not Magento admin. See [byte8.io docs](https://byte8.io/docs/xero) for the full list.

## License

[MIT](LICENSE.txt)

## Support

Byte8 Ltd — support@byte8.io
