<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model;

use Magenx\Rma\Model\DeleteFailureMessage;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class DeleteFailureMessageTest extends TestCase
{
    public function testForeignKeyFailureTellsToDeactivate(): void
    {
        $pdo = new \PDOException('SQLSTATE[23000]: Integrity constraint violation');
        $pdo->errorInfo = ['23000', 1451, 'Cannot delete or update a parent row'];
        $e = new \Zend_Db_Statement_Exception('SQLSTATE[23000]: ... 1451 Cannot delete ..., query was: DELETE FROM `rma_reason`', 23000, $pdo);

        $message = (string)DeleteFailureMessage::forException($e, 'RMA Reason');

        $this->assertStringContainsString('Deactivate it instead', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
    }

    public function testBusinessRefusalKeepsItsMessage(): void
    {
        $message = (string)DeleteFailureMessage::forException(
            new LocalizedException(__('"withdrawal" is used by the system and cannot be deleted.')),
            'RMA Reason'
        );

        $this->assertStringContainsString('used by the system', $message);
    }

    public function testOtherFailureShowsNoDatabaseText(): void
    {
        $message = (string)DeleteFailureMessage::forException(new \RuntimeException('SQLSTATE[HY000]: gone away'), 'RMA Reason');

        $this->assertSame('Could not delete the RMA Reason.', $message);
    }
}
