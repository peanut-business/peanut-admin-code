-- Generated declarative application schema; Core KernelSchema is created by install.php.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Durable application migration ledger. The 3.0 baseline is fresh-only, but
-- minor/patch releases may append migrations and apply them exactly once.
CREATE TABLE `pa_schema_migration` (
  `migration_id` VARCHAR(190) NOT NULL,
  `release_version` VARCHAR(32) NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `status` ENUM('applying','applied','failed') NOT NULL DEFAULT 'applying',
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  `error_code` VARCHAR(255) NULL,
  PRIMARY KEY (`migration_id`),
  KEY `idx_schema_migration_status` (`status`, `migration_id`),
  KEY `idx_schema_migration_release` (`release_version`, `migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用追加迁移账本';

CREATE TABLE `pa_system_menu` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pid`        INT UNSIGNED NOT NULL DEFAULT 0,
  `type`       CHAR(1)      NOT NULL DEFAULT 'C' COMMENT 'M目录 C菜单 A按钮',
  `name`       VARCHAR(50)  NOT NULL DEFAULT '',
  `icon`       VARCHAR(100) NOT NULL DEFAULT '',
  `sort`       SMALLINT     NOT NULL DEFAULT 0,
  `perms`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT '权限标识',
  `paths`      VARCHAR(200) NOT NULL DEFAULT '' COMMENT '路由路径',
  `component`  VARCHAR(200) NOT NULL DEFAULT '' COMMENT '前端组件',
  `is_cache`   TINYINT(1)   NOT NULL DEFAULT 0,
  `is_show`    TINYINT(1)   NOT NULL DEFAULT 1,
  `is_disable` TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统菜单';

CREATE TABLE `pa_operation_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `username`    VARCHAR(50)  NOT NULL DEFAULT '',
  `ip`          VARCHAR(50)  NOT NULL DEFAULT '',
  `uri`         VARCHAR(200) NOT NULL DEFAULT '',
  `method`      VARCHAR(10)  NOT NULL DEFAULT '',
  `request_id` VARCHAR(128) NOT NULL DEFAULT '',
  `params`      TEXT,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation_log_tenant_id` (`tenant_id`, `id`),
  KEY `idx_operation_log_tenant_created` (`tenant_id`, `create_time`, `id`),
  CONSTRAINT `fk_operation_log_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

CREATE TABLE `pa_config` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`        VARCHAR(30)  NOT NULL DEFAULT '',
  `name`        VARCHAR(60)  NOT NULL DEFAULT '',
  `value`       TEXT,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_name` (`type`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='实例级配置（仅 Platform/部署拥有）';

CREATE TABLE `pa_jobs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '岗位名称',
  `active_name` VARCHAR(50)
    GENERATED ALWAYS AS (IF(`delete_time` IS NULL, `name`, NULL)) STORED,
  `code`        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '岗位编码',
  `active_code` VARCHAR(64)
    GENERATED ALWAYS AS (IF(`delete_time` IS NULL, `code`, NULL)) STORED,
  `sort`        SMALLINT     NOT NULL DEFAULT 0  COMMENT '排序',
  `is_disable`  TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否禁用：0启用 1禁用',
  `status`      TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '状态：0停用 1正常',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `remark` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '备注',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_code` (`code`),
  UNIQUE KEY `uk_jobs_tenant_id` (`tenant_id`, `id`),
  KEY `idx_jobs_tenant_code` (`tenant_id`, `code`),
  CONSTRAINT `fk_jobs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  UNIQUE KEY `uk_jobs_tenant_active_name` (`tenant_id`, `active_name`),
  UNIQUE KEY `uk_jobs_tenant_active_code` (`tenant_id`, `active_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='岗位';

CREATE TABLE `pa_dict_type` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '字典名称',
  `type`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '字典类型（英文标识）',
  `is_disable`  TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否禁用：0启用 1禁用',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `active_type` VARCHAR(100)
    GENERATED ALWAYS AS (CASE WHEN `delete_time` IS NULL THEN `type` ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  UNIQUE KEY `uk_dict_type_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_dict_type_tenant_active_type` (`tenant_id`, `active_type`),
  KEY `idx_dict_type_tenant_status_name` (`tenant_id`, `is_disable`, `name`, `id`),
  CONSTRAINT `fk_dict_type_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典类型';

CREATE TABLE `pa_dict_data` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '数据名称',
  `value`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '数据值',
  `type_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '字典类型id',
  `type_value`  VARCHAR(100) NOT NULL DEFAULT '' COMMENT '冗余：字典类型标识（随类型编辑级联更新）',
  `sort`        SMALLINT     NOT NULL DEFAULT 0  COMMENT '排序',
  `is_disable`  TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否禁用：0启用 1禁用',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_id` (`type_id`),
  UNIQUE KEY `uk_dict_data_tenant_id` (`tenant_id`, `id`),
  KEY `idx_dict_data_tenant_type_status_sort`
    (`tenant_id`, `type_id`, `is_disable`, `sort`, `id`),
  CONSTRAINT `fk_dict_data_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_dict_data_tenant_type`
    FOREIGN KEY (`tenant_id`, `type_id`)
    REFERENCES `pa_dict_type` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典数据';

CREATE TABLE `pa_file_cate` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pid`         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '父级ID',
  `type`        TINYINT(2)   NOT NULL DEFAULT 10 COMMENT '类型：10图片 20视频 30文件',
  `name`        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '分类名称',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  UNIQUE KEY `uk_file_cate_tenant_id` (`tenant_id`, `id`),
  KEY `idx_file_cate_tenant_type_parent` (`tenant_id`, `type`, `pid`, `id`),
  CONSTRAINT `fk_file_cate_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件分类';

CREATE TABLE `pa_file` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cid`         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '分类ID',
  `source_id`   INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '上传者ID',
  `source`      TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '来源：0后台 1用户',
  `type`        TINYINT(2)   NOT NULL DEFAULT 10 COMMENT '类型：10图片 20视频 30文件',
  `name`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '文件名称',
  `uri`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT '相对路径',
  `storage`     VARCHAR(20)  NOT NULL DEFAULT 'local' COMMENT '存储引擎',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cid` (`cid`),
  KEY `idx_type` (`type`),
  KEY `idx_type_cid_source` (`type`,`cid`,`source`),
  UNIQUE KEY `uk_file_tenant_id` (`tenant_id`, `id`),
  KEY `idx_file_tenant_type_cid_source` (`tenant_id`, `type`, `cid`, `source`, `id`),
  CONSTRAINT `fk_file_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件';

CREATE TABLE `pa_crontab` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '任务名称',
  `type`        TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '类型：1定时任务',
  `command`     VARCHAR(100) NOT NULL DEFAULT '' COMMENT '命令（think console 命令名）',
  `params`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '命令参数（空格分隔）',
  `status`      TINYINT(1)   NOT NULL DEFAULT 2  COMMENT '状态：1运行 2停止 3错误',
  `expression`  VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'crontab 运行规则',
  `error`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最近一次错误信息',
  `last_time`   INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '最后执行时间',
  `time`        DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '最近一次耗时（秒）',
  `max_time`    DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '最大耗时（秒）',
  `sort`        INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '排序',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  UNIQUE KEY `uk_crontab_tenant_id` (`tenant_id`, `id`),
  KEY `idx_crontab_tenant_status_last` (`tenant_id`, `status`, `last_time`, `id`),
  CONSTRAINT `fk_crontab_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务';

CREATE TABLE `pa_member` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sn`          VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '会员编号',
  `account`     VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '用户账号',
  `account_unique` VARCHAR(50) GENERATED ALWAYS AS (NULLIF(`account`, '')) STORED,
  `password`    VARCHAR(100) NOT NULL DEFAULT '' COMMENT '用户密码',
  `nickname`    VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '头像',
  `real_name`   VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '真实姓名',
  `mobile`      VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '手机号',
  `channel`     TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '注册来源：1小程序 2公众号 3手机H5 4电脑PC 5苹果APP 6安卓APP',
  `email`       VARCHAR(100) NOT NULL DEFAULT '' COMMENT '邮箱',
  `sex`         TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '性别：0未知 1男 2女',
  `birthday`    DATE                  DEFAULT NULL COMMENT '生日',
  `status`      TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '状态：0禁用 1正常',
  `login_time`  INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '最后登录时间',
  `login_ip`    VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `is_new_user` TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否新用户：0否 1是',
  `user_money`  DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户可用余额',
  `total_recharge_amount` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计充值金额',
  `points`      INT UNSIGNED  NOT NULL DEFAULT 0  COMMENT '积分',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `mobile_unique` VARCHAR(20) GENERATED ALWAYS AS (NULLIF(`mobile`,'')) STORED,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account`),
  KEY `idx_mobile` (`mobile`),
  KEY `idx_status` (`status`),
  KEY `idx_channel` (`channel`),
  KEY `idx_create_time` (`create_time`),
  UNIQUE KEY `uk_member_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_member_tenant_sn` (`tenant_id`, `sn`),
  UNIQUE KEY `uk_member_tenant_account` (`tenant_id`, `account_unique`),
  UNIQUE KEY `uk_member_tenant_mobile` (`tenant_id`, `mobile_unique`),
  KEY `idx_member_tenant_status_channel` (`tenant_id`, `status`, `channel`, `id`),
  CONSTRAINT `fk_member_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员';

CREATE TABLE `pa_member_tag` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '标签名称',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_tag_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_member_tag_tenant_name` (`tenant_id`, `name`),
  KEY `idx_member_tag_tenant_live` (`tenant_id`, `delete_time`, `id`),
  CONSTRAINT `fk_member_tag_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员标签';

CREATE TABLE `pa_member_tag_relation` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `tag_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tag_id` (`tag_id`),
  UNIQUE KEY `uk_member_tag_relation_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_member_tag_relation_tenant_pair` (`tenant_id`, `member_id`, `tag_id`),
  KEY `idx_member_tag_relation_tenant_tag` (`tenant_id`, `tag_id`, `member_id`),
  CONSTRAINT `fk_member_tag_relation_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_tag_relation_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_tag_relation_tag` FOREIGN KEY (`tenant_id`, `tag_id`) REFERENCES `pa_member_tag` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员标签关联';

CREATE TABLE `pa_member_balance_log` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `sn`            VARCHAR(32)   NOT NULL DEFAULT '' COMMENT '流水号',
  `member_id`     INT UNSIGNED  NOT NULL DEFAULT 0  COMMENT '会员ID',
  `change_object` TINYINT(2) UNSIGNED NOT NULL DEFAULT 1 COMMENT '变动对象：1用户余额',
  `change_type`   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '变动类型：100/101/200/201',
  `action`        TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '动作：1增加 2减少',
  `left_amount`   DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0 COMMENT '变动后余额',
  `source_type`   TINYINT(2) NOT NULL DEFAULT 0 COMMENT 'Peanut旧字段',
  `extra`         TEXT NULL COMMENT '扩展数据JSON',
  `admin_id`      INT UNSIGNED  NOT NULL DEFAULT 0  COMMENT '操作管理员',
  `create_time`   INT UNSIGNED  NOT NULL DEFAULT 0,
  `update_time`   INT UNSIGNED  NULL DEFAULT NULL,
  `delete_time`   INT UNSIGNED  NULL DEFAULT NULL,
  `change_amount` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0 COMMENT '变动金额（无符号）',
  `source_sn` VARCHAR(255) NULL DEFAULT NULL COMMENT '来源单号',
  `source_sn_unique` VARCHAR(255) GENERATED ALWAYS AS (NULLIF(`source_sn`, '')) STORED,
  `remark` VARCHAR(255) NULL DEFAULT '' COMMENT '备注',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_change_type` (`change_type`),
  KEY `idx_create_time` (`create_time`),
  UNIQUE KEY `uk_member_balance_log_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_member_balance_log_tenant_sn` (`tenant_id`, `sn`),
  UNIQUE KEY `uk_member_balance_log_tenant_source` (`tenant_id`, `source_sn_unique`),
  KEY `idx_member_balance_log_tenant_member_time` (`tenant_id`, `member_id`, `create_time`, `id`),
  CONSTRAINT `fk_member_balance_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_balance_log_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员余额变动记录';

CREATE TABLE `pa_notice_template` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '模板名称',
  `code`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '模板标识（英文）',
  `channel`     TINYINT(2)   NOT NULL DEFAULT 1  COMMENT '渠道：1短信 2邮件 3推送',
  `title`       VARCHAR(200) NOT NULL DEFAULT '' COMMENT '标题（邮件/推送用）',
  `content`     TEXT                              COMMENT '内容（支持变量{xxx}）',
  `is_disable`  TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否禁用：0启用 1禁用',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_channel` (`channel`),
  UNIQUE KEY `uk_notice_template_tenant_code` (`tenant_id`, `code`),
  UNIQUE KEY `uk_notice_template_tenant_id` (`tenant_id`, `id`),
  KEY `idx_notice_template_tenant_channel` (`tenant_id`, `channel`, `is_disable`, `id`),
  CONSTRAINT `fk_notice_template_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知模板';

CREATE TABLE `pa_notice_scene` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(50) NOT NULL DEFAULT '' COMMENT '业务场景标识',
  `name`            VARCHAR(100) NOT NULL DEFAULT '' COMMENT '业务场景名称',
  `description`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '场景说明',
  `recipient`       VARCHAR(50) NOT NULL DEFAULT '用户' COMMENT '接收对象',
  `variables`       JSON NULL COMMENT '可用模板变量',
  `sms_template_id` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '服务商短信模板ID',
  `sms_content`     VARCHAR(500) NOT NULL DEFAULT '' COMMENT '短信内容模板',
  `sms_status`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '短信通知 0-关闭 1-开启',
  `create_time`     INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time`     INT UNSIGNED NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sms_status` (`sms_status`),
  UNIQUE KEY `uk_notice_scene_tenant_code` (`tenant_id`, `code`),
  UNIQUE KEY `uk_notice_scene_tenant_id` (`tenant_id`, `id`),
  KEY `idx_notice_scene_tenant_sms` (`tenant_id`, `sms_status`, `id`),
  CONSTRAINT `fk_notice_scene_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知业务场景';

CREATE TABLE `pa_notice_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '模板ID',
  `scene_id`    INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '业务场景ID',
  `channel`     TINYINT(2)   NOT NULL DEFAULT 1  COMMENT '渠道：1短信 2邮件 3推送',
  `provider`    VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '发送服务商',
  `receiver`    VARCHAR(200) NOT NULL DEFAULT '' COMMENT '接收者（手机号/邮箱/设备token）',
  `title`       VARCHAR(200) NOT NULL DEFAULT '' COMMENT '标题快照',
  `content`     TEXT                              COMMENT '内容快照（已替换变量）',
  `is_verified` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否已验证',
  `check_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '校验次数',
  `verified_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '验证时间',
  `status`      TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '状态：0待发 1成功 2失败',
  `error`       VARCHAR(500) NOT NULL DEFAULT '' COMMENT '错误信息',
  `extra`       TEXT                              COMMENT '额外数据（JSON）',
  `send_time`   INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '发送时间',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `verify_code_hash` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '验证码单向慢哈希',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_template_id` (`template_id`),
  KEY `idx_channel` (`channel`),
  KEY `idx_status` (`status`),
  KEY `idx_send_time` (`send_time`),
  UNIQUE KEY `uk_notice_log_tenant_id` (`tenant_id`, `id`),
  KEY `idx_notice_log_tenant_scene_receiver` (`tenant_id`, `scene_id`, `channel`, `receiver`, `status`, `send_time`, `id`),
  KEY `idx_notice_log_tenant_list` (`tenant_id`, `status`, `channel`, `send_time`, `id`),
  CONSTRAINT `fk_notice_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知发送记录';

CREATE TABLE `pa_hot_search` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(200)    NOT NULL DEFAULT '' COMMENT '搜索词',
  `sort`        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序（倒序）',
  `create_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hot_search_tenant_id` (`tenant_id`, `id`),
  KEY `idx_hot_search_tenant_sort` (`tenant_id`, `sort`, `id`),
  CONSTRAINT `fk_hot_search_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='热门搜索词';

CREATE TABLE `pa_article_cate` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `is_show`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否显示 0否1是',
  `create_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED    NULL     DEFAULT NULL,
  `name` VARCHAR(90) NOT NULL DEFAULT '' COMMENT '分类名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序（倒序）',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_article_cate_tenant_id` (`tenant_id`, `id`),
  KEY `idx_article_cate_tenant_visible` (`tenant_id`, `is_show`, `sort`, `id`),
  CONSTRAINT `fk_article_cate_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章分类';

CREATE TABLE `pa_article` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `desc`        VARCHAR(255)    NULL DEFAULT '' COMMENT '简介',
  `abstract`    TEXT            NULL COMMENT '文章摘要',
  `click_virtual` INT           NULL DEFAULT 0 COMMENT '虚拟浏览量',
  `click_actual`  INT           NULL DEFAULT 0 COMMENT '实际浏览量',
  `is_show`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否显示 0否1是',
  `create_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED    NULL     DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '文章标题',
  `author` VARCHAR(255) NULL DEFAULT '' COMMENT '作者',
  `content` TEXT NULL COMMENT '文章内容',
  `sort` INT NULL DEFAULT 0 COMMENT '排序（倒序）',
  `image` VARCHAR(2048) NULL DEFAULT NULL COMMENT '文章图片：local 相对 URI 或云/CDN 绝对 URL',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `cid` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文章分类',
  PRIMARY KEY (`id`),
  KEY `idx_cid` (`cid`),
  UNIQUE KEY `uk_article_tenant_id` (`tenant_id`, `id`),
  KEY `idx_article_tenant_visible_cate` (`tenant_id`, `is_show`, `cid`, `sort`, `id`),
  CONSTRAINT `fk_article_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_tenant_cate` FOREIGN KEY (`tenant_id`, `cid`) REFERENCES `pa_article_cate` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章';

CREATE TABLE `pa_article_collect` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员ID',
  `article_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文章ID',
  `status`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '收藏状态 0-未收藏 1-已收藏',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_id` (`member_id`),
  UNIQUE KEY `uk_article_collect_tenant_member_article` (`tenant_id`, `member_id`, `article_id`),
  KEY `idx_article_collect_tenant_member_status` (`tenant_id`, `member_id`, `status`, `id`),
  CONSTRAINT `fk_article_collect_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_collect_tenant_article` FOREIGN KEY (`tenant_id`, `article_id`) REFERENCES `pa_article` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_collect_tenant_member`
    FOREIGN KEY (`tenant_id`, `member_id`)
    REFERENCES `pa_member` (`tenant_id`, `id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章收藏';

CREATE TABLE `pa_recharge_order` (
  `id`                    INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `user_id`               INT UNSIGNED   NOT NULL DEFAULT 0 COMMENT '用户ID',
  `pay_status`            TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '支付状态：0未支付 1已支付',
  `order_amount`          DECIMAL(10,2)  NOT NULL DEFAULT 0 COMMENT '充值金额',
  `order_terminal`        TINYINT(1)     NULL DEFAULT 1 COMMENT '支付终端',
  `refund_status`         TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '是否已发起退款：0否 1是',
  `refund_transaction_id` VARCHAR(255)   NULL DEFAULT NULL COMMENT '退款交易流水号',
  `delete_time`           INT UNSIGNED   NULL DEFAULT NULL,
  `sn` VARCHAR(64) NOT NULL COMMENT '充值订单编号',
  `pay_way` TINYINT(2) NOT NULL DEFAULT 2 COMMENT '支付方式：1余额 2微信 3支付宝',
  `pay_time` INT UNSIGNED NULL DEFAULT NULL COMMENT '支付时间',
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  `pay_sn` VARCHAR(255) NULL DEFAULT NULL COMMENT '支付请求编号',
  `transaction_id` VARCHAR(128) NULL DEFAULT NULL COMMENT '第三方交易流水号',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_pay_status` (`pay_status`),
  KEY `idx_refund_status` (`refund_status`),
  KEY `idx_create_time` (`create_time`),
  UNIQUE KEY `uk_pay_sn` (`pay_sn`),
  UNIQUE KEY `uk_transaction_id` (`transaction_id`),
  UNIQUE KEY `uk_recharge_order_tenant_id` (`tenant_id`, `id`),
  KEY `idx_recharge_order_tenant_member_time` (`tenant_id`, `user_id`, `create_time`, `id`),
  CONSTRAINT `fk_recharge_order_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_recharge_order_member` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值订单';

CREATE TABLE `pa_refund_record` (
  `id`             INT          NOT NULL AUTO_INCREMENT,
  `order_type`     VARCHAR(255) DEFAULT 'order',
  `order_amount`   DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `refund_amount`  DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `transaction_id` VARCHAR(255) DEFAULT NULL,
  `refund_way`     TINYINT(1)   NOT NULL DEFAULT 1,
  `refund_type`    TINYINT(1)   NOT NULL DEFAULT 1,
  `refund_status`  TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `refund_msg`     TEXT         DEFAULT NULL,
  `create_time`    INT UNSIGNED DEFAULT 0,
  `update_time`    INT          DEFAULT NULL,
  `sn` VARCHAR(32) NOT NULL DEFAULT '',
  `order_sn` VARCHAR(64) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_user_id`      (`user_id`),
  KEY `idx_order_sn`     (`order_sn`),
  KEY `idx_refund_status`(`refund_status`),
  KEY `idx_create_time`  (`create_time`),
  UNIQUE KEY `uk_refund_record_tenant_id` (`tenant_id`, `id`),
  KEY `idx_refund_record_tenant_order_amount` (`tenant_id`, `order_type`, `order_id`, `refund_amount`, `id`),
  KEY `idx_refund_record_tenant_status_time` (`tenant_id`, `refund_status`, `create_time`, `id`),
  CONSTRAINT `fk_refund_record_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_record_member` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_record_order` FOREIGN KEY (`tenant_id`, `order_id`) REFERENCES `pa_recharge_order` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退款记录';

CREATE TABLE `pa_generator_table` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '配置所有者管理员ID',
  `table_name`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '数据库物理表名',
  `table_comment` VARCHAR(300) NOT NULL DEFAULT '' COMMENT '表说明',
  `module_name`   VARCHAR(32)  NOT NULL DEFAULT 'generated' COMMENT '生成模块名',
  `entity_name`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '生成实体类名',
  `template_type` VARCHAR(10)  NOT NULL DEFAULT 'crud' COMMENT '模板类型 crud/tree',
  `author`         VARCHAR(100) NOT NULL DEFAULT '' COMMENT '作者',
  `tree_config`   JSON NULL COMMENT '树结构字段配置',
  `relations`     JSON NULL COMMENT '模型关系配置',
  `create_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_table` (`admin_id`,`table_name`),
  KEY `idx_owner_update` (`admin_id`,`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安全代码生成器表配置';

CREATE TABLE `pa_generator_column` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `table_id`       INT UNSIGNED NOT NULL DEFAULT 0,
  `column_name`    VARCHAR(64)  NOT NULL DEFAULT '',
  `column_comment` VARCHAR(300) NOT NULL DEFAULT '',
  `column_type`    VARCHAR(100) NOT NULL DEFAULT '',
  `php_type`       VARCHAR(20)  NOT NULL DEFAULT 'string',
  `is_required`    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_pk`          TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_insert`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_update`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_lists`       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_query`       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `query_type`     VARCHAR(20) NOT NULL DEFAULT '=',
  `view_type`      VARCHAR(20) NOT NULL DEFAULT 'input',
  `dict_type`      VARCHAR(100) NOT NULL DEFAULT '',
  `sort`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `create_time`    INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_table_column` (`table_id`,`column_name`),
  KEY `idx_table_sort` (`table_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安全代码生成器字段配置';

CREATE TABLE `pa_generator_download` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '令牌所有者管理员ID',
  `token_hash`    CHAR(64) NOT NULL DEFAULT '' COMMENT '下载令牌SHA-256',
  `archive_path`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '生成根目录内相对路径',
  `download_name` VARCHAR(120) NOT NULL DEFAULT '',
  `expire_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  `used_time`     INT UNSIGNED NOT NULL DEFAULT 0,
  `create_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_owner_expire` (`admin_id`,`expire_time`,`used_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代码生成器一次性下载令牌';

CREATE TABLE `pa_decorate_page` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` tinyint unsigned NOT NULL COMMENT '1首页 2个人中心 3客服 4PC首页 5系统风格',
  `name` varchar(100) NOT NULL DEFAULT '',
  `data` longtext NOT NULL,
  `meta` longtext NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decorate_page_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_decorate_page_tenant_type` (`tenant_id`, `type`),
  CONSTRAINT `fk_decorate_page_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='装修页面';

CREATE TABLE `pa_decorate_tabbar` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `position` tinyint unsigned NOT NULL,
  `name` varchar(20) NOT NULL DEFAULT '',
  `link` varchar(1000) NOT NULL DEFAULT '{}',
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `selected` VARCHAR(2048) NOT NULL DEFAULT '' COMMENT '选中图标：local 相对 URI 或云/CDN 绝对 URL',
  `unselected` VARCHAR(2048) NOT NULL DEFAULT '' COMMENT '未选图标：local 相对 URI 或云/CDN 绝对 URL',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decorate_tabbar_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_decorate_tabbar_tenant_position` (`tenant_id`, `position`),
  CONSTRAINT `fk_decorate_tabbar_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='装修 Tabbar';

CREATE TABLE `pa_official_account_reply` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '规则名称',
  `keyword` varchar(255) NOT NULL DEFAULT '' COMMENT '关键词',
  `reply_type` tinyint unsigned NOT NULL COMMENT '1关注 2关键词 3默认',
  `matching_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1全匹配 2模糊匹配',
  `content_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1文本',
  `content` text NOT NULL COMMENT '回复内容',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '0停用 1启用',
  `sort` int unsigned NOT NULL DEFAULT 0 COMMENT '匹配优先级，升序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `singleton_active_key` tinyint unsigned GENERATED ALWAYS AS (
    CASE
      WHEN `delete_time` = 0 AND `status` = 1 AND `reply_type` IN (1, 3) THEN `reply_type`
      ELSE NULL
    END
  ) STORED,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_oa_reply_state` (`reply_type`,`status`,`delete_time`,`sort`,`id`),
  UNIQUE KEY `uk_oa_reply_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_oa_reply_tenant_singleton_active` (`tenant_id`,`singleton_active_key`),
  KEY `idx_oa_reply_tenant_state` (`tenant_id`,`reply_type`,`status`,`delete_time`,`sort`,`id`),
  CONSTRAINT `fk_oa_reply_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='微信公众号自动回复';

CREATE TABLE `pa_payment_scene` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `terminal`    TINYINT UNSIGNED NOT NULL COMMENT '终端：1小程序 2公众号 3H5 4PC 5iOS 6Android',
  `pay_way`     TINYINT UNSIGNED NOT NULL COMMENT '支付渠道：2微信 3支付宝',
  `status`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '状态：0关闭 1开启',
  `is_default`  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否默认：0否 1是',
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_terminal_pay_way` (`terminal`,`pay_way`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值终端支付场景';

CREATE TABLE `pa_oauth_principal` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`    VARCHAR(32) NOT NULL COMMENT '提供商',
  `union_scope` VARCHAR(64) NOT NULL COMMENT '联合身份作用域',
  `union_id`    VARCHAR(191) NOT NULL COMMENT '联合身份',
  `member_id`   INT UNSIGNED NOT NULL COMMENT '会员ID',
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_id` (`member_id`),
  UNIQUE KEY `uk_oauth_principal_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_oauth_principal_tenant_union` (`tenant_id`, `provider`, `union_scope`, `union_id`),
  KEY `idx_oauth_principal_tenant_member` (`tenant_id`, `member_id`),
  CONSTRAINT `fk_oauth_principal_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_principal_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuth联合身份归属';

CREATE TABLE `pa_oauth_identity` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`     VARCHAR(32) NOT NULL COMMENT '提供商',
  `client_key`   VARCHAR(64) NOT NULL COMMENT '客户端配置标识',
  `subject`      VARCHAR(191) NOT NULL COMMENT '客户端内外部身份',
  `principal_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '联合身份ID',
  `member_id`    INT UNSIGNED NOT NULL COMMENT '会员ID',
  `terminal`     TINYINT UNSIGNED NOT NULL COMMENT '业务终端',
  `create_time`  INT UNSIGNED NULL DEFAULT NULL,
  `update_time`  INT UNSIGNED NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_terminal` (`member_id`,`terminal`),
  KEY `idx_principal_id` (`principal_id`),
  UNIQUE KEY `uk_oauth_identity_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_oauth_identity_tenant_subject` (`tenant_id`, `provider`, `client_key`, `subject`),
  UNIQUE KEY `uk_oauth_identity_tenant_member_client` (`tenant_id`, `member_id`, `provider`, `client_key`),
  KEY `idx_oauth_identity_tenant_member_terminal` (`tenant_id`, `member_id`, `terminal`),
  KEY `idx_oauth_identity_tenant_principal` (`tenant_id`, `principal_id`),
  CONSTRAINT `fk_oauth_identity_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_identity_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_identity_principal` FOREIGN KEY (`tenant_id`, `principal_id`) REFERENCES `pa_oauth_principal` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuth客户端身份';

CREATE TABLE `pa_oauth_attempt` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `state_hash`  CHAR(64) NOT NULL COMMENT 'state SHA-256',
  `scene`       VARCHAR(32) NOT NULL COMMENT 'oa/open_pc',
  `return_path` VARCHAR(500) NOT NULL DEFAULT '/' COMMENT '站内返回路径',
  `expires_at`  INT UNSIGNED NOT NULL,
  `used_at`     INT UNSIGNED NULL DEFAULT NULL,
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expires_at` (`expires_at`),
  UNIQUE KEY `uk_oauth_attempt_tenant_state` (`tenant_id`, `state_hash`),
  KEY `idx_oauth_attempt_tenant_expires` (`tenant_id`, `expires_at`),
  CONSTRAINT `fk_oauth_attempt_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuth一次性state';

CREATE TABLE `pa_tenant_setting` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `namespace` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `config_json` JSON NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_setting_namespace` (`tenant_id`, `namespace`),
  CONSTRAINT `fk_tenant_setting_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_tenant_setting_namespace`
    CHECK (REGEXP_LIKE(`namespace`, '^[a-z][a-z0-9.-]{0,63}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Tenant-owned application capability settings';

CREATE TABLE `pa_tenant_entry_binding` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `host` VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_entry_binding` (`host`, `client_key`),
  KEY `idx_tenant_entry_lookup` (`host`, `client_key`, `status`, `tenant_id`),
  CONSTRAINT `fk_tenant_entry_binding_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_entry_binding_status`
    CHECK (`status` IN ('active', 'disabled')),
  CONSTRAINT `chk_tenant_entry_binding_host`
    CHECK (`host` = LOWER(`host`) AND CHAR_LENGTH(`host`) BETWEEN 1 AND 253),
  CONSTRAINT `chk_tenant_entry_binding_client`
    CHECK (REGEXP_LIKE(`client_key`, '^[a-z][a-z0-9-]{0,63}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant_owner_invitation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `email_normalized` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(120) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `delivery_status` VARCHAR(32) NOT NULL DEFAULT 'pending_delivery',
  `delivery_provider` VARCHAR(64) NULL,
  `delivery_message_id` VARCHAR(190) NULL,
  `delivery_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `delivery_error_code` VARCHAR(96) NULL,
  `last_delivery_at` DATETIME(3) NULL,
  `generation` INT UNSIGNED NOT NULL DEFAULT 1,
  `expires_at` DATETIME(3) NOT NULL,
  `accepted_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `accepted_account_id` BIGINT UNSIGNED NULL,
  `accepted_member_id` BIGINT UNSIGNED NULL,
  `invited_by_operator_id` BIGINT UNSIGNED NOT NULL,
  `revoked_by_operator_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `pending_tenant_id` BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN `status` = 'pending' THEN `tenant_id` ELSE NULL END
  ) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_invitation_token_hash` (`token_hash`),
  UNIQUE KEY `uk_owner_invitation_pending_tenant` (`pending_tenant_id`),
  KEY `idx_owner_invitation_tenant_time` (`tenant_id`, `created_at`, `id`),
  KEY `idx_owner_invitation_status_expiry` (`status`, `expires_at`, `id`),
  CONSTRAINT `fk_owner_invitation_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_account` FOREIGN KEY (`accepted_account_id`)
    REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_member` FOREIGN KEY (`tenant_id`, `accepted_member_id`)
    REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_inviter` FOREIGN KEY (`invited_by_operator_id`)
    REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_revoker` FOREIGN KEY (`revoked_by_operator_id`)
    REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_owner_invitation_status` CHECK (
    `status` IN ('pending', 'accepted', 'revoked', 'expired')
  ),
  CONSTRAINT `chk_owner_invitation_delivery_status` CHECK (
    `delivery_status` IN ('pending_delivery', 'sent', 'failed')
  ),
  CONSTRAINT `chk_owner_invitation_accepted` CHECK (
    (`status` = 'accepted' AND `accepted_at` IS NOT NULL
      AND `accepted_account_id` IS NOT NULL AND `accepted_member_id` IS NOT NULL)
    OR (`status` <> 'accepted' AND `accepted_at` IS NULL
      AND `accepted_account_id` IS NULL AND `accepted_member_id` IS NULL)
  ),
  CONSTRAINT `chk_owner_invitation_revoked` CHECK (
    (`status` = 'revoked' AND `revoked_at` IS NOT NULL AND `revoked_by_operator_id` IS NOT NULL)
    OR (`status` <> 'revoked' AND `revoked_at` IS NULL AND `revoked_by_operator_id` IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant_idempotency_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `tenant_member_id` BIGINT UNSIGNED NOT NULL,
  `operation_key` VARCHAR(160) NOT NULL,
  `idempotency_key_hash` CHAR(64) NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `response_status` SMALLINT UNSIGNED NULL,
  `response_body_json` JSON NULL,
  `resource_type` VARCHAR(160) NULL,
  `resource_id` VARCHAR(128) NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`, `tenant_member_id`, `operation_key`, `idempotency_key_hash`),
  KEY `idx_tenant_idempotency_expiry` (`expires_at`, `status`, `id`),
  CONSTRAINT `fk_tenant_idempotency_member`
    FOREIGN KEY (`tenant_id`, `tenant_member_id`)
    REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_idempotency_status`
    CHECK (`status` IN ('processing','completed','failed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_system_dict_type` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(100) NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `is_disable` TINYINT(1) NOT NULL DEFAULT 0,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_dict_type_code` (`code`),
  KEY `idx_system_dict_type_status` (`is_disable`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统只读字典类型';

CREATE TABLE `pa_system_dict_data` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type_code` VARCHAR(100) NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `value` VARCHAR(255) NOT NULL DEFAULT '',
  `sort` SMALLINT NOT NULL DEFAULT 0,
  `is_disable` TINYINT(1) NOT NULL DEFAULT 0,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_dict_data_type_value` (`type_code`, `value`),
  KEY `idx_system_dict_data_lookup` (`type_code`, `is_disable`, `sort`, `id`),
  CONSTRAINT `fk_system_dict_data_type`
    FOREIGN KEY (`type_code`) REFERENCES `pa_system_dict_type` (`code`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统只读字典数据';

CREATE TABLE `pa_customer_service_setting` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    BIGINT UNSIGNED NOT NULL,
  `qr_file_id`   INT UNSIGNED NULL DEFAULT NULL,
  `wechat`       VARCHAR(100) NOT NULL DEFAULT '',
  `phone`        VARCHAR(50) NOT NULL DEFAULT '',
  `service_time` VARCHAR(100) NOT NULL DEFAULT '',
  `create_time`  INT UNSIGNED NULL DEFAULT NULL,
  `update_time`  INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_service_setting_tenant` (`tenant_id`),
  KEY `idx_customer_service_setting_tenant_qr` (`tenant_id`, `qr_file_id`),
  CONSTRAINT `fk_customer_service_setting_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_customer_service_setting_qr_file` FOREIGN KEY (`tenant_id`, `qr_file_id`) REFERENCES `pa_file` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tenant客服联系方式';

CREATE TABLE `pa_decorate_tabbar_setting` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `style` LONGTEXT NOT NULL,
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decorate_tabbar_setting_tenant` (`tenant_id`),
  CONSTRAINT `chk_decorate_tabbar_setting_style` CHECK (JSON_VALID(`style`) AND JSON_TYPE(`style`) = 'OBJECT'),
  CONSTRAINT `fk_decorate_tabbar_setting_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tenant 装修 Tabbar 样式';

CREATE TABLE `pa_task_job` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `task_type` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `handler_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` JSON NOT NULL,
  `payload_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `trusted_envelope` TEXT NOT NULL,
  `idempotency_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL,
  `available_at` DATETIME(3) NOT NULL,
  `lease_owner_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `lease_token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `lease_expires_at` DATETIME(3) NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_job_key` (`job_key`),
  UNIQUE KEY `uk_task_job_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_task_job_idempotency` (`tenant_id`, `created_by_member_id`, `task_type`, `idempotency_key_hash`),
  KEY `idx_task_job_claim` (`tenant_id`, `status`, `available_at`, `priority`, `id`),
  KEY `idx_task_job_lease` (`status`, `lease_expires_at`, `id`),
  CONSTRAINT `fk_task_job_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_task_job_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_job_key` CHECK (`job_key` REGEXP '^job_[0-9a-f]{32}$'),
  CONSTRAINT `chk_task_job_payload_hash` CHECK (`payload_hash` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_task_job_status` CHECK (`status` IN ('queued','running','succeeded','dead','cancelled')),
  CONSTRAINT `chk_task_job_attempts` CHECK (`max_attempts` BETWEEN 1 AND 10 AND `attempt_count` <= `max_attempts`),
  CONSTRAINT `chk_task_job_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_task_job_lease` CHECK ((`status` = 'running') = (`lease_owner_hash` IS NOT NULL AND `lease_token_hash` IS NOT NULL AND `lease_expires_at` IS NOT NULL)),
  CONSTRAINT `chk_task_job_completion` CHECK ((`status` IN ('succeeded','dead','cancelled')) = (`completed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_task_job_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `job_id` BIGINT UNSIGNED NOT NULL,
  `attempt_number` SMALLINT UNSIGNED NOT NULL,
  `worker_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `started_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_attempt_number` (`tenant_id`, `job_id`, `attempt_number`),
  KEY `idx_task_attempt_status` (`tenant_id`, `status`, `started_at`, `id`),
  CONSTRAINT `fk_task_attempt_job` FOREIGN KEY (`tenant_id`, `job_id`) REFERENCES `pa_task_job` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_attempt_status` CHECK (`status` IN ('running','succeeded','retry','dead','abandoned')),
  CONSTRAINT `chk_task_attempt_completion` CHECK ((`status` = 'running') = (`completed_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_task_job_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `job_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `metadata_json` JSON NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_event_job` (`tenant_id`, `job_id`, `id`),
  KEY `idx_task_event_time` (`tenant_id`, `occurred_at`, `id`),
  CONSTRAINT `fk_task_event_job` FOREIGN KEY (`tenant_id`, `job_id`) REFERENCES `pa_task_job` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_task_event_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_event_key` CHECK (`event_key` REGEXP '^tenant\\.task\\.[a-z_]+$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_import_export_operation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `operation_key` VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `provider_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `direction` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `format` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'csv',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  `input_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `result_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `error_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `task_job_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `schema_revision` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mapping_json` JSON NOT NULL,
  `processed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `accepted_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `rejected_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `attempt_number` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `idempotency_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `retention_until` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_import_export_key` (`operation_key`),
  UNIQUE KEY `uk_import_export_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_import_export_idempotency` (`tenant_id`, `created_by_member_id`, `direction`, `provider_key`, `idempotency_key_hash`),
  KEY `idx_import_export_status` (`tenant_id`, `status`, `id`),
  KEY `idx_import_export_task` (`tenant_id`, `task_job_key`),
  KEY `idx_import_export_retention` (`status`, `retention_until`, `id`),
  CONSTRAINT `fk_import_export_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_import_export_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_import_export_key` CHECK (`operation_key` REGEXP '^iox_[0-9a-f]{32}$'),
  CONSTRAINT `chk_import_export_direction` CHECK (`direction` IN ('import','export')),
  CONSTRAINT `chk_import_export_format` CHECK (`format` = 'csv'),
  CONSTRAINT `chk_import_export_status` CHECK (`status` IN ('queued','running','cancel_requested','succeeded','failed','cancelled','expired')),
  CONSTRAINT `chk_import_export_input` CHECK ((`direction` = 'import') = (`input_file_key` IS NOT NULL)),
  CONSTRAINT `chk_import_export_files` CHECK ((`result_file_key` IS NULL OR `result_file_key` REGEXP '^file_[0-9a-f]{32}$') AND (`error_file_key` IS NULL OR `error_file_key` REGEXP '^file_[0-9a-f]{32}$')),
  CONSTRAINT `chk_import_export_task` CHECK (`task_job_key` IS NULL OR `task_job_key` REGEXP '^job_[0-9a-f]{32}$'),
  CONSTRAINT `chk_import_export_hashes` CHECK (`idempotency_key_hash` REGEXP '^[0-9a-f]{64}$' AND `request_hash` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_import_export_progress` CHECK (`accepted_rows` + `rejected_rows` <= `processed_rows` AND `processed_rows` <= 100000 AND `total_rows` <= 100000),
  CONSTRAINT `chk_import_export_attempt` CHECK (`attempt_number` <= 10),
  CONSTRAINT `chk_import_export_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_import_export_completion` CHECK ((`status` IN ('succeeded','failed','cancelled','expired')) = (`completed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_import_export_row_error` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `operation_id` BIGINT UNSIGNED NOT NULL,
  `row_number` INT UNSIGNED NOT NULL,
  `column_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `column_key_unique` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (COALESCE(`column_key`, '')) STORED,
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_import_export_row_error` (`tenant_id`, `operation_id`, `row_number`, `column_key_unique`, `error_code`),
  KEY `idx_import_export_row_error` (`tenant_id`, `operation_id`, `row_number`, `id`),
  CONSTRAINT `fk_import_export_row_operation` FOREIGN KEY (`tenant_id`, `operation_id`) REFERENCES `pa_import_export_operation` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_import_export_row_number` CHECK (`row_number` >= 2 AND `row_number` <= 100001),
  CONSTRAINT `chk_import_export_column` CHECK (`column_key` IS NULL OR `column_key` REGEXP '^[a-z][a-z0-9_]{0,63}$'),
  CONSTRAINT `chk_import_export_error_code` CHECK (`error_code` REGEXP '^[A-Z][A-Z0-9_]{2,63}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_file_object` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `storage_provider_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `storage_key` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ready',
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_object_key` (`file_key`),
  UNIQUE KEY `uk_file_object_storage` (`tenant_id`, `storage_provider_key`, `storage_key`),
  KEY `idx_file_object_status` (`tenant_id`, `status`, `id`),
  KEY `idx_file_object_sha256` (`tenant_id`, `sha256`),
  CONSTRAINT `fk_file_object_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_object_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_object_key` CHECK (`file_key` REGEXP '^file_[0-9a-f]{32}$'),
  CONSTRAINT `chk_file_object_sha256` CHECK (`sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_file_object_status` CHECK (`status` IN ('ready', 'archived')),
  CONSTRAINT `chk_file_object_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_file_object_archive_shape` CHECK ((`status` = 'ready' AND `archived_at` IS NULL) OR (`status` = 'archived' AND `archived_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_module_installation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(96) NOT NULL,
  `installed_version` VARCHAR(32) NOT NULL,
  `manifest_schema_version` INT UNSIGNED NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `status` VARCHAR(24) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `installed_at` DATETIME(3) NULL,
  `activated_at` DATETIME(3) NULL,
  `upgraded_at` DATETIME(3) NULL,
  `last_error_code` VARCHAR(96) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_installation_key` (`module_key`),
  KEY `idx_module_installation_status` (`status`, `module_key`),
  CONSTRAINT `chk_module_installation_status` CHECK (`status` IN ('installing','active','upgrading','maintenance','failed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_transaction_setting` (
  `id`                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`                    BIGINT UNSIGNED NOT NULL,
  `cancel_unpaid_orders`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `cancel_unpaid_orders_times`   INT UNSIGNED NOT NULL DEFAULT 30,
  `verification_orders`          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `verification_orders_times`    INT UNSIGNED NOT NULL DEFAULT 24,
  `create_time`                  INT UNSIGNED NULL DEFAULT NULL,
  `update_time`                  INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transaction_setting_tenant` (`tenant_id`),
  CONSTRAINT `chk_transaction_setting_cancel_mode` CHECK (`cancel_unpaid_orders` IN (0, 1)),
  CONSTRAINT `chk_transaction_setting_cancel_time` CHECK (`cancel_unpaid_orders_times` > 0),
  CONSTRAINT `chk_transaction_setting_verify_mode` CHECK (`verification_orders` IN (0, 1)),
  CONSTRAINT `chk_transaction_setting_verify_time` CHECK (`verification_orders_times` > 0),
  CONSTRAINT `fk_transaction_setting_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tenant交易运营政策';

CREATE TABLE `pa_external_channel_binding` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `provider` VARCHAR(64) NOT NULL,
  `callback_key` CHAR(64) NOT NULL COMMENT 'server generated opaque callback route key',
  `identity_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of canonical public provider identity',
  `identity_hint` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'non-secret audit/display hint',
  `config_json` JSON NOT NULL COMMENT 'server-controlled provider verifier/transport config',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_external_callback_key` (`provider`, `callback_key`),
  UNIQUE KEY `uk_external_provider_identity` (`provider`, `identity_hash`),
  UNIQUE KEY `uk_external_tenant_provider` (`tenant_id`, `provider`),
  KEY `idx_external_tenant_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_external_binding_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tenant-owned external provider binding and callback verifier configuration';

CREATE TABLE `pa_plugin_installation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_key` VARCHAR(96) NOT NULL,
  `installed_version` VARCHAR(32) NOT NULL,
  `source` VARCHAR(255) NOT NULL,
  `artifact_sha256` CHAR(64) NOT NULL,
  `lock_digest` CHAR(64) NOT NULL,
  `composer_identity_json` JSON NOT NULL,
  `npm_identity_json` JSON NOT NULL,
  `frontend_identity_json` JSON NOT NULL,
  `status` VARCHAR(24) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `installed_at` DATETIME(3) NULL,
  `activated_at` DATETIME(3) NULL,
  `upgraded_at` DATETIME(3) NULL,
  `uninstalled_at` DATETIME(3) NULL,
  `last_error_code` VARCHAR(96) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_installation_key` (`plugin_key`),
  KEY `idx_plugin_installation_status` (`status`, `plugin_key`),
  CONSTRAINT `chk_plugin_installation_status` CHECK (`status` IN (
    'installing','active','upgrading','maintenance','failed','uninstalled'
  ))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_plugin_module` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_key` VARCHAR(96) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `module_version` VARCHAR(32) NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_module_key` (`module_key`),
  KEY `idx_plugin_module_plugin` (`plugin_key`, `module_key`),
  CONSTRAINT `fk_plugin_module_plugin` FOREIGN KEY (`plugin_key`)
    REFERENCES `pa_plugin_installation` (`plugin_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_module_migration` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(96) NOT NULL,
  `migration_key` VARCHAR(160) NOT NULL,
  `module_version` VARCHAR(32) NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `batch_no` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(24) NOT NULL,
  `started_at` DATETIME(3) NOT NULL,
  `finished_at` DATETIME(3) NULL,
  `error_code` VARCHAR(96) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_migration` (`module_key`, `migration_key`),
  KEY `idx_module_migration_batch` (`batch_no`, `status`),
  CONSTRAINT `chk_module_migration_status` CHECK (`status` IN (
    'applying','applied','rolled_back','failed'
  ))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_protected_resource` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `ownership` VARCHAR(32) NOT NULL,
  `provider_key` VARCHAR(160) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_version` VARCHAR(32) NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `retired_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_protected_resource_key` (`key`),
  KEY `idx_protected_resource_module` (`module_key`, `status`),
  CONSTRAINT `chk_protected_resource_ownership` CHECK (`ownership` IN ('tenant_owned', 'business_target_owned', 'shared_master', 'global_reference', 'platform_internal')),
  CONSTRAINT `chk_protected_resource_status` CHECK (`status` IN ('active', 'retired')),
  CONSTRAINT `chk_protected_resource_retired` CHECK ((`status` = 'retired' AND `retired_at` IS NOT NULL) OR `status` <> 'retired')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_target_type` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `resolver_key` VARCHAR(160) NOT NULL,
  `catalog_provider_key` VARCHAR(160) NOT NULL,
  `id_format` VARCHAR(16) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_version` VARCHAR(32) NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_target_type_key` (`key`),
  KEY `idx_target_type_module` (`module_key`, `status`),
  CONSTRAINT `chk_target_type_id_format` CHECK (`id_format` IN ('decimal', 'uuid', 'ulid', 'string')),
  CONSTRAINT `chk_target_type_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_resource_operation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `protected_resource_id` BIGINT UNSIGNED NOT NULL,
  `operation` VARCHAR(64) NOT NULL,
  `access_mode` VARCHAR(32) NOT NULL,
  `target_cardinality` VARCHAR(32) NOT NULL,
  `permission_match` VARCHAR(8) NOT NULL DEFAULT 'all',
  `audit_level` VARCHAR(32) NOT NULL DEFAULT 'deny_and_write',
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation` (`protected_resource_id`, `operation`),
  CONSTRAINT `fk_resource_operation_resource` FOREIGN KEY (`protected_resource_id`)
    REFERENCES `pa_protected_resource` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_resource_operation_access` CHECK (`access_mode` IN ('tenant_wide', 'rule_filtered', 'explicit_targets', 'global_reference_read', 'system_internal')),
  CONSTRAINT `chk_resource_operation_cardinality` CHECK (`target_cardinality` IN ('none', 'one_required', 'many_readable', 'aggregate_read', 'policy_publish', 'bulk_write')),
  CONSTRAINT `chk_resource_operation_permission_match` CHECK (`permission_match` IN ('all', 'any')),
  CONSTRAINT `chk_resource_operation_audit` CHECK (`audit_level` IN ('deny', 'write', 'deny_and_write', 'all')),
  CONSTRAINT `chk_resource_operation_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_resource_operation_target_type` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_operation_id` BIGINT UNSIGNED NOT NULL,
  `target_type_id` BIGINT UNSIGNED NOT NULL,
  `target_role` VARCHAR(64) NOT NULL DEFAULT 'primary',
  `input_mode` VARCHAR(16) NOT NULL DEFAULT 'explicit',
  `policy_selection_permission_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation_target_type` (`resource_operation_id`, `target_role`, `target_type_id`),
  CONSTRAINT `fk_operation_target_operation` FOREIGN KEY (`resource_operation_id`)
    REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_target_type` FOREIGN KEY (`target_type_id`)
    REFERENCES `pa_target_type` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_target_selection_permission` FOREIGN KEY (`policy_selection_permission_id`)
    REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_operation_target_input` CHECK (`input_mode` IN ('explicit', 'derived', 'either')),
  CONSTRAINT `chk_operation_target_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_resource_operation_permission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_operation_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation_permission` (`resource_operation_id`, `permission_id`),
  CONSTRAINT `fk_operation_permission_operation` FOREIGN KEY (`resource_operation_id`)
    REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_permission_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_data_condition_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `category` VARCHAR(32) NOT NULL,
  `target_mode` VARCHAR(32) NOT NULL,
  `config_schema_json` JSON NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_version` VARCHAR(32) NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_condition_key` (`key`),
  CONSTRAINT `chk_data_condition_category` CHECK (`category` IN ('tenant', 'self', 'department', 'selected', 'relation')),
  CONSTRAINT `chk_data_condition_target_mode` CHECK (`target_mode` IN ('none', 'department', 'resource')),
  CONSTRAINT `chk_data_condition_definition_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_resource_operation_condition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_operation_id` BIGINT UNSIGNED NOT NULL,
  `condition_definition_id` BIGINT UNSIGNED NOT NULL,
  `selector_resource_key` VARCHAR(160) NULL,
  `selector_resource_key_norm` VARCHAR(160) GENERATED ALWAYS AS (COALESCE(`selector_resource_key`, '')) STORED,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation_condition` (`resource_operation_id`, `condition_definition_id`, `selector_resource_key_norm`),
  CONSTRAINT `fk_operation_condition_operation` FOREIGN KEY (`resource_operation_id`)
    REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_condition_definition` FOREIGN KEY (`condition_definition_id`)
    REFERENCES `pa_data_condition_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_operation_condition_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_menu_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `scope` VARCHAR(16) NOT NULL,
  `parent_key` VARCHAR(160) NULL,
  `type` VARCHAR(16) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `route_name` VARCHAR(160) NULL,
  `route_path` VARCHAR(255) NULL,
  `component_key` VARCHAR(160) NULL,
  `icon` VARCHAR(64) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `required_permission_id` BIGINT UNSIGNED NULL,
  `client_keys_json` JSON NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_definition_key` (`key`),
  UNIQUE KEY `uk_menu_route_name` (`scope`, `route_name`),
  KEY `idx_menu_module` (`module_key`, `scope`, `status`, `sort_order`),
  CONSTRAINT `fk_menu_permission` FOREIGN KEY (`required_permission_id`)
    REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_menu_scope` CHECK (`scope` IN ('platform','tenant')),
  CONSTRAINT `chk_menu_type` CHECK (`type` IN ('group','page','link')),
  CONSTRAINT `chk_menu_status` CHECK (`status` IN ('active','retired')),
  CONSTRAINT `chk_menu_page` CHECK (`type` <> 'page' OR (
    `route_name` IS NOT NULL AND `component_key` IS NOT NULL
    AND `required_permission_id` IS NOT NULL
  ))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_setting_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `setting_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `schema_json` JSON NOT NULL,
  `required_flag` TINYINT UNSIGNED NOT NULL,
  `secret_flag` TINYINT UNSIGNED NOT NULL,
  `deployment_scope_flag` TINYINT UNSIGNED NOT NULL,
  `tenant_scope_flag` TINYINT UNSIGNED NOT NULL,
  `target_scope_flag` TINYINT UNSIGNED NOT NULL,
  `target_resource_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `target_operation` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `default_json` JSON NULL,
  `definition_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_definition` (`module_key`, `setting_key`),
  CONSTRAINT `chk_setting_definition_required` CHECK (`required_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_secret` CHECK (`secret_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_deployment_scope` CHECK (`deployment_scope_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_tenant_scope` CHECK (`tenant_scope_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_target_scope` CHECK (`target_scope_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_target` CHECK ((
    `target_scope_flag` = 1 AND `target_resource_key` IS NOT NULL
    AND `target_operation` IS NOT NULL
  ) OR (
    `target_scope_flag` = 0 AND `target_resource_key` IS NULL
    AND `target_operation` IS NULL
  )),
  CONSTRAINT `chk_setting_definition_default` CHECK (`secret_flag` = 0 OR `default_json` IS NULL),
  CONSTRAINT `chk_setting_definition_status` CHECK (`status` IN ('active','retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_setting_deployment_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `value_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` JSON NULL,
  `ciphertext` VARBINARY(8192) NULL,
  `nonce` BINARY(24) NULL,
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `updated_by_operator_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_deployment_value` (`definition_id`),
  CONSTRAINT `fk_setting_deployment_definition` FOREIGN KEY (`definition_id`)
    REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_deployment_operator` FOREIGN KEY (`updated_by_operator_id`)
    REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_deployment_state` CHECK (`value_state` IN ('set','unset')),
  CONSTRAINT `chk_setting_deployment_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_deployment_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND ((`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_setting_tenant_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `value_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` JSON NULL,
  `ciphertext` VARBINARY(8192) NULL,
  `nonce` BINARY(24) NULL,
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_tenant_value` (`tenant_id`, `definition_id`),
  KEY `idx_setting_tenant_definition` (`definition_id`),
  CONSTRAINT `fk_setting_tenant_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_tenant_definition` FOREIGN KEY (`definition_id`)
    REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_tenant_member` FOREIGN KEY (`updated_by_member_id`)
    REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_tenant_state` CHECK (`value_state` IN ('set','unset')),
  CONSTRAINT `chk_setting_tenant_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_tenant_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND ((`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_setting_target_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `target_resource_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` JSON NULL,
  `ciphertext` VARBINARY(8192) NULL,
  `nonce` BINARY(24) NULL,
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_target_value` (`tenant_id`, `definition_id`, `target_resource_key`, `target_id`),
  KEY `idx_setting_target_definition` (`definition_id`),
  CONSTRAINT `fk_setting_target_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_target_definition` FOREIGN KEY (`definition_id`)
    REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_target_member` FOREIGN KEY (`updated_by_member_id`)
    REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_target_state` CHECK (`value_state` IN ('set','unset')),
  CONSTRAINT `chk_setting_target_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_target_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND ((`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_refund_log` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `sn`            VARCHAR(32)  DEFAULT NULL,
  `record_id`     INT          NOT NULL,
  `handle_id`     INT          NOT NULL DEFAULT 0,
  `order_amount`  DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `refund_amount` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `refund_status` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `refund_msg`    TEXT         DEFAULT NULL,
  `create_time`   INT UNSIGNED DEFAULT 0,
  `update_time`   INT          DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_record_id` (`record_id`),
  KEY `idx_refund_status` (`refund_status`),
  KEY `idx_create_time` (`create_time`),
  UNIQUE KEY `uk_refund_log_tenant_id` (`tenant_id`, `id`),
  KEY `idx_refund_log_tenant_record_time` (`tenant_id`, `record_id`, `create_time`, `id`),
  CONSTRAINT `fk_refund_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_log_member` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_log_record` FOREIGN KEY (`tenant_id`, `record_id`) REFERENCES `pa_refund_record` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退款日志';

CREATE TABLE `pa_oauth_completion_ticket` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash`       CHAR(64) NOT NULL COMMENT '票据 SHA-256',
  `member_id`        INT UNSIGNED NOT NULL,
  `need_profile`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `need_mobile`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at`       INT UNSIGNED NOT NULL,
  `used_at`          INT UNSIGNED NULL DEFAULT NULL,
  `create_time`      INT UNSIGNED NULL DEFAULT NULL,
  `update_time`      INT UNSIGNED NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `binding_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_expires_at` (`expires_at`),
  UNIQUE KEY `uk_oauth_ticket_tenant_token` (`tenant_id`, `token_hash`),
  KEY `idx_oauth_ticket_tenant_member` (`tenant_id`, `member_id`),
  KEY `idx_oauth_ticket_tenant_expires` (`tenant_id`, `expires_at`),
  CONSTRAINT `fk_oauth_ticket_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_ticket_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  KEY `idx_oauth_ticket_binding` (`binding_id`,`expires_at`),
  CONSTRAINT `fk_oauth_ticket_external_binding`
    FOREIGN KEY (`binding_id`) REFERENCES `pa_external_channel_binding` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuth首登补全票据';

SET FOREIGN_KEY_CHECKS = 1;

-- Deterministic fresh-install seed.

SET @pa_default_tenant_id = (SELECT `id` FROM `pa_tenant` WHERE `code`='default' AND `status`='active' ORDER BY `id` LIMIT 1);

INSERT INTO `pa_system_dict_type` (`code`, `name`, `remark`)
VALUES
  ('member_sex', '会员性别', '系统固定枚举，租户不可修改'),
  ('member_status', '会员状态', '系统固定枚举，租户不可修改'),
  ('member_channel', '会员注册渠道', '系统固定枚举，租户不可修改'),
  ('payment_status', '支付状态', '系统固定枚举，租户不可修改'),
  ('refund_status', '退款状态', '系统固定枚举，租户不可修改');

INSERT INTO `pa_system_dict_data` (`type_code`, `name`, `value`, `sort`, `remark`)
VALUES
  ('member_sex', '未知', '0', 30, 'unknown'),
  ('member_sex', '男', '1', 20, 'male'),
  ('member_sex', '女', '2', 10, 'female'),
  ('member_status', '正常', '1', 20, 'active'),
  ('member_status', '禁用', '0', 10, 'disabled'),
  ('member_channel', '微信小程序', '1', 60, 'wechat_mmp'),
  ('member_channel', '微信公众号', '2', 50, 'wechat_oa'),
  ('member_channel', 'H5', '3', 40, 'h5'),
  ('member_channel', 'PC', '4', 30, 'pc'),
  ('member_channel', 'iOS', '5', 20, 'ios'),
  ('member_channel', 'Android', '6', 10, 'android'),
  ('payment_status', '待支付', '0', 20, 'unpaid'),
  ('payment_status', '已支付', '1', 10, 'paid'),
  ('refund_status', '处理中', '0', 30, 'processing'),
  ('refund_status', '成功', '1', 20, 'success'),
  ('refund_status', '失败', '2', 10, 'failed');

INSERT INTO `pa_tenant_setting`
  (`tenant_id`,`namespace`,`config_json`,`revision`,`create_time`,`update_time`)
SELECT @pa_default_tenant_id, 'website',
       JSON_OBJECT(
         'name', 'Peanut Admin',
         'web_favicon', 'brand/favicon.svg',
         'web_logo', 'brand/logo.svg',
         'login_image', 'brand/login-background.svg',
         'shop_name', 'Peanut Admin',
         'shop_logo', 'brand/logo.svg',
         'pc_logo', 'brand/logo.svg',
         'pc_title', 'Peanut Admin',
         'pc_ico', 'brand/favicon.svg',
         'pc_desc', 'Peanut Admin 全端管理脚手架',
         'pc_keywords', 'Peanut Admin,Vue 3,ThinkPHP 8,UniApp',
         'h5_favicon', 'brand/favicon.svg',
         'slogan', '连接管理端、PC 与移动端的中性应用基线',
         'copyright', '花生科技',
         'official_url', '',
         'github_url', 'https://github.com/peanut-business/peanut-admin-code'
       ),
       1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @pa_default_tenant_id IS NOT NULL;

INSERT INTO `pa_tenant_setting`
  (`tenant_id`,`namespace`,`config_json`,`revision`,`create_time`,`update_time`)
SELECT @pa_default_tenant_id, 'copyright',
       JSON_OBJECT('config', JSON_ARRAY()),
       1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @pa_default_tenant_id IS NOT NULL;

INSERT INTO `pa_tenant_setting`
  (`tenant_id`,`namespace`,`config_json`,`revision`,`create_time`,`update_time`)
SELECT @pa_default_tenant_id, seed.namespace, seed.config_json,
       1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM (
  SELECT 'agreement' AS namespace, JSON_OBJECT(
    'service_title', '', 'service_content', '',
    'privacy_title', '', 'privacy_content', ''
  ) AS config_json
  UNION ALL SELECT 'site-statistics', JSON_OBJECT('clarity_code', '')
  UNION ALL SELECT 'member-profile', JSON_OBJECT('user_avatar', 'brand/avatar-member.svg')
  UNION ALL SELECT 'login', JSON_OBJECT(
    'login_way', JSON_ARRAY(1, 2),
    'coerce_mobile', 0,
    'login_agreement', 0,
    'third_auth', 0,
    'wechat_auth', 0
  )
  UNION ALL SELECT 'web-page', JSON_OBJECT('status', 1, 'page_status', 0, 'page_url', '')
  UNION ALL SELECT 'hot-search', JSON_OBJECT('status', 0)
) seed
WHERE @pa_default_tenant_id IS NOT NULL;

INSERT INTO `pa_crontab`
  (`name`,`type`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`sort`,`remark`,`create_time`,`update_time`,`tenant_id`)
SELECT '退款状态收敛', 1, 'refund:reconcile', '', 1, '* * * * *', '', 0, 0, 0, 100,
       '查询支付渠道并收敛充值退款最终状态', 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (
  SELECT 1 FROM `pa_crontab` WHERE `command` = 'refund:reconcile'
);
INSERT IGNORE INTO `pa_notice_scene`
  (`id`,`code`,`name`,`description`,`recipient`,`variables`,`sms_template_id`,`sms_content`,`sms_status`,`create_time`,`update_time`,`tenant_id`) VALUES
  (1,'login_code','登录验证码','用户使用手机号验证码登录','用户',JSON_ARRAY('code'),'','您的登录验证码是${code}，五分钟内有效。',0,0,0,@pa_default_tenant_id),
  (2,'bind_mobile','绑定手机验证码','用户首次绑定手机号','用户',JSON_ARRAY('code'),'','您的绑定手机验证码是${code}，五分钟内有效。',0,0,0,@pa_default_tenant_id),
  (3,'change_mobile','变更手机验证码','用户更换已绑定手机号','用户',JSON_ARRAY('code'),'','您的变更手机验证码是${code}，五分钟内有效。',0,0,0,@pa_default_tenant_id),
  (4,'reset_password','找回密码验证码','用户通过手机号重置密码','用户',JSON_ARRAY('code'),'','您的找回密码验证码是${code}，五分钟内有效。',0,0,0,@pa_default_tenant_id);
INSERT INTO `pa_notice_scene`
  (`code`,`name`,`description`,`recipient`,`variables`,`sms_template_id`,`sms_content`,`sms_status`,`create_time`,`update_time`,`tenant_id`)
SELECT 'login_code', '登录验证码', '用户使用手机号验证码登录', '用户', JSON_ARRAY('code'), '', '您的登录验证码是${code}，五分钟内有效。', 0, 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_notice_scene` WHERE `code` = 'login_code');
INSERT INTO `pa_notice_scene`
  (`code`,`name`,`description`,`recipient`,`variables`,`sms_template_id`,`sms_content`,`sms_status`,`create_time`,`update_time`,`tenant_id`)
SELECT 'bind_mobile', '绑定手机验证码', '用户首次绑定手机号', '用户', JSON_ARRAY('code'), '', '您的绑定手机验证码是${code}，五分钟内有效。', 0, 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_notice_scene` WHERE `code` = 'bind_mobile');
INSERT INTO `pa_notice_scene`
  (`code`,`name`,`description`,`recipient`,`variables`,`sms_template_id`,`sms_content`,`sms_status`,`create_time`,`update_time`,`tenant_id`)
SELECT 'change_mobile', '变更手机验证码', '用户更换已绑定手机号', '用户', JSON_ARRAY('code'), '', '您的变更手机验证码是${code}，五分钟内有效。', 0, 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_notice_scene` WHERE `code` = 'change_mobile');
INSERT INTO `pa_notice_scene`
  (`code`,`name`,`description`,`recipient`,`variables`,`sms_template_id`,`sms_content`,`sms_status`,`create_time`,`update_time`,`tenant_id`)
SELECT 'reset_password', '找回密码验证码', '用户通过手机号重置密码', '用户', JSON_ARRAY('code'), '', '您的找回密码验证码是${code}，五分钟内有效。', 0, 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_notice_scene` WHERE `code` = 'reset_password');
INSERT INTO `pa_crontab`
    (`name`,`type`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`sort`,`remark`,`create_time`,`update_time`,`tenant_id`)
SELECT '退款状态收敛', 1, 'refund:reconcile', '', 1, '* * * * *', '', 0, 0, 0, 100,
       '查询支付渠道并收敛充值退款最终状态', 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (
    SELECT 1 FROM `pa_crontab` WHERE `command` = 'refund:reconcile'
);
INSERT INTO `pa_crontab`
  (`name`,`type`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`sort`,`remark`,`create_time`,`update_time`,`tenant_id`)
SELECT '代码生成归档清理',1,'generator:cleanup','',1,'0 3 * * *','',0,0,0,20,
       '清理已使用或过期的代码生成下载令牌和隔离归档',0,0,@pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_crontab` WHERE `command`='generator:cleanup');
INSERT INTO `pa_decorate_page` (`type`,`name`,`data`,`meta`,`create_time`,`update_time`,`tenant_id`)
SELECT 1, '移动端首页',
  '[{"title":"搜索","name":"search","disabled":1,"content":{},"styles":{}},{"title":"首页轮播图","name":"banner","content":{"enabled":1,"style":1,"bg_style":1,"data":[{"is_show":1,"image":"","bg":"","name":"","link":{"target_type":"shop","target":"home"}}]},"styles":{}},{"title":"导航菜单","name":"nav","content":{"enabled":1,"style":2,"per_line":5,"show_line":2,"data":[{"is_show":1,"image":"","name":"资讯中心","link":{"target_type":"shop","target":"news"}}]},"styles":{}},{"title":"首页中部轮播图","name":"middle-banner","content":{"enabled":1,"data":[{"is_show":1,"image":"","name":"","link":{"target_type":"shop","target":"home"}}]},"styles":{}},{"title":"资讯","name":"news","disabled":1,"content":{},"styles":{}}]',
  '[{"title":"页面设置","name":"page-meta","content":{"title":"首页","title_type":1,"title_img":"","bg_type":1,"bg_color":"#2F80ED","bg_image":"","text_color":1},"styles":{}}]', 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_decorate_page` WHERE `type`=1);
INSERT INTO `pa_decorate_page` (`type`,`name`,`data`,`meta`,`create_time`,`update_time`,`tenant_id`)
SELECT 2, '个人中心',
  '[{"title":"用户信息","name":"user-info","disabled":1,"content":{},"styles":{}},{"title":"我的服务","name":"my-service","content":{"enabled":1,"style":1,"title":"我的服务","data":[{"is_show":1,"image":"","name":"我的收藏","link":{"target_type":"shop","target":"favorites"}}]},"styles":{}},{"title":"个人中心广告图","name":"user-banner","content":{"enabled":1,"data":[{"is_show":1,"image":"","name":"","link":{"target_type":"shop","target":"profile"}}]},"styles":{}}]',
  '[{"title":"页面设置","name":"page-meta","content":{"title":"个人中心","title_type":1,"title_img":"","bg_type":1,"bg_color":"#2F80ED","bg_image":"","text_color":1},"styles":{}}]', 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_decorate_page` WHERE `type`=2);
INSERT INTO `pa_decorate_page` (`type`,`name`,`data`,`meta`,`create_time`,`update_time`,`tenant_id`)
SELECT 3, '客服设置',
  '[{"title":"客服设置","name":"customer-service","content":{"title":"添加客服二维码","time":"9:30 - 19:00","mobile":"","qrcode":"","remark":"长按添加客服或拨打客服热线"},"styles":{}}]',
  '[]', 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_decorate_page` WHERE `type`=3);
INSERT INTO `pa_decorate_page` (`type`,`name`,`data`,`meta`,`create_time`,`update_time`,`tenant_id`)
SELECT 4, 'PC 首页',
  '[{"title":"首页轮播图","name":"pc-banner","content":{"enabled":1,"data":[{"image":"","name":"","link":{"target_type":"shop","target":"home"}}]},"styles":{"position":"absolute","left":"40px","top":"75px","width":"750px","height":"340px"}}]',
  '[]', 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_decorate_page` WHERE `type`=4);
INSERT INTO `pa_decorate_page` (`type`,`name`,`data`,`meta`,`create_time`,`update_time`,`tenant_id`)
SELECT 5, '系统风格',
  '{"themeColorId":3,"topTextColor":"white","navigationBarColor":"#A74BFD","themeColor1":"#A74BFD","themeColor2":"#CB60FF","buttonColor":"white"}',
  '[]', 0, 0, @pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_decorate_page` WHERE `type`=5);
INSERT INTO `pa_decorate_tabbar` (`position`,`name`,`selected`,`unselected`,`link`,`is_show`,`create_time`,`update_time`,`tenant_id`)
SELECT 0,'首页','','','{"target_type":"shop","target":"home"}',1,0,0,@pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_decorate_tabbar` WHERE `position`=0);
INSERT INTO `pa_decorate_tabbar` (`position`,`name`,`selected`,`unselected`,`link`,`is_show`,`create_time`,`update_time`,`tenant_id`)
SELECT 1,'资讯','','','{"target_type":"shop","target":"news"}',1,0,0,@pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_decorate_tabbar` WHERE `position`=1);
INSERT INTO `pa_decorate_tabbar` (`position`,`name`,`selected`,`unselected`,`link`,`is_show`,`create_time`,`update_time`,`tenant_id`)
SELECT 2,'我的','','','{"target_type":"shop","target":"profile"}',1,0,0,@pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_decorate_tabbar` WHERE `position`=2);

INSERT INTO `pa_payment_scene`
  (`terminal`,`pay_way`,`status`,`is_default`,`create_time`,`update_time`)
VALUES
  (1,2,1,1,0,0),
  (2,2,1,1,0,0),
  (2,3,0,0,0,0),
  (3,2,1,1,0,0),
  (3,3,0,0,0,0),
  (4,2,1,1,0,0),
  (4,3,0,0,0,0),
  (5,2,1,1,0,0),
  (5,3,0,0,0,0),
  (6,2,1,1,0,0),
  (6,3,0,0,0,0)
ON DUPLICATE KEY UPDATE `terminal` = VALUES(`terminal`);

INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (1,  0, 'M', '权限管理',   'icon-settings', 100, '',            '/system',       '',                    0, 1, 0),
  (2,  1, 'C', '菜单管理',   'icon-menu',      90, 'menu/lists',  '/system/menu',  'system/menu/index',   0, 1, 0),
  (3,  2, 'A', '菜单新增',   '',                0, 'menu/add',    '',              '',                    0, 1, 0),
  (4,  2, 'A', '菜单编辑',   '',                0, 'menu/edit',   '',              '',                    0, 1, 0),
  (5,  2, 'A', '菜单删除',   '',                0, 'menu/delete', '',              '',                    0, 1, 0),
  (6,  1, 'C', '角色管理',   'icon-user-group', 80, 'role/lists',  '/system/role',  'system/role/index',   0, 1, 0),
  (7,  6, 'A', '角色新增',   '',                0, 'role/add',    '',              '',                    0, 1, 0),
  (8,  6, 'A', '角色编辑',   '',                0, 'role/edit',   '',              '',                    0, 1, 0),
  (9,  6, 'A', '角色删除',   '',                0, 'role/delete', '',              '',                    0, 1, 0),
  (10, 1, 'C', '管理员管理', 'icon-user',       70, 'admin/lists', '/system/admin', 'system/admin/index',  0, 1, 0),
  (11,10, 'A', '管理员新增', '',                0, 'admin/add',   '',              '',                    0, 1, 0),
  (12,10, 'A', '管理员编辑', '',                0, 'admin/edit',  '',              '',                    0, 1, 0),
  (13,10, 'A', '管理员删除', '',                0, 'admin/delete','',              '',                    0, 1, 0),
  (14, 0, 'M', '仪表盘',     'icon-dashboard', 110, '',                    '/dashboard',           '',                          0, 1, 0),
  (15,14, 'C', '工作台',     'icon-dashboard', 100, 'workbench/index',     '/dashboard/workplace', 'dashboard/workplace/index', 0, 1, 0),
  (16, 1, 'C', '岗位管理',   'icon-idcard',      60, 'jobs/lists',  '/system/jobs',  'system/jobs/index',   0, 1, 0),
  (17,16, 'A', '岗位新增',   '',                30, 'jobs/add',    '',              '',                    0, 1, 0),
  (18,16, 'A', '岗位编辑',   '',                20, 'jobs/edit',   '',              '',                    0, 1, 0),
  (19,16, 'A', '岗位删除',   '',                10, 'jobs/delete', '',              '',                    0, 1, 0);
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (20, 0, 'M', '会员管理',   'icon-user',       80, '',                   '/member',            '',                         0, 1, 0),
  (21,20, 'C', '会员列表',   'icon-user-group',  90, 'member/lists',       '/member/list',       'member/list/index',        0, 1, 0),
  (22,21, 'A', '会员详情',   '',                  0, 'member/detail',      '',                   '',                         0, 1, 0),
  (23,21, 'A', '会员状态',   '',                  0, 'member/status',      '',                   '',                         0, 1, 0),
  (24,21, 'A', '余额调整',   '',                  0, 'member/adjustBalance','',                  '',                         0, 1, 0),
  (59,21, 'A', '会员新增',   '',                  0, 'member/add',         '',                   '',                         0, 1, 0),
  (60,21, 'A', '会员编辑',   '',                  0, 'member/edit',        '',                   '',                         0, 1, 0),
  (61,21, 'A', '余额调整',   '',                  0, 'member/adjustMoney', '',                   '',                         0, 1, 0),
  (62,21, 'A', '用户详情',   '',                  0, 'user.user/detail',   '',                   '',                         0, 1, 0),
  (63,21, 'A', '用户编辑',   '',                  0, 'user.user/edit',     '',                   '',                         0, 1, 0),
  (64,21, 'A', '余额调整',   '',                  0, 'user.user/adjustMoney','',                 '',                         0, 1, 0),
  (65,21, 'A', '会员资料编辑','',                  0, 'member/profile/edit','',                  '',                         0, 1, 0),
  (25,20, 'C', '会员标签',   'icon-tag',         80, 'member/tag/lists',   '/member/tag',        'member/tag/index',         0, 1, 0),
  (26,25, 'A', '标签新增',   '',                  0, 'member/tag/add',     '',                   '',                         0, 1, 0),
  (27,25, 'A', '标签编辑',   '',                  0, 'member/tag/edit',    '',                   '',                         0, 1, 0),
  (28,25, 'A', '标签删除',   '',                  0, 'member/tag/delete',  '',                   '',                         0, 1, 0);
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (30, 0, 'M', '通知管理',   'icon-notification', 70, '',                      '/notice',              '',                         0, 1, 0),
  (31,30, 'C', '渠道配置',   'icon-settings',     90, 'notice/channel/detail', '/notice/channel',      'notice/channel/index',     0, 1, 0),
  (32,31, 'A', '渠道保存',   '',                   0, 'notice/channel/save',   '',                     '',                         0, 1, 0),
  (33,30, 'C', '通知场景',   'icon-file',         80, 'notice/scene/lists',    '/notice/template',     'notice/template/index',    0, 1, 0),
  (34,33, 'A', '模板新增',   '',                   0, 'notice/template/add',   '',                     '',                         0, 1, 0),
  (35,33, 'A', '模板编辑',   '',                   0, 'notice/template/edit',  '',                     '',                         0, 1, 0),
  (36,33, 'A', '模板删除',   '',                   0, 'notice/template/delete','',                     '',                         0, 1, 0),
  (37,30, 'C', '发送日志',   'icon-history',      70, 'notice/log/lists',      '/notice/log',          'notice/log/index',         0, 1, 0),
  (73,33, 'A', '场景详情',   '',                   0, 'notice/scene/detail',     '',                     '',                         0, 1, 0),
  (74,33, 'A', '场景设置',   '',                   0, 'notice/scene/save',       '',                     '',                         0, 1, 0),
  (75,37, 'A', '日志详情',   '',                   0, 'notice/log/detail',       '',                     '',                         0, 1, 0);
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (38, 0, 'M', '财务管理', 'icon-fingerprint', 60, '',                              '/finance',             '',                          0, 1, 0),
  (39,38, 'C', '余额明细', 'icon-bar-chart',   90, 'finance.account_log/lists',     '/finance/account-log', 'finance/account-log/index', 0, 1, 0);
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (40, 0, 'M', '应用设置', 'icon-apps',        50, '',                                '/app-setting',                    '',                                     0, 1, 0),
  (41,40, 'C', '热门搜索', 'icon-search',      90, 'setting/hot-search/config',       '/app-setting/hot-search',         'app-setting/hot-search/index',         0, 1, 0),
  (42,41, 'A', '热门搜索保存', '',              0, 'setting/hot-search/save',         '',                                '',                                     0, 1, 0),
  (43,40, 'C', '客服设置', 'icon-customer-service', 80, 'setting/customer-service/config', '/app-setting/customer-service',  'app-setting/customer-service/index',   0, 1, 0),
  (44,43, 'A', '客服设置保存', '',              0, 'setting/customer-service/save',   '',                                '',                                     0, 1, 0);
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (95,55, 'A', 'H5渠道查看', '', 0, 'setting/web-page/config', '', '', 0, 1, 0),
  (96,55, 'A', 'H5渠道保存', '', 0, 'setting/web-page/save',   '', '', 0, 1, 0),
  (97,55, 'A', '小程序配置查看', '', 0, 'setting/mini-program/config', '', '', 0, 1, 0),
  (98,55, 'A', '小程序配置保存', '', 0, 'setting/mini-program/save',   '', '', 0, 1, 0);
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (45, 0, 'M', '内容管理', 'icon-file',        55, '',                       '/article',              '',                       0, 1, 0),
  (46,45, 'C', '文章分类', 'icon-folder',      90, 'article.articleCate/lists',       '/article/cate',         'article/cate/index',     0, 1, 0),
  (47,46, 'A', '分类新增', '',                  0, 'article.articleCate/add',         '',                       '',                       0, 1, 0),
  (48,46, 'A', '分类编辑', '',                  0, 'article.articleCate/edit',        '',                       '',                       0, 1, 0),
  (49,46, 'A', '分类删除', '',                  0, 'article.articleCate/delete',      '',                       '',                       0, 1, 0),
  (50,45, 'C', '文章管理', 'icon-file',        80, 'article.article/lists',          '/article/list',         'article/list/index',     0, 1, 0),
  (51,50, 'A', '文章新增', '',                  0, 'article.article/add',            '',                       '',                       0, 1, 0),
  (52,50, 'A', '文章编辑', '',                  0, 'article.article/edit',           '',                       '',                       0, 1, 0),
  (53,50, 'A', '文章删除', '',                  0, 'article.article/delete',         '',                       '',                       0, 1, 0),
  (69,46, 'A', '分类详情', '',                  0, 'article.articleCate/detail',      '',                       '',                       0, 1, 0),
  (70,46, 'A', '分类状态', '',                  0, 'article.articleCate/updateStatus','',                       '',                       0, 1, 0),
  (71,50, 'A', '文章详情', '',                  0, 'article.article/detail',         '',                       '',                       0, 1, 0),
  (72,50, 'A', '文章状态', '',                  0, 'article.article/updateStatus',   '',                       '',                       0, 1, 0);
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (84, 1, 'C', '素材管理',   'icon-folder', 50, 'file/lists',       '/system/file', 'system/file/index', 0, 1, 0),
  (85,84, 'A', '分类查看',   '',             0, 'file/cate/lists',  '',             '',                  0, 1, 0),
  (86,84, 'A', '上传图片',   '',             0, 'upload/image',     '',             '',                  0, 1, 0),
  (87,84, 'A', '上传视频',   '',             0, 'upload/video',     '',             '',                  0, 1, 0),
  (88,84, 'A', '上传文件',   '',             0, 'upload/file',      '',             '',                  0, 1, 0),
  (89,84, 'A', '分类新增',   '',             0, 'file/cate/add',    '',             '',                  0, 1, 0),
  (90,84, 'A', '分类编辑',   '',             0, 'file/cate/edit',   '',             '',                  0, 1, 0),
  (91,84, 'A', '分类删除',   '',             0, 'file/cate/delete', '',             '',                  0, 1, 0),
  (92,84, 'A', '素材移动',   '',             0, 'file/move',        '',             '',                  0, 1, 0),
  (93,84, 'A', '素材重命名', '',             0, 'file/rename',      '',             '',                  0, 1, 0),
  (94,84, 'A', '素材删除',   '',             0, 'file/delete',      '',             '',                  0, 1, 0);
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  -- 应用设置子项
  (54,40,'C','支付配置',  'icon-payment',    70, 'setting/pay/config',      '/app-setting/pay',      'app-setting/pay/index',      0,1,0),
  (55,40,'C','渠道配置',  'icon-share-alt',  60, 'setting/channel/config',  '/app-setting/channel',  'app-setting/channel/index',  0,1,0),
  (56,40,'C','页面装修',  'icon-brush',      50, 'setting/decorate/config', '/app-setting/decorate', 'app-setting/decorate/index', 0,1,0),
  -- 财务子项
  (57,38,'C','充值记录',  'icon-thunderbolt', 80, 'recharge.recharge/lists', '/finance/recharge',     'finance/recharge/index',     0,1,0);
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (58,38,'C','退款记录','icon-undo',70,'finance.refund/record','/finance/refund','finance/refund/index',0,1,0),
  (66,57,'A','退款','',0,'recharge.recharge/refund','','',0,1,0),
  (67,58,'A','重新退款','',0,'recharge.recharge/refundAgain','','',0,1,0),
  (68,58,'A','退款日志','',0,'finance.refund/log','','',0,1,0);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT 1, 'C', '部门管理', 'icon-mind-mapping', 70, 'dept/lists', '/system/dept', 'system/dept/index', 0, 1, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `paths` = '/system/dept' AND `type` = 'C'
);
SET @pa_dept_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `paths` = '/system/dept' AND `type` = 'C'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_dept_menu_id, 'A', '新增部门', '', 30, 'dept/add', '', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'dept/add');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_dept_menu_id, 'A', '编辑部门', '', 20, 'dept/edit', '', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'dept/edit');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_dept_menu_id, 'A', '删除部门', '', 10, 'dept/delete', '', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'dept/delete');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT 1, 'C', '岗位管理', 'icon-idcard', 60, 'jobs/lists', '/system/jobs', 'system/jobs/index', 0, 1, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `paths` = '/system/jobs' AND `type` = 'C'
);
SET @pa_jobs_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `paths` = '/system/jobs' AND `type` = 'C'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_jobs_menu_id, 'A', '新增岗位', '', 30, 'jobs/add', '', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'jobs/add');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_jobs_menu_id, 'A', '编辑岗位', '', 20, 'jobs/edit', '', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'jobs/edit');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_jobs_menu_id, 'A', '删除岗位', '', 10, 'jobs/delete', '', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'jobs/delete');
SET @pa_member_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `paths` = '/member/list' AND `type` = 'C'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_member_menu_id, 'A', '会员新增', '', 0, 'member/add', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'member/add');
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_member_menu_id, 'A', '会员编辑', '', 0, 'member/edit', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'member/edit');
INSERT IGNORE INTO `pa_system_menu`
  (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`) VALUES
  (14, 0, 'M', '仪表盘', 'icon-dashboard', 110, '',                '/dashboard',           '',                          0, 1, 0),
  (15,14, 'C', '工作台', 'icon-dashboard', 100, 'workbench/index', '/dashboard/workplace', 'dashboard/workplace/index', 0, 1, 0);
SET @pa_member_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `paths` = '/member/list' AND `type` = 'C'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_member_menu_id, 'A', '余额调整', '', 0, 'member/adjustMoney', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'member/adjustMoney');
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_member_menu_id, 'A', '会员资料编辑', '', 0, 'member/profile/edit', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'member/profile/edit');
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_member_menu_id, 'A', '用户详情', '', 0, 'user.user/detail', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'user.user/detail');
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_member_menu_id, 'A', '用户编辑', '', 0, 'user.user/edit', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'user.user/edit');
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_member_menu_id, 'A', '余额调整', '', 0, 'user.user/adjustMoney', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'user.user/adjustMoney');
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT 0, 'M', '财务管理', 'icon-fingerprint', 60, '', '/finance', '', 0, 1, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `type` = 'M' AND `paths` = '/finance'
);
SET @pa_finance_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'M' AND `paths` = '/finance'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_finance_menu_id, 'C', '余额明细', 'icon-bar-chart', 90,
       'finance.account_log/lists', '/finance/account-log',
       'finance/account-log/index', 0, 1, 0
WHERE @pa_finance_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `type` = 'C' AND `paths` = '/finance/account-log'
  );
SET @pa_article_cate_menu_id = (
    SELECT `id`
    FROM `pa_system_menu`
    WHERE `type` = 'C'
      AND (`paths` = '/article/cate' OR `perms` = 'article.articleCate/lists')
    ORDER BY (`paths` = '/article/cate') DESC, `id` ASC
    LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_cate_menu_id, 'A', '分类新增', '', 0, 'article.articleCate/add', '', '', 0, 1, 0
WHERE @pa_article_cate_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `pid` = @pa_article_cate_menu_id AND `perms` = 'article.articleCate/add'
  );
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_cate_menu_id, 'A', '分类编辑', '', 0, 'article.articleCate/edit', '', '', 0, 1, 0
WHERE @pa_article_cate_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `pid` = @pa_article_cate_menu_id AND `perms` = 'article.articleCate/edit'
  );
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_cate_menu_id, 'A', '分类删除', '', 0, 'article.articleCate/delete', '', '', 0, 1, 0
WHERE @pa_article_cate_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `pid` = @pa_article_cate_menu_id AND `perms` = 'article.articleCate/delete'
  );
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_cate_menu_id, 'A', '分类详情', '', 0, 'article.articleCate/detail', '', '', 0, 1, 0
WHERE @pa_article_cate_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `pid` = @pa_article_cate_menu_id AND `perms` = 'article.articleCate/detail'
  );
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_cate_menu_id, 'A', '分类状态', '', 0, 'article.articleCate/updateStatus', '', '', 0, 1, 0
WHERE @pa_article_cate_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `pid` = @pa_article_cate_menu_id AND `perms` = 'article.articleCate/updateStatus'
  );
SET @pa_article_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'C'
      AND (`paths` = '/article/list' OR `perms` = 'article.article/lists')
    ORDER BY (`paths` = '/article/list') DESC, `id` ASC
    LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_menu_id, 'A', '文章新增', '', 0, 'article.article/add', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/add'
);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_menu_id, 'A', '文章编辑', '', 0, 'article.article/edit', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/edit'
);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_menu_id, 'A', '文章删除', '', 0, 'article.article/delete', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/delete'
);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_menu_id, 'A', '文章详情', '', 0, 'article.article/detail', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/detail'
);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_article_menu_id, 'A', '文章状态', '', 0, 'article.article/updateStatus', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/updateStatus'
);
SET @pa_notice_scene_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'C' AND `paths` = '/notice/template'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_notice_scene_menu_id, 'A', '场景详情', '', 0, 'notice/scene/detail', '', '', 0, 1, 0
WHERE @pa_notice_scene_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'notice/scene/detail');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_notice_scene_menu_id, 'A', '场景设置', '', 0, 'notice/scene/save', '', '', 0, 1, 0
WHERE @pa_notice_scene_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'notice/scene/save');
SET @pa_notice_log_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'C' AND `paths` = '/notice/log'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_notice_log_menu_id, 'A', '日志详情', '', 0, 'notice/log/detail', '', '', 0, 1, 0
WHERE @pa_notice_log_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'notice/log/detail');
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT 0, 'M', '财务管理', 'icon-fingerprint', 60, '', '/finance', '', 0, 1, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `type` = 'M' AND `paths` = '/finance'
);
SET @pa_finance_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'M' AND `paths` = '/finance'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_finance_menu_id, 'C', '充值记录', 'icon-thunderbolt', 80,
       'recharge.recharge/lists', '/finance/recharge', 'finance/recharge/index', 0, 1, 0
