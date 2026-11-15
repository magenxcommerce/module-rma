<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: codes are immutable and system codes cannot be deleted.
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\ResourceModel;

use Magenx\Rma\Model\RMA\WithdrawalCodes;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ResolutionType extends AbstractDb
{
    use LookupCodeGuardTrait;

    /**
     * @var string
     */
    protected string $_eventPrefix = 'rma_resolution_type_resource_model';

    /**
     * @var string
     */
    protected string $labelsTable = '';

    /**
     * @return string[]
     */
    protected function getProtectedCodes(): array
    {
        return [WithdrawalCodes::RESOLUTION_REFUND];
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('rma_resolution_type', 'entity_id');
        $this->_useIsObjectNew = true;
        $this->labelsTable = $this->getTable('rma_resolution_type_label');
    }

    /**
     * @param AbstractModel $object
     * @return array
     */
    public function getStoreLabels(AbstractModel $object): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->labelsTable, ['store_id', 'label'])
            ->where('resolution_type_id = ?', (int)$object->getId());

        return $connection->fetchPairs($select);
    }

    /**
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object): static
    {
        parent::_afterLoad($object);

        if ($object->getId()) {
            $object->setData('store_labels', $this->getStoreLabels($object));
        }

        return $this;
    }

    /**
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterSave(AbstractModel $object): static
    {
        parent::_afterSave($object);

        if ($object->hasData('store_labels')) {
            $connection = $this->getConnection();
            $connection->delete($this->labelsTable, ['resolution_type_id = ?' => (int)$object->getId()]);

            $labels = $object->getData('store_labels');
            $data = [];
            foreach ($labels as $storeId => $label) {
                if (empty($label)) {
                    continue;
                }
                $data[] = [
                    'resolution_type_id' => (int)$object->getId(),
                    'store_id' => (int)$storeId,
                    'label' => $label,
                ];
            }

            if (!empty($data)) {
                $connection->insertMultiple($this->labelsTable, $data);
            }
        }

        return $this;
    }
}
