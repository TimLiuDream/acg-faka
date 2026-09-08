!function () {
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);

    const $secret = $('input[name=secret]');
    const $session = $('.ln-activate-session');
    const $verifyState = $('.ln-activate-verify-state');
    const $accountBox = $('.ln-activate-account-box');
    const $progress = $('.ln-activate-progress');

    let verifiedSecret = null;   // 已通过验证的卡密，提交前比对防止改了输入绕过验证

    const redeemStatusMap = () => ({
        0: {cls: 'is-queue', text: i18n('排队中')},
        1: {cls: 'is-running', text: i18n('处理中')},
        2: {cls: 'is-done', text: i18n('已完成')},
        3: {cls: 'is-failed', text: i18n('失败')}
    });

    // ===== 第 1 步：验证卡密 / 恢复进度 =====
    $('.ln-activate-verify').on('click', function () {
        const secret = String($secret.val() || '').trim();
        if (secret.length < 4) {
            message.error(i18n('请输入正确的卡密'));
            return;
        }

        const $btn = $(this);
        $btn.attr('disabled', true);

        util.post({
            url: "/user/api/index/redeem",
            data: {secret: secret},
            loader: false,
            done: res => {
                $btn.attr('disabled', false);
                const data = res?.data ?? {};
                verifiedSecret = secret;

                const statusText = data.status === 1 ? i18n('已激活')
                    : data.status === 2 ? i18n('已锁定') : i18n('未激活');
                $verifyState.html(
                    `<span class="is-ok"><i class="fa-duotone fa-regular fa-circle-check"></i> ${i18n('卡密有效')}</span> `
                    + `<span class="is-dim">${i18n('商品')}：${esc(data.commodity?.name || '-')} · ${esc(statusText)}</span>`
                );

                _RenderProgress(data.redeem ?? null);
            },
            error: res => {
                $btn.attr('disabled', false);
                verifiedSecret = null;
                $verifyState.html(`<span class="is-bad"><i class="fa-duotone fa-regular fa-circle-xmark"></i> ${esc(res?.msg || i18n('卡密不存在，请核对后重试'))}</span>`);
                $progress.hide().empty();
            },
            fail: () => {
                $btn.attr('disabled', false);
                verifiedSecret = null;
                $verifyState.html(`<span class="is-bad">${i18n('网络异常，请稍后再试')}</span>`);
            }
        });
    });

    // ===== 进度渲染（提交成功或恢复进度共用） =====
    function _RenderProgress(redeem) {
        if (!redeem) {
            $progress.hide().empty();
            return;
        }

        const map = redeemStatusMap();
        const meta = map[redeem.status] || map[0];
        const rows = [];
        if (redeem.account_email) {
            rows.push(`<p>${i18n('充值账号')}：${esc(redeem.account_email)}</p>`);
        }
        if (redeem.account_plan) {
            rows.push(`<p>${i18n('当前套餐')}：${esc(redeem.account_plan)}</p>`);
        }
        if (redeem.create_time) {
            rows.push(`<p>${i18n('提交时间')}：${esc(redeem.create_time)}</p>`);
        }
        if (redeem.message) {
            rows.push(`<p>${i18n('备注')}：${esc(redeem.message)}</p>`);
        }

        $progress.html(`
            <div class="ln-activate-progress__box">
                <strong>
                    <i class="fa-duotone fa-regular fa-clock-rotate-left"></i>${i18n('兑换进度')}
                    <span class="ln-activate-chip ${meta.cls}">${esc(meta.text)}</span>
                </strong>
                ${rows.join('')}
                <p>${i18n('刷新页面后输入原卡密，点击「验证卡密 / 恢复进度」可随时查看。')}</p>
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

        if (!json.accessToken && !json.sessionToken) {
            $accountBox.removeClass('is-ready').addClass('is-bad').html(
                `<strong>${i18n('缺少 accessToken 或 sessionToken')}</strong><p>${i18n('请确认复制的是 Session 页面的完整 JSON。')}</p>`
            );
            return;
        }

        const rows = [];
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

        if (!secret || secret !== verifiedSecret) {
            message.error(i18n('请先点击「验证卡密 / 恢复进度」验证卡密'));
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

        if (!json.accessToken && !json.sessionToken) {
            message.error(i18n('Session JSON 缺少 accessToken 或 sessionToken'));
            return;
        }

        const $btn = $(this);
        $btn.attr('disabled', true);

        util.post({
            url: "/user/api/index/redeemSubmit",
            data: {secret: secret, session: raw},
            loader: false,
            done: res => {
                $btn.attr('disabled', false);
                message.success(i18n('提交成功，卡密已进入处理队列'));
                _RenderProgress({
                    status: res?.data?.status ?? 0,
                    account_email: res?.data?.account_email,
                    create_time: res?.data?.create_time
                });
            },
            error: res => {
                $btn.attr('disabled', false);
                message.error(res?.msg || i18n('提交失败，请稍后再试'));
                //重复提交等场景同步一次服务端进度
                if (verifiedSecret) {
                    $('.ln-activate-verify').trigger('click');
                }
            },
            fail: () => {
                $btn.attr('disabled', false);
                message.error(i18n('网络异常，请稍后再试'));
            }
        });
    });
}();