WHERE @pa_finance_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu` WHERE `type` = 'C' AND `paths` = '/finance/recharge'
  );
SET @pa_recharge_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'C' AND `paths` = '/finance/recharge'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_recharge_menu_id, 'A', '退款', '', 0, 'recharge.recharge/refund', '', '', 0, 1, 0
WHERE @pa_recharge_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu` WHERE LOWER(`perms`) = 'recharge.recharge/refund'
  );
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_finance_menu_id, 'C', '退款记录', 'icon-undo', 70,
       'finance.refund/record', '/finance/refund', 'finance/refund/index', 0, 1, 0
WHERE @pa_finance_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu` WHERE `type` = 'C' AND `paths` = '/finance/refund'
  );
SET @pa_refund_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'C' AND `paths` = '/finance/refund'
    ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_refund_menu_id, 'A', '重新退款', '', 0, 'recharge.recharge/refundAgain', '', '', 0, 1, 0
WHERE @pa_refund_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu` WHERE LOWER(`perms`) = 'recharge.recharge/refundagain'
  );
INSERT INTO `pa_system_menu`
    (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_refund_menu_id, 'A', '退款日志', '', 0, 'finance.refund/log', '', '', 0, 1, 0
WHERE @pa_refund_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu` WHERE LOWER(`perms`) = 'finance.refund/log'
  );
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT 0, 'M', '开发工具', 'icon-tool', 20, '', '/dev-tools', '', 0, 1, 0
WHERE NOT EXISTS (
  SELECT 1 FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/dev-tools'
);
SET @pa_dev_tools_menu_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `type`='M' AND `paths`='/dev-tools'
  ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_dev_tools_menu_id, 'C', '代码生成器', 'icon-code', 90,
       'generator/lists', '/dev-tools/code', 'dev-tools/code/index', 0, 1, 0
WHERE @pa_dev_tools_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/lists');
SET @pa_generator_menu_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `perms`='generator/lists'
  ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '读取数据表', '', 0, 'generator/source-tables', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/source-tables');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '导入数据表', '', 0, 'generator/import', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/import');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '查看配置', '', 0, 'generator/detail', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/detail');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '同步字段', '', 0, 'generator/sync', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/sync');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '编辑配置', '', 0, 'generator/update', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/update');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '删除配置', '', 0, 'generator/delete', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/delete');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '预览代码', '', 0, 'generator/preview', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/preview');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '生成代码', '', 0, 'generator/generate', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/generate');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '下载代码', '', 0, 'generator/download', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/download');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_generator_menu_id, 'A', '模型列表', '', 0, 'generator/models', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/models');
SET @pa_decoration_root_id = (
  SELECT `id` FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/decoration' ORDER BY `id` LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT 0,'M','装修管理','icon-palette',30,'','/decoration','',0,1,0
WHERE @pa_decoration_root_id IS NULL;
SET @pa_decoration_root_id = (
  SELECT `id` FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/decoration' ORDER BY `id` LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_decoration_root_id,'C',`seed`.`name`,'',`seed`.`sort`,'',`seed`.`paths`,`seed`.`component`,0,1,0
FROM (
  SELECT '移动端装修' AS `name`,30 AS `sort`,'/decoration/mobile' AS `paths`,'decoration/mobile/index' AS `component`
  UNION ALL SELECT 'Tabbar 装修',20,'/decoration/tabbar','decoration/tabbar/index'
  UNION ALL SELECT 'PC 装修',10,'/decoration/pc','decoration/pc/index'
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `paths`=`seed`.`paths`);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT `parent`.`id`,'A',`seed`.`name`,'',0,`seed`.`perms`,'','',0,1,0
FROM (
  SELECT '/decoration/mobile' AS `parent_path`,'移动装修列表' AS `name`,'decoration/mobile/page/lists' AS `perms`
  UNION ALL SELECT '/decoration/mobile','移动装修查看','decoration/mobile/page/detail'
  UNION ALL SELECT '/decoration/mobile','移动装修保存','decoration/mobile/page/save'
  UNION ALL SELECT '/decoration/mobile','装修文章选择','decoration/mobile/article'
  UNION ALL SELECT '/decoration/tabbar','Tabbar 查看','decoration/tabbar/detail'
  UNION ALL SELECT '/decoration/tabbar','Tabbar 保存','decoration/tabbar/save'
  UNION ALL SELECT '/decoration/pc','PC 装修列表','decoration/pc/page/lists'
  UNION ALL SELECT '/decoration/pc','PC 装修查看','decoration/pc/page/detail'
  UNION ALL SELECT '/decoration/pc','PC 装修保存','decoration/pc/page/save'
) AS `seed`
JOIN `pa_system_menu` AS `parent` ON `parent`.`paths`=`seed`.`parent_path` AND `parent`.`type`='C'
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`=`seed`.`perms`);
SET @pa_app_setting_menu_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `type` = 'M' AND `paths` = '/app-setting'
  ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_app_setting_menu_id, 'C', '渠道配置', 'icon-share-alt', 60,
       'setting/channel/config', '/app-setting/channel', 'app-setting/channel/index', 0, 1, 0
