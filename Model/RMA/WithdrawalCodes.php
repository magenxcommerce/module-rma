<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\RMA;

/**
 * Lookup codes an RMA opened from an EU consumer withdrawal is created with.
 * Seeded by Setup\Patch\Data\AddWithdrawalLookups.
 */
class WithdrawalCodes
{
    const REASON = 'withdrawal';
    const RESOLUTION_REFUND = 'refund';
}
