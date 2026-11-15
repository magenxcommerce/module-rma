<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Console\Command;

use Magenx\Rma\Api\Data\RMASearchResultsInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Console\Command\CleanupCommand;
use Magenx\Rma\Model\RMA\StatusCodes;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CleanupCommandTest extends TestCase
{
    public function testRefundedIsClosed(): void
    {
        $this->assertContains(StatusCodes::REFUNDED, CleanupCommand::CLOSED_STATUSES);
    }

    public function testOnlyCustomerWaitingStatusesExpire(): void
    {
        $this->assertSame([StatusCodes::NEED_DETAILS, StatusCodes::APPROVED], CleanupCommand::EXPIRABLE_STATUSES);
    }

    public function testSearchSelectsExpirableNonWithdrawalReturnsOnly(): void
    {
        $statusResolver = $this->createMock(StatusResolver::class);
        $statusResolver->method('getRequiredIdByCode')->willReturn(7);
        $statusResolver->method('getIdsByCodes')
            ->with(CleanupCommand::EXPIRABLE_STATUSES)
            ->willReturn([2, 3]);

        $state = [];
        $filters = [];
        $filterBuilder = $this->createMock(FilterBuilder::class);
        foreach (['setField' => 'field', 'setConditionType' => 'type', 'setValue' => 'value'] as $method => $key) {
            $filterBuilder->method($method)->willReturnCallback(function ($v) use (&$state, $key, $filterBuilder) {
                $state[$key] = $v;
                return $filterBuilder;
            });
        }
        $filterBuilder->method('create')->willReturnCallback(function () use (&$state, &$filters) {
            $filters[] = $state;
            $state = [];
            return $this->createMock(Filter::class);
        });

        $groupBuilder = $this->createMock(FilterGroupBuilder::class);
        $groupBuilder->method('setFilters')->willReturnSelf();
        $groupBuilder->method('create')->willReturn($this->createMock(FilterGroup::class));

        $criteria = $this->createMock(SearchCriteria::class);
        $criteria->expects($this->once())->method('setFilterGroups')
            ->with($this->countOf(4));
        $criteria->expects($this->once())->method('setPageSize')->with(CleanupCommand::BATCH_SIZE);
        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('create')->willReturn($criteria);

        $repository = $this->createMock(RMARepositoryInterface::class);
        $repository->method('getList')->willReturn(
            $this->createConfiguredMock(RMASearchResultsInterface::class, ['getTotalCount' => 0, 'getItems' => []])
        );

        $command = new CleanupCommand($repository, $criteriaBuilder, $filterBuilder, $groupBuilder, $statusResolver);
        $tester = new CommandTester($command);
        $tester->execute(['--days' => '30']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('lteq', $filters[0]['type']);
        $this->assertSame(['field' => 'status_id', 'type' => 'in', 'value' => [2, 3]], $filters[1]);
        $this->assertSame(['field' => 'is_withdrawal', 'type' => 'eq', 'value' => 0], $filters[2]);
        $this->assertSame(['field' => 'entity_id', 'type' => 'gt', 'value' => 0], $filters[3]);
    }
}
