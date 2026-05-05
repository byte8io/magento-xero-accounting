---
sidebar_position: 5
title: Commercial knobs
description: Invoice number prefix, customer name priority, default invoice status, default payment terms. Cosmetic + presentational knobs that change how Xero shows the entities the chassis posts.
---

# Commercial knobs

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Commercial knobs** + **Xero defaults** cards.

Cosmetic + presentational knobs that control how Xero shows the entities the chassis posts. Mostly affect labelling / status, not the underlying data.

## `invoice_number_prefix`

**Default:** `null` (no prefix — Xero gets the Magento `increment_id` verbatim).

A short string (typically 3-6 chars) prepended to the Xero `InvoiceNumber` (and `CreditNoteNumber`). Used by accountants to distinguish Magento-sourced documents from manually-created ones in the Xero UI.

Examples:
- `MAG-` → `MAG-100012345` in Xero (instead of just `100012345`)
- `WEB-` → `WEB-100012345`
- `M-` → `M-100012345`

The prefix applies to **both** invoice and credit-note numbers. Tolerates double-prefix on mid-flight rename — if you change the prefix from `MAG-` to `WEB-` and the invoice already has `MAG-100012345` in Xero, the chassis won't double-prefix on retry.

## `customer_name_priority`

**Default:** `Company` (B2B-friendly).

When a Magento customer has both a company name and a person name, controls which becomes the Xero contact's primary `Name`:

- **`Company`** — B2B convention. `Contact.Name` = company name (e.g. `Acme Ltd`). Person name lives on `Contact.FirstName` / `Contact.LastName`.
- **`Person`** — B2C convention. `Contact.Name` = `"<First> <Last>"`; the company name folds into the address block if present.

Has **no effect** on customers with only one name field set — only branches when both are present.

Pick `Company` if your accountant invoices businesses primarily; pick `Person` if your B2C list dominates and the occasional company should display under the buyer's name.

## `xero_invoice_status_default`

**Default:** `AUTHORISED` — invoices land on Xero's AR ledger immediately.

The initial Xero status applied to every `invoice.created` POST. Xero invoice status moves through a fixed lifecycle (`DRAFT` → `SUBMITTED` → `AUTHORISED` → `PAID` / `VOIDED` / `DELETED`); the chassis controls the **initial** state only — Xero computes everything beyond that from the invoice's payment / void state.

| Value | When invoice appears in Xero |
|---|---|
| `DRAFT` | Draft tab — review-then-authorise workflow. Accountant clicks Approve in Xero UI to flip to AUTHORISED and book it as receivable. |
| `SUBMITTED` | Awaiting Approval tab — flagged for someone with approval permission to authorise. Useful for two-person bookkeeping setups. |
| `AUTHORISED` | Awaiting Payment tab — already booked as receivable. Skips the manual review step. |

Pick `DRAFT` if your accountant likes to review every invoice before it lands in the AR ledger (typical small-business workflow). Pick `AUTHORISED` if you want zero manual review and trust Magento's totals to be correct (typical mid-market workflow with an automated bookkeeping pipeline).

`invoice.paid` events still flip the invoice to PAID via `POST /Payments` regardless of this initial status — so a `DRAFT` invoice that gets marked paid in Magento before the accountant authorises it will still get a payment row attached.

## `default_xero_payment_terms_days`

**Default:** `null` → translator uses 14 days (net-14).

Number of days from invoice date to Xero `DueDate` when no per-customer terms are configured. Common values:

- `0` — net cash (B2C — money's already in the till)
- `7`, `14`, `30` — standard B2B AR terms
- `60`, `90` — extended terms for large B2B accounts

This populates Xero's `DueDate` field directly; Xero uses it for the AR aging buckets (Open → Overdue once `DueDate < today`).

If you want per-customer terms (some B2B accounts on 30, others on 60), either set the binding-level default to your most common value and edit the Xero invoice's DueDate manually for outliers, or — for v1.1+ — wait for the planned per-customer terms map. Email `helo@byte8.io` if this is blocking you.

## What's NOT here yet

The Xero settings surface is intentionally narrow at MVP — these are presentation knobs, not policy. Knobs that **could** live here are deferred to v1.1+ until a real merchant asks:

- **Per-customer payment terms** — currently every invoice carries the binding-level `default_xero_payment_terms_days`. A per-customer override (e.g. account `42` always gets net-60) would mirror the Magento customer-group → terms relationship.
- **Per-line tax routing (`taxClassMap`)** — the Xero translator already accepts a percentage → TaxType map; the dashboard UI gating is the only thing missing. Build when a multi-rate merchant asks.
- **Branding theme override** — Xero invoices can be assigned a `BrandingThemeID` (logo + colour palette). Today the chassis lets Xero apply the org default; a binding-level override would let one Magento install differentiate invoice branding by website.
- **Custom contact attributes** — Xero's Contact has fields like `IsCustomer`, `IsSupplier`, `Website`, `BankAccountDetails`. Today the chassis writes the basics (Name, ContactNumber, addresses, phones, email); a richer mapping is straightforward but waiting for the first merchant ask.
- **ContactGroups sync** — Magento customer groups → Xero ContactGroups. On the Growth-tier roadmap; build when a paying merchant asks.

If you need any of these, email `helo@byte8.io` with the use case — they're all small additions, but we want real-merchant signal before shipping.
