<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Service;

use Magenx\Rma\Api\Data\StatusInterface;
use Magenx\Rma\Api\ItemConditionRepositoryInterface;
use Magenx\Rma\Api\ReasonRepositoryInterface;
use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;
use Magenx\Rma\Api\StatusRepositoryInterface;
use Magenx\Rma\Service\LabelResolver;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class LabelResolverTest extends TestCase
{
    public function testSameValueIsLoadedOncePerRequest(): void
    {
        $statuses = $this->createMock(StatusRepositoryInterface::class);
        $statuses->expects($this->exactly(2))->method('get')->willReturn(
            $this->createConfiguredMock(StatusInterface::class, [
                'getEntityId' => 2, 'getCode' => 'approved', 'getStoreLabel' => 'Approved',
            ])
        );
        $resolver = new LabelResolver(
            $statuses,
            $this->createMock(ReasonRepositoryInterface::class),
            $this->createMock(ResolutionTypeRepositoryInterface::class),
            $this->createMock(ItemConditionRepositoryInterface::class),
            $this->createMock(StoreManagerInterface::class)
        );

        $first = $resolver->resolveAsArray(LabelResolver::TYPE_STATUS, 2, 1);
        $this->assertSame($first, $resolver->resolveAsArray(LabelResolver::TYPE_STATUS, 2, 1));

        $resolver->_resetState();
        $resolver->resolveAsArray(LabelResolver::TYPE_STATUS, 2, 1);
    }
}
