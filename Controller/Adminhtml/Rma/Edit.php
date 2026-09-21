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

namespace Magenx\Rma\Controller\Adminhtml\Rma;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Backend\Model\View\Result\Page;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Controller\Adminhtml\Rma as BaseController;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;

class Edit extends BaseController implements HttpGetActionInterface
{
    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param RMARepositoryInterface $rmaRepository
     */
    public function __construct(
        Context $context,
        protected readonly PageFactory $resultPageFactory,
        protected readonly RMARepositoryInterface $rmaRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @return Page|ResultInterface|ResponseInterface|Redirect
     */
    public function execute(): Page|ResultInterface|ResponseInterface|Redirect
    {
        $id = (int)$this->getRequest()->getParam('entity_id');

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $this->initPage($resultPage);

        if ($id) {
            try {
                $rma = $this->rmaRepository->get($id);
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(__('This RMA request no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }

            $resultPage->getConfig()->getTitle()->prepend(
                __('RMA #%1', $rma->getIncrementId())
            );
        } else {
            $resultPage->getConfig()->getTitle()->prepend(__('New RMA Request'));
        }

        return $resultPage;
    }
}
