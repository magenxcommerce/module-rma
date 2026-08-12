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

namespace Magenx\Rma\Controller\Adminhtml\Status;

use Magenx\Rma\Api\StatusRepositoryInterface;
use Magenx\Rma\Controller\Adminhtml\AbstractLookupEdit;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\PageFactory;

class Edit extends AbstractLookupEdit
{
    const ADMIN_RESOURCE = 'Magenx_Rma::rma_status';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param StatusRepositoryInterface $statusRepository
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        StatusRepositoryInterface $statusRepository
    ) {
        parent::__construct($context, $resultPageFactory, $statusRepository, 'status', 'Magenx_Rma::rma_status', 'Statuses');
    }
}
