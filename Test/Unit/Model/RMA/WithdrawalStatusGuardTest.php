<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model\RMA;

use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Model\RMA\StatusCodes;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magenx\Rma\Model\RMA\WithdrawalStatusGuard;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class WithdrawalStatusGuardTest extends TestCase
{
    private const IDS = [
        StatusCodes::NEW_REQUEST => 1,
        StatusCodes::NEED_DETAILS => 2,
        StatusCodes::APPROVED => 3,
        StatusCodes::REJECTED => 4,
        StatusCodes::SHIPPED_BY_CUSTOMER => 5,
        StatusCodes::RECEIVED_BY_ADMIN => 6,
        StatusCodes::CANCELED_BY_CUSTOMER => 7,
        StatusCodes::RESOLVED => 8,
        StatusCodes::REFUNDED => 9,
    ];

    private WithdrawalStatusGuard $guard;

    protected function setUp(): void
    {
        $resolver = $this->createMock(StatusResolver::class);
        $resolver->method('getCodeById')->willReturnCallback(
            fn(int $id) => array_search($id, self::IDS, true) ?: null
        );
        $this->guard = new WithdrawalStatusGuard($resolver);
    }

    private function rma(string $status, bool $withdrawal = true): RMAInterface
    {
        return $this->createConfiguredMock(RMAInterface::class, [
            'getStatusId' => self::IDS[$status],
            'isWithdrawal' => $withdrawal,
        ]);
    }

    public function testOrdinaryRmaMayBeRejected(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard->assertAllowed(
            $this->rma(StatusCodes::REJECTED, false),
            $this->rma(StatusCodes::NEW_REQUEST, false)
        );
    }

    public function testWithdrawalCannotBeRejected(): void
    {
        $this->expectException(LocalizedException::class);

        $this->guard->assertAllowed($this->rma(StatusCodes::REJECTED), $this->rma(StatusCodes::NEW_REQUEST));
    }

    public function testNewWithdrawalCannotStartRejected(): void
    {
        $this->expectException(LocalizedException::class);

        $this->guard->assertAllowed($this->rma(StatusCodes::REJECTED), null);
    }

    public function testWithdrawalCanBeCanceledBeforeTheGoodsAreSent(): void
    {
        $this->expectNotToPerformAssertions();

        foreach ([StatusCodes::NEW_REQUEST, StatusCodes::NEED_DETAILS, StatusCodes::APPROVED] as $from) {
            $this->guard->assertAllowed($this->rma(StatusCodes::CANCELED_BY_CUSTOMER), $this->rma($from));
        }
    }

    public function testWithdrawalCannotBeCanceledOnceShipped(): void
    {
        $this->expectException(LocalizedException::class);

        $this->guard->assertAllowed(
            $this->rma(StatusCodes::CANCELED_BY_CUSTOMER),
            $this->rma(StatusCodes::SHIPPED_BY_CUSTOMER)
        );
    }

    public function testWithdrawalFlagCannotBeRemoved(): void
    {
        $this->expectException(LocalizedException::class);

        $this->guard->assertAllowed(
            $this->rma(StatusCodes::APPROVED, false),
            $this->rma(StatusCodes::APPROVED)
        );
    }

    public function testUnchangedStatusIsAlwaysAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        // Even a stored withdrawal that is already rejected (from before this rule) can still be saved.
        $this->guard->assertAllowed($this->rma(StatusCodes::REJECTED), $this->rma(StatusCodes::REJECTED));
    }

    public function testWithdrawalMovesThroughTheReturnFlow(): void
    {
        $this->expectNotToPerformAssertions();

        $flow = [
            StatusCodes::APPROVED,
            StatusCodes::SHIPPED_BY_CUSTOMER,
            StatusCodes::RECEIVED_BY_ADMIN,
            StatusCodes::REFUNDED,
            StatusCodes::RESOLVED,
        ];
        for ($i = 1; $i < count($flow); $i++) {
            $this->guard->assertAllowed($this->rma($flow[$i]), $this->rma($flow[$i - 1]));
        }
    }
}
