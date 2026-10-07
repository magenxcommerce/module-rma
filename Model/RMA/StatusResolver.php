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

namespace Magenx\Rma\Model\RMA;

use Magenx\Rma\Api\StatusRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

class StatusResolver implements ResetAfterRequestInterface
{
    /**
     * @var array
     */
    protected array $cache = [];

    /**
     * @param StatusRepositoryInterface $statusRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     */
    public function __construct(
        protected readonly StatusRepositoryInterface $statusRepository,
        protected readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->cache = [];
    }

    /**
     * @param int $statusId
     * @return string|null
     */
    public function getCodeById(int $statusId): ?string
    {
        if (!isset($this->cache[$statusId])) {
            try {
                $status = $this->statusRepository->get($statusId);
                $this->cache[$statusId] = $status->getCode();
            } catch (NoSuchEntityException) {
                return null;
            }
        }

        return $this->cache[$statusId];
    }

    /**
     * @param string $code
     * @return int|null
     */
    public function getIdByCode(string $code): ?int
    {
        $flipped = array_flip($this->cache);

        if (isset($flipped[$code])) {
            return $flipped[$code];
        }

        $searchCriteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter('code', $code)
            ->create();

        $results = $this->statusRepository->getList($searchCriteria);

        foreach ($results->getItems() as $status) {
            $id = (int)$status->getEntityId();
            $this->cache[$id] = $status->getCode();

            return $id;
        }

        return null;
    }

    /**
     * Same as getIdByCode(), but for callers that treat a missing status as fatal
     * rather than as "nothing to do".
     *
     * @param string $code
     * @return int
     * @throws LocalizedException
     */
    public function getRequiredIdByCode(string $code): int
    {
        $id = $this->getIdByCode($code);

        if ($id === null) {
            throw new LocalizedException(__('Status with code "%1" not found.', $code));
        }

        return $id;
    }

    /**
     * Resolve several codes at once, dropping any that do not exist.
     *
     * @param string[] $codes
     * @return int[]
     */
    public function getIdsByCodes(array $codes): array
    {
        $ids = [];

        foreach ($codes as $code) {
            $id = $this->getIdByCode($code);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
