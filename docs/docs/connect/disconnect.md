---
sidebar_position: 3
title: Disconnect
description: Cleanly stop syncing — from either the Magento side or the chassis side. Covers what happens to in-flight outbox rows, sync state, and Xero OAuth tokens on disconnect.
---

# Disconnect

Two surfaces, both end-to-end safe.

## From the Magento admin

**Stores → Configuration → Byte8 → Xero Accounting → Disconnect**

What happens:

1. The Magento `Connection/Disconnect` controller calls `ByteClient::disconnect('xero')`, which POSTs `/v1/tenant/disconnect?provider=xero` to the chassis. The chassis flips the Xero binding's status to `revoked` and stops dispatching its sync jobs.
2. Magento clears `byte8/xero_accounting/tenant_id` + `byte8/xero_accounting/api_key` from `core_config_data`. **The Sage binding (if any) is untouched** — disconnect is per-provider.
3. The Connection Status block flips back to red ("Not connected") and the **Generate pairing code** button reappears.

**What stays:**
- `byte8_event_outbox` rows (with `provider = 'xero'`) — preserved as historical audit. The drain cron will skip them (they fail the chassis JWT verify since the api_key is gone).
- `byte8_entity_sync_state` rows for Xero — preserved so the admin grids can still show historical Xero Status chips on past invoices.
- The `entity_xref` table on the chassis (Xero invoice URL ↔ Magento entity mapping) — preserved so reconnecting later picks up the existing mapping rather than creating duplicate Xero entities.

**What goes:**
- The Magento Xero `api_key` (cleared from config_data).
- The chassis stops dispatching new jobs against this binding.

The disconnect is **best-effort** on the chassis side — even if the chassis is unreachable when you click Disconnect, the local clear still happens and Magento goes "not connected". Worst case: the chassis binding shows `connected` for a few minutes longer until the next status reconciliation, but no outbound calls succeed because the api_keys no longer match.

## From the ledger dashboard

`ledger.byte8.io/dashboard/bindings/{id}` → **Disconnect binding**.

The dashboard shows a confirmation dialog with a **What gets removed** / **What stays** breakdown so the operator knows exactly what they're committing to. On confirm:

1. Chassis flips the binding to `revoked`.
2. Xero OAuth tokens are revoked at Xero (best-effort `POST /v2/oauth/revoke` against `api.xero.com`).
3. The binding's `oauth_tokens` row is deleted from the chassis database.
4. Worker stops dispatching this binding's sync jobs.

The Magento side won't immediately notice — observers will keep enqueueing outbox rows because the merchant didn't click Disconnect on the Magento side. The drain cron will start failing those POSTs (chassis returns 401 because the binding is revoked) and the rows will dead-letter into `byte8_event_outbox` with `status='dead_lettered'`. The dead-letter banner on the Magento config page surfaces the count.

**Recommended order:** disconnect from Magento first (which auto-disconnects the chassis), not the other way round. The Magento-first disconnect avoids the dead-letter spike.

## Reconnecting

Reconnecting is identical to first connect — generate a new pairing code, paste into `ledger.byte8.io`, re-OAuth Xero. Because `entity_xref` is preserved, your existing Magento invoices that previously synced to Xero are recognised — no duplicate Xero entities created.

If you reconnect to a **different Xero company**, the `entity_xref` mappings won't apply (those URLs only exist in the original company). New syncs create new Xero entities; the dashboard sync history still shows the old runs against the old binding.

## Uninstalling the module

If you're uninstalling rather than just disconnecting:

```bash
# 1. Disconnect from Magento admin first (above)
# 2. Then:
bin/magento module:disable Byte8_XeroAccounting
composer remove byte8/magento-xero-accounting
bin/magento setup:upgrade
```

If you also installed Sage Accounting and want to keep it, leave `Byte8_Client` and `Byte8_Core` enabled — they're shared chassis modules. Only disable them if you're uninstalling every Byte8 connector.

The two tables (`byte8_event_outbox`, `byte8_entity_sync_state`) stay on disk after uninstall — they're audit data, and the Sage rows in them stay valid. Drop manually if you want a clean slate (only do this if you're uninstalling every Byte8 connector — Sage rows live in the same tables).

If you forget to disconnect first and just uninstall, the chassis binding stays in `connected` state with no Magento partner. The next time you try to install + pair, it'll just create a new binding (or you can reuse the old one from the dashboard).

## Cancelling your subscription

Disconnecting is independent of cancelling your Byte8 subscription. To cancel billing, do it from `byte8.io` → Account → Billing. Money-back guarantee terms live on the [Plans & pricing page](https://byte8.io/products/xero-accounting#pricing); subsequent invoices stop. The chassis stays connected for the remainder of the billing period and disconnects when the period ends.
