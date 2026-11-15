<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: its identity purges the cached storefront choice lists.
 */
declare(strict_types=1);

namespace Magenx\Rma\Model;

use Magenx\Rma\Api\Data\ItemConditionInterface;
use Magenx\Rma\Model\ResourceModel\ItemCondition as ResourceModel;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Exception\LocalizedException;

class ItemCondition extends AbstractModel implements ItemConditionInterface, IdentityInterface
{
    /**
     * Saving or deleting an entry purges the cached storefront choice lists.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        return [ChoiceListsCache::TAG];
    }

    /**
     * @var string
     */
    protected $_eventPrefix = 'rma_item_condition_model';

    /**
     * @return void
     * @throws LocalizedException
     */
    protected function _construct(): void
    {
        $this->_init(ResourceModel::class);
    }

    /**
     * @return int|null
     */
    public function getEntityId(): ?int
    {
        $id = $this->getData(self::ENTITY_ID);
        return $id !== null ? (int)$id : null;
    }

    /**
     * @param $entityId
     * @return self
     */
    public function setEntityId($entityId): self
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return (string)$this->getData(self::CODE);
    }

    /**
     * @param string $code
     * @return self
     */
    public function setCode(string $code): self
    {
        return $this->setData(self::CODE, $code);
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return (string)$this->getData(self::LABEL);
    }

    /**
     * @param string $label
     * @return self
     */
    public function setLabel(string $label): self
    {
        return $this->setData(self::LABEL, $label);
    }

    /**
     * @return int
     */
    public function getIsActive(): int
    {
        return (int)$this->getData(self::IS_ACTIVE);
    }

    /**
     * @param int $isActive
     * @return self
     */
    public function setIsActive(int $isActive): self
    {
        return $this->setData(self::IS_ACTIVE, $isActive);
    }

    /**
     * @return int
     */
    public function getSortOrder(): int
    {
        return (int)$this->getData(self::SORT_ORDER);
    }

    /**
     * @param int $sortOrder
     * @return self
     */
    public function setSortOrder(int $sortOrder): self
    {
        return $this->setData(self::SORT_ORDER, $sortOrder);
    }

    /**
     * @return array
     */
    public function getStoreLabels(): array
    {
        return $this->getData('store_labels') ?? [];
    }

    /**
     * @param array $storeLabels
     * @return self
     */
    public function setStoreLabels(array $storeLabels): self
    {
        return $this->setData('store_labels', $storeLabels);
    }

    /**
     * @param int $storeId
     * @return string
     */
    public function getStoreLabel(int $storeId): string
    {
        $labels = $this->getStoreLabels();

        return !empty($labels[$storeId]) ? (string)$labels[$storeId] : $this->getLabel();
    }
}