WHERE @pa_app_setting_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `paths` = '/app-setting/channel'
  );
SET @pa_channel_menu_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `type` = 'C' AND `paths` = '/app-setting/channel'
  ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_channel_menu_id, 'A', 'H5渠道查看', '', 0,
       'setting/web-page/config', '', '', 0, 1, 0
WHERE @pa_channel_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'setting/web-page/config'
  );
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_channel_menu_id, 'A', 'H5渠道保存', '', 0,
       'setting/web-page/save', '', '', 0, 1, 0
WHERE @pa_channel_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'setting/web-page/save'
  );
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT 1, 'C', '素材管理', 'icon-folder', 50, 'file/lists', '/system/file', 'system/file/index', 0, 1, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `type` = 'C' AND `paths` = '/system/file'
);
SET @pa_file_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'C' AND `paths` = '/system/file'
    ORDER BY `id` ASC
    LIMIT 1
);
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '分类查看', '', 0, 'file/cate/lists', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/cate/lists');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '上传图片', '', 0, 'upload/image', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'upload/image');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '上传视频', '', 0, 'upload/video', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'upload/video');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '上传文件', '', 0, 'upload/file', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'upload/file');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '分类新增', '', 0, 'file/cate/add', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/cate/add');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '分类编辑', '', 0, 'file/cate/edit', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/cate/edit');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '分类删除', '', 0, 'file/cate/delete', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/cate/delete');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '素材移动', '', 0, 'file/move', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/move');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '素材重命名', '', 0, 'file/rename', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/rename');
INSERT INTO `pa_system_menu`
    (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT @pa_file_menu_id, 'A', '素材删除', '', 0, 'file/delete', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/delete');
SET @pa_channel_menu_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `type` = 'C' AND `paths` = '/app-setting/channel'
  ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_channel_menu_id, 'A', '小程序配置查看', '', 0,
       'setting/mini-program/config', '', '', 0, 1, 0
WHERE @pa_channel_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'setting/mini-program/config'
  );
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_channel_menu_id, 'A', '小程序配置保存', '', 0,
       'setting/mini-program/save', '', '', 0, 1, 0
