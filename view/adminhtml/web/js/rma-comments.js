/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: the comment wrapper class comes from a fixed list, not author_type.
 */
define([
    'jquery',
    'mage/translate',
    'Magenx_Rma/js/rma-utils',
    'jquery/jquery.cookie'
], function ($, $t, rmaUtils) {
    'use strict';

    return function (config, element) {
        let rmaId = config.rmaId,
            saveUrl = config.saveUrl,
            loadListUrl = config.loadListUrl,
            lastCommentId = config.lastCommentId || 0,
            isAdmin = config.isAdmin || false,
            downloadUrl = config.downloadUrl || '',
            deleteUrl = config.deleteUrl || '',
            $container = $(element),
            $timeline = $container.find('#rma-comments-timeline'),
            $textarea = $container.find('#rma-comment-text'),
            $submitBtn = $container.find('#rma-comment-submit'),
            $visibleCheckbox = $container.find('#rma-comment-visible'),
            $uploadWidget = $container.find('.rma-comment-upload-widget'),
            $allAttachments = $('#rma-all-attachments'),
            pollTimer = null,
            baseInterval = 10000,
            maxInterval = 60000,
            currentInterval = baseInterval,
            sending = false;

        function scrollToBottom() {
            $timeline.scrollTop($timeline[0].scrollHeight);
        }

        function addToUnifiedSection(attachments) {
            if (!attachments || attachments.length === 0 || !$allAttachments.length) {
                return;
            }

            $allAttachments.find('.rma-no-attachments').remove();

            $.each(attachments, function (i, att) {
                if ($allAttachments.find('[data-attachment-id="' + att.entity_id + '"]').length) {
                    return;
                }

                let url = downloadUrl + (downloadUrl.indexOf('?') > -1 ? '&' : '?') + 'id=' + att.entity_id,
                    html = '<li data-attachment-id="' + att.entity_id + '" style="padding: 6px 0; border-bottom: 1px solid #eee;">' +
                        '<a href="' + url + '" target="_blank" style="color: #1979c3; text-decoration: none;">' +
                        '&#128206; ' + $('<span>').text(att.file_name).html() +
                        '</a>' +
                        ' <span style="color: #999; font-size: 12px;">(' + rmaUtils.formatFileSize(att.file_size) + ')</span>';

                if (isAdmin && deleteUrl) {
                    html += ' <button type="button" class="rma-unified-attachment-delete" data-id="' + att.entity_id + '" ' +
                        'style="color: #e22626; cursor: pointer; border: none; background: none; font-size: 11px; padding: 0; margin-left: 4px;">' +
                        $t('Delete') + '</button>';
                }

                html += '</li>';
                $allAttachments.append(html);
            });
        }

        function renderAttachments(attachments) {
            if (!attachments || attachments.length === 0) {
                return '';
            }

            let html = '<div class="rma-comment-attachments" style="margin-top: 6px; padding-top: 6px; border-top: 1px solid rgba(0,0,0,0.1);">';

            $.each(attachments, function (i, att) {
                let url = downloadUrl + (downloadUrl.indexOf('?') > -1 ? '&' : '?') + 'id=' + att.entity_id;
                html += '<div class="rma-attachment-item" style="font-size: 11px; margin-top: 3px;">';
                html += '<a href="' + url + '" target="_blank" style="color: #1979c3; text-decoration: none;">';
                html += '&#128206; ' + $('<span>').text(att.file_name).html();
                html += '</a>';
                html += ' <span style="color: #999;">(' + rmaUtils.formatFileSize(att.file_size) + ')</span>';

                if (isAdmin && deleteUrl) {
                    html += ' <button type="button" class="rma-attachment-delete" data-id="' + att.entity_id + '" ' +
                        'style="color: #e22626; cursor: pointer; border: none; background: none; font-size: 11px; padding: 0;">' +
                        $t('Delete') + '</button>';
                }

                html += '</div>';
            });

            html += '</div>';
            return html;
        }

        function renderComment(comment) {
            let $noComments = $timeline.find('.rma-no-comments');

            if ($noComments.length) {
                $noComments.remove();
            }

            let isAdminComment = comment.author_type === 'admin',
                isCustomerComment = comment.author_type === 'customer',
                style,
                html;

            if (isAdmin) {
                style = isAdminComment
                    ? 'background: #e8f0fe; margin-left: 20px;'
                    : 'background: #fff; margin-right: 20px; border: 1px solid #ddd;';
            } else {
                style = isCustomerComment
                    ? 'background: #e8f4e8; margin-left: 20px;'
                    : 'background: #fff; margin-right: 20px; border: 1px solid #ddd;';
            }

            // author_type can arrive from an integration: never put it into markup as is.
            html = '<div class="rma-comment ' + (isAdminComment ? 'admin' : 'customer') + '" ' +
                'data-comment-id="' + parseInt(comment.entity_id, 10) + '" ' +
                'style="margin-bottom: 10px; padding: 8px 12px; border-radius: 6px; ' + style + '">' +
                '<div style="font-size: 11px; color: #666; margin-bottom: 4px;">' +
                '<strong>' + $('<span>').text(comment.author_name).html() + '</strong>';

            if (!isAdmin && isAdminComment) {
                html += '<span style="margin-left: 4px; font-size: 10px; color: #1979c3;">' + $t('Support') + '</span>';
            }

            html += '<span style="margin-left: 8px;">' + $('<span>').text(comment.created_at).html() + '</span>';

            if (isAdmin && comment.is_visible_to_customer === false) {
                html += '<span style="margin-left: 8px; color: #e67700; font-weight: bold;">' + $t('Internal Note') + '</span>';
            }

            html += '</div>' +
                '<div style="white-space: pre-wrap;">' + $('<span>').text(comment.comment).html() + '</div>' +
                renderAttachments(comment.attachments) +
                '</div>';

            $timeline.append(html);
            addToUnifiedSection(comment.attachments);
        }

        function pollComments() {
            $.ajax({
                url: loadListUrl,
                type: 'GET',
                dataType: 'json',
                data: {
                    rma_id: rmaId,
                    after_id: lastCommentId
                },
                success: function (response) {
                    if (response.success && response.comments && response.comments.length > 0) {
                        $.each(response.comments, function (i, comment) {
                            if (comment.entity_id > lastCommentId) {
                                renderComment(comment);
                                lastCommentId = comment.entity_id;
                            }
                        });

                        scrollToBottom();
                        currentInterval = baseInterval;
                    } else {
                        currentInterval = Math.min(currentInterval * 1.5, maxInterval);
                    }
                },
                error: function () {
                    currentInterval = Math.min(currentInterval * 2, maxInterval);
                },
                complete: function () {
                    schedulePoll();
                }
            });
        }

        function schedulePoll() {
            if (pollTimer) {
                clearTimeout(pollTimer);
            }

            pollTimer = setTimeout(pollComments, currentInterval);
        }

        function stopPoll() {
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
        }

        function submitComment() {
            let commentText = $.trim($textarea.val());

            if (!commentText || sending) {
                return;
            }

            sending = true;
            $submitBtn.prop('disabled', true);

            let uploadApi = $uploadWidget.data('rmaFileUpload'),
                attachmentsJson = uploadApi ? uploadApi.getJson() : '[]';

            let postData = {
                rma_id: rmaId,
                comment: commentText,
                form_key: isAdmin ? window.FORM_KEY : ($.cookie('form_key') || ''),
                attachments: attachmentsJson
            };

            if (isAdmin && $visibleCheckbox.length) {
                postData.is_visible_to_customer = $visibleCheckbox.is(':checked') ? 1 : 0;
            }

            $.ajax({
                url: saveUrl,
                type: 'POST',
                dataType: 'json',
                data: postData,
                success: function (response) {
                    if (response.success && response.comment) {
                        renderComment(response.comment);
                        lastCommentId = response.comment.entity_id;
                        $textarea.val('');
                        scrollToBottom();
                        currentInterval = baseInterval;

                        if (uploadApi) {
                            uploadApi.clear();
                        }
                    } else {
                        alert(response.message || $t('Could not save comment.'));
                    }
                },
                error: function () {
                    alert($t('Could not save comment.'));
                },
                complete: function () {
                    sending = false;
                    $submitBtn.prop('disabled', false);
                    $textarea.focus();
                }
            });
        }

        function deleteAttachment(attachmentId) {
            if (!confirm($t('Delete this attachment?'))) {
                return;
            }

            $.ajax({
                url: deleteUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    id: attachmentId,
                    form_key: window.FORM_KEY
                },
                success: function (response) {
                    if (response.success) {
                        $timeline.find('.rma-attachment-delete[data-id="' + attachmentId + '"]')
                            .closest('.rma-attachment-item').remove();
                        $allAttachments.find('[data-attachment-id="' + attachmentId + '"]').remove();

                        if (!$allAttachments.find('[data-attachment-id]').length) {
                            $allAttachments.append(
                                '<li class="rma-no-attachments" style="color: #999; font-style: italic; padding: 4px 0;">' +
                                $t('No attachments yet.') + '</li>'
                            );
                        }
                    } else {
                        alert(response.message || $t('Could not delete attachment.'));
                    }
                },
                error: function () {
                    alert($t('Could not delete attachment.'));
                }
            });
        }

        if (isAdmin && deleteUrl) {
            $timeline.on('click', '.rma-attachment-delete', function () {
                deleteAttachment($(this).data('id'));
            });

            $allAttachments.on('click', '.rma-unified-attachment-delete', function () {
                deleteAttachment($(this).data('id'));
            });
        }

        $(document).on('visibilitychange', function () {
            if (document.hidden) {
                stopPoll();
            } else {
                currentInterval = baseInterval;
                pollComments();
            }
        });

        $submitBtn.on('click', submitComment);

        $textarea.on('keydown', function (e) {
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                submitComment();
            }
        });

        scrollToBottom();
        schedulePoll();
    };
});
