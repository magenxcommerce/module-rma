<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

/**
 * Outcome of WithdrawalSubmitService::submit(): the stored declaration, plus what
 * the return side did with it (null when that step failed unexpectedly; the
 * declaration stands regardless).
 */
class WithdrawalSubmission
{
    /**
     * @param WithdrawalDeclaration $declaration
     * @param WithdrawalResult|null $result
     */
    public function __construct(
        public readonly WithdrawalDeclaration $declaration,
        public readonly ?WithdrawalResult $result
    ) {
    }
}
