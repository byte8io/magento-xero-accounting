<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Controller\Adminhtml\PairingCode;

use Byte8\XeroAccounting\Api\XeroConfigInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Mints a single-use pairing code (128 bits CSPRNG → 32 hex chars),
 * stores SHA-256(hex) + timestamp, and flashes the plaintext to the
 * admin session so the merchant can copy it once. Same shape as the
 * Sage analogue.
 *
 * Clicking Generate again before the old code is consumed overwrites
 * the stored hash, invalidating the old code. 30-minute TTL enforced
 * in Model\Setup\Pair.
 */
class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Byte8_XeroAccounting::config';
    public const SESSION_PAIRING_CODE_KEY = 'byte8_xero_pairing_code';

    public function __construct(
        Context $context,
        private readonly ConfigResource $configResource,
        private readonly ReinitableConfigInterface $appConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly BackendSession $backendSession
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath(
            'adminhtml/system_config/edit',
            ['section' => 'byte8_xero_accounting']
        );

        try {
            $code = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Could not generate a pairing code: CSPRNG unavailable.')
            );
            return $redirect;
        }

        $hash = hash('sha256', $code);

        $this->configResource->saveConfig(
            XeroConfigInterface::XML_PATH_PAIRING_CODE_HASH,
            $hash,
            'default',
            0
        );
        $this->configResource->saveConfig(
            XeroConfigInterface::XML_PATH_PAIRING_CODE_ISSUED_AT,
            (string) time(),
            'default',
            0
        );

        $this->cacheTypeList->cleanType(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER);
        $this->appConfig->reinit();

        $this->backendSession->setData(self::SESSION_PAIRING_CODE_KEY, $code);

        $this->messageManager->addSuccessMessage(
            __('Pairing code generated. Copy it now — it is shown once and expires in 30 minutes.')
        );
        return $redirect;
    }
}
