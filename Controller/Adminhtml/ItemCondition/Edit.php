<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 */
declare(strict_types=1);

namespace Magenx\Rma\Controller\Adminhtml\ItemCondition;

use Magenx\Rma\Api\ItemConditionRepositoryInterface;
use Magenx\Rma\Controller\Adminhtml\AbstractLookupEdit;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\PageFactory;

class Edit extends AbstractLookupEdit
{
    const ADMIN_RESOURCE = 'Magenx_Rma::rma_item_condition';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param ItemConditionRepositoryInterface $itemConditionRepository
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        ItemConditionRepositoryInterface $itemConditionRepository
    ) {
        parent::__construct($context, $resultPageFactory, $itemConditionRepository, 'item condition', 'Magenx_Rma::rma_item_condition', 'Item Conditions');
    }
}
