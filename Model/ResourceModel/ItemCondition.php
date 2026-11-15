<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: codes are immutable and system codes cannot be deleted; store
 * labels can be read for many entries at once.
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ItemCondition extends AbstractDb
{
    use LookupCodeGuardTrait;

    /**
     * @var string
     */
    protected string $_eventPrefix = 'rma_item_condition_resource_model';

    /**
     * @var string
     */
    protected string $labelsTable = '';

    /**
     * @return string[]
     */
    protected function getProtectedCodes(): array
    {
        return [];
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('rma_item_condition', 'entity_id');
        $this->_useIsObjectNew = true;
        $this->labelsTable = $this->getTable('rma_item_condition_label');
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
            ->where('condition_id = ?', (int)$object->getId());

        return $connection->fetchPairs($select);
    }

    /**
     * Store labels of many entries in one query, for collections.
     *
     * @param int[] $ids
     * @return array<int, array<int, string>> entry id => [store id => label]
     */
    public function getStoreLabelsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->labelsTable, ['condition_id', 'store_id', 'label'])
            ->where('condition_id IN (?)', array_map('intval', $ids));

        $labels = [];
        foreach ($connection->fetchAll($select) as $row) {
            $labels[(int)$row['condition_id']][(int)$row['store_id']] = (string)$row['label'];
        }

        return $labels;
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
            $connection->delete($this->labelsTable, ['condition_id = ?' => (int)$object->getId()]);

            $labels = $object->getData('store_labels');
            $data = [];
            foreach ($labels as $storeId => $label) {
                if (empty($label)) {
                    continue;
                }
                $data[] = [
                    'condition_id' => (int)$object->getId(),
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
