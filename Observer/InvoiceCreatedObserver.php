<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Observer;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\XeroAccounting\Api\XeroConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

/**
 * Publishes `invoice.created` on the first save of a Magento invoice
 * (the entity_id ↔ orig_data edge). Ledger responds by POSTing the
 * invoice to Xero as outstanding AR — regardless of paid state.
 *
 * Mirrors Byte8\SageAccounting\Observer\InvoiceCreatedObserver: same
 * first-save edge detection, same idempotency-key scheme. The only
 * difference is the PROVIDER_KEY passed to ByteClient::enqueueEvent
 * so the ledger-side write-through mirror tags the row with the
 * correct provider.
 */
class InvoiceCreatedObserver implements ObserverInterface
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

        $event = $observer->getEvent();
        $invoice = $event->getData('object') ?? $event->getData('invoice');
        if (!$invoice instanceof InvoiceInterface) {
            return;
        }

        // First save (INSERT) only — subsequent updates skip cleanly.
        if ($invoice->getOrigData('entity_id') !== null) {
            return;
        }

        $state = (int) $invoice->getState();
        if ($state !== Invoice::STATE_OPEN && $state !== Invoice::STATE_PAID) {
            return;
        }

        $entityId = (int) $invoice->getEntityId();
        if ($entityId <= 0) {
            return;
        }

        try {
            $this->byteClient->enqueueEvent('invoice.created', [
                'magento_entity_id' => $entityId,
                'website_id'        => $this->resolveWebsiteId($invoice),
                'store_id'          => (int) $invoice->getStoreId(),
                'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
                'payload'           => [
                    'increment_id' => (string) $invoice->getIncrementId(),
                    'paid'         => $state === Invoice::STATE_PAID,
                ],
            ], 'invoice.created:' . $entityId, XeroConfigInterface::PROVIDER_KEY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Byte8: xero invoice.created observer failed to enqueue for invoice ' . $entityId
                . ': ' . $e->getMessage()
            );
        }
    }

    private function resolveWebsiteId(InvoiceInterface $invoice): int
    {
        if (method_exists($invoice, 'getOrder')) {
            $order = $invoice->getOrder();
            if ($order && method_exists($order, 'getStore')) {
                $store = $order->getStore();
                if ($store && method_exists($store, 'getWebsiteId')) {
                    return (int) $store->getWebsiteId();
                }
            }
        }
        return 0;
    }
}