WHERE @pa_channel_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'setting/mini-program/save'
  );
SET @pa_channel_menu_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `type` = 'C' AND `paths` = '/app-setting/channel'
  ORDER BY `id` ASC LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_channel_menu_id, 'A', `seed`.`name`, '', 0, `seed`.`perms`, '', '', 0, 1, 0
FROM (
  SELECT '公众号配置查看' AS `name`, 'setting/official-account/config' AS `perms`
  UNION ALL SELECT '公众号配置保存', 'setting/official-account/save'
  UNION ALL SELECT '公众号菜单查看', 'setting/official-account/menu'
  UNION ALL SELECT '公众号菜单保存', 'setting/official-account/menu/save'
  UNION ALL SELECT '公众号菜单发布', 'setting/official-account/menu/publish'
  UNION ALL SELECT '公众号回复列表', 'setting/official-account/reply/lists'
  UNION ALL SELECT '公众号回复详情', 'setting/official-account/reply/detail'
  UNION ALL SELECT '公众号回复新增', 'setting/official-account/reply/add'
  UNION ALL SELECT '公众号回复编辑', 'setting/official-account/reply/edit'
  UNION ALL SELECT '公众号回复删除', 'setting/official-account/reply/delete'
  UNION ALL SELECT '公众号回复状态', 'setting/official-account/reply/status'
  UNION ALL SELECT '开放平台配置查看', 'setting/open-platform/config'
  UNION ALL SELECT '开放平台配置保存', 'setting/open-platform/save'
) AS `seed`
WHERE @pa_channel_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `perms` = `seed`.`perms`
  );
