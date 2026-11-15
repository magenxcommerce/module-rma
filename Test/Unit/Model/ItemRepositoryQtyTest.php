<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model;

use Magenx\Rma\Model\Item;
use Magenx\Rma\Model\ItemRepository;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ItemRepositoryQtyTest extends TestCase
{
    private function check(int $requested, ?int $approved, ?int $returned): void
    {
        $item = (new \ReflectionClass(Item::class))->newInstanceWithoutConstructor();
        $item->setData(['order_item_id' => 5, 'qty_requested' => $requested, 'qty_approved' => $approved, 'qty_returned' => $returned]);
        $repository = (new \ReflectionClass(ItemRepository::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($repository, 'assertQuantities');
        $method->invoke($repository, $item);
    }

    /**
     * @dataProvider validProvider
     */
    public function testValidQuantitiesPass(int $requested, ?int $approved, ?int $returned): void
    {
        $this->check($requested, $approved, $returned);
        $this->addToAssertionCount(1);
    }

    public static function validProvider(): array
    {
        return [
            'nothing recorded' => [2, null, null],
            'approved all, received part' => [2, 2, 1],
            'approved none' => [2, 0, 0],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testInvalidQuantitiesAreRefused(int $requested, ?int $approved, ?int $returned): void
    {
        $this->expectException(LocalizedException::class);
        $this->check($requested, $approved, $returned);
    }

    public static function invalidProvider(): array
    {
        return [
            'approved above requested' => [2, 3, null],
            'received above approved' => [2, 1, 2],
            'received without approval' => [2, null, 1],
            'negative approved' => [2, -1, null],
        ];
    }
}
