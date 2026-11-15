<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Service;

use Magenx\Rma\Api\Data\AttachmentInterface;
use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Model\ResourceModel\Comment\Collection;
use Magenx\Rma\Model\ResourceModel\Comment\CollectionFactory;
use Magenx\Rma\Service\AttachmentService;
use Magenx\Rma\Service\CommentFormatter;
use PHPUnit\Framework\TestCase;

class CommentFormatterTest extends TestCase
{
    public function testAttachmentsOfAListComeFromOneQuery(): void
    {
        $comments = [];
        foreach ([1, 2, 3] as $id) {
            $comments[$id] = $this->createConfiguredMock(CommentInterface::class, ['getEntityId' => $id]);
        }
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn($comments);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $attachment = $this->createConfiguredMock(AttachmentInterface::class, ['getCommentId' => 2]);
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->expects($this->once())->method('getByCommentIds')->with([1, 2, 3])->willReturn([$attachment]);
        $attachments->expects($this->never())->method('getByCommentId');
        $attachments->method('toArray')->willReturn(['entity_id' => 7]);

        $list = (new CommentFormatter($attachments, $factory))->buildList(5);

        $this->assertSame([], $list[0]['attachments']);
        $this->assertSame([['entity_id' => 7]], $list[1]['attachments']);
        $this->assertSame([], $list[2]['attachments']);
    }
}
