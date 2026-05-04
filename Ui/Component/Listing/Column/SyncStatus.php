<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\XeroAccounting\Ui\Component\Listing\Column;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the "Xero Status" chip on Sales → Invoices / Credit
 * Memos grids. Mirrors `Byte8\SageAccounting\Ui\Component\Listing\Column\SyncStatus`
 * — the chip CSS classes are shared via `view/adminhtml/web/css/sync-status.css`
 * so the visual language stays uniform across providers.
 *
 * Empty / null status renders as `—` (no sync attempted yet — common
 * for entities that pre-date the install or sit outside the sync filters).
 */
class SyncStatus extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $field = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $status = $item[$field] ?? null;
            $tooltip = $this->buildTooltip($item);
            $item[$field] = $this->renderChip($status, $tooltip);
        }

        return $dataSource;
    }

    private function buildTooltip(array $item): string
    {
        // Reads `_xero`-suffixed fields populated by Xero's
        // grid-collection JOIN. See `InvoiceGridCollection` for the
        // alias-suffix rationale (Sage/Xero coexistence).
        if (!empty($item['byte8_sync_provider_reference_xero'])) {
            return (string) $item['byte8_sync_provider_reference_xero'];
        }
        if (!empty($item['byte8_sync_provider_entity_id_xero'])) {
            return (string) $item['byte8_sync_provider_entity_id_xero'];
        }
        if (!empty($item['byte8_sync_skip_reason_xero'])) {
            return 'Skipped: ' . (string) $item['byte8_sync_skip_reason_xero'];
        }
        if (!empty($item['byte8_sync_error_code_xero'])) {
            return 'Error: ' . (string) $item['byte8_sync_error_code_xero'];
        }
        return '';
    }

    private function renderChip(?string $status, string $tooltip): string
    {
        $tooltipAttr = $tooltip === ''
            ? ''
            : ' title="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '"';

        return match ($status) {
            EntitySyncStateInterface::STATUS_SYNCED => sprintf(
                '<span class="byte8-sync-chip byte8-sync-chip--synced"%s>✓ Synced</span>',
                $tooltipAttr
            ),
            EntitySyncStateInterface::STATUS_PENDING => sprintf(
                '<span class="byte8-sync-chip byte8-sync-chip--pending"%s>⏳ Pending</span>',
                $tooltipAttr
            ),
            EntitySyncStateInterface::STATUS_SKIPPED => sprintf(
                '<span class="byte8-sync-chip byte8-sync-chip--skipped"%s>⏸ Skipped</span>',
                $tooltipAttr
            ),
            EntitySyncStateInterface::STATUS_FAILED => sprintf(
                '<span class="byte8-sync-chip byte8-sync-chip--failed"%s>✗ Failed</span>',
                $tooltipAttr
            ),
            default => '<span class="byte8-sync-chip byte8-sync-chip--none">—</span>',
        };
    }
}
