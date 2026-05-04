# Changelog

## [1.0.0] — Unreleased

Initial release. Thin Magento 2 client for the Byte8 Xero SaaS
connector. Mirrors the surface of `byte8/magento-sage-accounting`
and `byte8/magento-xero-accounting` (formerly `xero`):

- Pairing-code admin flow against `apps/ledger`.
- Outbox-driven event publishing for invoice / credit-memo /
  customer / invoice-paid observers.
- Sync-status grid + detail-page chips on Sales → Invoices and
  Sales → Credit Memos.
- Connection-status banner on `Stores → Configuration → Byte8 →
  Xero Accounting`.
