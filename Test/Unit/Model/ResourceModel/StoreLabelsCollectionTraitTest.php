<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model\ResourceModel;

use Magenx\Rma\Model\Reason;
use Magenx\Rma\Model\ResourceModel\StoreLabelsCollectionTrait;
use PHPUnit\Framework\TestCase;

/**
 * Stands in for AbstractCollection: the trait only needs _items, getResource() and a parent _afterLoad().
 */
class LabelledCollectionStub
{
    public array $_items = [];
    public object $resource;

    protected function _afterLoad()
    {
        return $this;
    }

    public function getResource(): object
    {
        return $this->resource;
    }
}

class StoreLabelsCollectionTraitTest extends TestCase
{
    public function testItemsGetTheirStoreLabelsFromOneQuery(): void
    {
        $collection = new class extends LabelledCollectionStub {
            use StoreLabelsCollectionTrait;

            public function afterLoad(): void
            {
                $this->_afterLoad();
            }
        };
        $collection->resource = new class {
            public int $calls = 0;

            public function getStoreLabelsByIds(array $ids): array
            {
                $this->calls++;
                return [3 => [2 => 'Taille incorrecte']];
            }
        };

        $make = function (int $id, string $label): Reason {
            $reason = (new \ReflectionClass(Reason::class))->newInstanceWithoutConstructor();
            $reason->setData(['entity_id' => $id, 'label' => $label]);
            return $reason;
        };
        $collection->_items = [3 => $make(3, 'Wrong size'), 4 => $make(4, 'Defective')];

        $collection->afterLoad();

        $this->assertSame(1, $collection->resource->calls);
        $this->assertSame('Taille incorrecte', $collection->_items[3]->getStoreLabel(2));
        $this->assertSame('Wrong size', $collection->_items[3]->getStoreLabel(1));
        $this->assertSame('Defective', $collection->_items[4]->getStoreLabel(2));
    }
}
