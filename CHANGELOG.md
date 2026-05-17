# Changelog

## [1.2.0](https://github.com/byte8io/magento-xero-accounting/compare/v1.1.0...v1.2.0) (2026-05-17)


### Features

* initial Xero Accounting module ([865e465](https://github.com/byte8io/magento-xero-accounting/commit/865e465e8d4e1f2bfe73e76913ab3f95d5b465f6))
* **nav:** add "← All docs" link to navbar pointing to docs hub ([3db17c4](https://github.com/byte8io/magento-xero-accounting/commit/3db17c406a140280f571fee97118852c0c22f77d))
* **search:** wire Algolia DocSearch — cross-product search across docs.byte8.io ([dc30285](https://github.com/byte8io/magento-xero-accounting/commit/dc30285220a1b4aeab3a5975f3132495e900771a))


### Bug Fixes

* **search:** force full nav for cross-site search results ([edcd8de](https://github.com/byte8io/magento-xero-accounting/commit/edcd8deded339293433e04030847e85ee7159e37))
* **search:** keep DocSearch links internal-looking for SEO + clientModule for same-tab nav ([3e617e2](https://github.com/byte8io/magento-xero-accounting/commit/3e617e231afb178ef5680c42f54d3cf53ce78dac))


### Documentation

* align public docs with actual Xero MVP feature set ([098ed9b](https://github.com/byte8io/magento-xero-accounting/commit/098ed9b97d3be2524de16bf82ab24bff37d0e057))
* migrate to docs.byte8.io/xero unified domain ([408e610](https://github.com/byte8io/magento-xero-accounting/commit/408e610fa92e8b656183376fa7996f9b07bd8c43))

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
