---
title: Troubleshooting
description: Operator-facing diagnoses for the most common sync failures — Xero validation errors, dead-letter rows, the catalogued Xero quirks, cron, auth drift, missing chips.
---

# Troubleshooting

The most common sync failures, in rough order of how often they hit. Each has a "symptom" (what you see in the admin or dashboard) and "fix" (what to do about it).

## Cron not running

**Symptom:** chips stay `⏳ Pending` indefinitely. Outbox row count grows.

**Diagnose:**
```bash
bin/magento cron:run --group=default
# Should run cleanly. If it errors, your install's cron pipeline is broken.
```

**Fix:** verify your system cron is calling Magento's cron entry every minute. Standard production setup:
```cron
* * * * * /bin/bash -c "cd /var/www/magento && bin/magento cron:run --group=default 2>&1 >> var/log/cron.log"
```

If using ECE or a managed Magento host, check their cron configuration page.

## Dead-letter banner shows up

**Symptom:** banner on the config page: "N dead-lettered events".

**Diagnose:**
```bash
bin/magento byte8:client:outbox:inspect --provider=xero
```

Read the `last_error` column for the cause. Common categories:

- `HTTP 401 Unauthorized` → auth drift. Re-pair (see below).
- `HTTP 400 Validation` → Xero rejected the payload. Read the `errors[]` array in the response — Xero gives you the offending field path. Read on for the catalogued quirks.
- `HTTP 5xx` → chassis-side or Xero-side outage. Wait + re-queue.

**Fix:** see [Dead-letter banner](/docs/magento-admin/dead-letter-banner) for the full triage flow.

## Auth drift (401s)

**Symptom:** every outbox row dead-letters with `HTTP 401`. The chassis dashboard shows the Magento binding `magento_connection_status: token_revoked`.

**Cause:** the per-tenant `api_key` shared between Magento and the chassis has drifted. Most common cause: someone re-paired one side without storing the new code.

**Fix:** disconnect from the Magento config page (or the chassis dashboard), then re-pair with a fresh code. See [Pairing-code Connect flow](/docs/connect/pairing-code).

## "tenant has no active xero binding" 400

**Symptom:** dashboard / chassis returns "tenant has no active xero binding" when you'd expect it to.

**Cause:** the webhook URL didn't carry `?provider=xero` (or the chassis is on an old version that hardcoded `sage_accounting`). The chassis can't tell which provider's binding to dispatch against.

**Fix:** verify your `byte8/module-client` is recent (the per-provider routing landed alongside the Xero connector — see the [v1.0.0 release notes](/blog/v1-0-0-release)). Older chassis-client pairs may need a chassis upgrade.

## Backfill pre-PR7 rows

**Symptom:** existing invoices that synced before PR7 was deployed show the `—` chip on the grid.

**Cause:** the chassis only writes `byte8_entity_sync_state` rows on terminal `mark_*` calls **after** PR7 deployed. Historical sync history exists in the chassis dashboard but doesn't have a Magento mirror row.

**Fix (option 1, single rows):** retry from the chassis dashboard (`ledger.byte8.io/dashboard/sync` → row → Retry). The retry re-fires terminal mark + the PushSyncState callback, which populates the Magento mirror.

**Fix (option 2, batch):** SQL backfill on the Magento side:
```sql
INSERT INTO byte8_entity_sync_state
    (entity_type, magento_id, provider, sync_status, last_sync_at)
SELECT 'invoice', i.entity_id, 'xero', 'synced', NOW()
FROM sales_invoice i
WHERE i.entity_id IN (<comma-list-of-already-synced-ids>);
```

**Fix (option 3, future):** wait for the planned `byte8:client:sync-state:backfill` chassis CLI that walks `sync_runs WHERE status='succeeded' AND provider='xero'` and enqueues a PushSyncState per row. Slated for v1.1.

## Xero catalogued quirks

We've found and worked around eight non-obvious Xero behaviours so far. Each is invisible to merchants (the chassis handles it) but worth knowing for log-reading. The full list lives in `apps/ledger/__docs/XERO_API_QUIRKS.md`; we add an entry every time the worker logs a 4xx that isn't an obvious operator typo.

### §1 — `Phones[]` array auto-inflated to all four type slots on responses

Cosmetic. Xero's `GET /Contacts/{id}` returns a fixed-size 4-element `Phones[]` array (DEFAULT, MOBILE, FAX, DDI), filling unused slots with empty strings. Writes can send only the slots you care about. The chassis ignores empty slots when reading.

### §3 — Shipping-line `TaxType` derived from canonical, not from the merchant's default

