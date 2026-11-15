<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Model;

/**
 * Cache tag of the storefront return choice lists (reasons, resolutions, conditions).
 *
 * The GraphQL choice-list query tags its cached response with it; saving or deleting
 * any of those lookup entries purges it through the entries' identities.
 */
class ChoiceListsCache
{
    public const TAG = 'rma_choice_lists';
}
