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

namespace Magenx\Rma\Block\Trait;

trait AttachmentConfigTrait
{
    /**
     * @return string
     */
    public function getAllowedExtensions(): string
    {
        return implode(',', $this->moduleConfig->getAllowedAttachmentExtensions($this->getAttachmentConfigStoreId()));
    }

    /**
     * @return int
     */
    public function getMaxFileSize(): int
    {
        return $this->moduleConfig->getMaxAttachmentFileSize($this->getAttachmentConfigStoreId());
    }

    /**
     * @return int
     */
    public function getMaxFiles(): int
    {
        return $this->moduleConfig->getMaxAttachmentFiles($this->getAttachmentConfigStoreId());
    }

    /**
     * Store the attachment limits should be read for. Defaults to the default scope;
     * consumers that know which RMA is on screen override this so a website-level
     * override of the `attachments` group is actually honoured.
     *
     * @return int
     */
    protected function getAttachmentConfigStoreId(): int
    {
        return 0;
    }
}
