!function () {

    // LiuNeng 主题专用：个人资料 + 密码 + 邮箱 + 手机 四个面板整合在一页,
    // 各表单以 data-panel 区分、保存按钮以 data-save 区分,互不干扰。

    const $form = panel => $('form[data-panel="' + panel + '"]');

    // ===== 头像上传 =====
    util.bindButtonUpload(".avatar-input", "/user/api/upload/send?mime=image", result => {
        $('input[name=avatar]').val(result.url);
        $('.avatar-img').attr("src", result.url);
    });

    // ===== 微信收款二维码 =====
    $('.wx_qrcode').qrcode({
        render: "canvas",
        width: 150,
        height: 150,
        text: getVar('_user_wechat')
    });

    util.bindButtonUpload(".wechat-input", "/user/api/upload/send?mime=image", result => {
        $('input[name=wechat]').val(result.url);
        $('.wx_qrcode').html('<img class="wechat-img" src="' + result.url + '" style="width: 100px;cursor: pointer;" data-acg-proxy=".wechat-input">');
        $('.wx_qrcode_temp').html('<img class="wechat-img" src="' + result.url + '" style="width: 100px;cursor: pointer;" data-acg-proxy=".wechat-input">');
        message.success("上传完成，需要保存才会生效哦");
    });

    // ===== 修改个人信息 =====
    $('[data-save="profile"]').click(function () {
        util.post("/user/api/security/personal", util.getFormData($form('profile')[0]), () => {
            message.success("已生效");
        });
    });

    // ===== 重置商户密钥 =====
    $('.reset-key').click(function () {
        message.ask("是否要重置您的密钥？", () => {
            util.post('/user/api/security/resetKey', res => {
                $('.app-key').html(res.data.app_key);
                message.success("密钥已重置");
            });
        });
    });

    // ===== 密码设置 =====
    $('[data-save="password"]').click(function () {
        util.post("/user/api/security/password", util.getFormData($form('password')[0]), () => {
            message.success("修改成功");
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        })
    });

    // ===== 邮箱 / 手机验证码（需要图片人机验证） =====
    $('.send-captcha').click(function () {
        const $btn = $(this);
        const action = $btn.data('captcha');           // emailBindNew / phoneBindNew
        const field = action === 'phoneBindNew' ? 'phone' : 'email';
        const target = $form(field === 'phone' ? 'phone' : 'email').find('input[name=' + field + ']').val();
        if (!target) {
            message.error(field === 'phone' ? "请先输入新手机号" : "请先输入新邮箱");
            return;
        }
        message.prompt({
            title: '人机验证',
            width: 420,
            html: `<img src="/user/captcha/image?action=${action}" data-acg-refresh="/user/captcha/image?action=${action}" class="prompt-image-code" alt="${i18n('更换验证码')}">`,
            inputAttributes: {
                onpaste: 'return false',
                oncopy: 'return false'
            },
            confirmButtonText: `${i18n('继续操作')}`,
            inputValidator: function (value) {
                return (!value && i18n("请输入验证码"));
            }
        }).then(res => {
            if (res.isConfirmed === true) {
                util.post("/user/api/security/" + action, {
                    captcha: res.value,
                    [field]: target
                }, () => {
                    util.countDown($btn, 60);
                    message.success("验证码发送成功");
                });
            }
        });
    });

    // ===== 绑定邮箱 =====
    $('[data-save="email"]').click(function () {
        util.post("/user/api/security/email", util.getFormData($form('email')[0]), () => {
            message.success("绑定成功");
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        })
    });

    // ===== 绑定手机 =====
    $('[data-save="phone"]').click(function () {
        util.post("/user/api/security/phone", util.getFormData($form('phone')[0]), () => {
            message.success("绑定成功");
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        })
    });

}();
