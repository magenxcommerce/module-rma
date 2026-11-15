<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Controller\Adminhtml\Order;

use Magenx\Rma\Controller\Adminhtml\Order\Search;
use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Service\OrderEligibility;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\TestCase;

class SearchTest extends TestCase
{
    public function testQueryIsNarrowedSortedAndCapped(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getParam')->willReturnMap([
            ['searchKey', null, '12'],
            ['limit', 20, '1000'],
        ]);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getEnabledStoreIds')->willReturn([1, 2]);
        $config->method('getAllowedOrderStatuses')->willReturnMap([[1, ['complete']], [2, ['complete', 'closed']]]);
        $config->method('getReturnPeriod')->willReturnMap([[1, 30], [2, 60]]);

        $filters = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition) use (&$filters, $collection) {
                $filters[$field] = $condition;
                return $collection;
            }
        );
        $collection->expects($this->once())->method('setOrder')->with('entity_id', 'DESC');
        $collection->expects($this->once())->method('setPageSize')->with(150);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnSelf();
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $controller = new Search(
            $context,
            $jsonFactory,
            $collectionFactory,
            $config,
            $this->createMock(OrderEligibility::class)
        );
        $controller->execute();

        $this->assertSame(['like' => '%12%'], $filters['increment_id']);
        $this->assertSame(['in' => [1, 2]], $filters['store_id']);
        $this->assertSame(['in' => ['complete', 'closed']], $filters['status']);
        $this->assertArrayHasKey('gteq', $filters['created_at']);
        $this->assertSame(
            gmdate('Y-m-d', strtotime('-60 days')),
            substr($filters['created_at']['gteq'], 0, 10)
        );
    }
}
