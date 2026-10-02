<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Setup\Patch\Data;

use Magenx\Rma\Model\RMA\StatusCodes;
use Magenx\Rma\Model\RMA\WithdrawalCodes;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds the lookup values an EU consumer withdrawal needs: the `withdrawal`
 * reason, the `refund` resolution and the `refunded` status.
 *
 * Inserted with insertOnDuplicate on `code` but updating nothing on conflict,
 * so a store that already created one of these codes by hand keeps its label,
 * active flag and sort order.
 */
class AddWithdrawalLookups implements DataPatchInterface
{
    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        protected readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @return $this
     */
    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $rows = [
            'rma_reason' => [
                'code' => WithdrawalCodes::REASON,
                'label' => 'Withdrawal from Contract',
                'is_active' => 1,
                'sort_order' => 90,
            ],
            'rma_resolution_type' => [
                'code' => WithdrawalCodes::RESOLUTION_REFUND,
                'label' => 'Refund',
                'is_active' => 1,
                'sort_order' => 40,
            ],
            'rma_status' => [
                'code' => StatusCodes::REFUNDED,
                'label' => 'Refunded',
                'is_active' => 1,
                'sort_order' => 90,
            ],
        ];

        $connection = $this->moduleDataSetup->getConnection();

        foreach ($rows as $table => $row) {
            $connection->insertOnDuplicate($this->moduleDataSetup->getTable($table), $row, ['code']);
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * @return array|string[]
     */
    public static function getDependencies(): array
    {
        return [
            AddRmaReasons::class,
            AddRmaResolutionTypes::class,
            AddRmaStatuses::class,
        ];
    }

    /**
     * @return array|string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
