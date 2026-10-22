# Print Bridge · 纯 PHP 打印代理

[English](README.en.md)

`lfcheng92/print-bridge` 是框架无关的 Composer 库及 CLI worker。用本地 SQLite 和受控 spool 保存任务，提供业务幂等、状态查询、租约恢复及保守的 CUPS 提交语义。运行时没有第三方 PHP 包，不依赖 Node.js、Electron、Web 服务或常驻账户令牌。

当前为**本地首版，尚未发布**。macOS/Linux 的 CUPS 适配器仅经过隔离假命令验证，尚未做真实打印机验证；不承诺 Windows 支持。`submitted` 仅表示操作系统队列接受，物理出纸始终为 `unverified`。不能保证端到端 exactly-once。

## 环境与安装

- PHP `^8.2`，`pdo`、`pdo_sqlite`、`json`、`hash` 扩展。
- 本地可写磁盘；父目录由当前受信用户控制。运行目录必须私有（0700），文件为 0600。不要用网络文件系统、共享临时目录、Web 根目录或源码目录存放真实任务。
- CUPS 模式需要另行安装并配置 macOS/Linux CUPS 的 `lp`，且 PHP 允许 `proc_open`。本包不会安装、发现、增删或修改打印机。
- 首版只收 PDF（仅检查 `%PDF-` 文件头）和无危险控制字符的 UTF-8 纯文本，默认文档上限 10 MiB，份数 1–99。PDF 检查不是内容净化；只处理受信文档，系统过滤器安全由部署者负责。

本地开发：

```sh
composer install --no-interaction --prefer-dist --no-plugins
composer validate --strict
composer check
php tools/consumer-check.php
```

尚无公共安装源。独立消费者可在自己的 `composer.json` 配置本地 path 仓库：

```json
{
  "repositories": [
    {"type": "path", "url": "../php-print-bridge", "options": {
      "symlink": false,
      "versions": {"lfcheng92/print-bridge": "dev-main"}
    }}
  ],
  "require": {"lfcheng92/print-bridge": "dev-main"}
}
```

再运行 `composer install --no-dev --no-plugins --no-scripts`。`vendor/bin/print-bridge` 是安装后的 CLI。`tools/consumer-check.php` 使用新建临时目录、禁用网络和 Packagist，先检查 Composer ZIP，再从解压目录以 **copy** 方式安装，隐藏源目录后运行库和 Composer bin 代理。

## 安全的本地 CLI 体验

下面只运行 FakeTransport，不接触打印系统。`STATE` 仅是本次 shell 变量，不是系统配置；请将其换成自己控制的绝对目录，且父目录已经存在。

```sh
STATE=/absolute/private-parent/print-bridge-state
php bin/print-bridge init --state "$STATE"
printf 'Synthetic document\n' | php bin/print-bridge submit --state "$STATE" \
  --key example-001 --printer demo --mime text/plain --copies 1
php bin/print-bridge work --state "$STATE" --once --transport fake
php bin/print-bridge status --state "$STATE" --id JOB_ID_FROM_SUBMIT
```

`submit` 只读标准输入，不接受文件路径、URL、任意 CUPS 选项或远端服务器参数。调用方负责读取自己授权的数据并处理背压。相同幂等键和参数返回同一 ID；文档、打印机、MIME、份数或初始重试上限变化则抛出冲突。

命令清单：

| 命令 | 语义 |
| --- | --- |
| `init --state DIR` | 创建或打开私有队列 |
| `submit --key KEY --printer NAME --mime TYPE [--copies 1] [--max-attempts 3]` | 从 stdin 接收任务，返回 received |
| `status --id ID` | 返回持久状态快照；不会自动探测打印机或恢复租约 |
| `work --once --transport fake\|cups` | 必须显式选 transport，至多领取一个任务；fake 只得到 simulated |
| `work --max-jobs N --transport fake\|cups` | 正数处理至多 N 个；队列为空即退出；0 持续轮询，支持 SIGTERM/SIGINT（需 pcntl） |
| `recover` | 恢复过期租约，worker 领取前也会执行 |
| `retry --id ID` | 显式重新排入 simulated，或已证明未提交且 payload 完好的失败任务；耗尽后增加一次机会，总尝试上限 100 |
| `resolve --id ID --outcome submitted --evidence TICKET --system-job-id demo-42` | 人工确认系统已接受；不会重打 |
| `resolve --id ID --outcome not-submitted --evidence TICKET` | 人工证明从未提交后标为 failed；仍须另外执行 retry |
| `cancel --id ID` | 仅取消 received/retry_wait/failed/simulated，本包不取消 OS 作业 |
| `purge --id ID` | 仅删除 submitted/cancelled 的 payload，保留幂等墓碑 |
| `prune` | 删除超过 1 小时、无 DB 引用的孤立普通 spool 文件 |

