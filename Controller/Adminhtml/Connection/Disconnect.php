<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Controller\Adminhtml\Connection;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\Client\Api\ClientConfigInterface;
use Byte8\XeroAccounting\Api\XeroConfigInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Clears the local tenant binding and best-effort notifies ledger. Always
 * destructive on the Magento side — even if ledger is unreachable, we
 * remove the local credentials so the merchant can rebind cleanly.
 * Same shape as the Sage analogue.
 */
class Disconnect extends Action implements \Magento\Framework\App\Action\HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Byte8_XeroAccounting::config';

    public function __construct(
        Context $context,
        private readonly XeroConfigInterface $xeroConfig,
        private readonly ByteClientInterface $byteClient,
        private readonly ConfigResource $configResource,
        private readonly ReinitableConfigInterface $appConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly CacheInterface $cache
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

        if (!$this->xeroConfig->isConnected()) {
            $this->messageManager->addNoticeMessage(__('Already disconnected.'));
            return $redirect;
        }

        $acked = $this->byteClient->disconnect();

        foreach ([
            XeroConfigInterface::XML_PATH_TENANT_ID,
            XeroConfigInterface::XML_PATH_API_KEY,
            XeroConfigInterface::XML_PATH_PAIRING_CODE_HASH,
            XeroConfigInterface::XML_PATH_PAIRING_CODE_ISSUED_AT,
            ClientConfigInterface::XML_PATH_TENANT_ID,
            ClientConfigInterface::XML_PATH_API_KEY,
        ] as $path) {
            $this->configResource->deleteConfig($path, 'default', 0);
        }

        $this->cacheTypeList->cleanType(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER);
        $this->cache->clean([ByteClientInterface::HEALTH_CACHE_TAG]);
        $this->appConfig->reinit();

        $this->messageManager->addSuccessMessage(
            $acked
                ? __('Disconnected. Byte8 Ledger acknowledged the revocation.')
                : __('Disconnected locally. Byte8 Ledger was unreachable; it will reconcile on its next poll.')
        );
        return $redirect;
    }
}
