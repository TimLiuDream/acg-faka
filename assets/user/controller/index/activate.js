!function () {
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);

    const $secret = $('input[name=secret]');
    const $session = $('.ln-activate-session');
    const $verifyState = $('.ln-activate-verify-state');
    const $accountBox = $('.ln-activate-account-box');
    const $progress = $('.ln-activate-progress');

    const setButtonLoading = ($button, loading, text) => {
        if ($button.data('idle-html') == null) {
            $button.data('idle-html', $button.html());
        }

        if (loading) {
            $button
                .prop('disabled', true)
                .addClass('is-loading')
                .attr('aria-busy', 'true')
                .html(`<i class="fa-duotone fa-regular fa-spinner-third"></i>${esc(text)}`);
            return;
        }

        $button
            .prop('disabled', false)
            .removeClass('is-loading')
            .removeAttr('aria-busy')
            .html($button.data('idle-html'));
    };

    const progressStatusMap = () => ({
        unused: {cls: 'is-queue', text: i18n('未使用')},
        redeeming: {cls: 'is-running', text: i18n('提交中')},
        pending: {cls: 'is-queue', text: i18n('排队中')},
        processing: {cls: 'is-running', text: i18n('处理中')},
        done: {cls: 'is-done', text: i18n('已完成')},
        failed: {cls: 'is-failed', text: i18n('失败')},
        revoked: {cls: 'is-failed', text: i18n('已作废')}
    });

    // ===== 第 1 步：查询上游兑换进度 =====
    $('.ln-activate-verify').on('click', function () {
        const secret = String($secret.val() || '').trim();
        if (secret.length < 4) {
            message.error(i18n('请输入正确的卡密'));
            return;
        }

        const $btn = $(this);
        setButtonLoading($btn, true, i18n('查询中'));

        util.post({
            url: "/user/api/index/redeemProgress",
            data: {secret: secret},
            loader: false,
            done: res => {
                setButtonLoading($btn, false);
                const card = res?.data?.card ?? null;
                if (!card || !card.status) {
                    $verifyState.html(`<span class="is-bad">${i18n('进度查询服务返回异常，请稍后再试')}</span>`);
                    $progress.hide().empty();
                    return;
                }
                const meta = progressStatusMap()[card.status] || progressStatusMap().processing;
                $verifyState.html(
                    `<span class="is-ok"><i class="fa-duotone fa-regular fa-circle-check"></i> ${i18n('进度查询成功')}</span> `
                    + `<span class="is-dim">${i18n('当前状态')}：${esc(meta.text)}</span>`
                );
                _RenderRemoteProgress(card);
            },
            error: res => {
                setButtonLoading($btn, false);
                $verifyState.html(`<span class="is-bad"><i class="fa-duotone fa-regular fa-circle-xmark"></i> ${esc(res?.msg || i18n('未查询到兑换进度'))}</span>`);
                $progress.hide().empty();
            },
            fail: () => {
                setButtonLoading($btn, false);
                $verifyState.html(`<span class="is-bad">${i18n('网络异常，请稍后再试')}</span>`);
            }
        });
    });

    function _RenderRemoteProgress(card) {
        const map = progressStatusMap();
        const meta = map[card.status] || map.processing;
        const rows = [];
        const tier = card.tierLabel || card.tier;
        if (tier) rows.push(`<p>${i18n('产品')}：${esc(tier)}</p>`);
        if (card.orderId) rows.push(`<p>${i18n('订单号')}：${esc(card.orderId)}</p>`);
        if ((card.status === 'pending' || card.status === 'processing') && Number.isFinite(card.ahead)) {
            const total = Number.isFinite(card.total) ? card.total : '-';
            rows.push(`<p>${i18n('前方排队')}：${esc(card.ahead + 1)} / ${esc(total)}</p>`);
        }
        if ((card.status === 'pending' || card.status === 'processing') && Number.isFinite(card.estWaitMs)) {
            rows.push(`<p>${i18n('预计等待')}：${i18n('约')} ${Math.max(1, Math.round(card.estWaitMs / 60000))} ${i18n('分钟')}</p>`);
        }
        if (card.usedAt) {
            const usedAt = new Date(card.usedAt);
            rows.push(`<p>${i18n('提交时间')}：${esc(Number.isNaN(usedAt.getTime()) ? card.usedAt : usedAt.toLocaleString())}</p>`);
        }
        if (card.note) rows.push(`<p>${i18n('说明')}：${esc(card.note)}</p>`);

        const hints = {
            unused: i18n('卡密尚未提交兑换。'),
            done: i18n('兑换已完成，请登录账号确认到账。'),
            failed: i18n('本次兑换未成功，请根据说明处理或联系支持。'),
            revoked: i18n('该卡密已作废，请联系发卡方处理。')
        };
        const hint = hints[card.status] || i18n('进度来自兑换服务，可稍后再次查询。');

        $progress.html(`
            <div class="ln-activate-progress__box">
                <strong>
                    <i class="fa-duotone fa-regular fa-clock-rotate-left"></i>${i18n('兑换进度')}
                    <span class="ln-activate-chip ${meta.cls}">${esc(meta.text)}</span>
                </strong>
                ${rows.join('')}
                <p>${esc(hint)}</p>
            </div>
        `).show();
    }

    // ===== 第 2 步：本地解析 Session JSON =====
    $session.on('input', function () {
        const raw = String($(this).val() || '').trim();
        if (!raw) {
            $accountBox.removeClass('is-ready is-bad').html(
                `<strong>${i18n('等待账号信息')}</strong><p>${i18n('粘贴 Session JSON 后会在本地自动检查邮箱、当前套餐和有效期。')}</p>`
            );
            return;
        }

        let json;
        try {
            json = JSON.parse(raw);
        } catch (e) {
            $accountBox.removeClass('is-ready').addClass('is-bad').html(
                `<strong>${i18n('Session JSON 格式不正确')}</strong><p>${i18n('请完整复制以 { 开头、以 } 结尾的整段内容。')}</p>`
            );
            return;
        }

        const email = json?.user?.email || json?.user_email || '';
        const plan = json?.planName || json?.plan || json?.user?.planName || '';
        const expire = json?.expires || json?.expire || '';

        if (!json || Array.isArray(json) || typeof json !== 'object') {
            $accountBox.removeClass('is-ready').addClass('is-bad').html(
                `<strong>${i18n('Session JSON 格式不正确')}</strong><p>${i18n('请确认复制的是 Session 页面的完整 JSON。')}</p>`
            );
            return;
        }

        const rows = [];
        if (!email && !plan && !expire) {
            $accountBox.removeClass('is-bad').addClass('is-ready').html(
                `<strong>${i18n('Session JSON 格式有效')}</strong><p>${i18n('提交后将由兑换服务继续验证账号信息。')}</p>`
            );
            return;
        }
        rows.push(`<li><span>${i18n('邮箱')}</span><strong>${esc(email || i18n('未检测到'))}</strong></li>`);
        rows.push(`<li><span>${i18n('当前套餐')}</span><strong>${esc(plan || i18n('未检测到'))}</strong></li>`);
        rows.push(`<li><span>${i18n('有效期')}</span><strong>${esc(expire || i18n('未检测到'))}</strong></li>`);

        $accountBox.removeClass('is-bad').addClass('is-ready').html(
            `<strong>${i18n('已识别账号信息')}</strong><ul>${rows.join('')}</ul>`
        );
    });

    // ===== 提交兑换 =====
    $('.ln-activate-submit').on('click', function () {
        const secret = String($secret.val() || '').trim();
        const raw = String($session.val() || '').trim();

        if (secret.length < 4) {
            message.error(i18n('请输入正确的卡密'));
            return;
        }

        if (!raw) {
            message.error(i18n('请粘贴 Session JSON'));
            return;
        }

        let json;
        try {
            json = JSON.parse(raw);
        } catch (e) {
            message.error(i18n('Session JSON 格式不正确'));
            return;
        }

        if (!json || Array.isArray(json) || typeof json !== 'object') {
            message.error(i18n('Session JSON 格式不正确'));
            return;
        }

        const $btn = $(this);
        setButtonLoading($btn, true, i18n('提交中'));

        util.post({
            url: "/user/api/index/redeemSubmit",
            data: {secret: secret, session: raw},
            loader: false,
            done: res => {
                setButtonLoading($btn, false);
                $session.val('');
                const data = res?.data ?? {};
                if (data.alreadyUsed) {
                    message.success(i18n('该卡密已使用过，已显示最新进度'));
                } else if (data.resume) {
                    message.success(i18n('该卡密正在处理中，已显示最新进度'));
                } else {
                    message.success(i18n('提交成功，卡密已进入处理队列'));
                }
                if (data.card) {
                    _RenderRemoteProgress(data.card);
                    const meta = progressStatusMap()[data.card.status] || progressStatusMap().processing;
                    $verifyState.html(
                        `<span class="is-ok"><i class="fa-duotone fa-regular fa-circle-check"></i> ${i18n('提交成功')}</span> `
                        + `<span class="is-dim">${i18n('当前状态')}：${esc(meta.text)}</span>`
                    );
                } else {
                    $('.ln-activate-verify').trigger('click');
                }
            },
            error: res => {
                setButtonLoading($btn, false);
                message.error(res?.msg || i18n('提交失败，请稍后再试'));
            },
            fail: () => {
                setButtonLoading($btn, false);
                message.error(i18n('网络异常，请稍后再试'));
            }
        });
    });
}();
