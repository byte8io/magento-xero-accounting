<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Api;

/**
 * Reads byte8/xero_accounting/* config values. Decrypts api_key
 * transparently so callers never touch EncryptorInterface directly.
 *
 * Under the SaaS-initiated flow, both tenant_id and api_key are
 * populated by the inbound POST /V1/byte8/setup/pair webapi call
 * that ledger invokes after the merchant pastes Magento URL +
 * pairing code into ledger.byte8.io. Neither is typed by the
 * operator in Magento admin. (Same shape as Byte8_SageAccounting.)
 */
interface XeroConfigInterface
{
    /**
     * Stable provider key for Byte8\Client\Api\EntitySyncStateRepositoryInterface
     * lookups. Matches the `provider` column on the ledger-side
     * `connector_bindings` table and the JSON `provider` field on the
     * inbound `/V1/byte8/sync-state` callback.
     */
    public const PROVIDER_KEY = 'xero';

    public const XML_PATH_TENANT_ID = 'byte8/xero_accounting/tenant_id';
    public const XML_PATH_API_KEY   = 'byte8/xero_accounting/api_key';

    /**
     * SHA-256 hash (hex) of the current pairing code. Plaintext is never
     * stored — revealed once via session flash at generation time, then
     * consumed + cleared on first successful /setup/pair.
     */
    public const XML_PATH_PAIRING_CODE_HASH = 'byte8/xero_accounting/pairing_code_hash';

    /**
     * Unix timestamp the pairing code was minted. 30-minute TTL
     * enforced by Setup\Pair (when added).
     */
    public const XML_PATH_PAIRING_CODE_ISSUED_AT = 'byte8/xero_accounting/pairing_code_issued_at';

    public const PAIRING_CODE_TTL_SECONDS = 1800;

    public function getTenantId(): ?string;

    public function getApiKey(): ?string;

    public function getPairingCodeHash(): ?string;

    public function getPairingCodeIssuedAt(): ?int;

    /**
     * True once tenant_id AND api_key are both set — i.e. the paired
     * setup webapi call landed successfully.
     */
    public function isConnected(): bool;
}