SET @pa_pay_setting_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `type` = 'C' AND `paths` = '/app-setting/pay'
  ORDER BY `id` LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_pay_setting_id, 'A', seed.name, '', 0, seed.perms, '', '', 0, 1, 0
FROM (
  SELECT '充值配置查看' AS name, 'setting/recharge/config' AS perms
  UNION ALL SELECT '充值配置保存', 'setting/recharge/save'
) seed
WHERE @pa_pay_setting_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` m WHERE LOWER(m.`perms`) = LOWER(seed.perms)
  );
SET @setting_root_id = (
  SELECT id FROM pa_system_menu WHERE type='M' AND paths='/app-setting' ORDER BY id LIMIT 1
);
INSERT INTO pa_system_menu
  (pid,type,name,icon,sort,perms,paths,component,is_cache,is_show,is_disable)
SELECT (SELECT `id` FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/app-setting' ORDER BY `id` LIMIT 1),'C',seed.name,seed.icon,seed.sort,'',seed.paths,seed.component,0,1,0
FROM (
  SELECT '网站设置' name,'icon-desktop' icon,90 sort,'/app-setting/website' paths,'system/config/index' component
  UNION ALL SELECT '用户设置','icon-user',80,'/app-setting/user','app-setting/user/index'
  UNION ALL SELECT '支付设置','icon-payment',70,'/app-setting/pay','app-setting/pay/index'
) seed
WHERE (SELECT `id` FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/app-setting' ORDER BY `id` LIMIT 1) IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM pa_system_menu m WHERE m.paths=seed.paths);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT 0, 'M', '个人中心', 'icon-user', 40, '', '/user', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/user');
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT parent.id, 'C', '个人设置', 'icon-user', 100, 'user:setting', '/user/setting', 'user/setting/index', 0, 1, 0
FROM `pa_system_menu` parent
WHERE parent.`type`='M' AND parent.`paths`='/user'
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `paths`='/user/setting');
INSERT INTO pa_system_menu
  (pid,type,name,icon,sort,perms,paths,component,is_cache,is_show,is_disable)
SELECT parent.id,'A',seed.name,'',0,seed.perms,'','',0,1,0
FROM (
  SELECT '/app-setting/website' parent_path,'网站配置查看' name,'config/website' perms
  UNION ALL SELECT '/app-setting/website','网站配置保存','config/website/save'
  UNION ALL SELECT '/app-setting/website','备案配置查看','config/copyright'
  UNION ALL SELECT '/app-setting/website','备案配置保存','config/copyright/save'
  UNION ALL SELECT '/app-setting/website','协议配置查看','config/agreement'
  UNION ALL SELECT '/app-setting/website','协议配置保存','config/agreement/save'
  UNION ALL SELECT '/app-setting/website','统计配置查看','config/statistics'
  UNION ALL SELECT '/app-setting/website','统计配置保存','config/statistics/save'
  UNION ALL SELECT '/app-setting/user','用户配置查看','config/user'
  UNION ALL SELECT '/app-setting/user','用户配置保存','config/user/save'
  UNION ALL SELECT '/app-setting/user','登录配置查看','config/login'
  UNION ALL SELECT '/app-setting/user','登录配置保存','config/login/save'
  UNION ALL SELECT '/app-setting/pay','支付配置查看','setting/pay/config'
  UNION ALL SELECT '/app-setting/pay','支付配置保存','setting/pay/save'
) seed
JOIN pa_system_menu parent ON parent.paths=seed.parent_path AND parent.type='C'
WHERE NOT EXISTS (
  SELECT 1 FROM pa_system_menu m WHERE LOWER(m.perms)=LOWER(seed.perms)
);
SET @pa_system_root_id = (
  SELECT `id` FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/system' ORDER BY `id` LIMIT 1
);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_system_root_id,'C',`seed`.`name`,`seed`.`icon`,`seed`.`sort`,'',`seed`.`paths`,`seed`.`component`,0,1,0
FROM (
  SELECT '字典管理' AS `name`,'icon-book' AS `icon`,40 AS `sort`,'/system/dict' AS `paths`,'system/dict/index' AS `component`
  UNION ALL SELECT '定时任务','icon-clock-circle',30,'/system/crontab','system/crontab/index'
  UNION ALL SELECT '操作日志','icon-file',20,'/system/log','system/log/index'
  UNION ALL SELECT '系统维护','icon-tool',10,'/system/maintenance','system/maintenance/index'
) AS `seed`
WHERE @pa_system_root_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `paths`=`seed`.`paths`);
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT `parent`.`id`,'A',`seed`.`name`,'',0,`seed`.`perms`,'','',0,1,0
FROM (
  SELECT '/system/dict' AS `parent_path`,'字典类型列表' AS `name`,'dict/type/lists' AS `perms`
  UNION ALL SELECT '/system/dict','字典类型选择','dict/type/all'
  UNION ALL SELECT '/system/dict','字典类型详情','dict/type/detail'
  UNION ALL SELECT '/system/dict','字典类型新增','dict/type/add'
  UNION ALL SELECT '/system/dict','字典类型编辑','dict/type/edit'
  UNION ALL SELECT '/system/dict','字典类型删除','dict/type/delete'
  UNION ALL SELECT '/system/dict','字典类型状态','dict/type/status'
  UNION ALL SELECT '/system/dict','字典数据列表','dict/data/lists'
  UNION ALL SELECT '/system/dict','字典数据选择','dict/data/bytype'
  UNION ALL SELECT '/system/dict','字典数据详情','dict/data/detail'
  UNION ALL SELECT '/system/dict','字典数据新增','dict/data/add'
  UNION ALL SELECT '/system/dict','字典数据编辑','dict/data/edit'
  UNION ALL SELECT '/system/dict','字典数据删除','dict/data/delete'
  UNION ALL SELECT '/system/dict','字典数据状态','dict/data/status'
  UNION ALL SELECT '/system/crontab','定时任务列表','crontab/lists'
  UNION ALL SELECT '/system/crontab','定时任务详情','crontab/detail'
  UNION ALL SELECT '/system/crontab','Cron 预览','crontab/expression'
  UNION ALL SELECT '/system/crontab','定时任务新增','crontab/add'
  UNION ALL SELECT '/system/crontab','定时任务编辑','crontab/edit'
  UNION ALL SELECT '/system/crontab','定时任务删除','crontab/delete'
  UNION ALL SELECT '/system/crontab','定时任务启停','crontab/operate'
  UNION ALL SELECT '/system/log','操作日志列表','log/lists'
  UNION ALL SELECT '/system/log','操作日志清空','log/clear'
  UNION ALL SELECT '/system/maintenance','系统环境信息','system/info'
  UNION ALL SELECT '/system/maintenance','系统缓存清理','system/clearcache'
) AS `seed`
JOIN `pa_system_menu` AS `parent` ON `parent`.`paths`=`seed`.`parent_path` AND `parent`.`type`='C'
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE LOWER(`perms`)=LOWER(`seed`.`perms`));
INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT `parent`.`id`,'A','操作日志异步导出','',0,'log/export','','',0,1,0
FROM `pa_system_menu` AS `parent`
WHERE `parent`.`paths`='/system/log' AND `parent`.`type`='C'
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE LOWER(`perms`)='log/export'
  );

-- Exact Admin API permission nodes required by the default-deny middleware.
-- These are part of the fresh schema; no upgrade-only mapping or alias grant is used.
DROP TEMPORARY TABLE IF EXISTS `pa_admin_permission_seed`;
CREATE TEMPORARY TABLE `pa_admin_permission_seed` (
  `perms` VARCHAR(100) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `parent_permission` VARCHAR(100) NOT NULL DEFAULT '',
  `parent_path` VARCHAR(200) NOT NULL DEFAULT '',
  `type` CHAR(1) NOT NULL DEFAULT 'A',
  `paths` VARCHAR(200) NOT NULL DEFAULT '',
  `component` VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`perms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `pa_admin_permission_seed`
  (`perms`,`name`,`parent_permission`,`parent_path`,`type`,`paths`,`component`)
