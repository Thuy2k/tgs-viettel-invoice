(function ($) {
    'use strict';

    if (!$('#vi-cluster-admin').length || typeof tgsViettelInvoice === 'undefined') return;

    // Đưa modal ra thẳng body để position:fixed không bị lệch bởi transform/overflow
    // của layout trang TGS Shop Management.
    $('#vi-cluster-backdrop, #vi-cluster-toast, #vi-cluster-editor').appendTo(document.body);

    var selected = {};
    var searchTimer = null;
    var toastTimer = null;

    function request(action, data) {
        return $.post(tgsViettelInvoice.ajaxUrl, $.extend({
            action: 'tgs_viettel_invoice_cluster_' + action,
            nonce: tgsViettelInvoice.nonce
        }, data || {}));
    }

    function message(type, text, context) {
        var editorOpen = !$('#vi-cluster-editor').hasClass('d-none');
        var selector = (editorOpen && context !== 'list') ? '#vi-cluster-editor-feedback' : '#vi-cluster-feedback';
        $(selector).removeClass('d-none alert-success alert-danger alert-warning alert-info')
            .addClass('alert-' + type).text(text || '');

        clearTimeout(toastTimer);
        var icons = { success: '✓', danger: '!', warning: '!', info: 'i' };
        $('#vi-cluster-toast').removeClass('d-none success danger warning info').addClass(type);
        $('#vi-cluster-toast .vi-cluster-toast-icon').text(icons[type] || 'i');
        $('#vi-cluster-toast .vi-cluster-toast-text').text(text || '');
        toastTimer = setTimeout(function () { $('#vi-cluster-toast').addClass('d-none'); }, type === 'danger' ? 9000 : 5500);
    }

    function errorMessage(xhr, fallback) {
        var json = xhr && xhr.responseJSON;
        return (json && json.data && json.data.message) || fallback;
    }

    function esc(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }

    function loadClusters() {
        $('#vi-cluster-list').html('<tr><td colspan="8" class="text-muted">Đang tải...</td></tr>');
        request('list').done(function (resp) {
            var items = resp && resp.success ? (resp.data.items || []) : [];
            var html = '';
            items.forEach(function (item) {
                var statusClass = item.status === 'active' ? 'success' : (item.status === 'draft' ? 'secondary' : 'dark');
                html += '<tr><td><strong>' + esc(item.name) + '</strong></td><td>' + esc(item.code) + '</td><td>v' + item.config_version + '</td>';
                html += '<td>' + esc(item.supplier_tax_code || '-') + '</td><td>' + esc(item.template_code || '-') + ' / ' + esc(item.invoice_series || '-') + '</td>';
                html += '<td><span class="badge bg-light text-dark">' + Number(item.shop_count || 0) + '</span></td>';
                html += '<td><span class="badge bg-' + statusClass + '">' + esc(item.status) + '</span></td>';
                html += '<td><button class="btn btn-sm btn-outline-primary vi-cluster-edit" data-id="' + item.id + '">Mở</button></td></tr>';
            });
            $('#vi-cluster-list').html(html || '<tr><td colspan="8" class="text-muted">Chưa có cụm nào.</td></tr>');
            $('#vi-cluster-summary').text(items.length + ' cụm');
        }).fail(function (xhr) { message('danger', errorMessage(xhr, 'Không tải được danh sách cụm.'), 'list'); });
    }

    function resetEditor() {
        selected = {};
        $('#vi-cluster-editor input:not([type]), #vi-cluster-editor input[type=text], #vi-cluster-editor input[type=password], #vi-cluster-editor textarea').val('');
        $('#vic_id,#vic_version').val('0');
        $('#vic_status').val('draft'); $('#vic_auth_mode').val('basic'); $('#vic_auto_mode').val('issue');
        $('#vic_verify_ssl').prop('checked', true); $('#vic_auto_enabled').prop('checked', false);
        $('#vic_api_base_url').val('https://api-vinvoice.viettel.vn/services/einvoiceapplication/api');
        $('#vic_template_code').val('1/770'); $('#vic_invoice_series').val('K23TXM'); $('#vic_payment_method').val('TM/CK');
        $('#vic_shop_results,#vic_selected_shops').empty();
        $('#vi-cluster-test,#vi-cluster-deactivate').addClass('d-none');
        $('#vi-cluster-editor-title').text('Tạo cụm hóa đơn');
        $('#vi-cluster-editor').data('has-password', false).data('has-token', false);
        $('#vi-cluster-editor-feedback').addClass('d-none').text('');
        $('#vi-cluster-editor .is-invalid').removeClass('is-invalid');
    }

    function openEditor() {
        var $backdrop = $('#vi-cluster-backdrop').appendTo(document.body);
        var $editor = $('#vi-cluster-editor').appendTo(document.body);
        $backdrop.removeClass('d-none').attr('aria-hidden', 'false');
        $editor.removeClass('d-none').attr('aria-hidden', 'false').css('display', 'flex');
        $('body').addClass('vi-cluster-modal-open');
        $editor.children('.card-body').scrollTop(0);
    }

    function closeEditor() {
        $('#vi-cluster-backdrop').addClass('d-none').attr('aria-hidden', 'true');
        $('#vi-cluster-editor').addClass('d-none').attr('aria-hidden', 'true').css('display', '');
        $('body').removeClass('vi-cluster-modal-open');
    }

    function setSaving(saving) {
        $('#vi-cluster-save').prop('disabled', saving);
        $('#vi-cluster-save .vi-save-label').toggleClass('d-none', saving);
        $('#vi-cluster-save .vi-save-loading').toggleClass('d-none', !saving);
    }

    function scrollToSection(target) {
        var $body = $('#vi-cluster-editor > .card-body');
        var $target = $('#' + target);
        if (!$target.length) return;
        $body.stop(true).animate({ scrollTop: $body.scrollTop() + $target.position().top - 24 }, 250);
    }

    function validateForm() {
        $('#vi-cluster-editor .is-invalid').removeClass('is-invalid');
        var active = $('#vic_status').val() === 'active';
        var required = [
            ['#vic_code', 'vic-step-basic', 'Mã cụm'],
            ['#vic_name', 'vic-step-basic', 'Tên cụm']
        ];
        if (active) {
            required = required.concat([
                ['#vic_tax_code', 'vic-step-issuer', 'MST người bán'],
                ['#vic_legal_name', 'vic-step-issuer', 'Tên pháp lý'],
                ['#vic_api_base_url', 'vic-step-viettel', 'API base URL'],
                ['#vic_template_code', 'vic-step-invoice', 'Template code'],
                ['#vic_invoice_series', 'vic-step-invoice', 'Invoice series']
            ]);
            if ($('#vic_auth_mode').val() === 'basic') {
                required.push(['#vic_username', 'vic-step-viettel', 'Username']);
                if (!$('#vi-cluster-editor').data('has-password')) required.push(['#vic_password', 'vic-step-viettel', 'Password']);
            } else if (!$('#vi-cluster-editor').data('has-token')) {
                required.push(['#vic_access_token', 'vic-step-viettel', 'Access token']);
            }
        }
        var missing = [];
        required.forEach(function (rule) {
            if (!$.trim($(rule[0]).val() || '')) { $(rule[0]).addClass('is-invalid'); missing.push(rule); }
        });
        if (active && !Object.keys(selected).length) {
            missing.push(['#vic_shop_search', 'vic-step-shops', 'Ít nhất một shop']);
            $('#vic_shop_search').addClass('is-invalid');
        }
        if (!missing.length) return true;
        scrollToSection(missing[0][1]);
        $(missing[0][0]).trigger('focus');
        message('danger', 'Chưa thể lưu. Vui lòng bổ sung: ' + missing.map(function (x) { return x[2]; }).join(', ') + '.');
        return false;
    }

    function renderSelected() {
        var html = '';
        Object.keys(selected).forEach(function (id) {
            var item = selected[id];
            html += '<span class="vi-shop-tag">#' + id + ' ' + esc(item.name || '') + '<button type="button" class="vi-remove-shop" data-id="' + id + '">&times;</button></span>';
        });
        $('#vic_selected_shops').html(html || '<span class="small text-muted">Chưa chọn shop.</span>');
    }

    function searchShops() {
        request('search_shops', { search: $('#vic_shop_search').val(), selected_ids: JSON.stringify(Object.keys(selected)) }).done(function (resp) {
            var html = '';
            (resp.data.items || []).forEach(function (item) {
                if (selected[item.blog_id] && !selected[item.blog_id].name) selected[item.blog_id] = item;
                var conflict = item.cluster_id && Number(item.cluster_id) !== Number($('#vic_id').val());
                html += '<label class="vi-shop-row"><input type="checkbox" class="form-check-input vi-shop-check" value="' + item.blog_id + '" ' + (selected[item.blog_id] ? 'checked' : '') + '>';
                html += '<span class="vi-shop-meta"><strong>#' + item.blog_id + ' ' + esc(item.name) + '</strong><span class="vi-shop-domain">' + esc(item.domain + item.path) + '</span>';
                if (conflict) html += '<span class="badge bg-warning text-dark">Đang thuộc ' + esc(item.cluster_name) + '</span>';
                html += '</span></label>';
            });
            $('#vic_shop_results').html(html || '<div class="p-3 text-muted">Không tìm thấy shop.</div>');
            renderSelected();
        });
    }

    function parseUsers() {
        var result = [];
        ($('#vic_users').val() || '').split(/[,\n]+/).forEach(function (part) {
            var bits = $.trim(part).split(':');
            var id = parseInt(bits[0], 10);
            if (id > 0) result.push({ user_id: id, access_level: bits[1] || 'viewer' });
        });
        return result;
    }

    function payload() {
        return {
            code: $('#vic_code').val(), name: $('#vic_name').val(), status: $('#vic_status').val(), config_version: $('#vic_version').val(),
            supplier_tax_code: $('#vic_tax_code').val(), legal_name: $('#vic_legal_name').val(), legal_address: $('#vic_legal_address').val(), legal_phone: $('#vic_legal_phone').val(),
            api_base_url: $('#vic_api_base_url').val(), auth_mode: $('#vic_auth_mode').val(), username: $('#vic_username').val(), password: $('#vic_password').val(), access_token: $('#vic_access_token').val(), verify_ssl: $('#vic_verify_ssl').is(':checked') ? 1 : 0,
            default_template_code: $('#vic_template_code').val(), default_invoice_series: $('#vic_invoice_series').val(), default_payment_method: $('#vic_payment_method').val(), auto_enabled: $('#vic_auto_enabled').is(':checked') ? 1 : 0, auto_mode: $('#vic_auto_mode').val()
        };
    }

    function save(forceMove) {
        if (!forceMove && !validateForm()) return;
        setSaving(true);
        request('save', { cluster_id: $('#vic_id').val(), cluster: payload(), shop_ids: JSON.stringify(Object.keys(selected)), users: JSON.stringify(parseUsers()), force_move: forceMove ? 1 : 0 })
            .done(function (resp) {
                setSaving(false);
                message('success', resp.data.message); $('#vic_id').val(resp.data.cluster_id); $('#vic_version').val(resp.data.config_version);
                $('#vi-cluster-test,#vi-cluster-deactivate').removeClass('d-none'); loadClusters();
            }).fail(function (xhr) {
                var json = xhr.responseJSON;
                if (!forceMove && json && json.data && json.data.code === 'shop_conflict') {
                    setSaving(false);
                    var names = (json.data.conflicts || []).map(function (x) { return '#' + x.blog_id + ' (' + x.cluster_name + ')'; }).join(', ');
                    if (window.confirm('Các shop sau đang thuộc cụm khác: ' + names + '. Chuyển chúng sang cụm này?')) setTimeout(function () { save(true); }, 0);
                    return;
                }
                setSaving(false);
                message('danger', errorMessage(xhr, 'Không lưu được cụm.'));
            });
    }

    $(document).on('click', '#vi-cluster-create-main', function () { resetEditor(); openEditor(); searchShops(); });
    $(document).on('click', '#vi-cluster-refresh', loadClusters);
    $(document).on('click', '#vi-cluster-close,#vi-cluster-cancel', closeEditor);
    $(document).on('click', '.vi-cluster-toast-close', function () { clearTimeout(toastTimer); $('#vi-cluster-toast').addClass('d-none'); });
    $(document).on('keydown', function (event) { if (event.key === 'Escape' && !$('#vi-cluster-editor').hasClass('d-none')) closeEditor(); });
    $(document).on('input change', '#vi-cluster-editor input,#vi-cluster-editor select,#vi-cluster-editor textarea', function () { $(this).removeClass('is-invalid'); });
    $(document).on('click', '.vi-cluster-edit', function () {
        resetEditor();
        request('get', { cluster_id: $(this).data('id') }).done(function (resp) {
            var d = resp.data, s = d.settings || {};
            $('#vic_id').val(d.id); $('#vic_version').val(d.config_version); $('#vic_code').val(d.code); $('#vic_name').val(d.name); $('#vic_status').val(d.status);
            $('#vic_tax_code').val(d.supplier_tax_code); $('#vic_legal_name').val(d.legal_name); $('#vic_legal_address').val(d.legal_address); $('#vic_legal_phone').val(d.legal_phone);
            $('#vic_api_base_url').val(s.api_base_url); $('#vic_auth_mode').val(s.auth_mode); $('#vic_username').val(d.username); $('#vic_verify_ssl').prop('checked', !!Number(s.verify_ssl));
            $('#vi-cluster-editor').data('has-password', !!d.has_password).data('has-token', !!d.has_access_token);
            $('#vic_template_code').val(s.default_template_code); $('#vic_invoice_series').val(s.default_invoice_series); $('#vic_payment_method').val(s.default_payment_method); $('#vic_auto_enabled').prop('checked', !!Number(s.auto_enabled)); $('#vic_auto_mode').val(s.auto_mode);
            (d.shop_ids || []).forEach(function (id) { selected[id] = { blog_id: id, name: '' }; });
            if ($('#vic_users').length) $('#vic_users').val((d.users || []).map(function (u) { return u.user_id + ':' + u.access_level; }).join(', '));
            $('#vi-cluster-editor-title').text('Sửa cụm: ' + d.name); $('#vi-cluster-test,#vi-cluster-deactivate').removeClass('d-none'); openEditor(); searchShops();
        }).fail(function (xhr) { message('danger', errorMessage(xhr, 'Không tải được cụm.')); });
    });
    $(document).on('input', '#vic_shop_search', function () { clearTimeout(searchTimer); searchTimer = setTimeout(searchShops, 300); });
    $(document).on('change', '.vi-shop-check', function () { var id = Number(this.value); if (this.checked) selected[id] = selected[id] || { blog_id: id, name: $(this).closest('.vi-shop-row').find('strong').text().replace(/^#\d+\s*/, '') }; else delete selected[id]; renderSelected(); });
    $(document).on('click', '.vi-remove-shop', function () { delete selected[Number($(this).data('id'))]; renderSelected(); searchShops(); });
    $(document).on('click', '#vi-cluster-save', function () { save(false); });
    $(document).on('click', '#vi-cluster-test', function () { request('test_connection', { cluster_id: $('#vic_id').val() }).done(function (r) { message('success', r.data.message); }).fail(function (x) { message('danger', errorMessage(x, 'Kết nối thất bại.')); }); });
    $(document).on('click', '#vi-cluster-deactivate', function () { if (!confirm('Ngừng cụm này? Việc phát hành của các shop trong cụm sẽ bị chặn cho tới khi kích hoạt lại hoặc chuyển cụm.')) return; request('deactivate', { cluster_id: $('#vic_id').val() }).done(function (r) { message('success', r.data.message); $('#vic_status').val('inactive'); loadClusters(); }); });
    $(document).on('click', '#vi-cluster-unassigned', function () { request('unassigned_shops').done(function (r) { var list = (r.data.items || []).slice(0, 100).map(function (x) { return '#' + x.blog_id + ' ' + x.name; }).join('\n'); message('warning', 'Có ' + r.data.count + ' shop chưa gán.' + (list ? '\n' + list : '')); }); });
    $(document).on('click', '#vi-cluster-migration-preview', function () { request('migration_preview').done(function (r) { var lines = (r.data.groups || []).slice(0, 20).map(function (g, i) { return (i + 1) + '. MST ' + (g.supplier_tax_code || 'thiếu') + ', ' + g.template_code + '/' + g.invoice_series + ': ' + g.shop_ids.length + ' shop'; }); message('info', r.data.message + ' Tìm thấy ' + r.data.group_count + ' nhóm; ' + r.data.incomplete_shop_ids.length + ' shop thiếu cấu hình.\n' + lines.join('\n')); }).fail(function (x) { message('danger', errorMessage(x, 'Không phân tích được cấu hình cũ.')); }); });

    loadClusters();
})(jQuery);
