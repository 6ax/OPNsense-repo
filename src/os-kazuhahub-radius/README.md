# Kazuha Hub RADIUS for OPNsense

`os-kazuhahub-radius` 是 Irvine 站点 “Kazuha Hub” WPA2/WPA3-Enterprise Wi-Fi 的 RADIUS
代理。它在防火墙上终结接入点发来的 EAP-TTLS，用 OPNsense 信任库里的服务器证书
完成 TLS，再把隧道内的 PAP 请求按故障转移顺序转发给中心 RADIUS 服务器。它取代原来
跑在 NAS 上的 FreeRADIUS 容器。

| | |
| --- | --- |
| 插件版本 | `1.0.0` |
| 目标 | OPNsense 26.7，`FreeBSD:15:amd64` |
| 依赖 | 仅 FreeBSD 包 `freeradius3`（`net/freeradius3`，26.7 自带 3.2.10） |
| 菜单 | **服务 > Kazuha Hub RADIUS > Settings** |

软件包只含 PHP、shell 和配置模板，没有原生可执行文件，也没有 Python 依赖；
同一个构建适用于所有 amd64 ABI，安装钩子只接受 amd64 上的 FreeBSD 14、15、16。

## 工作方式

- 守护进程是 `freeradius3` 的 `radiusd`，由自己的 rc 脚本
  `/usr/local/etc/rc.d/kazuhahub_radiusd` 以私有配置目录
  `/usr/local/etc/kazuhahub-radius/` 启动。它**不读取** `/usr/local/etc/raddb`，
  不改 `radiusd_enable`，也不使用 os-freeradius 的任何内容。
- 默认站点在所选接口的静态 IPv4 地址上监听 UDP 1812（绝不监听 `*`）：
  `authorize` 依次是 `filter_username`、`eap { ok = return }`、`reject`。
- EAP 只启用 EAP-TTLS（TLS 1.2 到 1.3）；内层隧道里不做本地认证，只把 PAP 请求交给
  realm `ENTRA`，由中心服务器应答。
- 中心服务器按顺序组成故障转移池：`response_window = 15`、`zombie_period = 40`，
  并以 Status-Server 探测（`check_interval = 30`、`check_timeout = 4`、
  `num_answers_to_alive = 3`）。应答必须带 Message-Authenticator。
- 日志写到 `/var/log/kazuhahub-radius/radius.log`，不记录任何密码，无论对错。

## 设置

| 设置 | 默认 | 说明 |
| --- | --- | --- |
| Enable | 关 | 关闭时不运行任何进程。启用前必须填齐证书、密钥和全部密钥串。 |
| Listen interfaces | `lan` | 只能选已启用、静态 IPv4 的接口。 |
| Server certificate / Certificate authority | 空 | 来自 **系统 > 信任**；私钥必须保存在本机。 |
| Access point networks | LAN 子网 | 接入点所在的 IPv4 网络（Irvine 是 `192.168.0.0/22`），每个网络生成一个客户端。 |
| Access point secret | 空 | 接入点与本服务共用的密钥串。 |
| Central servers | Production `100.88.32.2:11812`，4c6g `100.88.33.1:11812`（默认禁用，无密钥串） | 地址、端口、密钥串、顺序；序号小的优先。 |

**密钥串从不回显。** 它们像 OPNsense 的其他密钥一样保存在 `config.xml`，API 永远不返回
它们；表单留空表示保留已存的值。它们只出现在 0640 的生成文件里，不进入日志、
configd 输出、状态页或测试输出。密钥串长 8 到 128 个字符，只允许字母、数字和
`! # & ( ) * + , - . / : ; < = > ? @ [ ] ^ _ { | } ~`。

## 绝不监听 WAN

保存时模型会拒绝下列接口：键为 `wan` 的接口、设置了网关的接口、IPv4 不是静态的接口
（DHCP、PPPoE 等）、没有 IPv4 地址的接口、未启用的接口，以及带有 core 只给 WAN 设置的
“阻止私有网络/阻止 bogon”选项的接口。已保存的配置若之后变成 WAN 样式，同样无法再保存。
模板对生成的配置再做一次同样的检查；没有任何可用地址时，监听器退回环回地址，rc 脚本
拒绝启动，守护进程不会落到通配监听。插件不添加任何防火墙规则：LAN 的默认规则已经放行
接入点，WAN 上什么都不开放。

## 与 os-freeradius 互斥

两者运行同一个 `radiusd`，不能共存：

