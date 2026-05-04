<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Block\Adminhtml\System\Config;

use Byte8\XeroAccounting\Api\XeroConfigInterface;
use Byte8\XeroAccounting\Controller\Adminhtml\PairingCode\Generate;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Pairing Code" field in system config. Same three display
 * modes as the Sage analogue:
 *   - No pending code → Generate button.
 *   - Stored hash but no session flash → "Pending; regenerate to reveal".
 *   - Session flash present → reveal plaintext once + Copy button.
 */
class PairingCodeField extends Field
{
    protected $_template = 'Byte8_XeroAccounting::system/config/pairing_code_field.phtml';

    public function __construct(
        Context $context,
        private readonly XeroConfigInterface $xeroConfig,
        private readonly BackendSession $backendSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function hasPendingCode(): bool
    {
        return $this->xeroConfig->getPairingCodeHash() !== null;
    }

    public function getPendingCodeAgeMinutes(): int
    {
        $issuedAt = $this->xeroConfig->getPairingCodeIssuedAt();
        if ($issuedAt === null) {
            return 0;
        }
        return (int) floor((time() - $issuedAt) / 60);
    }

    public function getPendingCodeTtlMinutes(): int
    {
        return (int) ceil(XeroConfigInterface::PAIRING_CODE_TTL_SECONDS / 60);
    }

    /**
     * One-shot reveal: returns the plaintext code from session AND clears it.
     */
    public function consumeFlashCode(): ?string
    {
        $value = $this->backendSession->getData(Generate::SESSION_PAIRING_CODE_KEY, true);
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getGenerateUrl(): string
    {
        return $this->getUrl('byte8xeroaccounting/pairingcode/generate');
    }

    public function getGenerateButtonHtml(bool $regenerate): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class)
            ->setData([
                'id' => 'byte8_xero_generate_pairing_code_button',
                'label' => $regenerate ? __('Regenerate Pairing Code') : __('Generate Pairing Code'),
                'class' => $regenerate ? 'action-secondary' : 'action-primary',
            ]);
        return $button->toHtml();
    }
}
