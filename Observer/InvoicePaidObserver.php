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
 * Publishes `invoice.paid` on the Magento invoice's OPEN → PAID
 * transition. Ledger turns this into an `AttachInvoicePayment` job
 * that records the payment against the Xero invoice already
 * created by `InvoiceCreatedObserver`.
 *
 * Same transition logic as Byte8\SageAccounting\Observer\InvoicePaidObserver:
 * fires exactly once on either fresh-PAID insert (capture-online) or
 * OPEN → PAID update (capture-offline); no-op on already-PAID re-saves.
 */
class InvoicePaidObserver implements ObserverInterface
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

        $state = (int) $invoice->getState();
        if ($state !== Invoice::STATE_PAID) {
            return;
        }
        $origState = $invoice->getOrigData('state');
        if ($origState !== null && (int) $origState === Invoice::STATE_PAID) {
            return;
        }

        $entityId = (int) $invoice->getEntityId();
        if ($entityId <= 0) {
            return;
        }

        try {
            $this->byteClient->enqueueEvent('invoice.paid', [
                'magento_entity_id' => $entityId,
                'website_id'        => $this->resolveWebsiteId($invoice),
                'store_id'          => (int) $invoice->getStoreId(),
                'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
                'payload'           => [
                    'increment_id' => (string) $invoice->getIncrementId(),
                ],
            ], 'invoice.paid:' . $entityId, XeroConfigInterface::PROVIDER_KEY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Byte8: xero invoice.paid observer failed to enqueue for invoice ' . $entityId
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
