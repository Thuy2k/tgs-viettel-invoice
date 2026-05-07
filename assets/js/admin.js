(function ($) {
    'use strict';

    function showFeedback(selector, type, message) {
        var $box = $(selector);
        if (!$box.length) {
            return;
        }

        $box.removeClass('d-none alert-success alert-danger alert-info')
            .addClass('alert-' + type)
            .text(message);
    }

    function getSettingsPayload() {
        return {
            company_name: $('#vi_company_name').val(),
            supplier_tax_code: $('#vi_supplier_tax_code').val(),
            company_address: $('#vi_company_address').val(),
            company_phone: $('#vi_company_phone').val(),
            api_base_url: $('#vi_api_base_url').val(),
            auth_mode: $('#vi_auth_mode').val(),
            username: $('#vi_username').val(),
            password: $('#vi_password').val(),
            access_token: $('#vi_access_token').val(),
            default_template_code: $('#vi_default_template_code').val(),
            default_invoice_series: $('#vi_default_invoice_series').val(),
            default_payment_method: $('#vi_default_payment_method').val(),
            verify_ssl: $('#vi_verify_ssl').is(':checked') ? 1 : 0,
            auto_enabled: $('#vi_auto_enabled').is(':checked') ? 1 : 0,
            auto_mode: $('#vi_auto_mode').val()
        };
    }

    function saveSettings() {
        $.post(tgsViettelInvoice.ajaxUrl, {
            action: 'tgs_viettel_invoice_save_settings',
            nonce: tgsViettelInvoice.nonce,
            settings: getSettingsPayload()
        }).done(function (resp) {
            if (resp && resp.success) {
                showFeedback('#vi_settings_feedback', 'success', resp.data.message || 'Da luu.');
                return;
            }
            showFeedback('#vi_settings_feedback', 'danger', (resp && resp.data && resp.data.message) || 'Khong luu duoc cau hinh.');
        }).fail(function (xhr) {
            var msg = 'Co loi khi luu cau hinh.';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                msg = xhr.responseJSON.data.message;
            }
            showFeedback('#vi_settings_feedback', 'danger', msg);
        });
    }

    function exportSettings() {
        $.post(tgsViettelInvoice.ajaxUrl, {
            action: 'tgs_viettel_invoice_export_settings',
            nonce: tgsViettelInvoice.nonce
        }).done(function (resp) {
            if (!(resp && resp.success)) {
                showFeedback('#vi_settings_feedback', 'danger', 'Khong export duoc JSON.');
                return;
            }

            var text = JSON.stringify(resp.data, null, 2);
            var blob = new Blob([text], { type: 'application/json;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            var now = new Date();
            var stamp = now.getFullYear() + ('0' + (now.getMonth() + 1)).slice(-2) + ('0' + now.getDate()).slice(-2) + '_' + ('0' + now.getHours()).slice(-2) + ('0' + now.getMinutes()).slice(-2);

            a.href = url;
            a.download = 'viettel-invoice-settings-blog-' + (resp.data.blog_id || 'unknown') + '-' + stamp + '.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            showFeedback('#vi_settings_feedback', 'success', 'Da export JSON thanh cong.');
        }).fail(function () {
            showFeedback('#vi_settings_feedback', 'danger', 'Co loi khi export JSON.');
        });
    }

    function importSettings() {
        $.post(tgsViettelInvoice.ajaxUrl, {
            action: 'tgs_viettel_invoice_import_settings',
            nonce: tgsViettelInvoice.nonce,
            settings_json: $('#vi_import_json').val()
        }).done(function (resp) {
            if (resp && resp.success) {
                showFeedback('#vi_settings_feedback', 'success', resp.data.message || 'Da import. Dang tai lai trang...');
                setTimeout(function () {
                    window.location.reload();
                }, 700);
                return;
            }
            showFeedback('#vi_settings_feedback', 'danger', (resp && resp.data && resp.data.message) || 'Khong import duoc JSON.');
        }).fail(function (xhr) {
            var msg = 'Co loi khi import JSON.';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                msg = xhr.responseJSON.data.message;
            }
            showFeedback('#vi_settings_feedback', 'danger', msg);
        });
    }

    function sendPayload(mode) {
        var selector = mode === 'issue' ? '#tgs-viettel-payload-issue' : '#tgs-viettel-payload-draft';
        var payload = $(selector).val();

        $('#tgs-viettel-response-status').text('Dang gui request...');

        $.post(tgsViettelInvoice.ajaxUrl, {
            action: 'tgs_viettel_invoice_send_payload',
            nonce: tgsViettelInvoice.nonce,
            mode: mode,
            payload_json: payload
        }).done(function (resp) {
            if (resp && resp.success) {
                $('#tgs-viettel-response-status').removeClass('text-danger').addClass('text-success').text(resp.data.message + ' (HTTP ' + (resp.data.http_code || '-') + ')');
                $('#tgs-viettel-response-box').text(JSON.stringify(resp.data, null, 2));
                return;
            }

            $('#tgs-viettel-response-status').removeClass('text-success').addClass('text-danger').text('Gui that bai.');
            $('#tgs-viettel-response-box').text(JSON.stringify(resp, null, 2));
        }).fail(function (xhr) {
            var data = xhr.responseJSON || { message: 'Request loi.' };
            $('#tgs-viettel-response-status').removeClass('text-success').addClass('text-danger').text('Gui that bai.');
            $('#tgs-viettel-response-box').text(JSON.stringify(data, null, 2));
        });
    }

    $(document).on('click', '#vi_save_settings', function () {
        saveSettings();
    });

    $(document).on('click', '#vi_export_settings', function () {
        exportSettings();
    });

    $(document).on('click', '#vi_import_settings', function () {
        importSettings();
    });

    $(document).on('click', '.tgs-viettel-send-btn', function () {
        var mode = $(this).data('mode') === 'issue' ? 'issue' : 'draft';
        sendPayload(mode);
    });

    $(document).on('click', '.tgs-viettel-fill-sample', function () {
        var target = $(this).data('target');
        var source = '#tgs-viettel-payload-draft';
        if ($(source).length && $(target).length) {
            $(target).val($(source).val());
        }
    });
})(jQuery);
