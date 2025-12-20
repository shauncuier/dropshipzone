/**
 * Dropshipzone Sync Admin JavaScript
 *
 * @package Dropshipzone
 * @version 1.0.0
 */

(function ($) {
    'use strict';

    // Global namespace
    window.DSZAdmin = {
        syncInterval: null,

        /**
         * Initialize
         */
        init: function () {
            this.bindEvents();
            this.initPricePreview();
            this.checkSyncStatus();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function () {
            // API Settings
            $('#dsz-test-connection').on('click', this.testConnection.bind(this));
            $('#dsz-api-form').on('submit', this.saveApiSettings.bind(this));

            // Price/Stock/Schedule forms
            $('#dsz-price-form, #dsz-stock-form, #dsz-schedule-form').on('submit', this.saveSettings.bind(this));

            // Sync control
            $('#dsz-run-sync').on('click', this.runSync.bind(this));

            // Logs
            $('#dsz-clear-logs').on('click', this.clearLogs.bind(this));
            $('#dsz-export-logs').on('click', this.exportLogs.bind(this));

            // Price preview inputs
            $('#dsz-price-form input, #dsz-price-form select').on('change', this.updatePricePreview.bind(this));
            $('#preview_price').on('input', this.updatePricePreview.bind(this));

            // Markup type toggle
            $('input[name="markup_type"]').on('change', this.toggleMarkupSymbol.bind(this));
        },

        /**
         * Test API Connection
         */
        testConnection: function (e) {
            e.preventDefault();

            var $btn = $('#dsz-test-connection');
            var $message = $('#dsz-api-message');

            $btn.addClass('dsz-loading').prop('disabled', true);
            $message.removeClass('dsz-message-success dsz-message-error').addClass('hidden');

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_test_connection',
                    nonce: dsz_admin.nonce,
                    email: $('#dsz_api_email').val(),
                    password: $('#dsz_api_password').val()
                },
                success: function (response) {
                    if (response.success) {
                        $message
                            .removeClass('hidden dsz-message-error')
                            .addClass('dsz-message-success')
                            .html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message +
                                ' (' + response.data.products + ' products available)');
                    } else {
                        $message
                            .removeClass('hidden dsz-message-success')
                            .addClass('dsz-message-error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + response.data.message);
                    }
                },
                error: function () {
                    $message
                        .removeClass('hidden dsz-message-success')
                        .addClass('dsz-message-error')
                        .html('<span class="dashicons dashicons-warning"></span> ' + dsz_admin.strings.error);
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Save API Settings
         */
        saveApiSettings: function (e) {
            e.preventDefault();

            var $form = $(e.target);
            var $btn = $form.find('button[type="submit"]');
            var $message = $('#dsz-api-message');

            $btn.addClass('dsz-loading').prop('disabled', true);

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_save_settings',
                    nonce: dsz_admin.nonce,
                    type: 'api',
                    settings: {
                        email: $('#dsz_api_email').val(),
                        password: $('#dsz_api_password').val()
                    }
                },
                success: function (response) {
                    if (response.success) {
                        $message
                            .removeClass('hidden dsz-message-error')
                            .addClass('dsz-message-success')
                            .html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message);
                    } else {
                        $message
                            .removeClass('hidden dsz-message-success')
                            .addClass('dsz-message-error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + response.data.message);
                    }
                },
                error: function () {
                    $message
                        .removeClass('hidden dsz-message-success')
                        .addClass('dsz-message-error')
                        .html('<span class="dashicons dashicons-warning"></span> ' + dsz_admin.strings.error);
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Save general settings (price/stock/schedule)
         */
        saveSettings: function (e) {
            e.preventDefault();

            var $form = $(e.target);
            var type = $form.data('type');
            var $btn = $form.find('button[type="submit"]');
            var $message = $form.find('.dsz-message');

            // Collect form data
            var settings = {};
            $form.find('input, select').each(function () {
                var $input = $(this);
                var name = $input.attr('name');

                if (!name) return;

                if ($input.attr('type') === 'checkbox') {
                    settings[name] = $input.is(':checked') ? 1 : 0;
                } else if ($input.attr('type') === 'radio') {
                    if ($input.is(':checked')) {
                        settings[name] = $input.val();
                    }
                } else {
                    settings[name] = $input.val();
                }
            });

            $btn.addClass('dsz-loading').prop('disabled', true);

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_save_settings',
                    nonce: dsz_admin.nonce,
                    type: type,
                    settings: settings
                },
                success: function (response) {
                    if (response.success) {
                        $message
                            .removeClass('hidden dsz-message-error')
                            .addClass('dsz-message-success')
                            .html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message);
                    } else {
                        $message
                            .removeClass('hidden dsz-message-success')
                            .addClass('dsz-message-error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + response.data.message);
                    }
                },
                error: function () {
                    $message
                        .removeClass('hidden dsz-message-success')
                        .addClass('dsz-message-error')
                        .html('<span class="dashicons dashicons-warning"></span> ' + dsz_admin.strings.error);
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);

                    // Auto-hide message after 5 seconds
                    setTimeout(function () {
                        $message.addClass('hidden');
                    }, 5000);
                }
            });
        },

        /**
         * Run manual sync
         */
        runSync: function (e) {
            e.preventDefault();

            var $btn = $('#dsz-run-sync');
            var $message = $('#dsz-sync-message');
            var $progress = $('#dsz-progress-container');

            $btn.addClass('dsz-loading').prop('disabled', true);
            $progress.removeClass('hidden');
            $message.addClass('hidden');

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_run_sync',
                    nonce: dsz_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        DSZAdmin.startSyncPolling();
                        DSZAdmin.updateProgress(response.data);
                    } else {
                        $message
                            .removeClass('hidden')
                            .addClass('dsz-message-error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + response.data.message);
                        $btn.removeClass('dsz-loading').prop('disabled', false);
                    }
                },
                error: function () {
                    $message
                        .removeClass('hidden')
                        .addClass('dsz-message-error')
                        .html('<span class="dashicons dashicons-warning"></span> ' + dsz_admin.strings.error);
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Start polling for sync status
         */
        startSyncPolling: function () {
            if (this.syncInterval) {
                clearInterval(this.syncInterval);
            }

            this.syncInterval = setInterval(function () {
                DSZAdmin.pollSyncStatus();
            }, 3000);
        },

        /**
         * Poll sync status
         */
        pollSyncStatus: function () {
            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_continue_sync',
                    nonce: dsz_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        DSZAdmin.updateProgress(response.data);

                        if (response.data.status === 'complete' || response.data.status === 'error') {
                            DSZAdmin.stopSyncPolling();
                            DSZAdmin.syncComplete(response.data);
                        }
                    }
                },
                error: function () {
                    DSZAdmin.stopSyncPolling();
                }
            });
        },

        /**
         * Stop sync polling
         */
        stopSyncPolling: function () {
            if (this.syncInterval) {
                clearInterval(this.syncInterval);
                this.syncInterval = null;
            }
        },

        /**
         * Update progress display
         */
        updateProgress: function (data) {
            var progress = data.progress || 0;
            $('#dsz-progress-fill').css('width', progress + '%');
            $('#dsz-progress-text').text(data.message || 'Processing... ' + progress + '%');
            $('#sync-status-text').text(dsz_admin.strings.syncing).addClass('dsz-status-active');
        },

        /**
         * Sync complete handler
         */
        syncComplete: function (data) {
            var $btn = $('#dsz-run-sync');
            var $message = $('#dsz-sync-message');
            var $progress = $('#dsz-progress-container');

            $btn.removeClass('dsz-loading').prop('disabled', false);
            $('#sync-status-text').text('Idle').removeClass('dsz-status-active');

            // Get values with defaults
            var productsUpdated = data.products_updated !== undefined ? data.products_updated : 0;
            var errorsCount = data.errors_count !== undefined ? data.errors_count : 0;

            if (data.status === 'complete') {
                $message
                    .removeClass('hidden dsz-message-error')
                    .addClass('dsz-message-success')
                    .html('<span class="dashicons dashicons-yes-alt"></span> Sync completed! ' +
                        productsUpdated + ' products updated, ' +
                        errorsCount + ' errors.');

                $('#dsz-progress-fill').css('width', '100%');
                $('#dsz-progress-text').text('Complete!');
            } else {
                $message
                    .removeClass('hidden dsz-message-success')
                    .addClass('dsz-message-error')
                    .html('<span class="dashicons dashicons-warning"></span> ' + (data.message || 'Sync failed'));
            }

            // Hide progress after 3 seconds
            setTimeout(function () {
                $progress.addClass('hidden');
            }, 3000);
        },

        /**
         * Check sync status on page load
         */
        checkSyncStatus: function () {
            var $progress = $('#dsz-progress-container');
            if ($progress.length && !$progress.hasClass('hidden')) {
                this.startSyncPolling();
            }
        },

        /**
         * Initialize price preview
         */
        initPricePreview: function () {
            if ($('#preview_price').length) {
                this.updatePricePreview();
                this.toggleMarkupSymbol();
            }
        },

        /**
         * Update price preview calculation
         */
        updatePricePreview: function () {
            var supplierPrice = parseFloat($('#preview_price').val()) || 0;
            var markupType = $('input[name="markup_type"]:checked').val() || 'percentage';
            var markupValue = parseFloat($('#markup_value').val()) || 0;
            var gstEnabled = $('input[name="gst_enabled"]').is(':checked');
            var gstType = $('input[name="gst_type"]:checked').val() || 'include';
            var roundingEnabled = $('input[name="rounding_enabled"]').is(':checked');
            var roundingType = $('select[name="rounding_type"]').val() || '99';

            // Calculate with markup
            var price = supplierPrice;
            if (markupType === 'percentage') {
                price = price * (1 + (markupValue / 100));
            } else {
                price = price + markupValue;
            }

            // Apply GST
            if (gstEnabled && gstType === 'exclude') {
                price = price * 1.10;
            }

            // Apply rounding
            if (roundingEnabled) {
                var whole = Math.floor(price);
                switch (roundingType) {
                    case '99':
                        price = whole + 0.99;
                        break;
                    case '95':
                        price = whole + 0.95;
                        break;
                    case 'nearest':
                        price = Math.round(price);
                        break;
                }
            }

            $('#calculated_price').text('$' + price.toFixed(2));
        },

        /**
         * Toggle markup symbol based on type
         */
        toggleMarkupSymbol: function () {
            var markupType = $('input[name="markup_type"]:checked').val();
            var symbol = markupType === 'percentage' ? '%' : '$';
            $('.dsz-markup-symbol').text(symbol);
        },

        /**
         * Clear logs
         */
        clearLogs: function (e) {
            e.preventDefault();

            if (!confirm(dsz_admin.strings.confirm_clear)) {
                return;
            }

            var $btn = $('#dsz-clear-logs');
            $btn.addClass('dsz-loading').prop('disabled', true);

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_clear_logs',
                    nonce: dsz_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function () {
                    alert(dsz_admin.strings.error);
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Export logs as CSV
         */
        exportLogs: function (e) {
            e.preventDefault();

            var $btn = $('#dsz-export-logs');
            var level = new URLSearchParams(window.location.search).get('level') || '';

            $btn.addClass('dsz-loading').prop('disabled', true);

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_export_logs',
                    nonce: dsz_admin.nonce,
                    level: level
                },
                success: function (response) {
                    if (response.success) {
                        // Decode base64 and create download
                        var csvContent = atob(response.data.csv);
                        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                        var link = document.createElement('a');
                        var url = URL.createObjectURL(blob);

                        link.setAttribute('href', url);
                        link.setAttribute('download', response.data.filename);
                        link.style.visibility = 'hidden';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function () {
                    alert(dsz_admin.strings.error);
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        }
    };

    // Initialize on DOM ready
    $(document).ready(function () {
        DSZAdmin.init();
    });

})(jQuery);
