<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model\ResourceModel;

use Magenx\Rma\Model\ResourceModel\LookupCodeGuardTrait;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\TestCase;

class LookupCodeGuardTraitTest extends TestCase
{
    private object $resource;

    protected function setUp(): void
    {
        $this->resource = new class extends GuardedResourceStub {
            use LookupCodeGuardTrait;

            protected function getProtectedCodes(): array
            {
                return ['withdrawal'];
            }

            public function beforeSave(AbstractModel $o): void
            {
                $this->_beforeSave($o);
            }

            public function beforeDelete(AbstractModel $o): void
            {
                $this->_beforeDelete($o);
            }
        };

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnCallback(fn() => $this->resource->storedCode ?? false);
        $this->resource->connection = $connection;
    }

    private function entry(?int $id, string $code): AbstractModel
    {
        $model = $this->getMockBuilder(AbstractModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $model->setData(['entity_id' => $id, 'code' => $code]);
        $model->setIdFieldName('entity_id');

        return $model;
    }

    public function testNewEntrySaves(): void
    {
        $this->resource->beforeSave($this->entry(null, 'wrong_size'));

        $this->assertSame(['parent_before_save'], $this->resource->calls);
    }

    public function testCodeOfStoredEntryCannotChange(): void
    {
        $this->resource->storedCode = 'withdrawal';
        $this->expectException(LocalizedException::class);

        $this->resource->beforeSave($this->entry(3, 'renamed'));
    }

    public function testProtectedEntryCannotBeDeleted(): void
    {
        $this->resource->storedCode = 'withdrawal';
        $this->expectExceptionMessageMatches('/cannot be deleted/');

        $this->resource->beforeDelete($this->entry(3, 'withdrawal'));
    }

    public function testOrdinaryEntryCanBeDeleted(): void
    {
        $this->resource->storedCode = 'wrong_size';
        $this->resource->beforeDelete($this->entry(4, 'wrong_size'));

        $this->assertSame(['parent_before_delete'], $this->resource->calls);
    }
}
