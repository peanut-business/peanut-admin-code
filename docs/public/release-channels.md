# 发行渠道、来源与版本

## 1. 从哪里取得产品

正式发行以官方GitHub Release的固定tag、来源提交、附件清单和可信摘要为准。开发源码版本由`release-versions.json#source_product_version`读取，不能从本页日期或示例推断已发布。历史实例按自己采用的版本阅读资料；CI构建成功、本地文件存在和正式公开发行分别记录。

| 对象 | 来源与用途 |
| --- | --- |
| 脚手架模板 | 官方固定发行来源，用于创建APP及提供上游升级输入 |
| APP开发源码 | APP自己的Git仓，承载业务、自有版本和上游baseline |
| APP部署包 | APP固定提交生成的server-only归档，用于该APP安装与生产更新 |
| Core包 | Composer/npm固定公开版本及锁定摘要；开发联调不冒充正式包 |
| CLI | 独立工具版本，不等同脚手架或APP版本 |

上游Code、PHP Core及Web Core六包采用既有统一发行版本合同；APP与CLI各有自己的版本。同一版本仍须核commit/tree及内容，不覆盖已发布附件。

## 2. 创建和上游升级

当前CLI公开来源创建仍核正式来源、固定Code提交和模板身份，再调用原生创建器。直接读取正式模板压缩包、显式本地包、接受严格空目录的简化创建方式是后续接口目标；不能把尚未实现的参数写成可执行命令。当前参数以对应CLI版本的help和说明为准。

`peanut upgrade`在开发APP中吸收上游：`--path`选择APP，`--ref`固定目标，`--check`只检查，`--plan`保存计划。apply/recover继续同一来源和计划，不重新选最新版。省略版本的渠道及主版本范围由所用CLI版本决定，不允许静默跨主版本。APP名称、独立版本、自有业务、配置和定制不能被上游重置。

原生源码入口：

```sh
php /absolute/my-app/scripts/upgrade plan --scope=source --instance-root=/absolute/my-app --package=/absolute/authenticated-upstream
```

用返回的同一plan_path继续apply、verify或recover。旧baseline、APP当前文件和目标上游作三方比较；冲突明确解决，未知或部分写入先对账。源码升级不等于依赖、数据库、前端构建或生产采用成功。

## 3. APP发行与生产更新

普通APP默认只发行server-only包，具体命令、输出、受管文件和构建见[APP发行](application-release.md)。上游模板与冻结内部候选的显式入口保留原合同，不成为普通APP完整源码包模式。

新实例使用`php server/database/install.php`或该版本安装向导，见[安装指南](installation.md)。已有实例使用自身可信`server/docker/scripts/update.sh`，先按APP发行说明明确`PEANUT_UPGRADE_PHP`绝对解释器与已经存在的私有workspace，再使用同一计划。它不执行首次安装器、生产`peanut upgrade`或不受控git pull。

人工和CI触发采用同一包、清单、摘要、计划、备份、受管替换、迁移、真实验证和恢复合同。固定Git取源生产更新尚需接通同一部署采用器并验收；Git能退版本不意味着数据库可以自动回退。

## 4. 来源与失败保护

取得来源后先核可信摘要与路径安全，再核APP/Edition/版本、逐文件清单和依赖。未知包旁自行计算的摘要不是可信来源。不要执行未认证目标包的更新入口，不复制其他APP的vendor或作无约束依赖更新。

恢复消费原计划和真实journal。激活前按实际阶段成对恢复程序、依赖、配置身份及必要数据；激活后不自动回灌旧数据库。失败、拒绝和UNKNOWN保留，不通过重建APP、删锁或改账本重置事实。

上游Core发布、候选封存、main/tag证明和资格工具在源码仓docs/maintenance中维护，不属于普通APP教程。本文不授予正式发行、生产、客户数据或费用操作权限。