所有业务命令带 `--state DIR`，选项采用 `--name VALUE`，未知/重复选项报错。stdout 为逐行 JSON；错误 stderr 只含固定错误码。退出码：0 正常或空闲；1 存储/操作失败；2 输入、查询或幂等冲突；3 worker 遇到 unknown；4 worker 得到 failed。重试等待可返回 0，请读 JSON 状态。日志在运行目录 `logs/events.jsonl`，只记录时间、内部任务 ID、状态、尝试次数和固定错误码；不记录内容、幂等键、文件路径、租约令牌、打印机名、CUPS 原始输出或异常消息。库用户需显式传入 `AuditLogger`。

`work` 没有默认 transport。fake 与 `--lp`/`--timeout` 混搭会在领取前拒绝，即使还提供了合法打印机名。模拟成功持久化为 **`simulated`**，JSON 标记 `simulated: true`，`system_job_id` 为 null；它既不是 OS 接收，也不是物理出纸。对同一实际队列使用 fake 会把所领取任务停在 simulated，后续 CUPS 不会自动领取，且不能直接 purge；必须明确 `retry --id ID` 重新排入后再显式选 cups。若决定丢弃，先明确 cancel，再 purge。示例和消费者验证只用合成数据；生产任务应使用单独目录，并避免试运行 worker 消费实际业务队列。

CUPS 必须由管理员在已核验的本地环境显式启用 `--transport cups --printers NAME[,NAME]`，可配置绝对 `--lp` 路径、`--timeout`（默认 10 秒）和 `--lease`（默认 45 秒）。**该模式会真实提交打印，本文的验证未运行真实 lp。** 它固定连接本机 `localhost:631`，使用参数数组直接启动 `lp`，以 `--` 结束选项、固定安全标题并显式传递 MIME；不继承父进程账户秘密或 CUPS 服务器环境。白名单只接受简单名称，故不支持所有 CUPS 合法名称、类/实例、远端服务或任意驱动选项。

## PHP API

```php
use Lfcheng\PrintBridge\{PrintRequest, Queue, Worker};
use Lfcheng\PrintBridge\Transport\FakeTransport;

$queue = new Queue('/absolute/private-parent/queue-state');
$job = $queue->enqueue('order-synthetic-001', new PrintRequest(
    printer: 'demo', bytes: "Synthetic text\n", mediaType: 'text/plain', copies: 1,
));
$same = $queue->enqueue('order-synthetic-001', new PrintRequest('demo', "Synthetic text\n", 'text/plain'));
$worker = new Worker($queue, new FakeTransport(['demo']));
$result = $worker->runOnce(); // simulated Job，或没有可领取任务时为 null
$snapshot = $queue->get($job->id);
```

`examples/basic.php` 可执行且只使用临时目录及 FakeTransport。

| 文件/API | 边界 |
| --- | --- |
| `src/Queue.php` | enqueue/get/recover/retry/resolveUnknown/cancel/purgeDocument/pruneOrphans |
| `src/PrintRequest.php` | 不可变文档请求、打印机和 MIME/份数验证 |
| `src/Job.php` / `Status.php` | 不可变快照与受控 JSON；不暴露幂等键或 payload |
| `src/Worker.php` | `runOnce(): ?Job`、提交边界、租约校验 |
| `src/Transport/Transport.php` | `maximumRunSeconds()` 和 `submit(Submission): TransportResult` |
| `FakeTransport.php` / `CupsTransport.php` | 模拟或本机 CUPS 适配器 |
| `TransportResult.php` | submitted / simulated / notSubmitted / unknown；FakeTransport 不允许返回 submitted |
| `AuditLogger.php` | 固定字段 JSONL，本地 flock，日志失败不回滚已提交状态 |
| `Clock.php` | 默认 SystemClock；可注入测试时钟 |

`claim`、`beginSubmission`、`finish`、`failBeforeSubmission`、`documentPath` 是 worker 内部协议，不是认证接口；除非编写适配器/worker，业务代码应使用上述高级 API。`InvalidArgumentException` 表示输入不合法，`IdempotencyConflict` 表示同键参数冲突，`OutOfBoundsException` 表示不存在，`LeaseLost` 表示过期/丢失所有权，`BridgeException`/`PDOException` 表示存储或状态操作失败。库异常可能包含环境信息，不要直接返回到不受信客户端。

## 状态、重试与崩溃恢复

| 状态 | 能证明的事实 | 自动处理 |
| --- | --- | --- |
| `received` | 文档已保存，DB 已记录接收 | 可领取 |
| `leased` | 本 worker 持有独占租约，尚未写提交标记 | 过期回 received |
| `submitting` | 提交标记已持久化，OS 是否收到可能未知 | 过期转 unknown |
| `submitted` | 适配器获得可识别的系统队列回执，或人工确认 | 不再领取；不代表出纸 |
| `simulated` | 仅完成模拟，没有系统队列回执 | 不自动领取、不能直接 purge；可显式 retry 或 cancel |
| `retry_wait` | 已证明本次未提交且允许安全重试 | 指数延迟后重试，默认最多 3 次 |
| `failed` | 本地失败/安全重试耗尽，或者人工确认未提交 | 不自动领取；只有 retry_safe 可人工重试 |
| `unknown` | 命令已启动但结果、租约或回执不确定 | 不自动重打，需人工核查 |
| `cancelled` | 在 OS 提交前取消 | 不再领取 |

