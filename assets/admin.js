/**
 * Dropshipzone Sync Admin JavaScript
 *
 * @package Dropshipzone
 * @version 2.0.5 - Advanced Search & Filters
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
            this.initAnimations();
            this.initRippleEffect();
            this.initProductImport();
        },

        /**
         * Initialize UI animations
         */
        initAnimations: function () {
            // Add entrance animation to elements
            $('.dsz-card, .dsz-form-section, .dsz-section').each(function (index) {
                $(this).css({
                    'animation-delay': (index * 0.1) + 's'
                });
            });

            // Animate stats numbers
            this.animateNumbers();

            // Add hover sound feedback (optional, visual only for now)
            this.initInteractiveFeedback();
        },

        /**
         * Initialize ripple effect for buttons
         */
        initRippleEffect: function () {
            $(document).on('click', '.button, .dsz-card', function (e) {
                var $this = $(this);

                // Skip if already has ripple or is a link that should navigate
                if ($this.find('.dsz-ripple').length) {
                    return;
                }

                var ripple = $('<span class="dsz-ripple"></span>');
                var offset = $this.offset();
                var x = e.pageX - offset.left;
                var y = e.pageY - offset.top;

                ripple.css({
                    left: x + 'px',
                    top: y + 'px'
                });

                $this.css('position', 'relative').css('overflow', 'hidden');
                $this.append(ripple);

                // Add ripple styles dynamically if not exists
                if (!$('#dsz-ripple-style').length) {
                    $('head').append(`
                        <style id="dsz-ripple-style">
                            .dsz-ripple {
                                position: absolute;
                                border-radius: 50%;
                                background: rgba(255, 255, 255, 0.4);
                                transform: scale(0);
                                animation: dsz-ripple-effect 0.6s linear;
                                pointer-events: none;
                                width: 100px;
                                height: 100px;
                                margin-left: -50px;
                                margin-top: -50px;
                            }
                            @keyframes dsz-ripple-effect {
                                to {
                                    transform: scale(4);
                                    opacity: 0;
                                }
                            }
                        </style>
                    `);
                }

                setTimeout(function () {
                    ripple.remove();
                }, 600);
            });
        },

        /**
         * Animate number counters
         */
        animateNumbers: function () {
            $('.dsz-card-value, .dsz-stat strong').each(function () {
                var $this = $(this);
                var text = $this.text().trim();
                var number = parseInt(text.replace(/[^0-9]/g, ''));

                if (!isNaN(number) && number > 0 && number < 10000) {
                    $this.prop('counter', 0).animate({
                        counter: number
                    }, {
                        duration: 1500,
                        easing: 'swing',
                        step: function (now) {
                            $this.text(Math.ceil(now));
                        },
                        complete: function () {
                            $this.text(text); // Restore original text with any prefix/suffix
                        }
                    });
                }
            });
        },

        /**
         * Initialize interactive feedback
         */
        initInteractiveFeedback: function () {
            // Add scale effect on button focus
            $('.button').on('focus', function () {
                $(this).css('transform', 'scale(1.02)');
            }).on('blur', function () {
                $(this).css('transform', '');
            });

            // Card icon bounce on hover
            $('.dsz-card').on('mouseenter', function () {
                $(this).find('.dashicons').css('animation', 'bounce 0.5s ease');
            }).on('mouseleave', function () {
                $(this).find('.dashicons').css('animation', '');
            });
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

            // Product Import search
            $('#dsz-import-search-btn').on('click', this.searchApiProducts.bind(this));
            $('#dsz-import-search').on('keypress', function (e) {
                if (e.which === 13) {
                    DSZAdmin.searchApiProducts();
                }
            });

            // Product Import action
            $(document).on('click', '.dsz-import-btn', this.importProduct.bind(this));

            // Product Resync action
            $(document).on('click', '.dsz-resync-btn', this.resyncProduct.bind(this));

            // Resync All Products action
            $('#dsz-resync-all').on('click', this.resyncAllProducts.bind(this));
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
                                ' <strong>(' + response.data.products + ' products available)</strong>');

                        // Add celebration effect
                        DSZAdmin.celebrateSuccess($btn);
                    } else {
                        $message
                            .removeClass('hidden dsz-message-success')
                            .addClass('dsz-message-error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + response.data.message);

                        DSZAdmin.shakeElement($btn);
                    }
                },
                error: function () {
                    $message
                        .removeClass('hidden dsz-message-success')
                        .addClass('dsz-message-error')
                        .html('<span class="dashicons dashicons-warning"></span> ' + dsz_admin.strings.error);

                    DSZAdmin.shakeElement($btn);
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Celebrate success with animation
         */
        celebrateSuccess: function ($element) {
            // Add success pulse
            $element.css({
                'animation': 'pulse 0.5s ease',
                'background': 'linear-gradient(135deg, #10b981 0%, #059669 100%)'
            });

            setTimeout(function () {
                $element.css({
                    'animation': '',
                    'background': ''
                });
            }, 1000);

            // Create confetti effect
            this.createConfetti();
        },

        /**
         * Create confetti particles
         */
        createConfetti: function () {
            var colors = ['#667eea', '#764ba2', '#10b981', '#f59e0b', '#ef4444'];
            var container = $('<div class="dsz-confetti-container"></div>');

            $('body').append(container);

            for (var i = 0; i < 30; i++) {
                var particle = $('<div class="dsz-confetti"></div>');
                particle.css({
                    'position': 'fixed',
                    'width': '10px',
                    'height': '10px',
                    'background': colors[Math.floor(Math.random() * colors.length)],
                    'top': '50%',
                    'left': Math.random() * 100 + '%',
                    'opacity': 1,
                    'border-radius': Math.random() > 0.5 ? '50%' : '0',
                    'z-index': 9999,
                    'pointer-events': 'none'
                });

                container.append(particle);

                particle.animate({
                    top: (Math.random() * 100) + '%',
                    left: (Math.random() * 20 - 10 + parseFloat(particle.css('left'))) + '%',
                    opacity: 0,
                    transform: 'rotate(' + (Math.random() * 360) + 'deg)'
                }, 1500 + Math.random() * 1000, function () {
                    $(this).remove();
                });
            }

            setTimeout(function () {
                container.remove();
            }, 3000);
        },

        /**
         * Shake element on error
         */
        shakeElement: function ($element) {
            $element.css('animation', 'none');
            setTimeout(function () {
                $element.css('animation', 'shake 0.5s ease');
            }, 10);

            // Add shake animation if not exists
            if (!$('#dsz-shake-style').length) {
                $('head').append(`
                    <style id="dsz-shake-style">
                        @keyframes shake {
                            0%, 100% { transform: translateX(0); }
                            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
                            20%, 40%, 60%, 80% { transform: translateX(5px); }
                        }
                    </style>
                `);
            }
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

                        DSZAdmin.celebrateSuccess($btn);
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
                    // Also save import settings
                    $.ajax({
                        url: dsz_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'dsz_save_settings',
                            nonce: dsz_admin.nonce,
                            type: 'import_settings',
                            settings: {
                                default_status: $('#dsz_import_status').val()
                            }
                        }
                    });

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

                        // Subtle success animation
                        $message.css('animation', 'fadeInUp 0.3s ease');
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

                    // Auto-hide message after 5 seconds with fade
                    setTimeout(function () {
                        $message.css('animation', 'fadeOut 0.3s ease forwards');
                        setTimeout(function () {
                            $message.addClass('hidden').css('animation', '');
                        }, 300);
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
            $progress.removeClass('hidden').css('animation', 'fadeInUp 0.3s ease');
            $message.addClass('hidden');
            $('#dsz-sync-hint').addClass('hidden');

            // Add glow effect to progress bar
            $('#dsz-progress-fill').css('box-shadow', '0 0 20px rgba(102, 126, 234, 0.5)');

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
            var $fill = $('#dsz-progress-fill');
            var $text = $('#dsz-progress-text');

            // Smooth progress animation
            $fill.css({
                'width': progress + '%',
                'transition': 'width 0.6s cubic-bezier(0.4, 0, 0.2, 1)'
            });

            $('#dsz-progress-percent').text(progress + '%');
            $text.text(data.message || 'Processing catalog... ' + progress + '%');

            // Update status text with pulse
            $('#sync-status-text')
                .text(dsz_admin.strings.syncing)
                .closest('.dsz-sync-card')
                .removeClass('dsz-card-idle')
                .addClass('dsz-card-syncing');
        },

        /**
         * Sync complete handler
         */
        syncComplete: function (data) {
            var $btn = $('#dsz-run-sync');
            var $message = $('#dsz-sync-message');
            var $progress = $('#dsz-progress-container');

            $btn.removeClass('dsz-loading').prop('disabled', false);
            $('#dsz-sync-hint').removeClass('hidden');

            $('#sync-status-text')
                .text('System Idle')
                .closest('.dsz-sync-card')
                .removeClass('dsz-card-syncing')
                .addClass('dsz-card-idle');

            // Get values with defaults
            var productsUpdated = data.products_updated !== undefined ? data.products_updated : 0;
            var errorsCount = data.errors_count !== undefined ? data.errors_count : 0;

            if (data.status === 'complete') {
                $message
                    .removeClass('hidden dsz-message-error')
                    .addClass('dsz-message-success')
                    .html('<span class="dashicons dashicons-yes-alt"></span> <strong>Sync completed!</strong> ' +
                        productsUpdated + ' products updated, ' +
                        errorsCount + ' errors.');

                $('#dsz-progress-fill').css('width', '100%');
                $('#dsz-progress-text').text('✓ Complete!');

                // Celebrate!
                this.createConfetti();
            } else {
                $message
                    .removeClass('hidden dsz-message-success')
                    .addClass('dsz-message-error')
                    .html('<span class="dashicons dashicons-warning"></span> ' + (data.message || 'Sync failed'));
            }

            // Hide progress after 3 seconds
            setTimeout(function () {
                $progress.css('animation', 'fadeOut 0.3s ease forwards');
                setTimeout(function () {
                    $progress.addClass('hidden').css('animation', '');
                }, 300);
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

            // Animate price change
            var $priceEl = $('#calculated_price');
            $priceEl.css('transform', 'scale(1.1)');
            setTimeout(function () {
                $priceEl.text('$' + price.toFixed(2)).css('transform', 'scale(1)');
            }, 150);
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

                        // Show success message instead of alert
                        DSZAdmin.showNotification('success', response.data.message || 'Export successful!');
                    }
                },
                error: function () {
                    DSZAdmin.showNotification('error', dsz_admin.strings.error);
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Show notification toast
         */
        showNotification: function (type, message) {
            // Remove existing notifications
            $('.dsz-toast').remove();

            var icon = type === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning';
            var toast = $(`
                <div class="dsz-toast dsz-toast-${type}">
                    <span class="dashicons ${icon}"></span>
                    <span>${message}</span>
                </div>
            `);

            // Add toast styles if not exist
            if (!$('#dsz-toast-style').length) {
                $('head').append(`
                    <style id="dsz-toast-style">
                        .dsz-toast {
                            position: fixed;
                            bottom: 30px;
                            right: 30px;
                            padding: 16px 24px;
                            border-radius: 12px;
                            display: flex;
                            align-items: center;
                            gap: 12px;
                            z-index: 10000;
                            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                            animation: slideInUp 0.3s ease, fadeOut 0.3s ease 3s forwards;
                            font-weight: 500;
                        }
                        .dsz-toast-success {
                            background: linear-gradient(135deg, #10b981, #059669);
                            color: white;
                        }
                        .dsz-toast-error {
                            background: linear-gradient(135deg, #ef4444, #dc2626);
                            color: white;
                        }
                        @keyframes slideInUp {
                            from { transform: translateY(100px); opacity: 0; }
                            to { transform: translateY(0); opacity: 1; }
                        }
                        @keyframes fadeOut {
                            to { opacity: 0; transform: translateY(-20px); }
                        }
                    </style>
                `);
            }

            $('body').append(toast);

            setTimeout(function () {
                toast.remove();
            }, 3500);
        },

        /**
         * Auto-map products
         */
        autoMap: function (e) {
            e.preventDefault();

            var $btn = $('#dsz-auto-map');
            var $message = $('#dsz-automap-message');

            if (!confirm('This will auto-map all WooCommerce products to Dropshipzone SKUs using their current SKU. Continue?')) {
                return;
            }

            $btn.addClass('dsz-loading').prop('disabled', true);
            $message.addClass('hidden');

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_auto_map',
                    nonce: dsz_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $message
                            .removeClass('hidden dsz-message-error')
                            .addClass('dsz-message-success')
                            .html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message);

                        // Celebrate and reload
                        DSZAdmin.createConfetti();
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
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
         * Create mapping
         */
        createMapping: function (e) {
            e.preventDefault();

            var $btn = $('#dsz-create-mapping');
            var $message = $('#dsz-mapping-message');
            var wcProductId = $('#dsz-wc-product-id').val();
            var dszSku = $('#dsz-dsz-sku').val();

            if (!wcProductId || !dszSku) {
                $message
                    .removeClass('hidden dsz-message-success')
                    .addClass('dsz-message-error')
                    .html('<span class="dashicons dashicons-warning"></span> Please select a WooCommerce product and enter a Dropshipzone SKU.');
                return;
            }

            $btn.addClass('dsz-loading').prop('disabled', true);

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_map_product',
                    nonce: dsz_admin.nonce,
                    wc_product_id: wcProductId,
                    dsz_sku: dszSku
                },
                success: function (response) {
                    if (response.success) {
                        $message
                            .removeClass('hidden dsz-message-error')
                            .addClass('dsz-message-success')
                            .html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message);

                        // Clear fields and reload
                        $('#dsz-wc-search').val('');
                        $('#dsz-wc-product-id').val('');
                        $('#dsz-dsz-sku').val('');

                        setTimeout(function () {
                            location.reload();
                        }, 1500);
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
         * Unmap product
         */
        unmapProduct: function (e) {
            e.preventDefault();

            var $btn = $(e.target).closest('.dsz-unmap-btn');
            var wcProductId = $btn.data('wc-id');

            if (!confirm('Remove this mapping?')) {
                return;
            }

            $btn.addClass('dsz-loading').prop('disabled', true);

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_unmap_product',
                    nonce: dsz_admin.nonce,
                    wc_product_id: wcProductId
                },
                success: function (response) {
                    if (response.success) {
                        // Animate row removal
                        $btn.closest('tr').css({
                            'background': 'rgba(239, 68, 68, 0.1)',
                            'transform': 'translateX(50px)',
                            'opacity': 0,
                            'transition': 'all 0.3s ease'
                        });

                        setTimeout(function () {
                            $btn.closest('tr').remove();
                        }, 300);
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
         * Search WooCommerce products
         */
        searchWCProducts: function () {
            var self = this;
            var searchTimeout;

            $('#dsz-wc-search').on('input', function () {
                var $input = $(this);
                var search = $input.val();
                var $results = $('#dsz-wc-results');

                clearTimeout(searchTimeout);

                if (search.length < 2) {
                    $results.addClass('hidden').empty();
                    return;
                }

                searchTimeout = setTimeout(function () {
                    $.ajax({
                        url: dsz_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'dsz_search_wc_products',
                            nonce: dsz_admin.nonce,
                            search: search
                        },
                        success: function (response) {
                            if (response.success && response.data.products.length > 0) {
                                var html = '';
                                response.data.products.forEach(function (product) {
                                    var mapped = product.is_mapped ? ' <span style="color: #f59e0b;">(mapped)</span>' : '';
                                    var sku = product.sku ? ' <code style="font-size: 11px; background: #f3f4f6; padding: 2px 6px; border-radius: 4px;">' + escapeHtml(product.sku) + '</code>' : '';
                                    html += '<div class="dsz-search-item" data-id="' + product.ID + '" data-name="' + escapeHtml(product.post_title) + '">';
                                    html += '<strong>' + escapeHtml(product.post_title) + '</strong>' + sku + mapped;
                                    html += '</div>';
                                });
                                $results.html(html).removeClass('hidden');
                            } else {
                                $results.addClass('hidden').empty();
                            }
                        }
                    });
                }, 300);
            });

            // Handle selection
            $(document).on('click', '#dsz-wc-results .dsz-search-item', function () {
                var $item = $(this);
                $('#dsz-wc-search').val($item.data('name'));
                $('#dsz-wc-product-id').val($item.data('id'));
                $('#dsz-wc-results').addClass('hidden');
                self.updateMappingButton();

                // Highlight selected
                $('#dsz-wc-search').css('border-color', '#10b981');
                setTimeout(function () {
                    $('#dsz-wc-search').css('border-color', '');
                }, 1000);
            });

            // Handle SKU input
            $('#dsz-dsz-sku').on('input', function () {
                self.updateMappingButton();
            });
        },

        /**
         * Update create mapping button state
         */
        updateMappingButton: function () {
            var wcProductId = $('#dsz-wc-product-id').val();
            var dszSku = $('#dsz-dsz-sku').val();
            var $btn = $('#dsz-create-mapping');

            $btn.prop('disabled', !wcProductId || !dszSku);

            // Add visual feedback
            if (wcProductId && dszSku) {
                $btn.css({
                    'opacity': 1,
                    'transform': 'scale(1.02)'
                });
            } else {
                $btn.css({
                    'opacity': 0.6,
                    'transform': 'scale(1)'
                });
            }
        },

        /**
         * Initialize mapping page
         */
        initMappingPage: function () {
            if ($('#dsz-auto-map').length === 0) {
                return;
            }

            // Bind events
            $('#dsz-auto-map').on('click', this.autoMap.bind(this));
            $('#dsz-create-mapping').on('click', this.createMapping.bind(this));
            $(document).on('click', '.dsz-unmap-btn', this.unmapProduct.bind(this));
            this.searchWCProducts();
        },
        /**
         * Initialize Product Import (Advanced)
         */
        initProductImport: function () {
            // Bind filter toggle
            $('#dsz-toggle-filters').on('click', function () {
                var $panel = $('#dsz-filters-panel');
                var $arrow = $(this).find('.dashicons-arrow-down-alt2, .dashicons-arrow-up-alt2');

                $panel.toggleClass('hidden');
                $arrow.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
            });

            // Bind apply filters button
            $('#dsz-apply-filters').on('click', function () {
                DSZAdmin.searchApiProducts();
            });

            // Bind clear filters button
            $('#dsz-clear-filters').on('click', function () {
                $('#dsz-filter-category').val('');
                $('#dsz-filter-instock').prop('checked', false);
                $('#dsz-filter-freeship').prop('checked', false);
                $('#dsz-filter-promotion').prop('checked', false);
                $('#dsz-filter-newarrivals').prop('checked', false);
                $('#dsz-filter-sort').val('');
                $('#dsz-import-search').val('');
                $('#dsz-search-info').addClass('hidden');
            });

            // Load categories button
            $('#dsz-load-categories').on('click', function () {
                DSZAdmin.loadCategories();
            });

            // Enter key triggers search
            $('#dsz-import-search').on('keypress', function (e) {
                if (e.which === 13) {
                    DSZAdmin.searchApiProducts();
                }
            });

            // Quick filter card clicks
            $('.dsz-quick-filter-card').on('click', function () {
                var $card = $(this);
                var filter = $card.data('filter');

                // Toggle active state
                $card.toggleClass('active');

                // Map filter to checkbox and toggle
                var checkboxMap = {
                    'in_stock': '#dsz-filter-instock',
                    'on_promotion': '#dsz-filter-promotion',
                    'free_shipping': '#dsz-filter-freeship',
                    'new_arrival': '#dsz-filter-newarrivals'
                };

                var $checkbox = $(checkboxMap[filter]);
                $checkbox.prop('checked', $card.hasClass('active'));

                // Trigger search with the new filter
                DSZAdmin.searchApiProducts();
            });

            // Toggle arrow on advanced filters
            $('#dsz-toggle-filters').on('click', function () {
                $(this).toggleClass('open');
            });
        },

        /**
         * Load categories from API
         */
        loadCategories: function () {
            var $btn = $('#dsz-load-categories');
            var $select = $('#dsz-filter-category');

            $btn.addClass('dsz-loading').prop('disabled', true);

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_get_categories',
                    nonce: dsz_admin.nonce
                },
                success: function (response) {
                    if (response.success && response.data.categories) {
                        // Clear existing options except first
                        $select.find('option:not(:first)').remove();

                        // Add categories
                        response.data.categories.forEach(function (cat) {
                            var prefix = cat.parent_id > 0 ? '— ' : '';
                            $select.append(`<option value="${cat.category_id}">${prefix}${escapeHtml(cat.title)}</option>`);
                        });

                        DSZAdmin.showNotification('success', 'Categories loaded successfully!');
                    } else {
                        DSZAdmin.showNotification('error', response.data.message || 'Failed to load categories');
                    }
                },
                error: function () {
                    DSZAdmin.showNotification('error', 'Failed to load categories from API');
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Search products from API (Advanced with filters)
         */
        searchApiProducts: function () {
            var $btn = $('#dsz-import-search-btn');
            var $results = $('#dsz-import-results');
            var $searchInfo = $('#dsz-search-info');

            // Collect all filter values
            var search = $('#dsz-import-search').val().trim();
            var categoryId = $('#dsz-filter-category').val();
            var inStock = $('#dsz-filter-instock').is(':checked');
            var freeShipping = $('#dsz-filter-freeship').is(':checked');
            var onPromotion = $('#dsz-filter-promotion').is(':checked');
            var newArrival = $('#dsz-filter-newarrivals').is(':checked');
            var sort = $('#dsz-filter-sort').val();

            // Check if we have valid input
            var hasFilters = categoryId || inStock || freeShipping || onPromotion || newArrival;
            if (search.length < 2 && !hasFilters) {
                DSZAdmin.showNotification('error', 'Enter at least 2 characters or select a filter.');
                return;
            }

            $btn.addClass('dsz-loading').prop('disabled', true);
            $results.html(`
                <div class="dsz-import-loading">
                    <span class="dsz-spinner"></span>
                    <p>Searching Dropshipzone products...</p>
                </div>
            `);

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_search_api_products',
                    nonce: dsz_admin.nonce,
                    search: search,
                    category_id: categoryId,
                    in_stock: inStock.toString(),
                    free_shipping: freeShipping.toString(),
                    on_promotion: onPromotion.toString(),
                    new_arrival: newArrival.toString(),
                    sort: sort
                },
                success: function (response) {
                    if (response.success && response.data.products && response.data.products.length > 0) {
                        DSZAdmin.renderImportResults(response.data.products);

                        // Show result count and active filters
                        var filterInfo = [];
                        if (search) filterInfo.push('"' + search + '"');
                        if (inStock) filterInfo.push('In Stock');
                        if (freeShipping) filterInfo.push('Free Shipping');
                        if (onPromotion) filterInfo.push('On Promotion');
                        if (newArrival) filterInfo.push('New Arrivals');

                        var countText = response.data.total + ' product' + (response.data.total !== 1 ? 's' : '') + ' found';
                        var filterText = filterInfo.length > 0 ? ' — ' + filterInfo.join(', ') : '';

                        $searchInfo.removeClass('hidden');
                        $('#dsz-result-count').html('<span class="dashicons dashicons-products"></span> ' + countText);
                        $('#dsz-active-filters').html(filterText);
                    } else {
                        $searchInfo.addClass('hidden');
                        $results.html(`
                            <div class="dsz-import-empty">
                                <span class="dashicons dashicons-search"></span>
                                <p>${response.data.message || 'No products found matching your search.'}</p>
                            </div>
                        `);
                    }
                },
                error: function () {
                    $searchInfo.addClass('hidden');
                    $results.html('<div class="dsz-message dsz-message-error">' + dsz_admin.strings.error + '</div>');
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Store products data for import
         */
        importProductsCache: {},

        /**
         * Render API search results
         */
        renderImportResults: function (products) {
            var html = '<div class="dsz-import-grid">';

            // Clear and rebuild products cache
            this.importProductsCache = {};

            products.forEach(function (product) {
                var btnText = product.is_imported ? 'Imported' : 'Import Product';
                var btnClass = product.is_imported ? 'button-secondary' : 'button-primary dsz-import-btn';
                var btnDisabled = product.is_imported ? 'disabled' : '';

                var imageUrl = product.image_url || product.image || '';
                // Check for gallery array (API returns images in 'gallery' field)
                if (!imageUrl && product.gallery && Array.isArray(product.gallery) && product.gallery.length > 0) {
                    imageUrl = product.gallery[0];
                }
                // Fallback to images array if gallery not found
                if (!imageUrl && product.images && Array.isArray(product.images) && product.images.length > 0) {
                    imageUrl = product.images[0];
                }

                // Store product data in cache (avoid JSON corruption in HTML attributes)
                DSZAdmin.importProductsCache[product.sku] = product;

                // Build badges HTML
                var badgesHtml = '<div class="dsz-import-item-badges">';
                if (product.on_promotion || product.is_on_promotion) {
                    badgesHtml += '<span class="dsz-badge dsz-badge-promo"><span class="dashicons dashicons-tag"></span> Sale</span>';
                }
                if (product.au_free_shipping || product.free_shipping) {
                    badgesHtml += '<span class="dsz-badge dsz-badge-shipping"><span class="dashicons dashicons-car"></span> Free Ship</span>';
                }
                if (product.new_arrival || product.is_new_arrival) {
                    badgesHtml += '<span class="dsz-badge dsz-badge-new">NEW</span>';
                }
                badgesHtml += '</div>';

                // Build buttons HTML - show Resync button for imported products
                var buttonsHtml = '';
                if (product.is_imported) {
                    buttonsHtml = `
                        <div class="dsz-import-item-buttons">
                            <button type="button" class="button button-secondary" disabled>
                                <span class="dashicons dashicons-yes-alt"></span> Imported
                            </button>
                            <button type="button" class="button button-primary dsz-resync-btn" data-sku="${escapeHtml(product.sku)}" data-product-id="${product.wc_product_id || ''}">
                                <span class="dashicons dashicons-update"></span> Resync
                            </button>
                        </div>
                    `;
                } else {
                    buttonsHtml = `
                        <button type="button" class="button button-primary dsz-import-btn" data-sku="${escapeHtml(product.sku)}">
                            <span class="dashicons dashicons-download"></span> Import Product
                        </button>
                    `;
                }

                // Build category display (API returns 'Category' field with path like "Electronics > Audio > Headphones")
                var categoryHtml = '';
                if (product.Category) {
                    categoryHtml = `<p class="dsz-import-item-category"><span class="dashicons dashicons-category"></span> ${escapeHtml(product.Category)}</p>`;
                } else if (product.l1_category_name) {
                    var catPath = product.l1_category_name;
                    if (product.l2_category_name) catPath += ' > ' + product.l2_category_name;
                    if (product.l3_category_name) catPath += ' > ' + product.l3_category_name;
                    categoryHtml = `<p class="dsz-import-item-category"><span class="dashicons dashicons-category"></span> ${escapeHtml(catPath)}</p>`;
                }

                // Build stock status display
                var stockQty = parseInt(product.stock_qty) || parseInt(product.stock) || 0;
                var stockClass = stockQty > 10 ? 'dsz-stock-high' : (stockQty > 0 ? 'dsz-stock-low' : 'dsz-stock-out');
                var stockLabel = stockQty > 10 ? 'In Stock' : (stockQty > 0 ? 'Low Stock' : 'Out of Stock');
                var stockHtml = `<span class="dsz-import-item-stock ${stockClass}"><span class="dashicons dashicons-${stockQty > 0 ? 'yes' : 'no'}"></span> ${stockLabel} (${stockQty})</span>`;

                // Build description preview (API returns 'desc' field)
                var descHtml = '';
                var desc = product.desc || product.description || '';
                if (desc) {
                    // Strip HTML tags and truncate
                    var cleanDesc = desc.replace(/<[^>]*>/g, '').trim();
                    if (cleanDesc.length > 100) {
                        cleanDesc = cleanDesc.substring(0, 100) + '...';
                    }
                    descHtml = `<p class="dsz-import-item-desc">${escapeHtml(cleanDesc)}</p>`;
                }

                // Build price display with RRP if available
                var supplierPrice = parseFloat(product.price || 0).toFixed(2);
                var rrpPrice = product.rrp ? parseFloat(product.rrp).toFixed(2) : null;
                var priceHtml = `<div class="dsz-import-item-prices">`;
                priceHtml += `<span class="dsz-price-supplier">$${supplierPrice} <small>Cost</small></span>`;
                if (rrpPrice && parseFloat(rrpPrice) > parseFloat(supplierPrice)) {
                    priceHtml += `<span class="dsz-price-rrp">$${rrpPrice} <small>RRP</small></span>`;
                }
                priceHtml += `</div>`;

                // Build specs (weight, dimensions)
                var specsHtml = '';
                var specs = [];
                if (product.weight && parseFloat(product.weight) > 0) {
                    specs.push(parseFloat(product.weight).toFixed(2) + 'kg');
                }
                if (product.brand) {
                    specs.push(escapeHtml(product.brand));
                }
                if (specs.length > 0) {
                    specsHtml = `<p class="dsz-import-item-specs">${specs.join(' • ')}</p>`;
                }

                html += `
                    <div class="dsz-import-item ${product.is_imported ? 'dsz-imported' : ''}" data-sku="${escapeHtml(product.sku)}">
                        <div class="dsz-import-item-image">
                            ${imageUrl ? `<img src="${imageUrl}" alt="${escapeHtml(product.title || product.sku)}" loading="lazy">` : '<span class="dashicons dashicons-format-image"></span>'}
                            ${badgesHtml}
                        </div>
                        <div class="dsz-import-item-info">
                            <h4>${escapeHtml(product.title || product.sku)}</h4>
                            <p class="dsz-import-item-sku">SKU: <code>${escapeHtml(product.sku)}</code> ${stockHtml}</p>
                            ${categoryHtml}
                            ${specsHtml}
                            ${descHtml}
                            ${priceHtml}
                            ${buttonsHtml}
                        </div>
                    </div>
                `;
            });

            html += '</div>';
            $('#dsz-import-results').html(html);
        },

        /**
         * Import product via AJAX
         */
        importProduct: function (e) {
            var $btn = $(e.currentTarget);
            var sku = $btn.data('sku');
            var $item = $btn.closest('.dsz-import-item');

            // Get product data from cache (stored during search results rendering)
            var productData = this.importProductsCache[sku] || null;

            $btn.addClass('dsz-loading').prop('disabled', true);

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_import_product',
                    nonce: dsz_admin.nonce,
                    sku: sku,
                    product_data: productData ? JSON.stringify(productData) : ''
                },
                success: function (response) {
                    if (response.success) {
                        $btn.removeClass('button-primary dsz-import-btn dsz-loading')
                            .addClass('button-secondary')
                            .html('<span class="dashicons dashicons-yes-alt"></span> Imported')
                            .prop('disabled', true);

                        DSZAdmin.celebrateSuccess($item);
                        DSZAdmin.showNotification('success', response.data.message);
                    } else {
                        DSZAdmin.showNotification('error', response.data.message);
                        $btn.removeClass('dsz-loading').prop('disabled', false);
                    }
                },
                error: function () {
                    DSZAdmin.showNotification('error', dsz_admin.strings.error);
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                }
            });
        },

        /**
         * Resync all mapped products with data from Dropshipzone API
         */
        resyncAllProducts: function (e) {
            e.preventDefault();

            var $btn = $('#dsz-resync-all');
            var $message = $('#dsz-resync-all-message');
            var $progress = $('#dsz-resync-all-progress');

            if (!confirm('This will resync all mapped products with the latest data from Dropshipzone (price, stock, images, etc.). This may take a while depending on the number of mapped products. Continue?')) {
                return;
            }

            // Show loading state and progress bar
            $btn.addClass('dsz-loading').prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update dsz-spin"></span> Resyncing All...');
            $message.addClass('hidden').removeClass('dsz-message-success dsz-message-error');
            $progress.removeClass('hidden');

            // Add spin animation for the update icon if not exists
            if (!$('#dsz-spin-style').length) {
                $('head').append(`
                    <style id="dsz-spin-style">
                        .dsz-spin {
                            animation: dsz-spin 1s linear infinite;
                        }
                        @keyframes dsz-spin {
                            from { transform: rotate(0deg); }
                            to { transform: rotate(360deg); }
                        }
                    </style>
                `);
            }

            // Reset progress bar
            $('#dsz-resync-all-progress-fill').css('width', '0%');
            $('#dsz-resync-all-progress-text').text('Starting resync...');

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_resync_all',
                    nonce: dsz_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        // Update progress to 100%
                        $('#dsz-resync-all-progress-fill').css('width', '100%');
                        $('#dsz-resync-all-progress-text').text('Complete!');

                        $message
                            .removeClass('hidden dsz-message-error')
                            .addClass('dsz-message-success')
                            .html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message);

                        DSZAdmin.createConfetti();
                        DSZAdmin.showNotification('success', response.data.message);
                    } else {
                        $message
                            .removeClass('hidden dsz-message-success')
                            .addClass('dsz-message-error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + response.data.message);

                        DSZAdmin.showNotification('error', response.data.message);
                    }
                },
                error: function () {
                    $message
                        .removeClass('hidden dsz-message-success')
                        .addClass('dsz-message-error')
                        .html('<span class="dashicons dashicons-warning"></span> ' + dsz_admin.strings.error);

                    DSZAdmin.showNotification('error', dsz_admin.strings.error);
                },
                complete: function () {
                    $btn.removeClass('dsz-loading').prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-update"></span> Resync All Products');

                    // Hide progress bar after 3 seconds
                    setTimeout(function () {
                        $progress.addClass('hidden');
                    }, 3000);
                }
            });
        },

        /**
         * Resync product with data from Dropshipzone API
         */
        resyncProduct: function (e) {
            var $btn = $(e.currentTarget);
            var sku = $btn.data('sku');
            var productId = $btn.data('product-id');
            var $item = $btn.closest('.dsz-import-item');

            // Get product data from cache (stored during search results rendering)
            var productData = this.importProductsCache[sku] || null;

            $btn.addClass('dsz-loading').prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update dsz-spin"></span> Syncing...');

            // Add spin animation for the update icon
            if (!$('#dsz-spin-style').length) {
                $('head').append(`
                    <style id="dsz-spin-style">
                        .dsz-spin {
                            animation: dsz-spin 1s linear infinite;
                        }
                        @keyframes dsz-spin {
                            from { transform: rotate(0deg); }
                            to { transform: rotate(360deg); }
                        }
                    </style>
                `);
            }

            $.ajax({
                url: dsz_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsz_resync_product',
                    nonce: dsz_admin.nonce,
                    product_id: productId,
                    sku: sku,
                    product_data: productData ? JSON.stringify(productData) : '',
                    options: {
                        update_images: true,
                        update_description: true,
                        update_price: true,
                        update_stock: true,
                        update_title: true
                    }
                },
                success: function (response) {
                    if (response.success) {
                        $btn.removeClass('dsz-loading')
                            .html('<span class="dashicons dashicons-yes-alt"></span> Synced!')
                            .prop('disabled', false);

                        // Reset button after 2 seconds
                        setTimeout(function () {
                            $btn.html('<span class="dashicons dashicons-update"></span> Resync');
                        }, 2000);

                        DSZAdmin.celebrateSuccess($item);
                        DSZAdmin.showNotification('success', response.data.message);

                        // Add link to edit product if available
                        if (response.data.edit_url) {
                            DSZAdmin.showNotification('success', response.data.message + ' <a href="' + response.data.edit_url + '" target="_blank">Edit product</a>');
                        }
                    } else {
                        DSZAdmin.showNotification('error', response.data.message);
                        $btn.removeClass('dsz-loading')
                            .html('<span class="dashicons dashicons-update"></span> Resync')
                            .prop('disabled', false);
                    }
                },
                error: function () {
                    DSZAdmin.showNotification('error', dsz_admin.strings.error);
                    $btn.removeClass('dsz-loading')
                        .html('<span class="dashicons dashicons-update"></span> Resync')
                        .prop('disabled', false);
                }
            });
        }
    };

    // Helper function to escape HTML
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // Initialize on DOM ready
    $(document).ready(function () {
        DSZAdmin.init();
        DSZAdmin.initMappingPage();

        // Add fadeOut keyframe if not exists
        if (!$('#dsz-fadeout-style').length) {
            $('head').append(`
                <style id="dsz-fadeout-style">
                    @keyframes fadeOut {
                        from { opacity: 1; }
                        to { opacity: 0; }
                    }
                </style>
            `);
        }
    });

})(jQuery);
