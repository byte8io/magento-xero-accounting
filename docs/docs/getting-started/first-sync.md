---
sidebar_position: 3
title: Your first sync
description: Walk through what happens — observer side, queue side, Xero side — when you raise your first invoice after pairing. Useful for verifying the install end-to-end.
---

# Your first sync

The 60-second [Quick start](/docs/getting-started/quick-start) gets you paired. This page walks through what *actually happens* when you raise your first invoice — useful both for verifying the install end-to-end and for understanding the trace if something doesn't sync.

## Step 1 — Raise an invoice in Magento

In **Sales → Orders**, pick an order, **Invoice → Submit Invoice**. The Magento `invoice_save_after` event fires.

`InvoiceCreatedObserver` (`Byte8\XeroAccounting\Observer\InvoiceCreatedObserver`) catches it on its first save (filters subsequent state-flip saves), confirms the `Byte8_XeroAccounting` module is connected (`XeroConfig::isConnected()`), then enqueues:

```php
$this->byteClient->enqueueEvent('invoice.created', [
    'magento_entity_id' => $entityId,
    'website_id'        => $this->resolveWebsiteId($invoice),
    'store_id'          => (int) $invoice->getStoreId(),
    'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
    'payload'           => ['increment_id' => $invoice->getIncrementId()],
], 'invoice.created:' . $entityId, XeroConfigInterface::PROVIDER_KEY);
```

Two things happen synchronously inside `enqueueEvent`:

1. A row is inserted into `byte8_event_outbox` with `status = 'pending'` and `provider = 'xero'`.
2. Because `$providerForMirror` is set (PR7 write-through), a row is also UPSERTed into `byte8_entity_sync_state` with `sync_status = 'pending'`.

The merchant's invoice-save click returns immediately. No HTTP, no Xero round-trip in the save transaction.

## Step 2 — Check the Magento admin grid

Navigate to **Sales → Invoices**. The new invoice's row shows a `⏳ Pending` chip in the Xero Status column. That chip came from the write-through write in Step 1 — it doesn't wait for the cron drain or the chassis callback.

This is the core PR7 UX: the chip appears the moment you click Submit Invoice, not 60 seconds later.

## Step 3 — Cron drains the outbox

Within 60 seconds the `byte8_outbox_drain` cron picks up the `pending` outbox row, signs a JWT, and POSTs to:

```
POST https://ledger.byte8.io/webhooks/magento/<your-tenant-id>/invoice.created?provider=xero
Authorization: Bearer <signed-JWT>
Idempotency-Key: invoice.created:42
{ "magento_entity_id": 42, "website_id": 1, ... }
```

The chassis verifies the JWT (HKDF subkey from your shared `api_key`), routes by `?provider=xero`, inserts a `sync_runs` row (status `queued`), publishes the job to its Redis queue, and returns `202 Accepted` with the new `sync_run_id`. Magento marks the outbox row `succeeded`.

## Step 4 — The worker fetches your canonical invoice

The chassis worker pops the job and calls back into your Magento:

```
GET https://your-shop.example.com/rest/V1/byte8/invoice/42
Authorization: Bearer <chassis-signed-JWT>
```

The thin module's `InvoiceRepository::get()` returns the canonical Magento invoice — snake_case, with line items, addresses, payment method, currency, base-to-order rate. The chassis then checks the binding's sync policy (e.g. `sync_unpaid_invoices`, `website_filter`, etc), and if it passes, calls `XeroProvider::post_invoice(...)`.

## Step 5 — Xero POST

The provider:

1. Resolves or creates the Xero `Contact` for this customer (one Contact per Magento customer, currency-flexible — see [XERO_API_QUIRKS §7](https://github.com/byte8io/byte8.io/blob/main/apps/ledger/__docs/XERO_API_QUIRKS.md#section-7)). `ContactNumber=<magento_customer_id>` lets Xero dedupe on subsequent syncs.
2. Translates the canonical invoice into Xero's `Invoice` shape — handling per-line `DiscountAmount`, `LineItem.AccountCode` from `default_xero_revenue_account_code`, `LineItem.TaxType` from `default_xero_tax_type`, the dedicated shipping line with derived TaxType, and `DueDate = Date + default_xero_payment_terms_days`.
3. POSTs `/api.xro/2.0/Invoices` with body `{"Invoices": [<invoice>]}` and `Idempotency-Key: invoice:<magento_id>`. Xero responds with the created `InvoiceID`; the chassis stores `(magento_entity_id ↔ InvoiceID)` in `entity_xref` under `entity_type='invoice'`.

On success, the worker calls `SyncRun::mark_succeeded(sync_run_id, xero_invoice_id)`. Then a follow-up `JobKind::PushSyncState` is enqueued — same chassis, different job kind — that POSTs the terminal status back to your Magento at `/rest/V1/byte8/sync-state` with `provider = 'xero'`. Your `byte8_entity_sync_state` row flips from `pending` to `synced`.

## Step 6 — Verify

Refresh **Sales → Invoices** in your Magento admin. The chip is now `✓ Synced` (blue). Hover for the Xero invoice URL; click into the invoice for the **Xero Accounting** info block with the timestamp.

Cross-check on the Byte8 dashboard at `ledger.byte8.io/dashboard/sync` — the run row shows `succeeded` with the resolved Xero `InvoiceID`.

## Total elapsed time

Typical path on a healthy install:

| Step | Latency |
|---|---|
| Observer → outbox INSERT | < 5 ms |
| Pending chip appears | < 5 ms (write-through) |
| Cron picks up the row | 0–60 s (cron interval) |
| Outbox POST → chassis 202 | ~150 ms |
| Worker fetches canonical | ~80 ms |
| Xero POST | ~250–700 ms (Xero-side latency dominates) |
| Sync-state callback to Magento | ~100 ms |
| **Synced chip appears** | **typically 5–60 s after Submit Invoice** |

If your chip stays `⏳ Pending` for over 90 seconds, the cron is probably not running. See [Troubleshooting → Cron](/docs/troubleshooting#cron-not-running).
