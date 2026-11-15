<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: plain order collection, status/period narrowed in SQL, newest
 * first, limit capped.
 */
declare(strict_types=1);

namespace Magenx\Rma\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Service\OrderEligibility;

class Search extends Action implements HttpGetActionInterface
{
    use OrderOptionFormatter;

    const ADMIN_RESOURCE = 'Magenx_Rma::rma_manage';

    /**
     * Upper bound for the number of options one request may ask for.
     */
    private const MAX_LIMIT = 50;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param ModuleConfig $moduleConfig
     * @param OrderEligibility $orderEligibility
     */
    public function __construct(
        Context $context,
        protected readonly JsonFactory $resultJsonFactory,
        protected readonly OrderCollectionFactory $orderCollectionFactory,
        protected readonly ModuleConfig $moduleConfig,
        protected readonly OrderEligibility $orderEligibility
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $searchKey = (string)$this->getRequest()->getParam('searchKey');
        $limit = max(1, min(self::MAX_LIMIT, (int)$this->getRequest()->getParam('limit', 20)));

        $enabledStoreIds = $this->moduleConfig->getEnabledStoreIds();

        if (empty($enabledStoreIds)) {
            return $this->resultJsonFactory->create()->setData([
                'options' => [],
                'total' => 0,
            ]);
        }

        $collection = $this->orderCollectionFactory->create();
        // Escape the LIKE wildcards so a searchKey of "%" does not turn into a
        // match-everything scan. The value itself is still bound as a parameter.
        $escapedKey = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchKey);
        $collection->addFieldToFilter('increment_id', ['like' => '%' . $escapedKey . '%']);
        $collection->addFieldToFilter('store_id', ['in' => $enabledStoreIds]);

        // Narrow in SQL with the loosest status and period rules of the enabled stores, so the
        // LIKE only runs over orders that can qualify; the exact per-store check follows.
        $statuses = [];
        $period = 0;
        $unlimited = false;
        foreach ($enabledStoreIds as $storeId) {
            $statuses = array_merge($statuses, $this->moduleConfig->getAllowedOrderStatuses($storeId));
            $storePeriod = $this->moduleConfig->getReturnPeriod($storeId);
            $unlimited = $unlimited || $storePeriod <= 0;
            $period = max($period, $storePeriod);
        }
        $collection->addFieldToFilter('status', ['in' => array_values(array_unique($statuses)) ?: ['']]);
        if (!$unlimited) {
            $collection->addFieldToFilter(
                'created_at',
                ['gteq' => gmdate('Y-m-d H:i:s', strtotime("-{$period} days"))]
            );
        }

        $collection->setOrder('entity_id', 'DESC');
        // Fetch more than needed since we post-filter for eligibility
        $collection->setPageSize($limit * 3);

        $options = [];
        foreach ($collection as $order) {
            if (!$this->orderEligibility->isOrderEligible($order)) {
                continue;
            }

            $options[] = $this->formatOrderOption($order);

            if (count($options) >= $limit) {
                break;
            }
        }

        return $this->resultJsonFactory->create()->setData([
            'options' => $options,
            'total' => count($options),
        ]);
    }
}