VALUES
  ('menu/all','菜单选择','menu/lists','','A','',''),
  ('menu/detail','菜单详情','menu/lists','','A','',''),
  ('menu/status','菜单状态','menu/lists','','A','',''),
  ('role/all','角色选择','role/lists','','A','',''),
  ('role/detail','角色详情','role/lists','','A','',''),
  ('admin/detail','管理员详情','admin/lists','','A','',''),
  ('admin/status','管理员状态','admin/lists','','A','',''),
  ('dept/all','部门选择','dept/lists','','A','',''),
  ('dept/leaderdept','负责人部门','dept/lists','','A','',''),
  ('dept/detail','部门详情','dept/lists','','A','',''),
  ('dept/status','部门状态','dept/lists','','A','',''),
  ('jobs/all','岗位选择','jobs/lists','','A','',''),
  ('jobs/detail','岗位详情','jobs/lists','','A','',''),
  ('jobs/status','岗位状态','jobs/lists','','A','',''),
  ('log/export/status','导出状态','log/lists','','A','',''),
  ('log/export/download','导出下载','log/lists','','A','',''),
  ('article.articlecate/all','文章分类选择','article.articlecate/lists','','A','',''),
  ('finance/account-log/lists','余额明细 REST 列表','finance.account_log/lists','','A','',''),
  ('finance/account-log/change-types','余额变动类型','finance.account_log/lists','','A','',''),
  ('finance.account_log/getumchangetype','余额变动类型兼容接口','finance.account_log/lists','','A','',''),
  ('setting/transaction/config','交易设置','','/app-setting','C','/app-setting/transaction','app-setting/transaction/index'),
  ('setting/transaction/save','交易设置保存','setting/transaction/config','','A','',''),
  ('finance/recharge/lists','充值记录 REST 列表','recharge.recharge/lists','/finance/recharge','A','',''),
  ('finance/recharge/refund','充值退款 REST 接口','recharge.recharge/lists','/finance/recharge','A','',''),
  ('finance/recharge/refundagain','重新退款 REST 接口','finance.refund/record','/finance/refund','A','',''),
  ('finance/refund/stat','退款统计 REST 接口','finance.refund/record','/finance/refund','A','',''),
  ('finance/refund/record','退款记录 REST 接口','','/finance','A','',''),
  ('finance/refund/log','退款日志 REST 接口','finance.refund/record','/finance/refund','A','',''),
  ('finance.refund/stat','退款统计兼容接口','finance.refund/record','/finance/refund','A','','');

INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT COALESCE(
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE LOWER(p.`perms`)=LOWER(seed.`parent_permission`)), 0),
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE p.`paths`=seed.`parent_path`), 0)
  ),
  seed.`type`, seed.`name`, '', 0, seed.`perms`, seed.`paths`, seed.`component`, 0, 1, 0
FROM `pa_admin_permission_seed` seed
WHERE COALESCE(
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE LOWER(p.`perms`)=LOWER(seed.`parent_permission`)), 0),
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE p.`paths`=seed.`parent_path`), 0)
  ) IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` existing WHERE LOWER(existing.`perms`)=LOWER(seed.`perms`)
  );

DROP TEMPORARY TABLE `pa_admin_permission_seed`;

DELETE FROM `pa_system_menu` WHERE `paths` IN ('/app-setting/decorate','/app-setting/customer-service') OR `perms` IN ('setting/decorate/config','setting/decorate/save','setting/customer-service/config','setting/customer-service/save','setting/pay/config/set');

INSERT INTO `pa_customer_service_setting` (`tenant_id`,`qr_file_id`,`wechat`,`phone`,`service_time`,`create_time`,`update_time`) VALUES (@pa_default_tenant_id,NULL,'','','',0,0);
INSERT INTO `pa_decorate_tabbar_setting` (`tenant_id`,`style`,`create_time`,`update_time`) VALUES (@pa_default_tenant_id,'{"default_color":"#666666","selected_color":"#2F80ED"}',0,0);
INSERT INTO `pa_transaction_setting` (`tenant_id`,`cancel_unpaid_orders`,`cancel_unpaid_orders_times`,`verification_orders`,`verification_orders_times`,`create_time`,`update_time`) VALUES (@pa_default_tenant_id,1,30,1,24,0,0);

INSERT INTO `pa_external_channel_binding` (`tenant_id`,`provider`,`callback_key`,`identity_hash`,`identity_hint`,`config_json`,`status`,`create_time`,`update_time`)
SELECT @pa_default_tenant_id, providers.`provider`, SHA2(CONCAT('fresh-default:',providers.`provider`),256), SHA2(CONCAT('unconfigured:',providers.`provider`,':default'),256), '', JSON_OBJECT(), 0, 0, 0
FROM (SELECT 'payment.wechat' AS `provider` UNION ALL SELECT 'payment.alipay' UNION ALL SELECT 'wechat.official-account' UNION ALL SELECT 'oauth.wechat.oa' UNION ALL SELECT 'oauth.wechat.mini-program' UNION ALL SELECT 'oauth.wechat.open-pc') providers;

INSERT INTO `pa_permission` (`key`,`module_key`,`type`,`name`,`description`,`risk_level`,`status`,`manifest_version`,`created_at`,`updated_at`,`retired_at`)
SELECT DISTINCT `perms`,CASE
  WHEN `perms` LIKE 'file/%' OR `perms` LIKE 'upload/%' THEN 'official.file'
  WHEN `perms` LIKE 'notice/%' THEN 'official.notification'
  WHEN `perms` LIKE 'setting/web-page/%'
    OR `perms` LIKE 'setting/mini-program/%'
    OR `perms` LIKE 'setting/official-account/%'
    OR `perms` LIKE 'setting/open-platform/%' THEN 'official.oauth'
  WHEN `perms` LIKE 'setting/pay/%'
    OR `perms` LIKE 'setting/recharge/%'
    OR `perms` LIKE 'finance/recharge/%'
    OR `perms` LIKE 'recharge.recharge/%'
    OR `perms` LIKE 'finance/refund/%'
    OR `perms` LIKE 'finance.refund/%' THEN 'official.payment'
  WHEN `perms` LIKE 'member/%'
    OR `perms` LIKE 'user.user/%'
    OR `perms` LIKE 'finance/account-log/%'
    OR `perms` LIKE 'finance.account_log/%' THEN 'official.member'
  WHEN `perms` LIKE 'crontab/%' THEN 'official.task'
  WHEN `perms` IN ('log/export', 'log/export/status', 'log/export/download') THEN 'official.import-export'
  ELSE 'peanut.admin'
END,CASE WHEN `type`='A' THEN 'api' ELSE 'menu' END,`name`,NULL,'normal','active','fresh-schema-v1',TIMESTAMP('2026-08-16 00:00:00.000'),TIMESTAMP('2026-08-16 00:00:00.000'),NULL
FROM `pa_system_menu` WHERE `perms`<>'' AND `is_disable`=0
ON DUPLICATE KEY UPDATE `module_key`=VALUES(`module_key`),`type`=VALUES(`type`),`name`=VALUES(`name`),`status`='active',`updated_at`=VALUES(`updated_at`),`retired_at`=NULL;

INSERT IGNORE INTO `pa_role_permission` (`tenant_id`,`role_id`,`permission_id`,`granted_by_member_id`,`granted_at`)
SELECT role.`tenant_id`,role.`id`,permission.`id`,membership.`tenant_member_id`,TIMESTAMP('2026-08-16 00:00:00.000')
FROM `pa_role` role
JOIN `pa_member_role` membership ON membership.`tenant_id`=role.`tenant_id` AND membership.`role_id`=role.`id`
JOIN `pa_permission` permission ON permission.`status`='active'
WHERE role.`tenant_id`=@pa_default_tenant_id AND role.`key`='core.tenant-owner' AND role.`status`='active';

SET @pa_default_tenant_id = NULL;
