<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Console\Command;

use Magenx\Rma\Console\Command\CleanupCommand;
use Magenx\Rma\Model\RMA\StatusCodes;
use PHPUnit\Framework\TestCase;

class CleanupCommandTest extends TestCase
{
    public function testRefundedIsClosed(): void
    {
        $this->assertContains(StatusCodes::REFUNDED, CleanupCommand::CLOSED_STATUSES);
    }
}
