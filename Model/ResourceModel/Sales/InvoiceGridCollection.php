<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Model\ResourceModel\Sales;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Byte8\XeroAccounting\Api\XeroConfigInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface as Logger;

/**
 * Invoice grid collection with the byte8_entity_sync_state JOIN baked in
 * for Xero. Mirror of `Byte8\SageAccounting\Model\ResourceModel\Sales\InvoiceGridCollection`.
 *
 * **Coexistence note.** Both Sage and Xero ship a custom Invoice
 * grid collection that swaps in for the same Magento virtualType
 * (`Magento\Sales\Model\ResourceModel\Order\Invoice\Grid\Collection`).
 * Magento DI honours whichever module's `etc/di.xml` was last loaded
 * — module sequence matters. Both subclasses do the same shape of
 * JOIN against `byte8_entity_sync_state`, just filtered to a
 * different `provider` value. Future enhancement: a single shared
 * grid that emits BOTH chips when both providers are bound.
 */
class InvoiceGridCollection extends SearchResult
{
    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager,
        $mainTable = 'sales_invoice_grid',
        $resourceModel = \Magento\Sales\Model\ResourceModel\Order\Invoice::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    protected function _initSelect()
    {
        parent::_initSelect();
        $this->addSyncStateJoin();
        return $this;
    }

    private function addSyncStateJoin(): void
    {
        if ($this->getFlag('byte8_sync_state_joined')) {
            return;
        }
        $this->setFlag('byte8_sync_state_joined', true);

        $select = $this->getSelect();
        $connection = $this->getConnection();

        // Suffix all aliases with `_xero` so they don't collide
        // with Sage's identical JOIN aliases when both providers are
        // bound on the same install. Sage's column renderer reads
        // `byte8_sync_*`; Xero's reads `byte8_sync_*_xero`.
        // This keeps the data lanes separate even if only one
        // provider's grid-collection swap wins Magento DI.
        $select->joinLeft(
            ['byte8_sync_fa' => $this->getTable(EntitySyncStateInterface::DB_TABLE_NAME)],
            sprintf(
                "byte8_sync_fa.entity_type = %s AND byte8_sync_fa.magento_id = main_table.entity_id AND byte8_sync_fa.provider = %s",
                $connection->quote(EntitySyncStateInterface::ENTITY_TYPE_INVOICE),
                $connection->quote(XeroConfigInterface::PROVIDER_KEY)
            ),
            [
                'byte8_sync_status_xero' => 'byte8_sync_fa.sync_status',
                'byte8_sync_provider_entity_id_xero' => 'byte8_sync_fa.provider_entity_id',
                'byte8_sync_provider_reference_xero' => 'byte8_sync_fa.provider_reference',
                'byte8_sync_skip_reason_xero' => 'byte8_sync_fa.skip_reason',
                'byte8_sync_error_code_xero' => 'byte8_sync_fa.error_code',
                'byte8_sync_last_sync_at_xero' => 'byte8_sync_fa.last_sync_at',
            ]
        );
    }
}