SQLite `BEGIN IMMEDIATE` 保证同机领取及幂等检查互斥；随机 lease token 防止旧 worker 覆盖新状态。提交尝试在写入 `submitting` 时计数，状态机不会根据 CUPS 接收推断物理成功。租约须比适配器最长运行时间至少长 5 秒；CUPS 的进程终止宽限也计入运行上限。不做心跳续租，主机休眠、暂停、向前跳时钟或超长 DB 等待都可能使结果保守地变为 unknown。

只有适配器明确证明未启动/未提交才可自动重试。CUPS 非零退出、超时、输出超限、格式异常，或适配器抛异常，一律 unknown。初始 `maxAttempts` 为 1–10；安全失败使用 10/20/40/80/160/300 秒封顶退避。缺少可执行文件和白名单不匹配不自动重试；修正配置后可显式 retry。文档丢失、被篡改或不再私有时在执行前失败且禁止 retry。

早期私有提交曾将 fake 回执写为 submitted；旧状态不会被自动重解释或重放。若曾用旧版运行 FakeTransport，先停 worker 并独立核查受影响任务，不能把旧 `fake-*` 标识当作系统提交证明，也不能直接清除其文档。旧 CUPS 打印机也可能恰好名为 fake，因此不能只根据该前缀自动迁移。

**人工核查 unknown 前必须停止所有可能仍在运行/暂停的旧 worker 和子进程。** 独立确认作业是否曾被接受/输出，不能把“现在队列里找不到”当作未提交证明（作业可能已完成或被清理）。无法可靠证明未提交就保持 unknown。记录不含秘密的 ticket 引用，再 resolve；只有确认从未提交后才考虑显式 retry。DB 和 OS 之间没有分布式事务，暂停后恢复的旧进程及人为错误仍可能导致重复；此工具不能消除这些风险。

## 存储与运行限制

- 同机、受信单用户或同身份多 worker；不支持 NFS、多主机共享队列或敌对同用户进程。私有目录不能抵御该用户、root 或恶意驱动修改文件；输入校验不等于权限隔离。
- payload 先独占创建、写入并 fsync，再提交 SQLite 事务；SQLite 使用 WAL/FULL/busy_timeout。进程崩溃可能留下孤立 payload，prune 会清理；断电/磁盘损坏没有额外保证，丢失 payload 会在提交前失败。
- 幂等只在同一个持久 DB 内有效；不自动过期/删除墓碑，避免旧业务键被重打。日志和任务元数据不会自动轮转；部署者需设置磁盘配额、保留策略、监控，并停止 worker 后做维护。
- 备份须停止所有 worker 并一致保存 DB/WAL 与 spool，或使用可靠 SQLite 备份方案；不要在运行时只复制 `.sqlite`。恢复旧备份后先人工对账，不能直接重放。
- 没有 HTTP 入口、网络监听、认证系统、任意 URL/文件读取接口、打印机探测、物理完成查询、OS 任务取消、PDF 渲染或 Windows 支持。若以后增加 HTTP，应另行设计 loopback、强认证、来源和大小限制。
- `notSubmitted()` 是自定义适配器的信任边界，误用会导致重复。日志失败可能使 CLI 返回 1，但已提交状态仍保留；先查询状态，不能据 CLI 重试或更换业务键。

## 验证与发布边界

测试使用随机私有临时目录、SQLite、FakeTransport 和临时 PHP 可执行假命令，包括真实子进程终止和并发；不会调用真实 `lp`、访问客户数据或生产服务。PHPStan 以 level 8、`--debug` 单进程运行，避免本地并行协调端口。`tools/consumer-check.php` 额外需要 zip 扩展（仅验证工具需要，运行库不需要）。CI 声明 macOS/Linux、PHP 8.2/8.3/8.4/8.5 矩阵，但未在本轮云端执行；实际已执行结果见 [LOCAL_VALIDATION.md](LOCAL_VALIDATION.md)。

当前 `license: proprietary` 是暂不授予开源许可的保守元数据。来源及依赖界限见 [PROVENANCE.md](PROVENANCE.md)、[LICENSE](LICENSE)、[SECURITY.md](SECURITY.md)。独立重新实现不自动解决权属。首次私有上传必须由父任务在本地报告后安排；公开仓库及 Packagist 需要另设明确发布检查关口，不能由单测或 CI 通过自动触发。
