<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: a list loads the attachments of all its comments in one query.
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Model\ResourceModel\Comment\CollectionFactory;

class CommentFormatter
{
    /**
     * @param AttachmentService $attachmentService
     * @param CollectionFactory $commentCollectionFactory
     */
    public function __construct(
        protected readonly AttachmentService $attachmentService,
        protected readonly CollectionFactory $commentCollectionFactory
    ) {
    }

    /**
     * @param int $rmaId
     * @param bool $visibleOnly
     * @param bool $includeVisibility
     * @param int $afterId
     * @return array
     */
    public function buildList(
        int $rmaId,
        bool $visibleOnly = false,
        bool $includeVisibility = false,
        int $afterId = 0
    ): array {
        $collection = $this->commentCollectionFactory->create();
        $collection->addFieldToFilter('rma_id', $rmaId);

        if ($visibleOnly) {
            $collection->addFieldToFilter('is_visible_to_customer', 1);
        }

        if ($afterId > 0) {
            $collection->addFieldToFilter('entity_id', ['gt' => $afterId]);
        }

        $collection->setOrder('created_at', 'ASC');

        $comments = $collection->getItems();
        // One attachment query for the whole list instead of one per comment.
        $attachmentsByComment = $this->loadAttachmentsByComment(
            array_map(static fn($comment): int => (int)$comment->getEntityId(), $comments)
        );

        return array_values(array_map(
            fn($comment) => $this->toArray(
                $comment,
                $includeVisibility,
                $attachmentsByComment[(int)$comment->getEntityId()] ?? []
            ),
            $comments
        ));
    }

    /**
     * @param CommentInterface $comment
     * @param bool $includeVisibility
     * @param array|null $attachments Preloaded attachment rows; null loads them here
     * @return array
     */
    public function toArray(
        CommentInterface $comment,
        bool $includeVisibility = false,
        ?array $attachments = null
    ): array {
        $data = [
            'entity_id' => $comment->getEntityId(),
            'author_type' => $comment->getAuthorType(),
            'author_name' => $comment->getAuthorName(),
            'comment' => $comment->getComment(),
            'created_at' => $comment->getCreatedAt(),
        ];

        if ($includeVisibility) {
            $data['is_visible_to_customer'] = (bool)$comment->getIsVisibleToCustomer();
        }

        $commentId = (int)$comment->getEntityId();
        if ($commentId) {
            $data['attachments'] = $attachments ?? $this->formatAttachments($commentId);
        }

        return $data;
    }

    /**
     * @param int[] $commentIds
     * @return array<int, array> comment id => formatted attachments
     */
    protected function loadAttachmentsByComment(array $commentIds): array
    {
        $commentIds = array_values(array_filter($commentIds));
        if (empty($commentIds)) {
            return [];
        }

        $grouped = [];
        foreach ($this->attachmentService->getByCommentIds($commentIds) as $attachment) {
            $grouped[(int)$attachment->getCommentId()][] = $this->attachmentService->toArray($attachment);
        }

        return $grouped;
    }

    /**
     * @param int $commentId
     * @return array
     */
    protected function formatAttachments(int $commentId): array
    {
        return array_values(array_map(
            [$this->attachmentService, 'toArray'],
            $this->attachmentService->getByCommentId($commentId)
        ));
    }
}
