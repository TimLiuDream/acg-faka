!function () {
    const namespace = '.mdConfigNotificationController';
    let active = true;
    let saving = false;
    let testing = false;
    let dirty = false;

    if (typeof window.__mdConfigNotificationDestroy === 'function') window.__mdConfigNotificationDestroy();

    function setBusy(selector, busy) {
        $(selector).prop('disabled', busy).toggleClass('disabled', busy);
    }

    $('#data-form').off(namespace).on('input' + namespace + ' change' + namespace, 'input', function () {
        dirty = true;
        document.dispatchEvent(new CustomEvent('admin:mobile:form-dirty', {detail: {form: document.getElementById('data-form')}}));
    });

    $('.save-data').off(namespace).on('click' + namespace, function () {
        if (!active || saving || testing) return;
        saving = true;
        setBusy('.save-data, .test-notification', true);
        util.post({
            url: '/admin/api/config/notification',
            data: util.arrayToObject($('#data-form').serializeArray()),
            done: res => {
                if (!active) return;
                saving = false;
                dirty = false;
                $('#data-form input[type="password"]').val('');
                setBusy('.save-data, .test-notification', false);
                layer.msg(res.msg || i18n('通知设置已保存'));
                document.dispatchEvent(new CustomEvent('admin:mobile:form-saved', {detail: {form: document.getElementById('data-form')}}));
            },
            error: res => {
                if (!active) return;
                saving = false;
                setBusy('.save-data, .test-notification', false);
                message.error(res?.msg || i18n('通知设置保存失败'));
            },
            fail: () => {
                if (!active) return;
                saving = false;
                setBusy('.save-data, .test-notification', false);
                message.error(i18n('网络异常，通知设置未保存'));
            }
        });
    });

    $('.test-notification').off(namespace).on('click' + namespace, function () {
        if (!active || saving || testing) return;
        if (dirty) {
            layer.msg(i18n('请先保存当前通知设置'));
            return;
        }
        testing = true;
        setBusy('.save-data, .test-notification', true);
        util.post({
            url: '/admin/api/config/notificationTest',
            data: {},
            done: res => {
                if (!active) return;
                testing = false;
                setBusy('.save-data, .test-notification', false);
                layer.msg(res.msg || i18n('测试通知已发送'));
            },
            error: res => {
                if (!active) return;
                testing = false;
                setBusy('.save-data, .test-notification', false);
                message.error(res?.msg || i18n('测试通知发送失败'));
            },
            fail: () => {
                if (!active) return;
                testing = false;
                setBusy('.save-data, .test-notification', false);
                message.error(i18n('网络异常，测试通知发送失败'));
            }
        });
    });

    function destroy() {
        if (!active) return;
        active = false;
        $('#data-form, .save-data, .test-notification').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (window.__mdConfigNotificationDestroy === destroy) delete window.__mdConfigNotificationDestroy;
    }

    window.__mdConfigNotificationDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
