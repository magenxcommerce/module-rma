/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 *
 * Copies the approved / received qty inputs of the RMA edit page into the form's
 * data source, so they are posted with the form as item_qtys[<item id>][<field>].
 */
define(['jquery', 'uiRegistry'], function ($, registry) {
    'use strict';

    return function (config, element) {
        registry.get(config.provider, function (source) {
            $(element).on('change', '.rma-item-qty-input', function () {
                var input = $(this),
                    value = input.val();

                source.set(
                    'data.item_qtys.' + parseInt(input.data('item-id'), 10) + '.' + input.data('field'),
                    value === '' ? '' : parseInt(value, 10)
                );
            });
        });
    };
});
