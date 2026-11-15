<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * Stands in for AbstractDb: the trait only needs these members.
 */
class GuardedResourceStub
{
    public ?string $storedCode = null;
    public array $calls = [];
    public AdapterInterface $connection;

    protected function _beforeSave(AbstractModel $object): static
    {
        $this->calls[] = 'parent_before_save';
        return $this;
    }

    protected function _beforeDelete(AbstractModel $object): static
    {
        $this->calls[] = 'parent_before_delete';
        return $this;
    }

    public function getConnection(): AdapterInterface
    {
        return $this->connection;
    }

    public function getMainTable(): string
    {
        return 'rma_reason';
    }

    public function getIdFieldName(): string
    {
        return 'entity_id';
    }
}
