<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: save() checks approved <= requested and received <= approved.
 */
declare(strict_types=1);

namespace Magenx\Rma\Model;

use Magenx\Rma\Api\Data\ItemInterface;
use Magenx\Rma\Api\Data\ItemSearchResultsInterface;
use Magenx\Rma\Api\Data\ItemSearchResultsInterfaceFactory;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Model\ResourceModel\Item as ResourceModel;
use Magenx\Rma\Model\ResourceModel\Item\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class ItemRepository extends AbstractRepository implements ItemRepositoryInterface
{
    /**
     * @param ResourceModel $resourceModel
     * @param ItemFactory $itemFactory
     * @param CollectionFactory $collectionFactory
     * @param ItemSearchResultsInterfaceFactory $searchResultsFactory
     * @param CollectionProcessorInterface $collectionProcessor
     */
    public function __construct(
        ResourceModel $resourceModel,
        protected readonly ItemFactory $itemFactory,
        protected readonly CollectionFactory $collectionFactory,
        protected readonly ItemSearchResultsInterfaceFactory $searchResultsFactory,
        CollectionProcessorInterface $collectionProcessor
    ) {
        parent::__construct($resourceModel, $collectionProcessor);
    }

    /**
     * @return string
     */
    protected function getEntityLabel(): string
    {
        return 'RMA Item';
    }

    /**
     * @return AbstractModel
     */
    protected function createEntity(): AbstractModel
    {
        return $this->itemFactory->create();
    }

    /**
     * @return AbstractCollection
     */
    protected function createCollection(): AbstractCollection
    {
        return $this->collectionFactory->create();
    }

    /**
     * @return SearchResultsInterface
     */
    protected function createSearchResults(): SearchResultsInterface
    {
        return $this->searchResultsFactory->create();
    }

    /**
     * @param int $entityId
     * @return ItemInterface
     * @throws NoSuchEntityException
     */
    public function get(int $entityId): ItemInterface
    {
        return $this->loadEntity($entityId);
    }

    /**
     * @param ItemInterface $item
     * @return ItemInterface
     * @throws CouldNotSaveException
     */
    public function save(ItemInterface $item): ItemInterface
    {
        $this->assertQuantities($item);

        return $this->saveEntity($item);
    }

    /**
     * Approved never exceeds requested, received never exceeds approved (PRD FR-R7).
     *
     * @param ItemInterface $item
     * @return void
     * @throws LocalizedException
     */
    protected function assertQuantities(ItemInterface $item): void
    {
        $approved = $item->getQtyApproved();
        $returned = $item->getQtyReturned();

        if ($approved !== null && ($approved < 0 || $approved > $item->getQtyRequested())) {
            throw new LocalizedException(
                __('Approved qty for item %1 must be between 0 and the requested qty (%2).',
                    $item->getOrderItemId(), $item->getQtyRequested())
            );
        }

        if ($returned !== null && ($returned < 0 || $returned > (int)$approved)) {
            throw new LocalizedException(
                __('Received qty for item %1 must be between 0 and the approved qty (%2).',
                    $item->getOrderItemId(), (int)$approved)
            );
        }
    }

    /**
     * @param ItemInterface $item
     * @return bool
     * @throws CouldNotDeleteException
     */
    public function delete(ItemInterface $item): bool
    {
        return $this->deleteEntity($item);
    }

    /**
     * @param int $entityId
     * @return bool
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     */
    public function deleteById(int $entityId): bool
    {
        return $this->delete($this->get($entityId));
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return ItemSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): ItemSearchResultsInterface
    {
        return $this->performGetList($searchCriteria);
    }
}
