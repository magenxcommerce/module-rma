<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model;

use Magenx\Rma\Model\ChoiceListsCache;
use Magenx\Rma\Model\ItemCondition;
use Magenx\Rma\Model\Reason;
use Magenx\Rma\Model\ResolutionType;
use Magento\Framework\DataObject\IdentityInterface;
use PHPUnit\Framework\TestCase;

class ChoiceListsIdentityTest extends TestCase
{
    /**
     * @dataProvider lookupProvider
     */
    public function testLookupEntryPurgesTheChoiceLists(string $class): void
    {
        $entry = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        $this->assertInstanceOf(IdentityInterface::class, $entry);
        $this->assertSame([ChoiceListsCache::TAG], $entry->getIdentities());
    }

    public static function lookupProvider(): array
    {
        return [[Reason::class], [ResolutionType::class], [ItemCondition::class]];
    }
}
