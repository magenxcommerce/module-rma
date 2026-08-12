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

namespace Magenx\Rma\Block\Adminhtml\Rma\Edit;

use Magenx\Rma\Api\ItemConditionRepositoryInterface;
use Magenx\Rma\Model\ResourceModel\Item\CollectionFactory as ItemCollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderItemRepositoryInterface;

class OrderItems extends Template
{
    /**
     * @var string
     */
    protected $_template = 'Magenx_Rma::rma/edit/order-items.phtml';

    /**
     * @param Context $context
     * @param ItemCollectionFactory $itemCollectionFactory
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param ItemConditionRepositoryInterface $itemConditionRepository
     * @param array $data
     */
    public function __construct(
        Context $context,
        protected readonly ItemCollectionFactory $itemCollectionFactory,
        protected readonly OrderItemRepositoryInterface $orderItemRepository,
        protected readonly ItemConditionRepositoryInterface $itemConditionRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array|null
     */
    public function getRmaItems(): ?array
    {
        $rmaId = (int)$this->getRequest()->getParam('entity_id');

        if (!$rmaId) {
            return null;
        }

        $collection = $this->itemCollectionFactory->create();
        $collection->addFieldToFilter('rma_id', $rmaId);

        return $collection->getItems();
    }

    /**
     * @param int $orderItemId
     * @return string[]
     */
    public function getOrderItemInfo(int $orderItemId): array
    {
        try {
            $orderItem = $this->orderItemRepository->get($orderItemId);
            return [
                'name' => (string)$orderItem->getName(),
                'sku' => (string)$orderItem->getSku(),
            ];
        } catch (NoSuchEntityException $e) {
            return [
                'name' => (string)__('Unknown Product'),
                'sku' => '',
            ];
        }
    }

    /**
     * @param int|null $conditionId
     * @return string
     */
    public function getConditionLabel(?int $conditionId): string
    {
        if ($conditionId === null) {
            return '—';
        }

        try {
            $condition = $this->itemConditionRepository->get($conditionId);
            return (string)$condition->getLabel();
        } catch (NoSuchEntityException $e) {
            return (string)__('Unknown');
        }
    }

    /**
     * @return bool
     */
    public function isEditMode(): bool
    {
        return (bool)$this->getRequest()->getParam('entity_id');
    }
}
