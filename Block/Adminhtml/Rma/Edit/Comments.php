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

use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Block\Trait\AttachmentConfigTrait;
use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Service\AttachmentService;
use Magenx\Rma\Service\CommentFormatter;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;

class Comments extends Template
{
    use AttachmentConfigTrait;

    /**
     * @var string
     */
    protected $_template = 'Magenx_Rma::rma/edit/comments.phtml';

    /**
     * Memoised so rendering the upload widget costs at most one RMA load.
     *
     * @var int|null
     */
    private ?int $attachmentConfigStoreId = null;

    /**
     * @param Context $context
     * @param RMARepositoryInterface $rmaRepository
     * @param CommentFormatter $commentFormatter
     * @param AttachmentService $attachmentService
     * @param ModuleConfig $moduleConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        protected readonly RMARepositoryInterface $rmaRepository,
        protected readonly CommentFormatter $commentFormatter,
        protected readonly AttachmentService $attachmentService,
        protected readonly ModuleConfig $moduleConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return int
     */
    public function getRmaId(): int
    {
        return (int)$this->getRequest()->getParam('entity_id');
    }

    /**
     * Read the attachment limits against the RMA's own store, so a website-level
     * override of the `attachments` config group reaches the upload widget.
     *
     * @return int
     */
    protected function getAttachmentConfigStoreId(): int
    {
        if ($this->attachmentConfigStoreId !== null) {
            return $this->attachmentConfigStoreId;
        }

        $rmaId = $this->getRmaId();

        if (!$rmaId) {
            return $this->attachmentConfigStoreId = 0;
        }

        try {
            return $this->attachmentConfigStoreId = (int)$this->rmaRepository->get($rmaId)->getStoreId();
        } catch (NoSuchEntityException) {
            return $this->attachmentConfigStoreId = 0;
        }
    }

    /**
     * @return bool
     */
    public function isEditMode(): bool
    {
        return $this->getRmaId() > 0;
    }

    /**
     * @return array
     */
    public function getComments(): array
    {
        $rmaId = $this->getRmaId();
        if (!$rmaId) {
            return [];
        }

        return $this->commentFormatter->buildList($rmaId, includeVisibility: true);
    }

    /**
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl('rma/comment/save');
    }

    /**
     * @return string
     */
    public function getLoadListUrl(): string
    {
        return $this->getUrl('rma/comment/loadList');
    }

    /**
     * @return string
     */
    public function getUploadUrl(): string
    {
        return $this->getUrl('rma/attachment/upload');
    }

    /**
     * @return string
     */
    public function getDownloadUrl(): string
    {
        return $this->getUrl('rma/attachment/download');
    }

    /**
     * @return string
     */
    public function getDeleteUrl(): string
    {
        return $this->getUrl('rma/attachment/delete');
    }

    /**
     * @return array
     */
    public function getAttachments(): array
    {
        $rmaId = $this->getRmaId();
        if (!$rmaId) {
            return [];
        }

        return array_values(array_map(
            [$this->attachmentService, 'toArray'],
            $this->attachmentService->getByRmaId($rmaId)
        ));
    }

    /**
     * @param int $attachmentId
     * @return string
     */
    public function getAttachmentDownloadUrl(int $attachmentId): string
    {
        return $this->getUrl('rma/attachment/download', ['id' => $attachmentId]);
    }
}
