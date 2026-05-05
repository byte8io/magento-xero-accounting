---
title: What syncs
description: Entity-by-entity matrix — which Magento events land in which Xero entities.
---

# What syncs

The full Magento → Xero entity matrix. Direction is **always** `M → X` in v1 (Magento source of truth, Xero ledger of record).

For per-plan feature gating (which entities are available on which tier), see the **[Plans & pricing page on byte8.io](https://byte8.io/products/xero-accounting#pricing)**.

## Entities

| Magento event | Xero entity | What's posted |
|---|---|---|
| **`invoice.created`** | `Invoice` (`Type=ACCREC`, status: per [Commercial knobs](/docs/settings/commercial)) | Invoice with full line items, addresses, currency, per-line `DiscountAmount`, dedicated shipping line. `LineAmountTypes=Exclusive`. `DueDate` derived from `defaultXeroPaymentTermsDays`. |
| **`invoice.paid`** | `Payment` | Auto-payment routed per [Payment-method map](/docs/settings/payment-methods) — single `POST /Payments` linking the Xero invoice to the routed bank account. The invoice transitions AUTHORISED → PAID server-side; no separate allocation step (unlike Sage). |
| **`creditmemo.created`** | `CreditNote` (`Type=ACCRECCREDIT`) | Refund routed to the same contact as the original invoice. Includes shipping-refund line. `Reference` links back to the parent invoice (or to the parent order increment_id when the credit memo was created from the order rather than an invoice). |
| **`customer.upserted`** | `Contact` | Magento customer → Xero contact. ONE contact per Magento customer regardless of currency (Xero is currency-flexible — see [XERO_API_QUIRKS §7](https://github.com/byte8io/byte8.io/blob/main/apps/ledger/__docs/XERO_API_QUIRKS.md#section-7)). `ContactNumber` carries the Magento customer id; addresses go on `Addresses[]`, phone on `Phones[]`. |

## What's NOT synced (intentionally, in v1)

- **Standalone payments without an invoice.** Magento has no API to attach an offline payment to an existing invoice; Xero's `/Payments` requires an `InvoiceID` to link against. The chassis intentionally doesn't ship a `payment.captured` flow — accountants reconcile offline payments manually in Xero via the bank-feed import.
- **Xero → Magento writeback.** Enterprise on request — needs Xero's webhook surface + Magento write endpoints + conflict-resolution policy.
- **Catalog product sync.** Xero's Items resource is supported as a Growth-tier promise but isn't in the v1 MVP. Default behaviour: products don't sync. Invoice line items are written directly with the SKU + name from Magento, no Xero Item lookup.
- **Stock-item sync.** Xero's Items resource isn't designed to track inventory the way Magento does — there's no `quantity_in_stock` or `stock_movements` equivalent. Use Sage Accounting if you need stock-level sync into your accounting system.
- **ContactGroups sync.** Magento customer groups → Xero ContactGroups is on the Growth-tier roadmap but not in MVP. Today every contact lands ungrouped; you can group manually in Xero or wait for the slice (triggered after the first paying merchant asks).
- **Estimates / Quotes.** Xero has `Quotes` as a separate resource; not in v1 scope.
- **Bidirectional FX rate.** When the invoice's currency differs from your Xero org base currency, Xero applies its own exchange rate at posting time. We don't override `CurrencyRate` today — works fine for the GBP/EUR/USD case the design partners have validated.

## Idempotency keys

Every event carries a stable idempotency key:

| Event | Key shape |
|---|---|
| `invoice.created` | `invoice.created:{entity_id}` |
| `invoice.paid` | `invoice.paid:{entity_id}` |
| `creditmemo.created` | `creditmemo.created:{entity_id}` |
| `customer.upserted` | `customer.upserted:{entity_id}` |

The chassis dedupes on these keys so observer re-fires, duplicate Magento saves, and replays are safe — never produces duplicate Xero entities.

The chassis also passes a Xero-side `Idempotency-Key` header on every write — `invoice:{magento_id}`, `credit_note:{magento_id}`, `invoice_payment:{magento_id}`, `contact:{magento_id_or_guest_hash}` — so Xero collapses replays onto the same `InvoiceID` / `PaymentID` / `ContactID` server-side too.

The chassis additionally dedupes downstream via `entity_xref` (Magento entity_id ↔ Xero UUID). This is the second line of defence: if a chassis-side bug ever caused a duplicate POST, the `entity_xref` lookup catches it and routes to the existing Xero entity. For contacts specifically, the chassis additionally handles Xero's "ContactNumber dedup" by recovering the existing `ContactID` rather than failing the run.

## Sync filters in priority order

What gets to Xero is gated by the binding's sync policy:

1. **`sync_unpaid_invoices: false`** filters out unpaid invoices entirely.
2. **`sync_zero_value_invoices: false`** filters out £0 invoices.
3. **`sync_since`** filters out everything before the cutover date.
4. **`website_filter`** + **`store_filter`** restrict to specific Magento sites.
5. **`payment_method_map`** explicit `null` (or unmapped method without a `default_bank_account_id`) leaves the matching Magento `invoice.paid` event as `skipped_by_policy / payment_method_not_mapped` — invoice stays AUTHORISED in Xero for manual reconciliation.

Skips are auditable in the dashboard sync history with stable reason codes.

## Plan-gated features

Some entities (credit notes, multi-store) require higher-tier plans. The full per-plan feature matrix lives on the **[Plans & pricing page](https://byte8.io/products/xero-accounting#pricing)**.

If you try to enable a feature your plan doesn't include, the chassis blocks it server-side with a clear `tier_limit_exceeded` validation error on the policy save.
