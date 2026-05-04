---
sidebar_position: 5
title: Commercial knobs
description: Invoice number prefix, customer name priority, default invoice status. Cosmetic + presentational knobs that change how Xero shows the entities the chassis posts.
---

# Commercial knobs

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Commercial Knobs** card.

Cosmetic + presentational knobs that control how Xero shows the entities the chassis posts. Mostly affect labelling / status, not the underlying data.

## `invoice_number_prefix`

**Default:** `null` (no prefix — Xero gets the Magento `increment_id` verbatim).

A short string (typically 3-6 chars) prepended to the Xero invoice + credit-note `reference`. Used by accountants to distinguish Magento-sourced documents from manually-created ones in the Xero UI.

Examples:
- `MAG-` → `MAG-100012345` in Xero (instead of just `100012345`)
- `WEB-` → `WEB-100012345`
- `M-` → `M-100012345`

The prefix applies to **both** invoice and credit-note references. Tolerates double-prefix on mid-flight rename — if you change the prefix from `MAG-` to `WEB-` and re-sync an invoice that already has `MAG-100012345` in Xero, the chassis won't double-prefix it.

## `customer_name_priority`

**Default:** `Company` (B2B-friendly).

When a Magento customer has both a company name and a person name, controls which becomes Xero's contact `organisation_name` vs `first_name` / `last_name`:

- **`Company`** — B2B convention. Xero contact `organisation_name` = company name (e.g. `Acme Ltd`). Person name lives on `first_name` / `last_name`.
- **`Person`** — B2C convention. Xero contact `first_name` / `last_name` = person name; company name folds into address line if present.

Has **no effect** on customers with only one name field set — only branches when both are present.

Pick `Company` if your accountant invoices businesses primarily; pick `Person` if your B2C list dominates and the occasional company should display under the buyer's name.

## `xero_invoice_status_default`

**Default:** `Draft` (invoices appear in Xero's Draft tab).

The initial Xero status applied to every `invoice.created` POST. Xero invoices have a status field that controls which tab they appear in (`Draft`, `Sent`, `Open`, `Overdue`, `Paid`). The map controls the *initial* status only — Xero transitions Open → Overdue → Paid based on its own logic once the invoice is past Draft.

| Value | When invoice appears in Xero |
|---|---|
| `Draft` | Draft tab — review-then-send workflow. Accountant clicks Mark as Sent in Xero UI to email + book it as Open AR. |
| `Sent` | Open tab — already booked as receivable. Skips the manual review step. |

Pick `Draft` if your accountant likes to review every invoice before it lands in the AR ledger (typical small-business workflow). Pick `Sent` if you want zero manual review and trust Magento's totals to be correct (typical mid-market workflow with an automated bookkeeping pipeline).

`invoice.paid` events still flip the invoice to Paid via the bank transaction explanation flow regardless of this initial status — so a `Draft` invoice that gets marked paid in Magento before the accountant reviews it will still get a bank explanation against the right invoice URL.

## What's NOT here yet

The Commercial Knobs card is intentionally short — these are presentation knobs, not policy. Knobs that **could** live here are deferred to v1.1+ until a real merchant asks:

- **Per-customer payment terms** — currently every invoice carries the binding-level `default_xero_payment_terms_days`. A per-customer override (e.g. account `42` always gets net-60) would let you mirror the Magento customer-group → terms relationship.
- **Xero `comments` template** — currently the chassis writes `Purchase Order: {po}` (when PO present) and `Credit for invoice {invoice_id} — {reason}` (on credit notes). A merchant-customisable template would let you change the wording.
- **Estimate flow** — Magento → Xero estimates supported on higher tiers; conversion (turn an accepted Xero estimate into a Magento order) is deferred.
- **Custom contact attributes** — Xero's contact has fields like `email_addresses`, `phone_number`, `mobile`, `default_payment_terms_in_days`. Today the chassis writes the basics; a richer mapping is straightforward but waiting for the first merchant ask.

If you need any of these, email `helo@byte8.io` with the use case — they're all small additions, but we want real-merchant signal before shipping.
