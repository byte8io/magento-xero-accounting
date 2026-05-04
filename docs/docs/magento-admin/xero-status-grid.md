---
sidebar_position: 1
title: Xero Status grid columns
description: The "Xero Status" column on Sales → Invoices and Sales → Credit Memos. Chips, hover tooltips, sortability, and what each status means.
---

# Xero Status grid columns

A sortable + filterable **Xero Status** column on:

- **Sales → Invoices** (`/admin/sales/invoice/index`)
- **Sales → Credit Memos** (`/admin/sales/creditmemo/index`)

The chip tells you, at a glance, whether each row reached Xero — without leaving the Magento admin you already live in.

## The five chip variants

| Chip | Meaning |
|---|---|
| <span className="fa-chip fa-chip--synced">✓ Synced</span> | Reached Xero successfully. Hover for the Xero invoice URL. |
| <span className="fa-chip fa-chip--pending">⏳ Pending</span> | In flight — outbox row enqueued, not yet drained or not yet round-tripped. Typically clears within 60 s. |
| <span className="fa-chip fa-chip--skipped">⏸ Skipped</span> | Filtered out by sync policy. Hover for the skip-reason code (e.g. `payment_method_not_mapped`, `outside_sync_since`, `zero_value_invoice`). |
| <span className="fa-chip fa-chip--failed">✗ Failed</span> | Hard failure in the chassis — auth issue, validation error, Xero 4xx. Hover for the error-code class (e.g. `provider`, `validation`, `http`). Investigate via the ledger dashboard's sync history. |
| <span className="fa-chip fa-chip--none">—</span> | No sync attempted. Either the row pre-dates the install, the binding wasn't connected when this row was created, or the row falls outside the sync filters in a way that didn't even produce a `skipped_by_policy` row. |

## Coexistence with Sage Accounting

If you also have `byte8/magento-sage-accounting` installed, both connectors live side-by-side on the grids — **two columns**: "Sage Status" and "Xero Status". Each chip reflects sync state for its own provider.

Behind the scenes the Xero grid LEFT JOINs `byte8_entity_sync_state` with the table alias `byte8_sync_fa` and column suffix `_xero` to keep the two providers' UI columns from colliding. You don't need to know about that — but it's why column names like `byte8_sync_status_xero` show up if you inspect the grid XML.

## Sorting + filtering

Click the **Xero Status** column header to sort. Use the column's filter dropdown to narrow the grid to one chip variant — useful for "show me everything that failed in the last 24h."

## Why pending appears immediately

The chip flips to `⏳ Pending` the **moment** the merchant clicks Submit Invoice — not 60 s later when the cron drain finally picks it up. This is the PR7 write-through behaviour:

- `ByteClient::enqueueEvent` writes the outbox row AND a `pending` row in `byte8_entity_sync_state` synchronously.
- The grid LEFT JOINs against `byte8_entity_sync_state` (filtered to `provider = 'xero'`) per row → chip renders pending immediately.

When the chassis terminal callback lands (typically 5-60 s later), the same row is UPSERTed with the terminal status (`synced` / `skipped` / `failed`). Refresh the grid to see the chip update.

## Hover tooltip

The chip's `title` attribute carries useful context per row:

- **Synced** → Xero invoice URL (`https://api.xero.com/v2/invoices/<uuid>` — opaque to humans but useful in support tickets)
- **Skipped** → skip-reason code (`payment_method_not_mapped`, `outside_sync_since`, etc — see [Sync behaviour](/docs/settings/sync-behavior) for the full list)
- **Failed** → error-code class (one of: `auth`, `not_found`, `validation`, `rate_limited`, `provider`, `http`, `database`, `serde`, `internal`)

For the human-readable Xero reference, see the next page — the [detail-page info block](/docs/magento-admin/xero-status-detail) shows it explicitly.

## What if I see `—` on an invoice that should have synced?

Three common causes:

1. **The invoice pre-dates the install.** The chassis only writes mirror rows for terminal `mark_*` calls *after* PR7 deployed. Historical invoices (synced before PR7) have no mirror row. Either retry from the ledger dashboard (re-fires the callback and populates the row), or backfill via SQL — see [Troubleshooting → Backfill pre-PR7 rows](/docs/troubleshooting#backfill-pre-pr7-rows).
2. **Binding wasn't connected** when the invoice was raised. Check the chassis dashboard — was the Xero binding `connected` at the time? Reconnect + retry.
3. **Cron isn't running.** Verify `bin/magento cron:run --group=default` works manually. If your install relies on system cron, check it's calling Magento's cron entry point on the minute.

## No chip on Sales → Orders

Intentional. Orders aren't synced directly — we sync invoices, of which an order can have 0..N. A row-level chip on the Orders grid would either need a synthetic rollup (obscuring multi-invoice partial-sync state) or arbitrarily pick one invoice. Operators drill into the Invoices tab from the order to see per-invoice status.

This may change in v1.1 if a design partner asks for an Orders-grid roll-up — let us know if you'd find it useful.

## No chip on Customers grid

Same reasoning. One Magento customer maps to one Xero contact, which is simpler than the Sage per-currency model — but a row-level chip would still need to surface "did this customer's contact upsert succeed" without the row-level signal of a specific event. Customer detail-page block with the chassis-resolved Xero contact URL is the planned v1.1+ surface.
