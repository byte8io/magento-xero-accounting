---
title: FAQ
description: Common questions about regions, multi-store, security, data residency, supported entities, and what happens to your data if you cancel.
---

# FAQ

## Plans & billing

For pricing, tier comparison, free-trial terms, money-back guarantee, and overage policy, see the **[Plans & pricing page on byte8.io](https://byte8.io/products/xero-accounting#pricing)**. We keep all commercial details there so this docs site stays purely about the product behaviour.

The Magento module itself (`byte8/magento-xero-accounting` + `byte8/module-client` + `byte8/module-core`) is MIT-licensed and free to install. The connector — what makes the module talk to Xero — is the SaaS subscription. You install the module *and* sign up for a Byte8 plan; the two together make the connector work.

## Regions

### Which Xero regions are supported?

Xero runs a single global API at `api.xero.com/v2`. Unlike Sage, there's no region picker — your Xero company carries its own location and tax setup, and the chassis routes everything through the one endpoint. UK, US, EU companies all work the same way at the connector level.

Sandbox lives at `api.sandbox.xero.com/v2` and uses separate OAuth client credentials — see [Xero OAuth → Sandbox vs production](/docs/connect/xero-oauth#sandbox-vs-production).

### Magento storefront in one country, Xero company in another?

Supported. Magento's per-store currency / tax setup drives what we send; Xero treats the invoice's stated currency + tax routing at face value. Cross-currency settlement (e.g. EUR invoice paid via a GBP bank account) is handled by Xero's own FX translation server-side — see [Payment-method map → Cross-currency payments](/docs/settings/payment-methods#cross-currency-payments).

### Multiple Xero companies?

Each Magento binding maps to one Xero company. For multi-company setups, spin up multiple bindings on the chassis and either: (a) use `website_filter` / `store_filter` per binding to scope which Magento orders flow to which Xero company, or (b) pair separate Magento environments per binding. Per-plan limits on the number of bindings live on the [Plans & pricing page](https://byte8.io/products/xero-accounting#pricing).

## Coexistence with Sage Accounting

### Can I run Xero + Sage on the same Magento install?

Yes. The two connectors share the `byte8/module-client` chassis (outbox, JWT auth, sync-state mirror) and add per-provider columns / aliases so they don't trample each other. You'll see two columns on Sales → Invoices ("Sage Status" + "Xero Status"), two info blocks on the detail page, and two pairing surfaces on the config page. Each is paired and disconnected independently — see [Pairing-code Connect flow → Pairing alongside Sage Accounting](/docs/connect/pairing-code#pairing-alongside-sage-accounting).

### Same invoice, two providers?

Yes — the same Magento invoice can sync to **both** Sage and Xero simultaneously if you have both connectors paired. Each provider's outbox / sync-state / `entity_xref` lookup is keyed independently. Most merchants pick one (the system their accountant uses); a few want a "primary + audit copy" setup, which works fine.

## Multi-store / multi-website

### Magento has 5 websites — does each need its own binding?

No. One binding can sync any number of Magento websites into one Xero company. Use `website_filter` on the sync policy if you only want some websites flowing.

### Per-website Xero companies?

Each binding pairs to one Xero company; the merchant maps Magento `website_id`s to bindings via `website_filter`. Per-plan limits on the number of Xero companies are on the [Plans & pricing page](https://byte8.io/products/xero-accounting#pricing).

### Can two bindings share the same Xero company?

Technically yes (the chassis doesn't prevent it), but it'll cause `entity_xref` conflicts on the same customer / product appearing on both bindings. Don't.

## Security

### Where do my Xero OAuth tokens live?

Encrypted at rest (AES-GCM) in the chassis database. Never on your Magento server, never in PHP, never in Magento config. The chassis refreshes them transparently before each provider call.

### Can Byte8 staff access my Xero data?

The chassis logs Magento entity ids, Xero entity URLs, sync status, and error messages — never invoice line content or customer PII beyond what's necessary for diagnosing failures. Token-level Xero access is restricted to the worker process; no Byte8 staff has interactive access to your Xero tokens.

### What's the inbound webapi attack surface on my Magento?

The connector exposes 7 REST endpoints under `/V1/byte8/*`:

- `GET /V1/byte8/{ping,payment-methods,invoice/:id,customer/:id,creditmemo/:id,payment/:id,product/:id}`
- `POST /V1/byte8/sync-state`

All 7 are JWT-authed via `JwtUserContext` against the per-tenant `api_key`. The synthetic ACL plugin grants the JWT-authed integration user access to `Byte8_Client::byte8_webapi` only — no scope to cart, customer-create, admin, or any core Magento resource.

The pairing-code endpoint (`POST /V1/byte8/xero/setup/pair`) is the **only** unauthenticated webapi route, and it accepts requests only when a fresh-within-30-min pairing-code hash matches.

### What if I want to revoke chassis access immediately?

Disconnect from `ledger.byte8.io/dashboard/bindings/{id}` → Disconnect binding. Within seconds the chassis flips the binding to `revoked`, stops dispatching jobs, and revokes Xero tokens at Xero. The Magento side will start dead-lettering subsequent observer-fired events; the dead-letter banner surfaces the count.

For nuclear-option: revoke the connection from Xero's Settings → Connected apps page, then disconnect the binding on the chassis dashboard. The chassis's signed JWTs against Xero will 401; the binding effectively goes dark immediately.

## Data

### Where does the chassis run?

UK + EU regions on Hetzner Cloud (Falkenstein + Helsinki), with database in eu-west-1. US region planned for the first US design partner — until then US-Xero merchants are served from EU with the cross-Atlantic latency.

### What data leaves my Magento?

Every observer-fired event publishes a JSON payload to the chassis. The shapes are documented in `apps/ledger/__docs/LEDGER_INTEGRATION_SPEC.md` — basically the canonical Magento entity (snake_case) for invoices / credit memos / customers / products, plus minimal context (`magento_entity_id`, `website_id`, `store_id`, `occurred_at`).

Payment card details, Magento admin user PII, and any Magento entity not explicitly listed in [What syncs](/docs/what-syncs) **never leave Magento** — the connector simply doesn't read or transmit them.

### What happens if I cancel my subscription?

- Chassis stops dispatching new jobs after the billing period ends.
- The Magento module disconnects (auto-flips to "Not connected" on cancellation).
- Your historical sync data (`sync_runs`, `entity_xref`) stays in the chassis database for 90 days for audit; after 90 days it's purged.
- Your Xero data is untouched — every Xero entity the chassis created stays in Xero. You don't lose your accounting history.
- Re-subscribe within 90 days to restore the binding without re-OAuthing Xero.

## Entities

### Why no Xero → Magento sync?

It's Enterprise on request — not a v1 feature. Doing it well needs Xero webhook surface, Magento write endpoints for products / contacts, and a conflict-resolution policy that no design partner has asked for. We'll build it on a custom contract for an Enterprise merchant who requests it.

### Why no `payment.captured` for offline payments?

Magento doesn't have an API to attach an offline payment (cheque clearing, bank transfer landing) to an existing invoice after the fact. So our chassis can't reliably link the Xero payment to the right Xero invoice. Best practice: leave invoices Open in Xero, accountant manually reconciles via Xero's bank-feed import when the money lands. Aligns with how every Xero user already handles AR.

### Estimates and quotes?

Magento → Xero estimates supported on higher tiers — see the [Plans & pricing page](https://byte8.io/products/xero-accounting#pricing) for tier-by-tier feature gating. Xero → Magento conversion (turn an accepted Xero estimate into a Magento order) is deferred — needs Magento write endpoints + commerce-side ordering logic.

### Stock-level sync?

Not synced. Xero's product catalog isn't designed for inventory tracking — there's no `quantity_in_stock` or `stock_movements` family equivalent. Use Sage Accounting (or another Magento module) if you need stock-level sync into your accounting system.

## Compatibility

### Adobe Commerce Cloud (ECE)?

Should work — pure Composer, no infrastructure dependencies. Confirm with first ECE design partner; nothing in the architecture suggests issues.

### Hyvä storefront?

The connector has zero frontend assets — all observers fire on the backend. Hyvä is fully supported; nothing to configure differently.

### Magento 2.3 support?

No. 2.4.4 is the floor (MariaDB / MySQL feature dependencies). If a single design partner needs 2.3, contact us.

### B2B Company Accounts?

Higher tiers handle the B2B-specific flows (Magento `Company` entities → Xero `contact` with company-name routing — see [Commercial knobs → customer_name_priority](/docs/settings/commercial#customer_name_priority)). See the [Plans & pricing page](https://byte8.io/products/xero-accounting#pricing) for tier gating.
