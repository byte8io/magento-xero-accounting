---
sidebar_position: 2
title: Connect your Xero account
description: After pairing Magento, connect your Xero organisation to the chassis via OAuth 2.0 with granular scopes. Tokens are stored encrypted at rest and refreshed automatically — you never see them in PHP.
---

# Connect your Xero account

Once your Magento install is paired (see [Pairing-code Connect flow](/docs/connect/pairing-code)), the next step is connecting the Xero organisation you want to sync into.

This happens **entirely on `ledger.byte8.io`** — the Magento module never sees a Xero OAuth token, never holds a Xero client secret, never makes a request directly against `api.xero.com`. The chassis owns Xero authentication centrally.

## The flow

### 1. Open the Xero connect page

`ledger.byte8.io/dashboard/connect-xero`. You can land there from:

- **First-run prompt** — after pairing Magento, the dashboard surfaces a "Connect your Xero account" CTA on the home tile.
- **Bindings page** — `dashboard/bindings/{id}` shows a "Xero not connected yet" banner with the same CTA.

### 2. Authorise via Xero

Click **Connect Xero** — you're redirected to Xero's OAuth consent screen, scoped to the **granular** scope set Xero introduced in March 2026:

- `openid profile email` — basic OIDC identity (so the chassis knows which Xero user authorised).
- `offline_access` — issues a refresh token. Without this, access tokens would expire after 30 minutes with no way to renew.
- `accounting.contacts` — read/write Contacts.
- `accounting.invoices` — read/write Invoices + CreditNotes.
- `accounting.payments` — read/write Payments.
- `accounting.settings.read` — read TaxRates + Accounts + BrandingThemes (the reference data the dashboard's settings dropdowns populate from).

Xero used to offer a broad `accounting.transactions` scope but deprecated it in March 2026 in favour of these per-resource scopes — narrower blast radius, and exactly what the chassis needs.

Xero has no region picker — `api.xero.com` is the single global endpoint. Pick the Xero **organisation** you want this Magento install to sync into during the consent screen — the binding is locked to that organisation after consent (the `Xero-tenant-id` is captured from `GET /Connections` post-consent and pinned on the binding).

Approve. Xero redirects back to `ledger.byte8.io` with an authorisation code.

### 3. Chassis exchanges + stores

The chassis exchanges the code for an access + refresh token pair. Both are stored **encrypted at rest** in the chassis database (AES-GCM with a server-side master key). The merchant never sees the plaintext token; the connector never has it on disk in PHP.

Xero access tokens have a 30-minute TTL; refresh tokens rotate on each use (the new refresh comes back with each token refresh). The chassis's `OauthToken::load_fresh` refreshes them transparently before each provider call.

### 4. Tenant discovery

Immediately after the code exchange, the chassis calls `GET /Connections` to discover which Xero tenant(s) the access token grants access to. The merchant's chosen organisation's `tenantId` is persisted on `connector_bindings.provider_business_id` — every subsequent worker call sets `Xero-tenant-id: <tenantId>` on the request header so Xero routes the write to the right organisation.

### 5. Reference data prefetch

The chassis builds a **reference cache** for this Xero organisation: tax rates, accounts (revenue + bank), branding themes. Takes a few seconds and runs once on Connect. The dashboard's settings dropdowns (default revenue account, default tax type, default bank account, payment-method map) populate from this cache.

Reference data is auto-refreshed on every load of the settings page if older than 24 hours, and on demand via the **Refresh** button. See the [Default mappings](/docs/settings/default-mappings) page for detail.

### 6. Done — your binding is live

The dashboard's **Bindings** page now shows a green binding row:

```
Magento your-shop.example.com  ↔  Xero Your Organisation Name
                                              [Settings] [Sync history]
```

From here, the next step is configuring sync policy — see [Sync settings](/docs/settings/sync-behavior). Most merchants ship with all defaults and never touch the policy.

## Demo Company vs production organisation

Xero offers a **Demo Company** mode inside every Xero account — a sandbox-style organisation pre-populated with sample data, useful for first-time integrators who want to validate the round-trip without touching their real books. It uses the same `api.xero.com` endpoint and the same OAuth client credentials as production; the chassis treats it identically.

To use Demo Company:

1. In Xero, top-right organisation switcher → **My Xero** → **Try the Demo Company**.
2. Run through the chassis Connect flow as normal — when Xero asks "which organisation?", pick the Demo Company.
3. Round-trip a few Magento invoices; verify they land correctly.
4. When you're ready for real data, disconnect the binding, re-Connect and pick your production organisation.

We strongly recommend the first design partner runs through the Demo Company once before flipping to production.

## Why this design

We frequently get asked: *can I just give you my Xero username + password instead of OAuth?* No — Xero only exposes OAuth, and the chassis is built around it. This is also why:

- We don't need to ask for your Xero password ever.
- Token rotation (Xero's 30-minute access token TTL + refresh-token rotation on each refresh) is invisible to you.
- Revoking access from Xero's My Apps page (Settings → Connected apps in the org switcher) instantly disables the chassis from posting to your books — your data security stays under your control.

## Multiple Xero organisations

Each Magento binding talks to **one** Xero organisation. If you have multiple:

- One Magento install + multiple Xero organisations → spin up multiple bindings on the chassis (Bindings page → New binding) and pair separate Magento environments to each. The connector itself doesn't multiplex one Magento install onto N Xero organisations (that introduces ambiguity at every observer fire — which org does *this* invoice go to?).
- Multiple Magento installs + one Xero organisation → pair each Magento install separately, point each binding at the same Xero organisation. Then use `website_filter` / `store_filter` in sync policy to scope which orders flow per binding.

Per-plan limits on the number of Xero organisations + Magento websites live on the [Plans & pricing page](https://byte8.io/products/xero-accounting#pricing).

## Disconnecting

See [Disconnect](/docs/connect/disconnect) — covers both the Magento side ("stop publishing") and the chassis side ("revoke the binding + invalidate Xero tokens at Xero").
