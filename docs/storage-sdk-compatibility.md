# 云存储 SDK 兼容与依赖维护

核查日期：2026-11-24。项目最低 PHP 7.4 保持，Composer 解析平台为 PHP 7.4.33；本机执行验证使用 PHP 8.2.12。没有部署到线上，也没有使用真实云凭据。

## 1. 最终依赖闭包

原有 Guzzle 6 / PSR-7 1.x 已结束维护，不能仅把 PSR-7 升到 1.9.1 就宣称解决 2026 年的新公告。最初的 1.9.1 小修方案已由以下受维护兼容线替代。

| 包 | 最终锁定版本 | 用途或兼容边界 |
|---|---|---|
| guzzlehttp/guzzle | 7.17.0 | HTTP 客户端，覆盖本次核查的 2026 年代理、Host、Cookie、重定向公告 |
| guzzlehttp/psr7 | 2.13.6 | URI/HTTP 解析与流，覆盖截至核查日的公开公告 |
| guzzlehttp/promises | 2.6.2 | Guzzle/云 SDK 异步结果 |
| guzzlehttp/guzzle-services | 1.7.6 | 保留 COS 的服务描述、请求与响应转换 |
| guzzlehttp/command | 1.5.4 | 旧 COS 命令接口兼容 |
| guzzlehttp/uri-template | v1.0.11 | Services 的 URI 模板依赖 |
| psr/http-message | 1.1 | 明确保留 1.x 接口，兼容旧 OBS CheckoutStream |
| psr/http-client | 1.0.3 | HTTP 客户端接口 |
| psr/http-factory | 1.1.0 | PSR-7 工厂接口 |
| myclabs/php-enum | 1.6.6 | 保留七牛已有依赖 |
| ralouphie/getallheaders | 3.0.3 | 保留现有行为 |
| symfony/deprecation-contracts | v2.5.4 | 由 PHP 7.4 平台选择可兼容分支 |
| symfony/polyfill-php80 | v1.43.0 | 新 HTTP 依赖的 PHP 兼容函数 |
| symfony/polyfill-intl-idn | v1.43.0 | 修复 CVE-2026-46644，保留旧站点 IDN 能力 |
| symfony/polyfill-intl-normalizer | v1.26.0 | 原版本保留，保留既有自动加载能力 |
| symfony/polyfill-php72 | v1.26.0 | 原版本保留，保留既有自动加载能力 |

includes/composer.json 声明依赖与兼容桥；新增 includes/composer.lock 固定完整闭包及官方来源。没有绕过 Composer 的安全阻断，没有整体覆盖 vendor 中无关目录。其余云 SDK、Mustache 和业务代码不由这份 Composer 清单托管。

## 2. 云 SDK 与实际修复

| 手工捆绑 SDK | 实际版本 | 本次修改 |
|---|---|---|
| 腾讯 COS | 2.5.6 | 服务 API 使用 HTTPS；证书验证开启；实际签名/HEAD/GET/PUT兼容回归 |
| 华为 OBS | 3.21.9 | 服务 API 使用 HTTPS；证书验证开启；实际请求、上传流和 CheckoutStream 回归 |
| 阿里 OSS | 2.6.0 | 适配器强制 HTTPS；回移 RequestCore 默认开启证书验证 |
| 七牛 | 7.7.0 | HTTP Client 验证证书链/主机名；上传和管理配置使用 HTTPS；固定官方区域发现地址补 HTTPS；保留 MIME 修复 |
| 又拍云 | 3.0.0 | useSsl 开启；兼容新的流工具；避免下载结束重复关闭已由 SDK 关闭的文件流 |

没有把这些 SDK 的版本号伪装为已升级。官方 OBS 指南对旧版本在 PHP>8.1 的全面兼容性没有保证；这里的本机回归不等于厂商支持声明。真实云上的分片、CORS、权限、时钟、签名和大文件传输仍需对应账号验收。

includes/lib/HttpCompat.php 只将旧 SDK 调用的四个函数转交给新库：Psr7 的 stream_for、copy_to_stream、mimetype_from_filename，以及 Promise 的 rejection_for。不复制旧 HTTP 解析器、不添加不校验证书的退路。该文件通过 Composer autoload.files 加载，站点原有 vendor/autoload.php 接入口不变。

## 3. 安全公告与核查范围

本轮重新获取了官方 Guzzle、PSR-7 全部公开公告，并用 Composer 当前注册表执行正式安装目录审计。结果为 advisories=[]、abandoned=[]。这只覆盖第 1 节的 16 个 Composer 包，不代表手工 SDK、整个站点或未来公告全部无风险。

本轮处理的依据包括：

- PSR-7 GHSA-wxmh-65f7-jcvw：旧版 HTTP 头换行校验。
- PSR-7 GHSA-hq7v-mx3g-29hw、GHSA-34xg-wgjx-8xph：不可信 URI/入站 Host 解释问题。
- PSR-7 GHSA-vm85-hxw5-5432：HTTP 起始行 CRLF。
- PSR-7 GHSA-c2w2-prh8-qm98、GHSA-jwvc-hj95-82hh：Host/authority 与括号主机一致性；后者发布于 2026-11-17。
- Guzzle 2026 年公开的代理 URL、Host、Cookie、重定向相关公告，最终 7.17.0 高于本次获取公告的修复下限。
- Symfony CVE-2026-46644：原有 IDN polyfill 1.26.0 被当前 Composer 阻断，已更新到 1.43.0；没有忽略公告或关闭审计。

