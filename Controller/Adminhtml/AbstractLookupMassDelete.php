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

namespace Magenx\Rma\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Framework\Exception\LocalizedException;
use Exception;

abstract class AbstractLookupMassDelete extends Action implements HttpPostActionInterface
{
    /**
     * @param Context $context
     * @param Filter $filter
     * @param object $collectionFactory
     * @param string $entityName
     */
    public function __construct(
        Context $context,
        protected readonly Filter $filter,
        protected readonly object $collectionFactory,
        protected readonly string $entityName = ''
    ) {
        parent::__construct($context);
    }

    /**
     * @param object $entity
     * @return bool
     */
    protected function isProtected(object $entity): bool
    {
        return false;
    }

    /**
     * @param int $count
     * @return string
     */
    protected function getProtectedSkippedMessage(int $count): string
    {
        return '';
    }

    /**
     * @return ResultInterface|ResponseInterface|Redirect
     * @throws LocalizedException
     */
    public function execute(): ResultInterface|ResponseInterface|Redirect
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $deleted = 0;
        $skipped = 0;

        foreach ($collection as $entity) {
            if ($this->isProtected($entity)) {
                $skipped++;
                continue;
            }

            try {
                $entity->delete();
                $deleted++;
            } catch (Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        if ($deleted) {
            $this->messageManager->addSuccessMessage(
                $this->entityName !== ''
                    ? __('A total of %1 %2(s) have been deleted.', $deleted, $this->entityName)
                    : __('A total of %1 record(s) have been deleted.', $deleted)
            );
        }

        if ($skipped) {
            $message = $this->getProtectedSkippedMessage($skipped);
            if ($message !== '') {
                $this->messageManager->addErrorMessage($message);
            }
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
