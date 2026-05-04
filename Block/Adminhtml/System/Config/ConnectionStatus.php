<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Block\Adminhtml\System\Config;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\Client\Api\OutboxEventRepositoryInterface;
use Byte8\XeroAccounting\Api\XeroConfigInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Three-state status reader off the Byte8 client `fetchHealth()` probe.
 * Mirrors the Sage analogue so wording stays consistent across providers.
 *
 * States:
 *   disconnected  — no local tenant_id/api_key yet (paired-setup hasn't landed)
 *   pending       — local creds in, ledger hasn't reported active yet
 *   active        — ledger says magento_connection_status == active AND binding active
 *   token_revoked — ledger got 401 on last probe; merchant must regenerate + re-paste
 *   unknown       — ledger unreachable on last call
 */
class ConnectionStatus extends Field
{
    protected $_template = 'Byte8_XeroAccounting::system/config/connection_status.phtml';

    public function __construct(
        Context $context,
        private readonly XeroConfigInterface $xeroConfig,
        private readonly ByteClientInterface $byteClient,
        private readonly OutboxEventRepositoryInterface $outboxRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getDeadLetterCount(): int
    {
        return $this->outboxRepository->countDeadLettered();
    }

    /**
     * @return array{state:string, label:\Magento\Framework\Phrase, detail:string}
     */
    public function getStatus(): array
    {
        if (!$this->xeroConfig->isConnected()) {
            return [
                'state'  => 'disconnected',
                'label'  => __('Not paired'),
                'detail' => (string) __('Generate a Pairing Code above, then paste it at ledger.byte8.io to complete setup.'),
            ];
        }

        $health = $this->byteClient->fetchHealth();
        $tenant = $health['tenant'] ?? [];
        $magentoStatus = (string) ($tenant['magento_connection_status'] ?? '');

        $binding = $health['binding'] ?? [];
        $bindingStatus = (string) ($binding['status'] ?? '');

        if ($magentoStatus === 'token_revoked') {
            return [
                'state'  => 'token_revoked',
                'label'  => __('Magento disconnected — re-pair required'),
                'detail' => (string) __('Ledger got 401 when calling Magento. Generate a new Pairing Code above and paste it again at ledger.byte8.io.'),
            ];
        }

        if ($magentoStatus === 'pending' || $magentoStatus === '') {
            return [
                'state'  => 'pending',
                'label'  => __('Waiting for ledger'),
                'detail' => (string) __('Paired-setup received. Finish connecting Xero at ledger.byte8.io to go live.'),
            ];
        }

        if ($magentoStatus === 'active' && $bindingStatus === 'active') {
            return [
                'state'  => 'connected',
                'label'  => __('Connected to Xero'),
                'detail' => '',
            ];
        }

        if ($magentoStatus === 'active') {
            return [
                'state'  => 'pending',
                'label'  => __('Magento paired — Xero not connected yet'),
                'detail' => (string) __('Finish the Connect to Xero step at ledger.byte8.io.'),
            ];
        }

        return [
            'state'  => 'unknown',
            'label'  => __('Ledger unreachable'),
            'detail' => (string) __('Last check could not reach Byte8 Ledger. Retrying in the background.'),
        ];
    }
}
