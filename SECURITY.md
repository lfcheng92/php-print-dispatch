# 安全与故障报告 / Security

本地首版，尚无公开仓库、安全邮箱或已承诺的维护周期。请通过当前受信的私有项目沟通渠道报告问题，不要粘贴文档内容、客户信息、数据库、访问令牌或完整 CUPS 输出。可提供版本、固定错误码、脱敏状态和最小合成复现。未来建私有仓库后可使用其私有漏洞报告能力（若可用），不能假定当前已开通。

## 部署信任边界

- CLI/库由当前受信本地用户调用，无 HTTP 服务。不要直接把任意远端请求映射到 CLI；本包没有账户权限、网络认证、来源校验或多租户隔离。
- 状态目录需 0700、文件需 0600；父目录受信。最终目录、DB、spool 文件和日志拒绝符号链接。已打开后同用户替换文件、硬链接、root 或恶意驱动等不在防护保证内。
- 文档为字节输入，CLI 只读 stdin；不接受 URL/任意文件路径。spool 名称只由随机 32 位十六进制 ID 派生，执行前检查常规文件、权限、长度、SHA256。
- 打印机同时经过字符约束和显式 allowlist；调用 lp 使用 argv 数组、绝对命令路径和 `--`，不拼 shell。管理员配置的自定义命令可执行任意代码，只应指向受信二进制。
- CUPS 只连接本机 localhost:631，客户端环境不继承账户秘密；本机 CUPS/驱动仍可将任务转到其配置的打印机。只有用户明确准备真机验证后才应启用。
- PDF 只做文件头检查，不净化主动内容或防御系统过滤器漏洞；只接收受信内容，并更新 PHP/SQLite/CUPS/驱动。
- 日志采用字段白名单且不写原始异常或进程输出。元数据、spool 和数据库仍可能敏感，需访问控制、磁盘加密、磁盘配额及本地保留策略。业务键在 DB 中以 SHA256 保存，哈希不是对低熵键的保密保证。
- unknown 需要停止旧进程并人工核查，禁止根据超时、非零退出或队列消失盲目重打。人工确认引用不得含秘密。备份恢复也需要对账。
- 不保证物理出纸、端到端恰好一次、断电恢复、敌对同用户隔离或真实打印机兼容。测试不连接生产服务，不替代现场验证。

## 发布检查关口

本地验证后先报告，再由父任务安排私有仓库上传。公开需单独、明确确认：来源/权属与许可证审核、敏感文件扫描、依赖复查、重复验证以及用户参与的隔离真机检查。不能自动公开、发布 Packagist 或配置持续账户访问凭据。本仓库没有发布工作流。

For a private security report, use the existing trusted project channel and synthetic reproducers. Never attach payloads, credentials or raw customer data. This first version exposes no network ingress, grants no public release permission, and makes no physical-output or exactly-once guarantee.
