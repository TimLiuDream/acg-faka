!function () {
    function verificationPrompt(action) {
        const imageUrl = `/user/captcha/image?action=${action}`;

        return message.prompt({
            title: i18n('人机验证'),
            width: 460,
            html: `<div class="ln-captcha-prompt">
                <p>${i18n('请输入验证码')}</p>
                <img src="${imageUrl}" data-acg-refresh="${imageUrl}" class="prompt-image-code ln-captcha-prompt__image" alt="${i18n('更换验证码')}">
                <span class="ln-captcha-prompt__refresh">
                    <i class="fa-duotone fa-regular fa-arrows-rotate"></i>${i18n('更换验证码')}
                </span>
            </div>`,
            input: 'text',
            inputPlaceholder: i18n('请输入验证码'),
            inputAttributes: {
                autocapitalize: 'off',
                autocomplete: 'one-time-code',
                inputmode: 'numeric',
                maxlength: '8',
                'aria-label': i18n('请输入验证码')
            },
            showCloseButton: true,
            focusConfirm: false,
            buttonsStyling: false,
            confirmButtonText: i18n('继续操作'),
            customClass: {
                container: 'ln-captcha-swal-container',
                popup: 'ln-captcha-swal',
                title: 'ln-captcha-swal__title',
                htmlContainer: 'ln-captcha-swal__content',
                input: 'ln-captcha-swal__input',
                actions: 'ln-captcha-swal__actions',
                confirmButton: 'ln-captcha-swal__confirm',
                cancelButton: 'ln-captcha-swal__cancel',
                closeButton: 'ln-captcha-swal__close',
                validationMessage: 'ln-captcha-swal__validation'
            },
            inputValidator: function (value) {
                return (!String(value || '').trim() && i18n('请输入验证码'));
            }
        });
    }

    $(`.needs-validation`).on("submit", function (e) {
        e.preventDefault();
        const formData = new FormData($('.needs-validation')[0]);
        const data = Object.fromEntries(formData.entries());
        util.post("/user/api/authentication/register", data, res => {
            window.location.href = "/";
            message.success(res.msg);
        });
    });


    $(`.send-phone-captcha`).click(function () {
        const button = this;
        verificationPrompt('phoneRegisterCaptcha').then(res => {
            if (res.isConfirmed === true) {
                util.post("/user/api/authentication/phoneRegisterCaptcha", {
                    captcha: res.value,
                    phone: $('input[name=phone]').val()
                }, res => {
                    util.countDown(button, 60);
                    message.success("验证码发送成功");
                });
            }
        });
    });


    $(`.send-email-code`).click(function () {
        const button = this;
        verificationPrompt('emailRegisterCaptcha').then(res => {
            if (res.isConfirmed === true) {
                util.post("/user/api/authentication/emailRegisterCaptcha", {
                    captcha: res.value,
                    email: $('input[name=email]').val()
                }, res => {
                    util.countDown(button, 60);
                    message.success("验证码发送成功");
                });
            }
        });
    });
}();
