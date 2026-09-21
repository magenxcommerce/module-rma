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
use Magento\Backend\Model\View\Result\Page;

abstract class AbstractLookupController extends Action
{
    /**
     * @return string
     */
    abstract protected function getMenuId(): string;

    /**
     * @return string
     */
    abstract protected function getBreadcrumbLabel(): string;

    /**
     * Apply the shared admin chrome (active menu + breadcrumbs) to a result page.
     *
     * The page must come from Magento\Framework\View\Result\PageFactory: only that
     * factory calls addDefaultHandle(), which loads the "default" layout handle that
     * declares the admin "menu" block. A page built by any other factory has no menu
     * block, and setActiveMenu() then fatals on false.
     *
     * @param Page $resultPage
     * @return Page
     */
    protected function initPage(Page $resultPage): Page
    {
        $resultPage->setActiveMenu($this->getMenuId())
            ->addBreadcrumb(__('RMA'), __('RMA'))
            ->addBreadcrumb(__($this->getBreadcrumbLabel()), __($this->getBreadcrumbLabel()));

        return $resultPage;
    }
}