有版本受影响范围不等于已证明本站每个出站流程都能被利用；测试通过也不表示未来不再需要维护。

## 4. TLS 与自定义 CA

COS、OBS、OSS、又拍云服务 API 以及七牛管理、上传、区域发现请求采用 HTTPS。OSS RequestCore 与七牛 HTTP Client 默认验证证书链及主机名。站长自定义文件下载域名的协议选择和七牛旧回源兼容策略保留，未在这次 API 传输修复中强行改变。

如果主机缺少最新 CA，或私有服务使用自签 CA，应配置可信 CA 后验证实际请求：

- 在服务端 php.ini 配置 curl.cainfo 与 openssl.cafile，指向包含所需根证书的 PEM 信任包。
- Guzzle 的 verify 接受 true 或 CA 路径；OBS 原生 ssl_verify、OSS RequestCore 原生 cacert_location 也支持 CA 文件。
- 本机测试中，七牛子进程仅设置 curl.cainfo 仍拒绝临时 CA，同时设置这两项后通过。部署时应核对生效的 PHP 配置，不能只根据配置文件已填写就认定信任已生效。
- 证书错误应修复信任配置，不能通过关闭验证掩盖。后台没有新增“关闭证书校验”选项。

## 5. 验证与部署

已在正式 vendor/autoload.php 下通过：

- StorageSdkCompatibilityTest.php：59 项，包括实际 COS/OBS 请求序列、签名头、HTTPS、上传字节、OBS 检查流、Upyun 本机 TLS 读取与下载、四函数桥、危险 Host/HTTP 起始行拒绝、CA 信任与拒绝、七牛区域查询构造。
- UpstreamCoreRegressionTest.php：78 项；StorageUrlTest.php、S3StorageTest.php 均通过。
- 本轮新增/更新的 183 个 PHP 文件语法检查、git diff --check。
- Composer validate --no-check-publish、audit --no-dev、check-platform-reqs。validate 有缺少 license 字段及精确版本约束的建议警告，退出码为 0；审计无公告/废弃包。
- 依赖由 PHP 7.4.33 平台解析；没有在真实 PHP 7.4 解释器或真实云账号下运行整站验收。

测试命令：

    D:/xampp/php/php.exe tests/StorageSdkCompatibilityTest.php
    D:/xampp/php/php.exe tests/UpstreamCoreRegressionTest.php
    composer --working-dir=includes validate --no-check-publish
    composer --working-dir=includes audit --no-dev
    composer --working-dir=includes check-platform-reqs --no-dev

测试临时证书、文件和服务在成功/失败后均清理。详细失败经过和逐次结果保存在中央 CHG-20261124-001；包括 CA 信任配置、Guzzle 错误类型及 Upyun 重复 fclose 的定位和修复。

includes/vendor/ 继续按仓库规则排除；本轮 9 个新回归脚本显式纳入源码，其余测试仍受既有忽略规则约束。交付时必须包含 Composer 文件、HttpCompat.php 及必要 vendor 变更，不能只传 composer.json 就认定线上已更新。维护者本地精确依赖清单为 .codex/sdk-maintained-http-delivery-20261124.json（不随源码提交，克隆后的重建依据是 composer.lock），包含 228 个新增/更新文件和 44 个旧包文件删除项。

覆盖前快照：.codex/sdk-before-maintained-http-20261124-8c2d58db。snapshot.json 保存逐文件 SHA-256；快照基线是本轮初步 PSR-7 1.9.1/TLS 修复阶段，不是任务最初的全仓库状态。本地快照不随源码提交，不代表任务最初的完整状态；源码交付不会自动回退、部署或发布打包。

## 6. 官方来源

- [Guzzle 7.17.0 依赖声明](https://github.com/guzzle/guzzle/blob/7.17.0/composer.json)
- [PSR-7 2.13.6 依赖声明](https://github.com/guzzle/psr7/blob/2.13.6/composer.json)
- [Services 1.7.6](https://github.com/guzzle/guzzle-services/blob/1.7.6/composer.json)、[Command 1.5.4](https://github.com/guzzle/command/blob/1.5.4/composer.json)、[Promises 2.6.2](https://github.com/guzzle/promises/blob/2.6.2/composer.json)
- [PSR-7 公开公告](https://github.com/guzzle/psr7/security/advisories)、[Guzzle 公开公告](https://github.com/guzzle/guzzle/security/advisories)
- [最新括号主机公告](https://github.com/guzzle/psr7/security/advisories/GHSA-jwvc-hj95-82hh)、[代理 URL 公告](https://github.com/guzzle/guzzle/security/advisories/GHSA-q8h8-pjjq-cq2h)
- [Symfony CVE-2026-46644](https://symfony.com/cve-2026-46644)
- [OBS CA 参数](https://support.huaweicloud.com/intl/en-us/api-obs_php_sdk_api_en/obs_38_0101.html)
- [OSS v2.7.3 TLS 默认值依据](https://github.com/aliyun/aliyun-oss-php-sdk/blob/v2.7.3/src/OSS/Http/RequestCore.php)
- [七牛 v7.14.0 HTTP Client 对照](https://github.com/qiniu/php-sdk/blob/v7.14.0/src/Qiniu/Http/Client.php)
- [又拍云官方依赖声明](https://github.com/upyun/php-sdk/blob/master/composer.json)

官方来源获取使用 HTTPS 验证。隔离 Composer 2.10.3 来自 getcomposer.org，下载后核对同源 SHA-256；临时信任包只导出系统已信任根证书的公有证书，不包含私钥。