Xero ignores the invoice-level "shipping tax rate" hint Sage exposes — the per-line `TaxType` controls everything. The chassis derives the shipping line's TaxType from `shipping_tax_amount / shipping_amount` (zero-rate → `NONE`, 20% → the merchant's default rate).

### §4 — Per-line `DiscountAmount` (NOT a synthetic discount line)

Xero's invoice model has a per-line `DiscountAmount` field that's subtracted BEFORE per-line tax. Magento's invoice-level `discount_amount` is spread proportionally across lines via the totals identity (`line_subtotal + line_tax - line_total`) and lands as `DiscountAmount` per-line. The previous synthetic-discount-line approach (used by FreeAgent) under-taxed Xero invoices.

### §5 — `Organisation is not subscribed to currency`

Org-level setting, not an API auth issue. Operators must enable the relevant currency in Xero org settings (Settings → Currencies). Xero's Standard plan caps at 2 active currencies; Premium plans have higher caps.

### §6 — `entity_xref` reverse-key constraint

Internal — Postgres write path. The `entity_xref` table has TWO unique constraints (natural key + reverse key). When Xero deduplicates server-side on `ContactNumber` (see §7 below), the reverse-key fires if the same ContactID gets linked to two different Magento ids. Surfaced as `entity_xref_reverse_collision` error code; the dashboard provides a SQL recipe for the operator reconcile.

### §7 — ONE Contact per Magento customer, NOT one per currency

Xero is currency-flexible — a single Contact transacts in any currency the org has enabled (provided the Contact's `DefaultCurrency` is unset). The previous Sage-style per-currency contact xref keying caused reverse-key collisions; the chassis now keeps one Contact per Magento customer.

### §8 — `/Payments` is a one-shot link (no allocation step) and only accepts `Type=BANK` accounts

Unlike Sage's two-step `contact_payment + contact_allocation`, Xero's `POST /Payments` links one invoice to one bank account in a single round-trip. `/Payments` writes are rejected against revenue / asset / current accounts — only `Type=BANK` is accepted. The settings UI filters the bank-account dropdown accordingly.

(See `XERO_API_QUIRKS.md` for §1–§8 in full, with reproduction steps + operator cleanup recipes.)

## Live API probing for hard validation errors

If a 4xx persists despite all the above, the fastest diagnostic is poking Xero's API directly with a known-good token:

1. Get the binding's current OAuth token from the chassis CLI:
```bash
cargo run -p ledger-cli -- oauth:status <binding-uuid> --reveal-token
# (Dev-only; production tokens never get revealed)
```

2. Curl Xero's API with the token + `Xero-tenant-id`:
```bash
TOKEN='...'
TENANT='...'
curl -sS \
  -H "Authorization: Bearer $TOKEN" \
  -H "Xero-tenant-id: $TENANT" \
  -H "Accept: application/json" \
  "https://api.xero.com/api.xro/2.0/Invoices?page=1" | jq
```

3. Reconstruct the failing payload from the chassis worker logs (the WARN-level "Xero 4xx — full error envelope" line dumps the raw body), tweak fields one at a time until Xero accepts. The 400 response carries `Elements[].ValidationErrors[].Message` so you can pinpoint the offending key.

This is what we use to find new Xero quirks. If you hit something not in the catalogue above, send the worker log line + the failing canonical to `helo@byte8.io` — we'll add the workaround.

## Duplicate contact (server-side dedup)

**Symptom:** `customer.upserted` returns the existing ContactID rather than creating a new row.

**Cause:** Xero deduplicates Contacts server-side on `ContactNumber`. The chassis writes `ContactNumber=<magento_customer_id>`, so the same Magento customer always lands on the same Xero Contact even across replays.

**Behaviour:** this is correct and intentional. Xero responding with the existing ContactID is a feature, not a failure — the chassis's `entity_xref` write captures whichever ContactID Xero returned. If you see a `entity_xref_reverse_collision` error, that's the §6 / §7 case: an old per-currency keyed xref colliding with the unified Contact. The dashboard surfaces a SQL recipe to reconcile.

## When to email support vs DIY

- **DIY:** dead-letter rows for catalogued causes (ref-cache stale, payment method unmapped, sync filter excluded) → re-queue after fixing.
- **Email Byte8 support (`helo@byte8.io`):** novel 4xx errors not in the catalogue, billing / subscription questions, anything where the chassis state seems out of sync with what you see in Magento or Xero.

Include in the email: tenant id (visible on the chassis dashboard), the Magento `entity_id` of the affected invoice, and the worker log line if you have it.
