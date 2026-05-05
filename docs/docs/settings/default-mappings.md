---
sidebar_position: 2
title: Default mappings
description: Default revenue account, default tax type, default bank account. The fallback values used when a Magento line / invoice doesn't carry enough information to route itself.
---

# Default mappings

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Default mappings** + **Xero defaults** cards.

Every Xero invoice line needs a revenue account (`LineItem.AccountCode`) and a tax type (`LineItem.TaxType`). Every payment needs a bank account (`Payment.Account.AccountID`). Defaults fill the gap when the Magento canonical doesn't carry that information directly.

All dropdowns are **populated from the chassis's reference cache** — the live Xero TaxRates, Accounts list (filtered by `Type`) for your binding. Type-aheads work; typing a partial name filters the list.

## `default_xero_revenue_account_code`

**Reference bucket:** Xero `/Accounts` filtered to `Type ∈ {REVENUE, SALES, OTHERINCOME, DIRECTCOSTS, EXPENSE, OVERHEADS}`.

The revenue account applied to every invoice line that doesn't carry a per-line override. Set to the Xero account where most of your Magento sales should book (UK CoA default `200 Sales`).

Stored as the Xero `Code` (e.g. `"200"`), NOT the AccountID UUID — Xero invoice lines reference accounts by their human-readable code. The dropdown shows each account as `<Code> <Name>` (e.g. `"200 Sales"`).

The validator rejects values not in the cached account-code set with a red-underline `defaultXeroRevenueAccountCode` field error before save. Typos surface form-time, not at sync-time as a 400 dead-letter.

If unset and a line carries no specific routing, the worker omits `AccountCode` from the line — Xero applies the organisation's default. Setting this explicitly is recommended so reconciliation is deterministic.

## `default_xero_tax_type`

**Reference bucket:** Xero `/TaxRates`.

The fallback `LineItem.TaxType` for invoice lines whose Magento tax rate doesn't match anything in the per-line tax-routing map (not in v1 Xero scope; see Commercial knobs for the per-rate routing path on Sage). UK default `OUTPUT2` (20% sales tax); other regions vary.

Stored as the Xero `TaxType` machine code (e.g. `"OUTPUT2"`, `"NONE"`, `"INPUT"`), not the human label.

If unset, lines fall back to `NONE` (no tax). Most merchants set this to their primary VAT code so single-rate invoices don't have to set it per-line.

## `default_bank_account_id`

**Reference bucket:** Xero `/Accounts` filtered to `Type=BANK`.

The default Xero bank account used for `POST /Payments` when `invoice.paid` fires for a payment method *not* mapped in the [Payment-method map](/docs/settings/payment-methods).

Stored as the Xero `AccountID` UUID (Xero's `/Payments` write requires the UUID, not the code).

Optional. When unset:

- Payment methods explicitly mapped → payment routed to the mapped bank account.
- Payment methods unmapped + no default → invoice stays AUTHORISED in Xero; accountant reconciles manually when the cheque / bank transfer lands. **This is the documented B2B / net-terms behaviour** and on-purpose for offline payment methods (`checkmo`, `banktransfer`, `purchaseorder`).

When set:

- Unmapped methods route to this default account. Useful if you trust a single "catch-all" bank account to capture every online-card payment that didn't map specifically.

The chassis additionally validates that the chosen account's `Type=BANK` — Xero's `/Payments` rejects writes against `REVENUE` / `CURRENT` / etc. accounts with `BANKACCOUNT_NOT_VALID_FOR_PAYMENT` (see [XERO_API_QUIRKS §8](https://github.com/byte8io/byte8.io/blob/main/apps/ledger/__docs/XERO_API_QUIRKS.md#section-8)). The form-time validator surfaces misconfiguration before the invoice round-trips.

## Reference cache freshness

The reference cache rebuilds:

- **At connect time** (post-OAuth handshake), once.
- **Auto on settings page load** if older than 24 hours — the dashboard fires `refreshReferenceData(bindingId)` once-per-mount with a useRef guard. You'll see "Refreshing reference data from Xero…" briefly in the actions bar.
- **Manually** via the **Refresh** button beside the freshness timestamp on the settings page. Use this when you've added a new Xero account or tax rate and want it to appear in the dropdown immediately.

Freshness is shown as `Updated 14 minutes ago` (with absolute timestamp on hover). If something looks stale or missing, click Refresh.

## What if a default override is no longer valid?

If you delete a Xero account or tax rate that the binding's policy still references (e.g. you mapped `default_xero_revenue_account_code = "999"` and then deleted account 999 in Xero), the validator blocks save with a `not_in_reference` field error. Pick a replacement before saving.
