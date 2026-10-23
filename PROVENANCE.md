# 来源界限 / Source boundary

- 本包根据本次通用功能需求新写：持久队列、业务幂等、本地 spool、保守提交、CLI 和隔离测试。没有采用、复制或迁移旧公司项目实现、历史 Git、凭据、真实业务数据或原 Node 代码。
- 环境核查仅用于目录、上级 AGENTS.md 和可用工具；其他项目未被修改。相邻项目不是代码来源。
- 本次实现参考官方公开接口资料，未复制其实现代码：
  - [PHP proc_open 参数数组](https://www.php.net/manual/en/function.proc-open.php)：直接 argv 启动和受控环境。
  - [CUPS lp 手册](https://www.cups.org/doc/man-lp.html)：本地 scheduler、目标、份数、标题和选项结束标记。
  - [Composer schema](https://getcomposer.org/doc/04-schema.md)：PSR-4、bin、archive 和许可元数据。
- 没有运行时第三方 Composer 依赖。开发依赖 `phpstan/phpstan` 来自默认官方 Packagist 注册源，具体版本锁定于 composer.lock，使用 MIT 许可（Ondřej Mirtes / PHPStan s.r.o.），其版权及许可保留在开发安装的 vendor/phpstan/phpstan/LICENSE 中；vendor 不进入 Git 或分发源码 ZIP。本包的 MIT 不替代或重新授予该依赖及外部运行时的许可。
- PHP/SQLite/CUPS 作为独立安装的系统/运行时组件使用，不打包其源码或二进制。CUPS 的真实驱动、过滤器、字体与输入文档权利由部署者另行核查。
- 测试全部使用合成文本/合成头部、随机临时数据库和假进程。PDF 头部测试不代表真实 PDF 兼容性或真机打印成功。
- 这是来源记录，不是法律意见；重新实现不自动消除雇佣、保密、专利或其他权属问题。本包自主编写部分由维护者在此次发布审查中选择 MIT；不得据此重新授予公司或第三方代码的许可。

This is a record of the implementation boundary, not proof of title. Fresh implementation does not automatically resolve ownership. The independently authored package is licensed under MIT; external dependencies, runtimes, drivers and documents retain their own terms. Tests do not authorize repository publication.
