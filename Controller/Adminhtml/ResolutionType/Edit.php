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

namespace Magenx\Rma\Controller\Adminhtml\ResolutionType;

use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;
use Magenx\Rma\Controller\Adminhtml\AbstractLookupEdit;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\PageFactory;

class Edit extends AbstractLookupEdit
{
    const ADMIN_RESOURCE = 'Magenx_Rma::rma_resolution_type';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param ResolutionTypeRepositoryInterface $resolutionTypeRepository
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        ResolutionTypeRepositoryInterface $resolutionTypeRepository
    ) {
        parent::__construct($context, $resultPageFactory, $resolutionTypeRepository, 'resolution type', 'Magenx_Rma::rma_resolution_type', 'Resolution Types');
    }
}
