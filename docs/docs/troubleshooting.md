---
title: Troubleshooting
description: Operator-facing diagnoses for the most common sync failures — Xero 422s, dead-letter rows, the catalogued Xero v2 quirks, cron, auth drift, missing chips.
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
- `HTTP 422 Validation failed: …` → Xero rejected the payload. Read on for the catalogued quirks.
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

## Xero v2 catalogued quirks

We've found and worked around **two** non-obvious Xero v2 behaviours so far. Each is invisible to merchants (the chassis handles it) but worth knowing for log-reading. The full list lives in `apps/ledger/__docs/XERO_API_QUIRKS.md`; we add an entry every time the worker logs a 4xx that isn't an obvious operator typo.

### §1 — `payment_terms_in_days` required on every POST

Xero's `POST /v2/invoices` returns `422 Validation failed: payment_terms_in_days is not a number` if the field is missing — even on net-cash invoices, even on £0 lines. The chassis always sends `Some(default_xero_payment_terms_days || 30)` regardless of the merchant's input.

This was non-obvious because `#[serde(skip_serializing_if = "Option::is_none")]` on the optional field caused the chassis to omit it for net-cash flows; Xero rejected. Fixed by always passing a value through; the field is documented as required by Xero's spec but easy to miss.

### §2 — Numeric fields stringified on responses (POST + GET)

Xero's API stringifies several numeric fields on responses (`exchange_rate: "1.0"`, `total_value: "100.00"`, etc.) but accepts them as numeric on input. Trying to decode a posted-and-returned invoice into a single struct fails with `invalid type: string "1.0", expected f64`.

The chassis works around it with **minimal-decode envelopes** for create-response decoding — types like `XeroInvoiceCreatedEnvelope` only read the `url` field, which is always a stable string identifier. The full invoice can be re-fetched later with a more permissive decoder if needed.

If you ever see a `serde` decode error in the chassis logs after a POST, the fix is usually to add another stringified field to the workaround list (or to introduce a new minimal-decode envelope for that endpoint).

## Live API probing for hard 422s

If a 4xx persists despite all the above, the fastest diagnostic is poking Xero's API directly with a known-good token:

1. Get the binding's current OAuth token from the chassis CLI:
```bash
cargo run -p ledger-cli -- oauth:status <binding-uuid> --reveal-token
# (Dev-only; production tokens never get revealed)
```

2. Curl Xero's v2 API with the token:
```bash
TOKEN='...'
curl -sS -H "Authorization: Bearer $TOKEN" \
  "https://api.xero.com/v2/invoices?per_page=5" | jq
```

3. Reconstruct the failing payload from the chassis worker logs (the WARN-level "Xero 4xx — full error envelope" line dumps the raw body), tweak fields one at a time until Xero accepts. The 422 response carries `errors` with `field` paths so you can pinpoint the offending key.

This is what we use to find new Xero quirks. If you hit something not in the catalogue above, send the worker log line + the failing canonical to `helo@byte8.io` — we'll add the workaround.

## Duplicate contact 422

**Symptom:** `customer.upserted` 422s with "email has already been taken" or similar.

**Cause:** Xero rejects POST `/v2/contacts` when an existing contact has the same email — a "soft duplicate" rather than a real validation failure.

**Fix (live in the chassis):** the Xero provider catches the 422, GETs `/v2/contacts?email=…` to find the existing contact, stores the URL in `entity_xref`, and the worker mark_succeeds the run as if the POST had returned the existing contact directly. This is invisible to operators — but if you see it in the dead-letter pile, the chassis has a real bug; please email.

## When to email support vs DIY

- **DIY:** dead-letter rows for catalogued causes (ref-cache stale, payment method unmapped, sync filter excluded) → re-queue after fixing.
- **Email Byte8 support (`helo@byte8.io`):** novel 422s not in the catalogue, billing / subscription questions, anything where the chassis state seems out of sync with what you see in Magento or Xero.

Include in the email: tenant id (visible on the chassis dashboard), the Magento `entity_id` of the affected invoice, and the worker log line if you have it.
