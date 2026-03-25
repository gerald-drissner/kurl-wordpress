jQuery(function ($) {
    'use strict';

    function getInlineStatusBox(button) {
        return button.closest('.kurl-box').find('.kurl-inline-status');
    }

    function setInlineStatus(button, message, type) {
        const box = getInlineStatusBox(button);
        box.removeClass('error success');
        if (type) {
            box.addClass(type);
        }
        box.text(message || '');
    }

    function getAjaxErrorMessage(xhr) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        return kurlAdmin.strings.error;
    }

    function setButtonBusy(button, busy) {
        button.prop('disabled', !!busy);
        if (busy) {
            button.data('original-text', button.text());
            button.text(kurlAdmin.strings.working);
        } else if (button.data('original-text')) {
            button.text(button.data('original-text'));
        }
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function copyTextToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                const ok = document.execCommand('copy');
                document.body.removeChild(textarea);
                if (ok) {
                    resolve();
                } else {
                    reject(new Error('Copy command failed'));
                }
            } catch (err) {
                document.body.removeChild(textarea);
                reject(err);
            }
        });
    }


    function getManualStatusBox() {
        return $('.kurl-manual-status');
    }

    function setManualStatus(message, type) {
        const box = getManualStatusBox();
        box.removeClass('error success');
        if (type) {
            box.addClass(type);
        }
        box.text(message || '');
    }

    function updateMetaStats(box, clicks) {
        let statsBox = box.find('.kurl-meta-stats');
        if (!statsBox.length) {
            statsBox = $('<div class="kurl-meta-stats" style="margin-top: 12px;"></div>');
            box.append(statsBox);
        }
        statsBox.html('<strong>' + escapeHtml(kurlAdmin.strings.clicks_label) + '</strong> ' + escapeHtml(clicks)).show();
    }

    function getStatusLabel(status) {
        switch (status) {
            case 'created':
                return kurlAdmin.strings.status_created;
            case 'updated':
                return kurlAdmin.strings.status_updated;
            case 'imported':
                return kurlAdmin.strings.status_imported;
            case 'skipped_existing':
                return kurlAdmin.strings.status_skipped_exist;
            case 'would_import':
                return kurlAdmin.strings.status_would_import;
            case 'would_replace':
                return kurlAdmin.strings.status_would_replace;
            case 'verified':
                return kurlAdmin.strings.status_verified;
            case 'mismatch':
                return kurlAdmin.strings.status_mismatch;
            case 'skipped':
                return kurlAdmin.strings.status_skipped;
            case 'error':
                return kurlAdmin.strings.status_error;
            default:
                return status;
        }
    }

    $(document).on('click', '.kurl-generate', function () {
        const button = $(this);
        const box = button.closest('.kurl-box');
        if (button.prop('disabled')) {
            return;
        }
        setInlineStatus(button, kurlAdmin.strings.working, '');
        setButtonBusy(button, true);
        $.post(kurlAdmin.ajaxUrl, {
            action: 'kurl_generate_post_link',
            nonce: kurlAdmin.nonce,
            post_id: button.data('post'),
            keyword: box.find('.kurl-keyword').val()
        }).done(function (response) {
            if (response && response.success) {
                box.find('.kurl-shorturl').val(response.data.shorturl || '');
                box.find('.kurl-keyword').prop('readonly', true).attr('readonly', 'readonly');
                box.find('.kurl-delete').css('display', 'inline-block');
                setInlineStatus(button, response.data.message || kurlAdmin.strings.done, 'success');
            } else {
                setInlineStatus(button, response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error, 'error');
            }
        }).fail(function (xhr) {
            setInlineStatus(button, getAjaxErrorMessage(xhr), 'error');
        }).always(function () {
            setButtonBusy(button, false);
        });
    });

    $(document).on('click', '.kurl-sync', function () {
        const button = $(this);
        const box = button.closest('.kurl-box');
        if (button.prop('disabled')) {
            return;
        }
        setInlineStatus(button, kurlAdmin.strings.working, '');
        setButtonBusy(button, true);
        $.post(kurlAdmin.ajaxUrl, {
            action: 'kurl_check_sync_post',
            nonce: kurlAdmin.nonce,
            post_id: button.data('post')
        }).done(function (response) {
            if (response && response.success) {
                if (response.data.shorturl) {
                    box.find('.kurl-shorturl').val(response.data.shorturl);
                    box.find('.kurl-delete').css('display', 'inline-block');
                }
                setInlineStatus(button, response.data.message || kurlAdmin.strings.done, 'success');
            } else {
                setInlineStatus(button, response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error, 'error');
            }
        }).fail(function (xhr) {
            setInlineStatus(button, getAjaxErrorMessage(xhr), 'error');
        }).always(function () {
            setButtonBusy(button, false);
        });
    });

    $(document).on('click', '.kurl-refresh-stats', function () {
        const button = $(this);
        const box = button.closest('.kurl-box');
        if (button.prop('disabled')) {
            return;
        }
        setInlineStatus(button, kurlAdmin.strings.working, '');
        setButtonBusy(button, true);
        $.post(kurlAdmin.ajaxUrl, {
            action: 'kurl_refresh_post_stats',
            nonce: kurlAdmin.nonce,
            post_id: button.data('post')
        }).done(function (response) {
            if (response && response.success) {
                const clicks = response.data && response.data.stats ? response.data.stats.clicks : 0;
                updateMetaStats(box, clicks);
                setInlineStatus(button, kurlAdmin.strings.clicks_label + ' ' + clicks, 'success');
            } else {
                setInlineStatus(button, response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error, 'error');
            }
        }).fail(function (xhr) {
            setInlineStatus(button, getAjaxErrorMessage(xhr), 'error');
        }).always(function () {
            setButtonBusy(button, false);
        });
    });

    $(document).on('click', '.kurl-delete', function () {
        const button = $(this);
        const box = button.closest('.kurl-box');
        if (button.prop('disabled')) {
            return;
        }
        if (!window.confirm(kurlAdmin.strings.confirm_delete)) {
            return;
        }
        setInlineStatus(button, kurlAdmin.strings.working, '');
        setButtonBusy(button, true);
        $.post(kurlAdmin.ajaxUrl, {
            action: 'kurl_delete_post_link',
            nonce: kurlAdmin.nonce,
            post_id: button.data('post')
        }).done(function (response) {
            if (response && response.success) {
                box.find('.kurl-shorturl').val('');
                box.find('.kurl-keyword').val('').prop('readonly', false).removeAttr('readonly');
                box.find('.kurl-meta-stats').hide();
                button.css('display', 'none');
                setInlineStatus(button, response.data.message || kurlAdmin.strings.done, 'success');
            } else {
                setInlineStatus(button, response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error, 'error');
            }
        }).fail(function (xhr) {
            setInlineStatus(button, getAjaxErrorMessage(xhr), 'error');
        }).always(function () {
            setButtonBusy(button, false);
        });
    });


    $(document).on('click', '.kurl-manual-check', function () {
        const button = $(this);
        if (button.prop('disabled')) {
            return;
        }
        setManualStatus(kurlAdmin.strings.working, '');
        setButtonBusy(button, true);
        $.post(kurlAdmin.ajaxUrl, {
            action: 'kurl_manual_lookup_url',
            nonce: kurlAdmin.nonce,
            url: $('#kurl-manual-url').val()
        }).done(function (response) {
            if (response && response.success) {
                $('#kurl-manual-shorturl').val(response.data.shorturl || '');
                setManualStatus(response.data.message || kurlAdmin.strings.done, 'success');
            } else {
                setManualStatus(response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error, 'error');
            }
        }).fail(function (xhr) {
            setManualStatus(getAjaxErrorMessage(xhr), 'error');
        }).always(function () {
            setButtonBusy(button, false);
        });
    });

    $(document).on('click', '.kurl-manual-generate', function () {
        const button = $(this);
        if (button.prop('disabled')) {
            return;
        }
        setManualStatus(kurlAdmin.strings.working, '');
        setButtonBusy(button, true);
        $.post(kurlAdmin.ajaxUrl, {
            action: 'kurl_manual_generate_url',
            nonce: kurlAdmin.nonce,
            url: $('#kurl-manual-url').val(),
            keyword: $('#kurl-manual-keyword').val()
        }).done(function (response) {
            if (response && response.success) {
                $('#kurl-manual-shorturl').val(response.data.shorturl || '');
                setManualStatus(response.data.message || kurlAdmin.strings.done, 'success');
            } else {
                setManualStatus(response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error, 'error');
            }
        }).fail(function (xhr) {
            setManualStatus(getAjaxErrorMessage(xhr), 'error');
        }).always(function () {
            setButtonBusy(button, false);
        });
    });

    $(document).on('click', '.kurl-manual-delete', function () {
        const button = $(this);
        if (button.prop('disabled')) {
            return;
        }
        if (!window.confirm(kurlAdmin.strings.confirm_delete)) {
            return;
        }
        setManualStatus(kurlAdmin.strings.working, '');
        setButtonBusy(button, true);
        $.post(kurlAdmin.ajaxUrl, {
            action: 'kurl_manual_delete_url',
            nonce: kurlAdmin.nonce,
            shorturl: $('#kurl-manual-shorturl').val()
        }).done(function (response) {
            if (response && response.success) {
                $('#kurl-manual-shorturl').val('');
                setManualStatus(response.data.message || kurlAdmin.strings.done, 'success');
            } else {
                setManualStatus(response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error, 'error');
            }
        }).fail(function (xhr) {
            setManualStatus(getAjaxErrorMessage(xhr), 'error');
        }).always(function () {
            setButtonBusy(button, false);
        });
    });

    $(document).on('click', '.kurl-manual-regenerate', function () {
        const button = $(this);
        if (button.prop('disabled')) {
            return;
        }
        if (!window.confirm(kurlAdmin.strings.confirm_delete)) {
            return;
        }
        setManualStatus(kurlAdmin.strings.working, '');
        setButtonBusy(button, true);
        $.post(kurlAdmin.ajaxUrl, {
            action: 'kurl_manual_regenerate_url',
            nonce: kurlAdmin.nonce,
            url: $('#kurl-manual-url').val(),
            keyword: $('#kurl-manual-keyword').val(),
            shorturl: $('#kurl-manual-shorturl').val()
        }).done(function (response) {
            if (response && response.success) {
                $('#kurl-manual-shorturl').val(response.data.shorturl || '');
                setManualStatus(response.data.message || kurlAdmin.strings.done, 'success');
            } else {
                setManualStatus(response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error, 'error');
            }
        }).fail(function (xhr) {
            setManualStatus(getAjaxErrorMessage(xhr), 'error');
        }).always(function () {
            setButtonBusy(button, false);
        });
    });

    $(document).on('click', '.kurl-copy-code', function () {
        const button = $(this);
        const codeText = $('#kurl-extension-code').val() || '';
        const statusSpan = $('.kurl-copy-status');
        if (!codeText) {
            window.alert(kurlAdmin.strings.copy_missing);
            return;
        }
        button.prop('disabled', true);
        copyTextToClipboard(codeText).then(function () {
            statusSpan.stop(true, true).fadeIn(200).delay(2000).fadeOut(300);
        }).catch(function () {
            window.alert(kurlAdmin.strings.copy_failed);
        }).finally(function () {
            button.prop('disabled', false);
        });
    });

    const testApiButton = $('#kurl-test-api');
    if (testApiButton.length) {
        testApiButton.on('click', function () {
            const button = $(this);
            const result = $('#kurl-test-api-result');
            if (button.prop('disabled')) {
                return;
            }
            result.removeClass('success error').text(kurlAdmin.strings.working);
            setButtonBusy(button, true);
            const apiUrlField = $('#kurl-api-url');
            const signatureField = $('#kurl-signature');

            $.post(kurlAdmin.ajaxUrl, {
                action: 'kurl_test_api',
                nonce: kurlAdmin.nonce,
                api_url: apiUrlField.length ? apiUrlField.val() : '',
                signature: signatureField.length ? signatureField.val() : ''
            }).done(function (response) {
                if (response && response.success) {
                    const message = response.data && response.data.message ? response.data.message : kurlAdmin.strings.done;
                    const totalLinks = response.data && typeof response.data.total_links !== 'undefined' ? response.data.total_links : 0;
                    const totalClicks = response.data && typeof response.data.total_clicks !== 'undefined' ? response.data.total_clicks : 0;
                    result.addClass('success').text(message + ' ' + kurlAdmin.strings.links_label + ' ' + totalLinks + ', ' + kurlAdmin.strings.clicks_label + ' ' + totalClicks);
                    window.setTimeout(function () { window.location.reload(); }, 1200);
                } else {
                    result.addClass('error').text(response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error);
                }
            }).fail(function (xhr) {
                result.addClass('error').text(getAjaxErrorMessage(xhr));
            }).always(function () {
                setButtonBusy(button, false);
            });
        });
    }

    const bulkStart = $('#kurl-bulk-start');
    if (bulkStart.length) {
        let bulkStopped = false;
        let bulkRunning = false;
        let lastId = 0;
        let totals = { created: 0, updated: 0, imported: 0, skipped_existing: 0, error: 0 };
        const logBox = $('#kurl-bulk-log');
        const statsBox = $('#kurl-bulk-stats');
        const bar = $('#kurl-progress-bar');
        const bulkStop = $('#kurl-bulk-stop');

        function processedCount() {
            return totals.created + totals.updated + totals.imported + totals.skipped_existing + totals.error;
        }
        function appendLogRow(html) {
            logBox.append(html);
            if (logBox.length && logBox[0]) {
                logBox.scrollTop(logBox[0].scrollHeight);
            }
        }
        function renderTotals(done) {
            const processed = processedCount();
            statsBox.html(
                '<strong>' + escapeHtml(kurlAdmin.strings.bulk_processed) + '</strong> ' + processed +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.bulk_created) + '</strong> ' + totals.created +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.bulk_updated) + '</strong> ' + totals.updated +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.bulk_imported) + '</strong> ' + totals.imported +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.bulk_skipped) + '</strong> ' + totals.skipped_existing +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.bulk_errors) + '</strong> ' + totals.error +
                (done ? ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.bulk_status) + '</strong> ' + escapeHtml(kurlAdmin.strings.bulk_done_label) : '')
            );
            const pct = done ? 100 : Math.min(95, Math.max(5, processed));
            bar.css('width', pct + '%').text(done ? '100%' : '…');
        }
        function appendRows(rows) {
            rows.forEach(function (row) {
                const status = row && row.status ? row.status : 'error';
                if (typeof totals[status] === 'undefined') {
                    totals[status] = 0;
                }
                totals[status] += 1;
                appendLogRow('<div class="kurl-bulk-row status-' + escapeHtml(status) + '"><strong>#' + escapeHtml(row.post_id || '') + '</strong> ' + escapeHtml(row.title || '') + ' <em>(' + escapeHtml(getStatusLabel(status)) + ')</em> – ' + escapeHtml(row.message || '') + '</div>');
            });
        }
        function setBulkUiRunning(running) {
            bulkRunning = running;
            bulkStart.prop('disabled', running);
            bulkStop.prop('disabled', !running);
        }
        function runBatch() {
            if (bulkStopped || !bulkRunning) {
                return;
            }
            $.post(kurlAdmin.ajaxUrl, {
                action: 'kurl_bulk_batch',
                nonce: kurlAdmin.nonce,
                post_type: $('#kurl-bulk-post-type').val(),
                batch_size: $('#kurl-bulk-batch-size').val(),
                mode: $('#kurl-bulk-mode').val(),
                last_id: lastId
            }).done(function (response) {
                if (!response || !response.success) {
                    appendLogRow('<div class="kurl-bulk-row status-error">' + escapeHtml(kurlAdmin.strings.bulk_error_prefix) + ' ' + escapeHtml(response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error) + '</div>');
                    setBulkUiRunning(false);
                    return;
                }
                appendRows(response.data.results || []);
                lastId = response.data.last_id || lastId;
                renderTotals(!!response.data.done);
                if (response.data.done) {
                    appendLogRow('<div class="kurl-bulk-row status-success"><strong>' + escapeHtml(kurlAdmin.strings.bulk_done) + '</strong></div>');
                    setBulkUiRunning(false);
                    return;
                }
                if (!bulkStopped) {
                    runBatch();
                } else {
                    setBulkUiRunning(false);
                }
            }).fail(function (xhr) {
                appendLogRow('<div class="kurl-bulk-row status-error">' + escapeHtml(kurlAdmin.strings.bulk_ajax_prefix) + ' ' + escapeHtml(getAjaxErrorMessage(xhr)) + '</div>');
                setBulkUiRunning(false);
            });
        }
        bulkStart.on('click', function () {
            if (bulkRunning) {
                return;
            }
            bulkStopped = false;
            lastId = 0;
            totals = { created: 0, updated: 0, imported: 0, skipped_existing: 0, error: 0 };
            logBox.empty();
            renderTotals(false);
            setBulkUiRunning(true);
            runBatch();
        });
        bulkStop.on('click', function () {
            if (!bulkRunning) {
                return;
            }
            bulkStopped = true;
            appendLogRow('<div class="kurl-bulk-row">' + escapeHtml(kurlAdmin.strings.bulk_stopped) + '</div>');
            setBulkUiRunning(false);
        });
        bulkStop.prop('disabled', true);
    }


    const reconcileStart = $('#kurl-reconcile-start');
    if (reconcileStart.length) {
        let reconcileStopped = false;
        let reconcileRunning = false;
        let reconcileLastId = 0;
        let reconcileTotals = {
            checked: 0,
            imported: 0,
            replaced: 0,
            verified: 0,
            mismatches: 0,
            skipped: 0
        };

        const reconcileStop = $('#kurl-reconcile-stop');
        const reconcileLog = $('#kurl-reconcile-log');
        const reconcileStats = $('#kurl-reconcile-stats');
        const reconcileBar = $('#kurl-reconcile-progress-bar');

        function reconcileAppendLog(html) {
            reconcileLog.append(html);
            if (reconcileLog.length && reconcileLog[0]) {
                reconcileLog.scrollTop(reconcileLog[0].scrollHeight);
            }
        }

        function reconcileRenderTotals(done) {
            reconcileStats.html(
                '<strong>' + escapeHtml(kurlAdmin.strings.reconcile_checked) + '</strong> ' + reconcileTotals.checked +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.reconcile_imported) + '</strong> ' + reconcileTotals.imported +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.reconcile_replaced) + '</strong> ' + reconcileTotals.replaced +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.reconcile_verified) + '</strong> ' + reconcileTotals.verified +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.reconcile_mismatches) + '</strong> ' + reconcileTotals.mismatches +
                ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.reconcile_skipped) + '</strong> ' + reconcileTotals.skipped +
                (done ? ' &nbsp; <strong>' + escapeHtml(kurlAdmin.strings.bulk_status) + '</strong> ' + escapeHtml(kurlAdmin.strings.bulk_done_label) : '')
            );
            const pct = done ? 100 : Math.min(95, Math.max(5, reconcileTotals.checked));
            reconcileBar.css('width', pct + '%').text(done ? '100%' : '…');
        }

        function reconcileApplyResults(rows) {
            rows.forEach(function (row) {
                const status = row && row.status ? row.status : 'error';
                reconcileTotals.checked += 1;

                if (status === 'imported' || status === 'would_import') {
                    reconcileTotals.imported += 1;
                } else if (status === 'replaced' || status === 'would_replace') {
                    reconcileTotals.replaced += 1;
                } else if (status === 'verified') {
                    reconcileTotals.verified += 1;
                } else if (status === 'mismatch') {
                    reconcileTotals.mismatches += 1;
                } else if (status === 'skipped') {
                    reconcileTotals.skipped += 1;
                }

                reconcileAppendLog(
                    '<div class="kurl-bulk-row status-' + escapeHtml(status) + '">' +
                    '<strong>#' + escapeHtml(row.post_id || '') + '</strong> ' +
                    escapeHtml(row.title || '') +
                    ' <em>(' + escapeHtml(getStatusLabel(status)) + ')</em> – ' +
                    escapeHtml(row.message || '') +
                    '</div>'
                );
            });
        }

        function setReconcileUiRunning(running) {
            reconcileRunning = running;
            reconcileStart.prop('disabled', running);
            reconcileStop.prop('disabled', !running);
            $('#kurl-reconcile-batch-size').prop('disabled', running);
            $('#kurl-reconcile-preview').prop('disabled', running);
        }

        function reconcileRunBatch() {
            if (reconcileStopped || !reconcileRunning) {
                return;
            }

            $.post(kurlAdmin.ajaxUrl, {
                action: 'kurl_reconcile_batch',
                nonce: kurlAdmin.nonce,
                batch_size: $('#kurl-reconcile-batch-size').val(),
                preview: $('#kurl-reconcile-preview').is(':checked') ? 1 : 0,
                last_id: reconcileLastId
            }).done(function (response) {
                if (!response || !response.success) {
                    reconcileAppendLog('<div class="kurl-bulk-row status-error">' + escapeHtml(kurlAdmin.strings.bulk_error_prefix) + ' ' + escapeHtml(response && response.data && response.data.message ? response.data.message : kurlAdmin.strings.error) + '</div>');
                    setReconcileUiRunning(false);
                    return;
                }

                reconcileApplyResults(response.data.results || []);
                reconcileLastId = response.data.last_id || reconcileLastId;
                reconcileRenderTotals(!!response.data.done);

                if (response.data.done) {
                    reconcileAppendLog('<div class="kurl-bulk-row status-success"><strong>' + escapeHtml(kurlAdmin.strings.reconcile_done) + '</strong></div>');
                    setReconcileUiRunning(false);
                    return;
                }

                if (!reconcileStopped) {
                    reconcileRunBatch();
                } else {
                    setReconcileUiRunning(false);
                }
            }).fail(function (xhr) {
                reconcileAppendLog('<div class="kurl-bulk-row status-error">' + escapeHtml(kurlAdmin.strings.bulk_ajax_prefix) + ' ' + escapeHtml(getAjaxErrorMessage(xhr)) + '</div>');
                setReconcileUiRunning(false);
            });
        }

        reconcileStart.on('click', function () {
            if (reconcileRunning) {
                return;
            }

            reconcileStopped = false;
            reconcileLastId = 0;
            reconcileTotals = { checked: 0, imported: 0, replaced: 0, verified: 0, mismatches: 0, skipped: 0 };
            reconcileLog.empty();
            reconcileRenderTotals(false);
            setReconcileUiRunning(true);
            reconcileRunBatch();
        });

        reconcileStop.on('click', function () {
            if (!reconcileRunning) {
                return;
            }

            reconcileStopped = true;
            reconcileAppendLog('<div class="kurl-bulk-row">' + escapeHtml(kurlAdmin.strings.reconcile_stopped) + '</div>');
            setReconcileUiRunning(false);
        });

        reconcileStop.prop('disabled', true);
    }

});
