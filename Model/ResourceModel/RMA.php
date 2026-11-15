<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: dropped the unused resource-model event prefix.
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\ResourceModel;

use Magenx\Rma\Helper\ModuleConfig;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Exception\LocalizedException;

class RMA extends AbstractDb
{
    /**
     * @param Context $context
     * @param ModuleConfig $moduleConfig
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        protected readonly ModuleConfig $moduleConfig,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('rma_entity', 'entity_id');
        $this->_useIsObjectNew = true;
    }

    /**
     * @param AbstractModel $object
     * @return $this
     * @throws LocalizedException
     */
    protected function _afterSave(AbstractModel $object): static
    {
        parent::_afterSave($object);

        if (!$object->getData('increment_id')) {
            $storeId = (int)$object->getData('store_id');
            $prefix = $this->moduleConfig->getIncrementIdPrefix($storeId);
            $incrementId = $prefix . str_pad((string)$object->getId(), 9, '0', STR_PAD_LEFT);

            $this->getConnection()->update(
                $this->getMainTable(),
                ['increment_id' => $incrementId],
                ['entity_id = ?' => $object->getId()]
            );

            $object->setData('increment_id', $incrementId);
        }

        return $this;
    }
}
