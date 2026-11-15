<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\ResourceModel;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;

/**
 * Guards lookup codes the module depends on, on every save path (admin, REST, code).
 *
 * A code never changes once stored, and a protected code cannot be deleted. The
 * controllers had the same rules, but only for statuses and only in the admin;
 * a REST delete or a crafted POST went straight through.
 */
trait LookupCodeGuardTrait
{
    /**
     * @return string[] Codes that cannot be deleted
     */
    abstract protected function getProtectedCodes(): array;

    /**
     * @param AbstractModel $object
     * @return static
     * @throws LocalizedException
     */
    protected function _beforeSave(AbstractModel $object): static
    {
        $storedCode = $this->fetchStoredCode($object);

        if ($storedCode !== null && (string)$object->getData('code') !== $storedCode) {
            throw new LocalizedException(__('The code of an existing entry cannot be changed.'));
        }

        return parent::_beforeSave($object);
    }

    /**
     * @param AbstractModel $object
     * @return static
     * @throws LocalizedException
     */
    protected function _beforeDelete(AbstractModel $object): static
    {
        $code = $this->fetchStoredCode($object) ?? (string)$object->getData('code');

        if (in_array($code, $this->getProtectedCodes(), true)) {
            throw new LocalizedException(
                __('"%1" is used by the system and cannot be deleted. Deactivate it instead.', $code)
            );
        }

        return parent::_beforeDelete($object);
    }

    /**
     * Reads the code from the database, because a REST update arrives on a fresh
     * model with no original data to compare against.
     *
     * @param AbstractModel $object
     * @return string|null Null for a new entry
     */
    private function fetchStoredCode(AbstractModel $object): ?string
    {
        if (!$object->getId()) {
            return null;
        }

        $code = $this->getConnection()->fetchOne(
            $this->getConnection()->select()
                ->from($this->getMainTable(), 'code')
                ->where($this->getIdFieldName() . ' = ?', (int)$object->getId())
        );

        return $code === false ? null : (string)$code;
    }
}
