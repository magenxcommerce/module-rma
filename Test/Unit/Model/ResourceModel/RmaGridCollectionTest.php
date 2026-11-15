<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model\ResourceModel;

use Magenx\Rma\Model\ResourceModel\RMA\Grid\Collection;
use PHPUnit\Framework\TestCase;

class RmaGridCollectionTest extends TestCase
{
    public function testBareGridFieldsMapToTheReturnTable(): void
    {
        $collection = (new \ReflectionClass(Collection::class))->newInstanceWithoutConstructor();
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('joinLeft')->willReturnSelf();
        (new \ReflectionProperty(Collection::class, '_select'))->setValue($collection, $select);
        $resource = $this->createMock(\Magenx\Rma\Model\ResourceModel\RMA::class);
        $resource->method('getTable')->willReturnArgument(0);
        (new \ReflectionProperty(Collection::class, '_resource'))->setValue($collection, $resource);

        (new \ReflectionMethod(Collection::class, 'addOrderNumberAndFieldMap'))->invoke($collection);

        $map = (new \ReflectionMethod(Collection::class, '_getMappedField'));
        $this->assertSame('main_table.store_id', $map->invoke($collection, 'store_id'));
        $this->assertSame('main_table.entity_id', $map->invoke($collection, 'entity_id'));
        $this->assertSame('so.increment_id', $map->invoke($collection, 'order_increment_id'));
    }
}
