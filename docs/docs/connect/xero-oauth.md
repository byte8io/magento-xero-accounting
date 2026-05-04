---
sidebar_position: 2
title: Connect your Xero account
description: After pairing Magento, connect your Xero account to the chassis via standard OAuth. Tokens are stored encrypted at rest and refreshed automatically — you never see them in PHP.
---

# Connect your Xero account

Once your Magento install is paired (see [Pairing-code Connect flow](/docs/connect/pairing-code)), the next step is connecting the Xero account you want to sync into.

This happens **entirely on `ledger.byte8.io`** — the Magento module never sees a Xero OAuth token, never holds a Xero client secret, never makes a request directly against `api.xero.com`. The chassis owns Xero authentication centrally.

## The flow

### 1. Open the Xero connect page

`ledger.byte8.io/dashboard/connect-xero`. You can land there from:

- **First-run prompt** — after pairing Magento, the dashboard surfaces a "Connect your Xero account" CTA on the home tile.
- **Bindings page** — `dashboard/bindings/{id}` shows a "Xero not connected yet" banner with the same CTA.

### 2. Authorise via Xero

Click **Connect Xero** — you're redirected to Xero's OAuth consent screen, scoped to:

- `full_access` — read + write on contacts, invoices, credit notes, projects, bank accounts, bank transactions, bank transaction explanations, categories, expenses, users.

Xero doesn't have a region picker the way Sage does — `api.xero.com/v2` is the single global endpoint, with company location stored on the company record itself. Pick the Xero **company** you want this Magento install to sync into during the consent screen — the binding is locked to that company after consent.

Approve. Xero redirects back to `ledger.byte8.io` with an OAuth authorisation code.

### 3. Chassis exchanges + stores

The chassis exchanges the code for an access + refresh token pair. Both are stored **encrypted at rest** in the chassis database (AES-GCM with a server-side master key). The merchant never sees the plaintext token; the connector never has it on disk in PHP.

Access tokens are 1-hour-TTL on Xero's side; the chassis refreshes them transparently before each provider call (`OauthToken::load_fresh` in the worker).

### 4. Reference data prefetch

Immediately after connect, the chassis builds a **reference cache** for this Xero company: income categories, bank accounts, payment-method targets. This takes ~3-5 seconds and runs once. The dashboard's settings dropdowns (default income category, default bank account, payment-method map, item-type map) populate from this cache.

Reference data is auto-refreshed once per 24 hours — see the [Default mappings](/docs/settings/default-mappings) page for how to manually refresh.

### 5. Done — your binding is live

The dashboard's **Bindings** page now shows a green binding row:

```
Magento your-shop.example.com  ↔  Xero your-company-name
                                              [Settings] [Sync history]
```

From here, the next step is configuring sync policy — see [Sync settings](/docs/settings/sync-behavior). Most merchants ship with all defaults and never touch the policy.

## Sandbox vs production

Xero's sandbox environment lives at `api.sandbox.xero.com/v2` and uses a separate set of OAuth client credentials. The chassis points at production by default; if you want to test against sandbox first (recommended for the first design partner), email `helo@byte8.io` — we'll point your tenant at the sandbox endpoint while you validate, then flip to production before going live.

## Why this design

We frequently get asked: *can I just give you my Xero username + password instead of OAuth?* No — Xero only exposes OAuth, and the chassis is built around it. This is also why:

- We don't need to ask for your Xero password ever.
- Token rotation (Xero's 1-hour access token TTL + 7-day refresh-token rotation) is invisible to you.
- Revoking access from Xero's Settings → Connected apps page instantly disables the chassis from posting to your books — your data security stays under your control.

## Multiple Xero companies

Each Magento binding talks to **one** Xero company. If you have multiple:

- One Magento install + multiple Xero companies → spin up multiple bindings on the chassis (Bindings page → New binding) and pair separate Magento environments to each. The connector itself doesn't multiplex one Magento install onto N Xero companies (that introduces ambiguity at every observer fire — which company does *this* invoice go to?).
- Multiple Magento installs + one Xero company → pair each Magento install separately, point each binding at the same Xero company. Then use `website_filter` / `store_filter` in sync policy to scope which orders flow per binding.

Per-plan limits on the number of Xero companies + Magento websites live on the [Plans & pricing page](https://byte8.io/products/xero-accounting#pricing).

## Disconnecting

See [Disconnect](/docs/connect/disconnect) — covers both the Magento side ("stop publishing") and the chassis side ("revoke the binding + invalidate Xero tokens").
