<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model\ResourceModel;

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
