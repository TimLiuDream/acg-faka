#!/bin/sh
set -e

cd /var/www/html

# 这些目录都会在运行期被安装器、插件市场、上传接口、模板引擎、
# WAF/请求日志、在线更新等逻辑写入。容器启动时再修一次，是为了兼容
# 已存在的 named volume 或从旧版本 docker-compose 迁移过来的 volume。
mkdir -p \
    assets/cache \
    app/Pay \
    app/Plugin \
    app/View/User/Theme \
    config \
    kernel/Install/OS \
    kernel/Install/Update \
    runtime/log \
    runtime/plugin \
    runtime/request \
    runtime/tmp \
    runtime/view \
    runtime/waf

# app/Pay 使用持久化卷。代码随镜像更新，管理员维护的插件排序配置单独保留。
if [ -d /usr/local/share/acg-faka/pay/Epay ]; then
    mkdir -p app/Pay/Epay/Config app/Pay/Epay/Impl
    if [ ! -f app/Pay/Epay/Config/Config.php ]; then
        cp /usr/local/share/acg-faka/pay/Epay/Config/Config.php app/Pay/Epay/Config/Config.php
    fi
    cp /usr/local/share/acg-faka/pay/Epay/Config/Info.php app/Pay/Epay/Config/Info.php
    cp /usr/local/share/acg-faka/pay/Epay/Config/Submit.php app/Pay/Epay/Config/Submit.php
    cp -R /usr/local/share/acg-faka/pay/Epay/Impl/. app/Pay/Epay/Impl/
fi

# 后台“基础设置”会把上传的 Logo 写到 /favicon.ico。
# 将它落到 assets/cache 这个持久化卷中，避免容器重建后丢失。
if [ ! -f assets/cache/favicon.ico ]; then
    if [ -f /usr/local/share/acg-faka/favicon.ico ]; then
        cp /usr/local/share/acg-faka/favicon.ico assets/cache/favicon.ico
    else
        : > assets/cache/favicon.ico
    fi
fi

if [ ! -L favicon.ico ]; then
    rm -f favicon.ico
    ln -s assets/cache/favicon.ico favicon.ico
fi

chown -R www-data:www-data \
    assets/cache \
    app/Pay \
    app/Plugin \
    app/View/User/Theme \
    config \
    kernel/Install \
    runtime

exec docker-php-entrypoint "$@"
