<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Console\Command;

use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\Data\RMASearchResultsInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Console\Command\ListCommand;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magenx\Rma\Model\ResourceModel\Status\Collection as StatusCollection;
use Magenx\Rma\Model\ResourceModel\Status\CollectionFactory as StatusCollectionFactory;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ListCommandTest extends TestCase
{
    public function testListIsBoundedAndReadsOrderNumbersOnce(): void
    {
        $criteria = $this->createMock(SearchCriteria::class);
        $criteria->expects($this->once())->method('setPageSize')->with(100);
        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('create')->willReturn($criteria);
        $sortBuilder = $this->createMock(SortOrderBuilder::class);
        $sortBuilder->method('setField')->willReturnSelf();
        $sortBuilder->method('setDescendingDirection')->willReturnSelf();
        $sortBuilder->method('create')->willReturn($this->createMock(SortOrder::class));

        $rmas = [
            $this->createConfiguredMock(RMAInterface::class, ['getOrderId' => 100, 'getIncrementId' => 'RMA-1', 'getStatusId' => 1]),
            $this->createConfiguredMock(RMAInterface::class, ['getOrderId' => 101, 'getIncrementId' => 'RMA-2', 'getStatusId' => 1]),
        ];
        $repository = $this->createMock(RMARepositoryInterface::class);
        $repository->method('getList')->willReturn(
            $this->createConfiguredMock(RMASearchResultsInterface::class, ['getTotalCount' => 2, 'getItems' => $rmas])
        );

        $statuses = $this->createMock(StatusCollection::class);
        $statuses->method('getIterator')->willReturn(new \ArrayIterator([]));
        $statusFactory = $this->createMock(StatusCollectionFactory::class);
        $statusFactory->method('create')->willReturn($statuses);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects($this->once())->method('fetchPairs')->willReturn([100 => '000000100', 101 => '000000101']);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new ListCommand(
            $repository,
            $criteriaBuilder,
            $this->createMock(FilterBuilder::class),
            $this->createMock(FilterGroupBuilder::class),
            $sortBuilder,
            $this->createMock(StatusResolver::class),
            $statusFactory,
            $resource
        ));
        $tester->execute([]);

        $this->assertStringContainsString('#000000101', $tester->getDisplay());
        $this->assertStringContainsString('Showing 2 of 2', $tester->getDisplay());
    }
}
