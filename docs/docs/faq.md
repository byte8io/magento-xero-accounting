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

Xero runs a single global API at `api.xero.com/api.xro/2.0/`. Unlike Sage, there's no region picker — your Xero organisation carries its own country / tax setup, and the chassis routes everything through the one endpoint. UK, US, EU, AU, NZ organisations all work the same way at the connector level.

For sandbox-style validation, Xero offers a built-in **Demo Company** mode inside every Xero account — uses the same `api.xero.com` endpoint and the same OAuth client credentials as production. See [Xero OAuth → Demo Company vs production organisation](/docs/connect/xero-oauth#demo-company-vs-production-organisation).

### Magento storefront in one country, Xero organisation in another?

Supported. Magento's per-store currency / tax setup drives what we send; Xero applies the per-line `TaxType` we set against its org's tax-rate setup. Cross-currency settlement (e.g. EUR invoice paid via a GBP bank account) is handled by Xero's own FX translation server-side — see [Payment-method map → Cross-currency payments](/docs/settings/payment-methods#cross-currency-payments). Make sure the relevant currency is enabled in Xero (Settings → Currencies); Xero's Standard plan caps at 2 active currencies.

### Multiple Xero organisations?

Each Magento binding maps to one Xero organisation. For multi-org setups, spin up multiple bindings on the chassis and either: (a) use `website_filter` / `store_filter` per binding to scope which Magento orders flow to which Xero organisation, or (b) pair separate Magento environments per binding. Per-plan limits on the number of bindings live on the [Plans & pricing page](https://byte8.io/products/xero-accounting#pricing).

## Coexistence with Sage Accounting and FreeAgent

### Can I run Xero alongside another Byte8 connector on the same Magento install?

Yes. The connectors share the `byte8/module-client` chassis (outbox, JWT auth, sync-state mirror) and add per-provider columns / aliases so they don't trample each other. You'll see one Status column per installed provider on Sales → Invoices, one info block per provider on the detail page, and one pairing surface per provider on the config page. Each is paired and disconnected independently — see [Pairing-code Connect flow → Pairing alongside Sage Accounting](/docs/connect/pairing-code#pairing-alongside-sage-accounting).

### Same invoice, multiple providers?

Yes — the same Magento invoice can sync to **all** installed providers simultaneously. Each provider's outbox / sync-state / `entity_xref` lookup is keyed independently. Most merchants pick one (the system their accountant uses); a few want a "primary + audit copy" setup, which works fine.

## Multi-store / multi-website

### Magento has 5 websites — does each need its own binding?

No. One binding can sync any number of Magento websites into one Xero company. Use `website_filter` on the sync policy if you only want some websites flowing.

### Per-website Xero organisations?

Each binding pairs to one Xero organisation; the merchant maps Magento `website_id`s to bindings via `website_filter`. Per-plan limits on the number of Xero organisations are on the [Plans & pricing page](https://byte8.io/products/xero-accounting#pricing).

### Can two bindings share the same Xero organisation?

Technically yes (the chassis doesn't prevent it), but it'll cause `entity_xref` conflicts on the same customer appearing on both bindings. Don't.

## Security

### Where do my Xero OAuth tokens live?

Encrypted at rest (AES-GCM) in the chassis database. Never on your Magento server, never in PHP, never in Magento config. The chassis refreshes them transparently before each provider call.

### Can Byte8 staff access my Xero data?

The chassis logs Magento entity ids, Xero entity URLs, sync status, and error messages — never invoice line content or customer PII beyond what's necessary for diagnosing failures. Token-level Xero access is restricted to the worker process; no Byte8 staff has interactive access to your Xero tokens.

### What's the inbound webapi attack surface on my Magento?

The connector exposes a small set of REST endpoints under `/V1/byte8/*`:

- `GET /V1/byte8/{ping,payment-methods,invoice/:id,customer/:id,creditmemo/:id,payment/:id}`
- `POST /V1/byte8/sync-state`

All are JWT-authed via `JwtUserContext` against the per-tenant `api_key`. The synthetic ACL plugin grants the JWT-authed integration user access to `Byte8_Client::byte8_webapi` only — no scope to cart, customer-create, admin, or any core Magento resource.

The pairing-code endpoint (`POST /V1/byte8/xero_accounting/setup/pair`) is the **only** unauthenticated webapi route, and it accepts requests only when a fresh-within-30-min pairing-code hash matches.

### What if I want to revoke chassis access immediately?

Disconnect from `ledger.byte8.io/dashboard/bindings/{id}` → Disconnect binding. Within seconds the chassis flips the binding to `revoked`, stops dispatching jobs, and revokes Xero tokens at Xero. The Magento side will start dead-lettering subsequent observer-fired events; the dead-letter banner surfaces the count.

For nuclear-option: revoke the connection from Xero's My Apps page (Settings → Connected apps in the org switcher), then disconnect the binding on the chassis dashboard. The chassis's bearer tokens against Xero will 401; the binding effectively goes dark immediately.

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

Magento doesn't have an API to attach an offline payment (cheque clearing, bank transfer landing) to an existing invoice after the fact. So our chassis can't reliably link the Xero payment to the right Xero invoice. Best practice: leave invoices AUTHORISED in Xero, accountant manually reconciles via Xero's bank-feed import when the money lands. Aligns with how every Xero user already handles AR.

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
