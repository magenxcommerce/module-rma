<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PDOException;
use Throwable;

/**
 * Turns a failed lookup delete into an admin message without database text.
 *
 * An entry still used by a return fails on its foreign key; the admin should be told
 * to deactivate it instead (PRD FR-L4, AC-15), not shown the SQLSTATE and the query.
 */
class DeleteFailureMessage
{
    /**
     * MySQL: cannot delete or update a parent row (foreign key).
     */
    private const FK_PARENT_ROW = 1451;

    /**
     * @param Throwable $e
     * @param string $entityLabel e.g. "RMA Reason"
     * @return Phrase
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- pure mapping of an exception to a message, shared by the repository and the mass-delete controller; nothing to intercept.
    public static function forException(Throwable $e, string $entityLabel): Phrase
    {
        if (self::isForeignKeyViolation($e)) {
            return __('This %1 is used by existing returns and cannot be deleted. Deactivate it instead.', $entityLabel);
        }

        if ($e instanceof LocalizedException) {
            return __('Could not delete the %1: %2', $entityLabel, $e->getMessage());
        }

        return __('Could not delete the %1.', $entityLabel);
    }

    /**
     * @param Throwable $e
     * @return bool
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- private helper of the static mapping above.
    private static function isForeignKeyViolation(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && (int)($current->errorInfo[1] ?? 0) === self::FK_PARENT_ROW) {
                return true;
            }
            if (str_contains($current->getMessage(), 'SQLSTATE[23000]')
                && str_contains($current->getMessage(), (string)self::FK_PARENT_ROW)
            ) {
                return true;
            }
        }

        return false;
    }
}
