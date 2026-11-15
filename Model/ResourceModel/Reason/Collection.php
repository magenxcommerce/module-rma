<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: loaded items carry their store labels.
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\ResourceModel\Reason;

use Magenx\Rma\Model\Reason as Model;
use Magenx\Rma\Model\ResourceModel\Reason as ResourceModel;
use Magenx\Rma\Model\ResourceModel\StoreLabelsCollectionTrait;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    use StoreLabelsCollectionTrait;

    /**
     * @var string
     */
    protected $_idFieldName = 'entity_id';

    /**
     * @var string
     */
    protected $_eventPrefix = 'rma_reason_collection';

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
