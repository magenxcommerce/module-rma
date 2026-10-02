<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

/**
 * A stored withdrawal declaration, as returned by the recorder.
 */
class WithdrawalDeclaration
{
    /**
     * @param string $ticketCode Public code of the record (e.g. the helpdesk ticket), quoted to the consumer
     * @param string $declaredAt UTC `Y-m-d H:i:s` the declaration arrived (server time)
     */
    public function __construct(
        public readonly string $ticketCode,
        public readonly string $declaredAt
    ) {
    }
}
