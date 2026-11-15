<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\ResourceModel;

/**
 * Fills store_labels on lookup collection items.
 *
 * The lookup resource models fill store_labels in _afterLoad, which runs only for a
 * single-entity load; a collection never calls it, so getStoreLabel($storeId) on a
 * collection item always fell back to the default label. One query per collection
 * loads the labels of every item.
 */
trait StoreLabelsCollectionTrait
{
    /**
     * @return $this
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        $ids = array_map('intval', array_keys($this->_items));
        if (!empty($ids)) {
            $labels = $this->getResource()->getStoreLabelsByIds($ids);
            foreach ($this->_items as $id => $item) {
                $item->setData('store_labels', $labels[(int)$id] ?? []);
            }
        }

        return $this;
    }
}
