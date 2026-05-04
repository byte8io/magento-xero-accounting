<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Api;

/**
 * Paired-setup service for the Xero provider — same shape as
 * Byte8\SageAccounting\Api\SetupPairInterface.
 *
 * Ledger calls this ONCE after the merchant pastes Magento URL +
 * pairing code into `ledger.byte8.io/dashboard/connect-magento`.
 * Delivers the master api_key + tenant_id + ledger base URL.
 *
 * Authentication is the `pairing_code` in the request body, NOT a
 * bearer token (the route is `anonymous` in webapi.xml). Single-use,
 * 30-minute TTL.
 */
interface SetupPairInterface
{
    /**
     * @param string $pairingCode   One-time code revealed in Magento admin.
     * @param string $tenantId      UUID assigned by ledger to this merchant.
     * @param string $byte8ApiKey   Master HMAC secret.
     * @param string $ledgerBaseUrl Ledger origin, e.g. https://ledger.byte8.io.
     * @return void
     */
    public function pair(
        string $pairingCode,
        string $tenantId,
        string $byte8ApiKey,
        string $ledgerBaseUrl
    ): void;
}
