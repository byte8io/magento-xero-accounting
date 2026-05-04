<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Observer;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\XeroAccounting\Api\XeroConfigInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer as CustomerModel;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes `customer.upserted` on `customer_save_after`. Id-only
 * payload; ledger refetches canonical data via `GET /V1/byte8/customer/:id`
 * once the save transaction commits.
 */
class CustomerUpsertedObserver implements ObserverInterface
{
    public function __construct(
        private readonly ByteClientInterface $byteClient,
        private readonly XeroConfigInterface $xeroConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->xeroConfig->isConnected()) {
            return;
        }

        $entityId = $this->extractCustomerId($observer);
        if ($entityId === null) {
            return;
        }

        $websiteId = $this->extractWebsiteId($observer);

        try {
            $this->byteClient->enqueueEvent('customer.upserted', [
                'magento_entity_id' => $entityId,
                'website_id'        => $websiteId,
                'store_id'          => 0,
                'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
                'payload'           => (object) [],
            ], 'customer.upserted:' . $entityId, XeroConfigInterface::PROVIDER_KEY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Byte8: xero customer.upserted observer failed to enqueue for customer ' . $entityId
                . ': ' . $e->getMessage()
            );
        }
    }

    private function extractCustomerId(Observer $observer): ?int
    {
        $event = $observer->getEvent();
        $candidate = $event->getData('customer_data_object') ?? $event->getData('customer');
        if ($candidate instanceof CustomerInterface || $candidate instanceof CustomerModel) {
            $id = (int) $candidate->getId();
            return $id > 0 ? $id : null;
        }
        return null;
    }

    private function extractWebsiteId(Observer $observer): int
    {
        $event = $observer->getEvent();
        $candidate = $event->getData('customer_data_object') ?? $event->getData('customer');
        if (($candidate instanceof CustomerInterface || $candidate instanceof CustomerModel)
            && method_exists($candidate, 'getWebsiteId')
        ) {
            return (int) $candidate->getWebsiteId();
        }
        return 0;
    }
}
