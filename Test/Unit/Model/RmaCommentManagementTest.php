<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model;

use Magenx\Rma\Api\CommentRepositoryInterface;
use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Model\RmaCommentManagement;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrderBuilder;
use PHPUnit\Framework\TestCase;

class RmaCommentManagementTest extends TestCase
{
    /**
     * @dataProvider authorTypeProvider
     */
    public function testOnlyKnownAuthorTypesAreStored(string $given, ?string $expectedSet): void
    {
        $repository = $this->createMock(CommentRepositoryInterface::class);
        $management = new RmaCommentManagement(
            $this->createMock(RMARepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilderFactory::class),
            $this->createMock(SortOrderBuilder::class),
            $repository
        );
        $comment = $this->createMock(CommentInterface::class);
        $comment->method('getAuthorType')->willReturn($given);
        $expectedSet === null
            ? $comment->expects($this->never())->method('setAuthorType')
            : $comment->expects($this->once())->method('setAuthorType')->with($expectedSet);
        $repository->expects($this->once())->method('save')->willReturn($comment);

        $management->save(5, $comment);
    }

    public static function authorTypeProvider(): array
    {
        return [
            'admin kept' => ['admin', null],
            'customer kept' => ['customer', null],
            'markup replaced' => ['x"><svg onload=alert(1)>', 'admin'],
        ];
    }
}
