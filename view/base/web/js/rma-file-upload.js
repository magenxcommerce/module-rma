/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 */
define([
    'jquery',
    'mage/translate',
    'Magenx_Rma/js/rma-utils'
], function ($, $t, rmaUtils) {
    'use strict';

    return function (config, element) {
        let uploadUrl = config.uploadUrl,
            allowedExtensions = (config.allowedExtensions || '').split(',').map(function (e) { return e.trim().toLowerCase(); }),
            maxFileSize = (config.maxFileSize || 10) * 1024 * 1024,
            maxFiles = config.maxFiles || 5,
            formKey = config.formKey || '',
            rmaId = config.rmaId || 0,
            uploadedFiles = [],
            $container = $(element),
            $dropZone = $container.find('.rma-upload-dropzone'),
            $fileInput = $container.find('.rma-upload-input'),
            $fileList = $container.find('.rma-upload-list'),
            $hiddenInput = $container.find('.rma-upload-data');

        function validateFile(file) {
            let ext = file.name.split('.').pop().toLowerCase();

            if (allowedExtensions.length && allowedExtensions[0] !== '' && $.inArray(ext, allowedExtensions) === -1) {
                alert($t('File type not allowed: %1').replace('%1', ext));
                return false;
            }

            if (file.size > maxFileSize) {
                alert($t('File "%1" exceeds the maximum allowed size.').replace('%1', file.name));
                return false;
            }

            return true;
        }

        function uploadFile(file) {
            if (uploadedFiles.length >= maxFiles) {
                alert($t('Maximum number of files reached (%1).').replace('%1', maxFiles));
                return;
            }

            let formData = new FormData();
            formData.append('attachment', file);
            formData.append('form_key', formKey);

            // Lets the controller enforce the same store-scoped limits this widget
            // was configured with, rather than the default-scope ones.
            if (rmaId) {
                formData.append('rma_id', rmaId);
            }

            let $progress = $('<div class="rma-upload-item uploading">')
                .text($t('Uploading %1...').replace('%1', file.name));
            $fileList.append($progress);

            $.ajax({
                url: uploadUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    $progress.remove();

                    if (response.success && response.file) {
                        uploadedFiles.push(response.file);
                        renderFileList();
                        updateHiddenInput();
                    } else {
                        alert(response.message || $t('Upload failed.'));
                    }
                },
                error: function () {
                    $progress.remove();
                    alert($t('Upload failed.'));
                }
            });
        }

        function handleFiles(files) {
            $.each(files, function (i, file) {
                if (uploadedFiles.length >= maxFiles) {
                    return false;
                }

                if (validateFile(file)) {
                    uploadFile(file);
                }
            });
        }

        function removeFile(index) {
            uploadedFiles.splice(index, 1);
            renderFileList();
            updateHiddenInput();
        }

        function renderFileList() {
            $fileList.empty();

            $.each(uploadedFiles, function (i, file) {
                let $item = $('<div class="rma-upload-item">');
                $item.append($('<span class="rma-upload-filename">').text(file.name));
                $item.append($('<span class="rma-upload-size">').text(' (' + rmaUtils.formatFileSize(file.size) + ')'));
                $item.append(
                    $('<button type="button" class="rma-upload-remove" title="' + $t('Remove') + '">')
                        .html('&times;')
                        .on('click', function () { removeFile(i); })
                );
                $fileList.append($item);
            });
        }

        function updateHiddenInput() {
            $hiddenInput.val(JSON.stringify(uploadedFiles));
        }

        // Bind events
        $fileInput.on('change', function () {
            handleFiles(this.files);
            this.value = '';
        });

        $dropZone.on('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('drag-over');
        });

        $dropZone.on('dragleave drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('drag-over');
        });

        $dropZone.on('drop', function (e) {
            handleFiles(e.originalEvent.dataTransfer.files);
        });

        $dropZone.on('click', function () {
            $fileInput.trigger('click');
        });

        // Public API accessible via jQuery data
        $container.data('rmaFileUpload', {
            getFiles: function () { return uploadedFiles; },
            getJson: function () { return JSON.stringify(uploadedFiles); },
            clear: function () {
                uploadedFiles = [];
                renderFileList();
                updateHiddenInput();
            }
        });
    };
});