- `+PRE_INSTALL` 在已安装 `os-freeradius` 时拒绝安装；
- rc 脚本在 `os-freeradius` 已安装、或 `radiusd_enable=YES` 时拒绝启动；
- 设置页的状态面板会指出原因。

## 状态与检查

设置页显示服务状态、监听地址、每台中心服务器的状态（`alive`、`zombie`、`dead`，来自
`radmin` 读取的控制套接字 `/var/run/kazuhahub-radius/radiusd.sock`，只读，仅 root）、
上次配置检查的结果和冲突警告。**Check now** 会导出证书并运行 `radiusd -XC`，结果只保留
错误行，不含密钥串。仪表盘有同样内容的小组件。

证书由 PHP 助手从信任库导出：`server.pem`（叶证书加证书链，链会一并发给客户端）、
`server.key`（0640 root:freeradius）和 `ca.pem`，仅在内容变化时原子替换。每次启动前都会
重新导出并检查。

## 文件

| 路径 | 内容 |
| --- | --- |
| `/usr/local/etc/kazuhahub-radius/` | 生成的配置，0750 root:freeradius，文件 0640；卸载时删除 |
| `/var/run/kazuhahub-radius/` | pid、控制套接字、上次检查结果 |
| `/var/log/kazuhahub-radius/radius.log` | 守护进程日志 |
| `/usr/local/opnsense/version/kazuhahub-radius` | 产品版本（JSON） |

卸载只删除生成的配置、运行时文件和日志，设置仍留在 `config.xml`，重装后恢复。升级保留生成的
配置，并在服务已启用时重启它。

## 其他行为

- **上行接口。** 被 **系统 > 网关** 里任何网关（包括已禁用的；26.7 的 `//OPNsense/Gateways` 与旧版
  `//gateways` 都认）指向的接口，同样不能监听；模板对生成的配置再检查一次，之后新增网关会让监听器消失。
- **中心服务器。** 预设两台默认是**禁用**且没有密钥串的；启用某台时，该行的密钥串必须有效（错误显示在该行的
  密钥串字段，网格的新增、编辑、开关都会拒绝）。启用服务时至少要有一台可用的中心服务器。模板跳过不可用的行，
  不写空密钥串；一台都不剩时在配置里留下标记，**Check now** 与启动都会以清楚的信息失败。
- **客户端网络。** 前缀不得短于 `/8`（模型与模板一致），重复的网络只生成一个客户端；没有可用的网络或密钥串时同样留下标记并拒绝启动。
- **每次启动与重载前重新渲染配置**（rc 脚本调用 `configctl template reload OPNsense/KazuhaHubRadius`），所以接口地址
  变化后下一次启动就会用新地址；运行中的监听集合不会自动变，需要重启。
- **拒绝原因进入状态页。** rc 脚本的所有拒绝（冲突、没有可用接口/中心服务器/客户端、监听地址不存在）都会写进上次检查结果。
- **到中心服务器的源地址。** 代理到中心服务器的出站请求使用 FreeRADIUS 的临时 UDP 通配套接字（随机高端口）。它不是监听器：
  没有任何 `listen` 段打开它，只用于向已配置的中心服务器发请求并接收其应答，对外不提供服务，
  生命周期检查会确认只有这一个通配套接字且不在 1812 端口。没有设置 `src_ipaddr`：由内核按路由为每台中心服务器选择源地址，
  固定一个源地址反而可能与某台中心服务器的路由不符。
- **日志轮转。** `/usr/local/etc/newsyslog.conf.d/kazuhahub-radius.conf` 超过 2 MB 时轮转，保留 7 份，轮转后向 radiusd 发 HUP 重开日志。
- **互斥检测。** `+PRE_INSTALL` 不用 `pkg info`（事务内不可靠），而是检查 os-freeradius 独有的文件
  （`/usr/local/opnsense/version/freeradius` 等）。pkg 2.8 在 pre-install 失败后只提示 “PRE-INSTALL script failed” 并继续安装，所以 post-install 会再次警告，服务本身在这些文件存在时也拒绝启动并在状态页说明原因。

## 测试

```sh
python3 -B tests/run.py --package os-kazuhahub-radius --python-only
php src/os-kazuhahub-radius/tests/native/test-model.php
```

测试用 jinja2 渲染全部模板（单台与两台中心服务器、多接口、多个客户端网络），断言生成的文件
与上述行为逐项一致；另有接线、钩子、rc 脚本和证书导出测试。FreeBSD 生命周期检查在测试主机上
用 `eapol_test` 做 EAP-TTLS/PAP：正确密码通过、错误密码被拒、第一台中心服务器停止后切到第二台。
