---
sidebar_position: 1
slug: /
title: Introduction
description: Byte8 Xero Accounting — hosted SaaS connector between Magento 2 and Xero. Invoices, credit notes, customers, payments. Audited.
---

# Byte8 Xero Accounting

A hosted SaaS connector that syncs **Magento 2 → Xero** in near real time. Invoices, credit notes, customers, and payments — with full audit trail and operator-visible failure handling.

## Why this exists

Most Magento × Xero connectors are one-time-fee PHP extensions with limited entity coverage and no central API maintenance. Xero's API has its own quirks (PascalCase JSON, plural envelopes on every write, the `Xero-tenant-id` header on every request, granular OAuth scopes since March 2026) and the fix shouldn't be your problem to ship.

Byte8 fixes the model:

- **The Magento module is thin.** Four observers, an outbox, a JWT-signed wire to our hosted ledger. No OAuth in PHP, no Xero API client on disk, no client secret.
- **The chassis is centrally hosted.** OAuth token rotation, rate-limit governance, retry, dead-letter handling, audit trail — all in one Rust SaaS we host. Xero ships an API change? We patch the chassis; every connected merchant gets the fix.
- **You get visibility where you live.** Xero Status chips on the Magento Sales → Invoices and Sales → Credit Memos grids; admin info block on each entity detail page. No need to context-switch to a separate dashboard for "did this invoice make it?"

## Where to start

If you've never installed it, the [Quick start](/docs/getting-started/quick-start) is 60 seconds: composer require, setup:upgrade, pair with `ledger.byte8.io`, done.

If you're a Magento dev who wants to understand the architecture before installing, jump to the [Pairing-code Connect flow](/docs/connect/pairing-code).

If you're the merchant operator (accountant / merchandiser) who'll actually use this day-to-day, head to the [Magento admin](/docs/magento-admin/xero-status-grid) section — that covers every chip, banner, and detail-page block you'll see.

If you're configuring sync behaviour for an unusual setup (custom revenue-account routing, tax-type defaults, payment-method routing), the [Sync settings](/docs/settings/sync-behavior) section walks every card on the ledger dashboard.

## What this connector is NOT

- **Not a one-time PHP extension.** It's a SaaS subscription. The Magento module is the client; the heavy lifting is on the chassis.
- **Not bidirectional in v1.** Magento is the source of truth for catalog, inventory, customers, and order documents. Xero is the accounting ledger of record. **Xero → Magento writeback is Enterprise-on-request scope** — needs Xero's webhook surface, Magento write endpoints, and conflict-resolution policy. Build only on a custom contract.
- **Not a tax advisor.** We pass through what Magento computes — VAT MOSS, EU OSS, post-Brexit reverse charge routing all happen because Magento + Xero know how, not because we re-implement tax logic. Xero applies the `LineItem.TaxType` we send and computes line-level tax from your Xero org's tax-rate setup.
- **Not a stock-sync tool.** Xero's Items resource isn't designed for inventory tracking — there's no `quantity_in_stock` or `stock_movements` family. If you need stock-level sync, that's Sage Accounting territory; Xero's connector ships invoice + payment + customer + credit-note flows only.
- **Not a Magento marketplace listing yet.** Distribution is via Composer + Cargoman (private Composer registry) for the closed-beta cohort; Magento Marketplace listing follows public launch.

## Module ecosystem

| Package | Role | Composer | Repo |
|---|---|---|---|
| `byte8/magento-xero-accounting` | The connector — installed by merchants | `composer require byte8/magento-xero-accounting` | [GitHub](https://github.com/byte8io/magento-xero-accounting) |
| `byte8/module-client` | Shared chassis (outbox, JWT, canonical REST) — pulled in transitively | — | private |
| `byte8/module-core` | Shared utilities — pulled in transitively | — | private |

Merchants install one package: `byte8/magento-xero-accounting`. Composer pulls in the other two automatically.

If you also run Sage Accounting or FreeAgent Accounting alongside Xero, all three connectors coexist on the same Magento install — they share the `byte8/module-client` chassis and route per-provider via the outbox `provider` column. See [Pairing-code Connect flow](/docs/connect/pairing-code) for the per-provider pairing detail.
