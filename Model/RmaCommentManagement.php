<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: save() stores only known author types.
 */
declare(strict_types=1);

namespace Magenx\Rma\Model;

use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Api\Data\CommentSearchResultsInterface;
use Magenx\Rma\Api\CommentRepositoryInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Api\RmaCommentManagementInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class RmaCommentManagement extends AbstractRmaManagement implements RmaCommentManagementInterface
{
    /**
     * @param RMARepositoryInterface $rmaRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param SortOrderBuilder $sortOrderBuilder
     * @param CommentRepositoryInterface $commentRepository
     */
    public function __construct(
        RMARepositoryInterface $rmaRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        SortOrderBuilder $sortOrderBuilder,
        protected readonly CommentRepositoryInterface $commentRepository
    ) {
        parent::__construct($rmaRepository, $searchCriteriaBuilderFactory, $sortOrderBuilder);
    }

    /**
     * @param int $rmaId
     * @param SearchCriteriaInterface $searchCriteria
     * @return CommentSearchResultsInterface
     * @throws NoSuchEntityException
     */
    public function getList(int $rmaId, SearchCriteriaInterface $searchCriteria): CommentSearchResultsInterface
    {
        $this->validateRmaExists($rmaId);

        return $this->commentRepository->getList(
            $this->buildScopedSearchCriteria($rmaId, $searchCriteria)
        );
    }

    /**
     * @param int $rmaId
     * @param CommentInterface $comment
     * @return CommentInterface
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     * @throws LocalizedException
     */
    public function save(int $rmaId, CommentInterface $comment): CommentInterface
    {
        $this->validateRmaExists($rmaId);
        $comment->setRmaId($rmaId);

        // The admin API writes staff comments; author_type is rendered as markup in the
        // admin timeline, so only the two known values are stored.
        if (!in_array(
            $comment->getAuthorType(),
            [CommentInterface::AUTHOR_TYPE_ADMIN, CommentInterface::AUTHOR_TYPE_CUSTOMER],
            true
        )) {
            $comment->setAuthorType(CommentInterface::AUTHOR_TYPE_ADMIN);
        }

        return $this->commentRepository->save($comment);
    }
}
