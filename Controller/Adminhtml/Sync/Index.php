<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Controller\Adminhtml\Sync;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\XeroAccounting\Api\XeroConfigInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * Renders the Byte8 → Xero Sync admin page — a full-page iframe to
 * the ledger embed UI. Mirrors the Sage analogue.
 */
class Index extends Action
{
    public const ADMIN_RESOURCE = 'Byte8_XeroAccounting::sync';

    public function __construct(
        Context $context,
        private readonly XeroConfigInterface $xeroConfig,
        private readonly ClientConfigInterface $clientConfig
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        if (!$this->xeroConfig->isConnected()) {
            /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $this->messageManager->addNoticeMessage(
                __('Connect to Xero before opening Xero Sync — there is nothing to show yet.')
            );
            return $redirect->setPath(
                'adminhtml/system_config/edit',
                ['section' => 'byte8_xero_accounting']
            );
        }

        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Byte8_XeroAccounting::sync');
        $resultPage->getConfig()->getTitle()->prepend(__('Xero Sync'));

        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHeader(
            'Content-Security-Policy',
            'frame-src ' . $this->clientConfig->getBaseUrl() . ';',
            true
        );

        return $resultPage;
    }
}
