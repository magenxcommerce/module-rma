<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: resolved values are kept for the request.
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use Magenx\Rma\Api\ItemConditionRepositoryInterface;
use Magenx\Rma\Api\ReasonRepositoryInterface;
use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;
use Magenx\Rma\Api\StatusRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\StoreManagerInterface;

class LabelResolver implements ResetAfterRequestInterface
{
    const TYPE_STATUS = 'status';
    const TYPE_REASON = 'reason';
    const TYPE_RESOLUTION_TYPE = 'resolution_type';
    const TYPE_ITEM_CONDITION = 'item_condition';

    /**
     * Resolved values per request, keyed type:id:store. A page of returns asks for the
     * same handful of statuses, reasons and conditions many times; each miss is a row
     * load plus a label query.
     *
     * @var array<string, array|null>
     */
    private array $resolved = [];

    /**
     * @param StatusRepositoryInterface $statusRepository
     * @param ReasonRepositoryInterface $reasonRepository
     * @param ResolutionTypeRepositoryInterface $resolutionTypeRepository
     * @param ItemConditionRepositoryInterface $itemConditionRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        protected readonly StatusRepositoryInterface $statusRepository,
        protected readonly ReasonRepositoryInterface $reasonRepository,
        protected readonly ResolutionTypeRepositoryInterface $resolutionTypeRepository,
        protected readonly ItemConditionRepositoryInterface $itemConditionRepository,
        protected readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param string $type
     * @param int $entityId
     * @param int|null $storeId
     * @return array|null
     */
    public function resolveAsArray(string $type, int $entityId, ?int $storeId = null): ?array
    {
        $storeId ??= $this->getCurrentStoreId();
        $key = $type . ':' . $entityId . ':' . $storeId;

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        try {
            $entity = $this->loadEntity($type, $entityId);

            return $this->resolved[$key] = [
                'id' => $entity->getEntityId(),
                'code' => $entity->getCode(),
                'label' => (string)__($entity->getStoreLabel($storeId)),
            ];
        } catch (NoSuchEntityException) {
            return $this->resolved[$key] = null;
        }
    }

    /**
     * @return void
     */
    public function _resetState(): void
    {
        $this->resolved = [];
    }

    /**
     * @param string $type
     * @param int $entityId
     * @return object
     * @throws NoSuchEntityException
     */
    protected function loadEntity(string $type, int $entityId): object
    {
        return match ($type) {
            self::TYPE_STATUS => $this->statusRepository->get($entityId),
            self::TYPE_REASON => $this->reasonRepository->get($entityId),
            self::TYPE_RESOLUTION_TYPE => $this->resolutionTypeRepository->get($entityId),
            self::TYPE_ITEM_CONDITION => $this->itemConditionRepository->get($entityId),
            default => throw new NoSuchEntityException(__('Unknown lookup type "%1".', $type)),
        };
    }

    /**
     * @return int
     */
    protected function getCurrentStoreId(): int
    {
        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException) {
            return 0;
        }
    }
}
