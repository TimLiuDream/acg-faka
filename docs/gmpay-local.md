# GM Pay 本地接入

商城通过自托管的 [GM Pay / Epusdt](https://github.com/GMWalletApp/epusdt) 接收 BSC（BEP20）USDT。GM Pay 负责汇率换算、唯一金额分配、链上监听和回调重试；商城负责验签、更新订单、自动发卡和飞书通知。

## 1. 启动并安装 GM Pay

```bash
docker compose up -d gmpay
```

浏览器打开 `http://127.0.0.1:8090` 完成安装向导。Docker 环境里的监听地址必须填写 `0.0.0.0`，端口填写 `8000`。运行数据保存在 `gmpay_data` Docker 卷中。

## 2. 配置 BSC 收款

在 GM Pay 后台完成以下设置：

1. 启用 BSC 链，并配置可用的 WSS RPC 节点。
2. 启用 BSC 上的 USDT 代币，并复核主网合约地址与精度。
3. 添加收款钱包 `0x7138c68c5649f96e2073a1e981f1f0551ca6bea2`。
4. 配置 CNY/USDT 汇率来源、订单有效期和确认数。
5. 将支付成功通知重试次数设为至少 `5`，避免商城瞬时不可用时漏发货。
6. 创建商城专用 API Key，保存其 `pid` 和 `secret_key`。

商城与 GM Pay 都不需要钱包私钥或助记词。

## 3. 配置商城支付插件

进入商城后台的「支付管理 → 插件配置」，为 `GM Pay 加密支付` 新建配置：

| 字段 | 本地值 |
| --- | --- |
| GM Pay 内部 API 地址 | `http://gmpay:8000` |
| GM Pay 公开地址 | `http://127.0.0.1:8090` |
| 商城公开回调地址 | `https://dreamlab.timliu.xyz`（线上） |
| 商户 PID | GM Pay 后台创建的 PID |
| 商户 Secret Key | GM Pay 后台创建的 Secret Key |
| 法币币种 | `cny` |
| 代币 | `usdt` |
| 网络 | `binance`（GM Pay 对 BSC 的内部标识） |

线上还需要为 GM Pay 单独配置 HTTPS 收银台域名并反代到服务器本机的 GM Pay 端口。不要把 `127.0.0.1` 或 HTTP 地址作为线上 `public_url`。

随后新建或修改商品支付接口：

- 插件：`GmPay`
- 支付方式：`USDT (BSC/BEP20)`
- 配置档：选择刚创建的 GM Pay 配置
- 商品支付：启用

> GM Pay 2.0 会拒绝 localhost、容器名和私网 IP 作为通知地址。纯本地环境可以检查后台配置、钱包监听和接口连通性，但创建真实支付单必须填写一个能从公网访问的 HTTPS 商城地址。若需要在部署前完整测试回调，应使用受控的临时 HTTPS 隧道，并在测试后立即关闭。

## 4. 测试

1. 在 GM Pay 后台确认 BSC、USDT、钱包与 API Key 均显示可用。
2. 将商城插件的「商城公开回调地址」填为当前测试环境可公网访问的 HTTPS 地址。
3. 在商城创建一笔低金额测试订单，确认浏览器跳到 `http://127.0.0.1:8090` 的 GM Pay 收银台。
4. 使用 BSC 钱包严格按页面金额转账，币种、网络、地址和尾数必须完全一致。
5. 等待达到确认数后，确认商城订单变成已支付、卡密已发货，并收到飞书通知。
6. 若回调稍慢，返回商城订单页会调用 GM Pay 状态接口进行一次主动查单。

排查命令：

```bash
docker compose ps
docker compose logs --tail=200 gmpay
docker compose logs --tail=200 app
```

不要把 GM Pay 的 `/data/.env`、数据库、API Secret 或任何钱包密钥提交到 Git。
