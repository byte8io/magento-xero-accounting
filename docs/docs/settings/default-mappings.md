---
sidebar_position: 2
title: Default mappings
description: Default income category URL, default bank account, and default payment terms days. The fallback values used when a Magento line / invoice doesn't carry enough information to route itself.
---

# Default mappings

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Xero Defaults** card.

Xero requires every invoice line to carry an income category, and every POST to carry a `payment_terms_in_days` value (Xero quirk #1 — the field is required even on net-cash invoices). Defaults fill the gap when Magento doesn't carry that information directly.

All dropdowns are **populated from the chassis's reference cache** — the live Xero category + bank-account list for your binding. Type-aheads work; typing a partial name filters the list.

## `default_xero_income_category_url`

**Bucket:** `categories?nested_category_type=income` (Xero `/v2/categories?view=income`).

The income category applied to invoice / credit-memo lines that don't carry their own routing. Set to the Xero income category where most of your Magento sales should book.

Typical UK choices (from a fresh Xero account):

- `https://api.xero.com/v2/categories/001` — Sales
- `https://api.xero.com/v2/categories/002` — Other Income
- `https://api.xero.com/v2/categories/003` — Discounts Received

The dropdown shows each category by its Xero `description` (the human-readable label) and stores the full `url` (Xero's stable identifier) in the policy. Don't confuse with `nominal_code` — categories are URL-keyed in Xero's API, not numerically.

The validator rejects values not in the cached `xeroIncomeCategories` list with a red-underline `defaultXeroIncomeCategoryUrl` field error before save. Typos surface form-time, not at sync-time as a 422 dead-letter.

If unset and a line carries no specific routing, the worker falls back to the first income category in the cache (alphabetical) and surfaces a WARN-level log line. Setting this explicitly is recommended.

## `default_xero_payment_terms_days`

**Default:** `30` (always sent — Xero quirk #1).

Number of days from invoice date until due. Xero's API requires this on **every** POST to `/v2/invoices` — even if the invoice is paid immediately, even if it's a £0 line. The chassis always sends a value, defaulting to `30` if unset on the binding.

Common values:
- `0` — net cash (B2C — money's already in the till)
- `7`, `14`, `30` — standard B2B AR terms
- `60`, `90` — extended terms for large B2B accounts

This value is *also* what Xero uses to compute the `due_on` date shown on the PDF invoice, so set it to whatever your accountant uses on manually-created invoices for consistency.

If you want per-customer terms (some B2B accounts on 30, others on 60), either set the binding-level default to your most common value and edit the Xero invoice manually for the outliers, or — for v1.1+ — wait for the planned per-customer terms map. Email `helo@byte8.io` if this is blocking you.

## `default_bank_account_id`

**Bucket:** `bank_accounts` (Xero `/v2/bank_accounts`).

The default Xero bank account used for `bank_transaction_explanations` when `invoice.paid` fires for a payment method *not* mapped in the [Payment-method map](/docs/settings/payment-methods).

Optional. When unset:

- Payment methods explicitly mapped → payment routed to the mapped bank account.
- Payment methods unmapped + no default → invoice stays Open in Xero; accountant reconciles manually when the cheque / bank transfer lands. **This is the documented B2B / net-terms behaviour** and on-purpose for offline payment methods (`checkmo`, `banktransfer`, `purchaseorder`).

When set:

- Unmapped methods route to this default account. Useful if you trust a single "catch-all" bank account to capture every online-card payment that didn't map specifically.

## Reference cache freshness

The reference cache rebuilds:

- **At connect time** (post-OAuth handshake), once.
- **Auto on settings page load** if older than 24 hours — the dashboard fires `refreshReferenceData(bindingId)` once-per-mount with a useRef guard. You'll see "Refreshing reference data from Xero…" briefly in the actions bar.
- **Manually** via the **Refresh** button beside the freshness timestamp on the settings page. Use this when you've added a new Xero income category or bank account in Xero and want it to appear in the dropdown immediately.

Freshness is shown as `Updated 14 minutes ago` (with absolute timestamp on hover). If something looks stale or missing, click Refresh.

## What if a default override is no longer valid?

If you delete a Xero income category or bank account that the binding's policy still references (e.g. you mapped `default_xero_income_category_url = "…/categories/old"` and then deleted that category in Xero), the dropdown shows it as `(missing) old-uuid` in red. Pick a replacement before saving — the validator blocks save with a `not_in_reference` field error otherwise.
