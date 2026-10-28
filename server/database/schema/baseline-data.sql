-- Versioned system defaults only; never replace this file with an instance dump.
-- Tenant identities and callback keys are supplied/generated for this installation.
-- Menu bootstrap keys derive from (type, perms, paths, name), then converge to canonical identities.
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
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/system', '权限管理'), 256)), 1,  0, 'M', '权限管理',   'icon-settings', 100, '',            '/system',       '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'menu/lists', '/system/menu', '菜单管理'), 256)), 2,  1, 'C', '菜单管理',   'icon-menu',      90, 'menu/lists',  '/system/menu',  'system/menu/index',   0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'menu/add', '', '菜单新增'), 256)), 3,  2, 'A', '菜单新增',   '',                0, 'menu/add',    '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'menu/edit', '', '菜单编辑'), 256)), 4,  2, 'A', '菜单编辑',   '',                0, 'menu/edit',   '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'menu/delete', '', '菜单删除'), 256)), 5,  2, 'A', '菜单删除',   '',                0, 'menu/delete', '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'role/lists', '/system/role', '角色管理'), 256)), 6,  1, 'C', '角色管理',   'icon-user-group', 80, 'role/lists',  '/system/role',  'system/role/index',   0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'role/add', '', '角色新增'), 256)), 7,  6, 'A', '角色新增',   '',                0, 'role/add',    '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'role/edit', '', '角色编辑'), 256)), 8,  6, 'A', '角色编辑',   '',                0, 'role/edit',   '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'role/delete', '', '角色删除'), 256)), 9,  6, 'A', '角色删除',   '',                0, 'role/delete', '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'admin/lists', '/system/admin', '管理员管理'), 256)), 10, 1, 'C', '管理员管理', 'icon-user',       70, 'admin/lists', '/system/admin', 'system/admin/index',  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'admin/add', '', '管理员新增'), 256)), 11,10, 'A', '管理员新增', '',                0, 'admin/add',   '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'admin/edit', '', '管理员编辑'), 256)), 12,10, 'A', '管理员编辑', '',                0, 'admin/edit',  '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'admin/delete', '', '管理员删除'), 256)), 13,10, 'A', '管理员删除', '',                0, 'admin/delete','',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/dashboard', '仪表盘'), 256)), 14, 0, 'M', '仪表盘',     'icon-dashboard', 110, '',                    '/dashboard',           '',                          0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'workbench/index', '/dashboard/workplace', '工作台'), 256)), 15,14, 'C', '工作台',     'icon-dashboard', 100, 'workbench/index',     '/dashboard/workplace', 'dashboard/workplace/index', 0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'jobs/lists', '/system/jobs', '岗位管理'), 256)), 16, 1, 'C', '岗位管理',   'icon-idcard',      60, 'jobs/lists',  '/system/jobs',  'system/jobs/index',   0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'jobs/add', '', '岗位新增'), 256)), 17,16, 'A', '岗位新增',   '',                30, 'jobs/add',    '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'jobs/edit', '', '岗位编辑'), 256)), 18,16, 'A', '岗位编辑',   '',                20, 'jobs/edit',   '',              '',                    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'jobs/delete', '', '岗位删除'), 256)), 19,16, 'A', '岗位删除',   '',                10, 'jobs/delete', '',              '',                    0, 1, 0)
;

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/member', '会员管理'), 256)), 20, 0, 'M', '会员管理',   'icon-user',       80, '',                   '/member',            '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'member/lists', '/member/list', '会员列表'), 256)), 21,20, 'C', '会员列表',   'icon-user-group',  90, 'member/lists',       '/member/list',       'member/list/index',        0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/detail', '', '会员详情'), 256)), 22,21, 'A', '会员详情',   '',                  0, 'member/detail',      '',                   '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/status', '', '会员状态'), 256)), 23,21, 'A', '会员状态',   '',                  0, 'member/status',      '',                   '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/adjustBalance', '', '余额调整'), 256)), 24,21, 'A', '余额调整',   '',                  0, 'member/adjustBalance','',                  '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/add', '', '会员新增'), 256)), 59,21, 'A', '会员新增',   '',                  0, 'member/add',         '',                   '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/edit', '', '会员编辑'), 256)), 60,21, 'A', '会员编辑',   '',                  0, 'member/edit',        '',                   '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/adjustMoney', '', '余额调整'), 256)), 61,21, 'A', '余额调整',   '',                  0, 'member/adjustMoney', '',                   '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'user.user/detail', '', '用户详情'), 256)), 62,21, 'A', '用户详情',   '',                  0, 'user.user/detail',   '',                   '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'user.user/edit', '', '用户编辑'), 256)), 63,21, 'A', '用户编辑',   '',                  0, 'user.user/edit',     '',                   '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'user.user/adjustMoney', '', '余额调整'), 256)), 64,21, 'A', '余额调整',   '',                  0, 'user.user/adjustMoney','',                 '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/profile/edit', '', '会员资料编辑'), 256)), 65,21, 'A', '会员资料编辑','',                  0, 'member/profile/edit','',                  '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'member/tag/lists', '/member/tag', '会员标签'), 256)), 25,20, 'C', '会员标签',   'icon-tag',         80, 'member/tag/lists',   '/member/tag',        'member/tag/index',         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/tag/add', '', '标签新增'), 256)), 26,25, 'A', '标签新增',   '',                  0, 'member/tag/add',     '',                   '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/tag/edit', '', '标签编辑'), 256)), 27,25, 'A', '标签编辑',   '',                  0, 'member/tag/edit',    '',                   '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/tag/delete', '', '标签删除'), 256)), 28,25, 'A', '标签删除',   '',                  0, 'member/tag/delete',  '',                   '',                         0, 1, 0)
;

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/notice', '通知管理'), 256)), 30, 0, 'M', '通知管理',   'icon-notification', 70, '',                      '/notice',              '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'notice/channel/detail', '/notice/channel', '渠道配置'), 256)), 31,30, 'C', '渠道配置',   'icon-settings',     90, 'notice/channel/detail', '/notice/channel',      'notice/channel/index',     0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/channel/save', '', '渠道保存'), 256)), 32,31, 'A', '渠道保存',   '',                   0, 'notice/channel/save',   '',                     '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'notice/scene/lists', '/notice/template', '通知场景'), 256)), 33,30, 'C', '通知场景',   'icon-file',         80, 'notice/scene/lists',    '/notice/template',     'notice/template/index',    0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/template/add', '', '模板新增'), 256)), 34,33, 'A', '模板新增',   '',                   0, 'notice/template/add',   '',                     '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/template/edit', '', '模板编辑'), 256)), 35,33, 'A', '模板编辑',   '',                   0, 'notice/template/edit',  '',                     '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/template/delete', '', '模板删除'), 256)), 36,33, 'A', '模板删除',   '',                   0, 'notice/template/delete','',                     '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'notice/log/lists', '/notice/log', '发送日志'), 256)), 37,30, 'C', '发送日志',   'icon-history',      70, 'notice/log/lists',      '/notice/log',          'notice/log/index',         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/scene/detail', '', '场景详情'), 256)), 73,33, 'A', '场景详情',   '',                   0, 'notice/scene/detail',     '',                     '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/scene/save', '', '场景设置'), 256)), 74,33, 'A', '场景设置',   '',                   0, 'notice/scene/save',       '',                     '',                         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/log/detail', '', '日志详情'), 256)), 75,37, 'A', '日志详情',   '',                   0, 'notice/log/detail',       '',                     '',                         0, 1, 0)
;

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/finance', '财务管理'), 256)), 38, 0, 'M', '财务管理', 'icon-fingerprint', 60, '',                              '/finance',             '',                          0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'finance.account_log/lists', '/finance/account-log', '余额明细'), 256)), 39,38, 'C', '余额明细', 'icon-bar-chart',   90, 'finance.account_log/lists',     '/finance/account-log', 'finance/account-log/index', 0, 1, 0)
;

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/app-setting', '应用设置'), 256)), 40, 0, 'M', '应用设置', 'icon-apps',        50, '',                                '/app-setting',                    '',                                     0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'setting/hot-search/config', '/app-setting/hot-search', '热门搜索'), 256)), 41,40, 'C', '热门搜索', 'icon-search',      90, 'setting/hot-search/config',       '/app-setting/hot-search',         'app-setting/hot-search/index',         0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/hot-search/save', '', '热门搜索保存'), 256)), 42,41, 'A', '热门搜索保存', '',              0, 'setting/hot-search/save',         '',                                '',                                     0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'setting/customer-service/config', '/app-setting/customer-service', '客服设置'), 256)), 43,40, 'C', '客服设置', 'icon-customer-service', 80, 'setting/customer-service/config', '/app-setting/customer-service',  'app-setting/customer-service/index',   0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/customer-service/save', '', '客服设置保存'), 256)), 44,43, 'A', '客服设置保存', '',              0, 'setting/customer-service/save',   '',                                '',                                     0, 1, 0)
;

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/web-page/config', '', 'H5渠道查看'), 256)), 95,55, 'A', 'H5渠道查看', '', 0, 'setting/web-page/config', '', '', 0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/web-page/save', '', 'H5渠道保存'), 256)), 96,55, 'A', 'H5渠道保存', '', 0, 'setting/web-page/save',   '', '', 0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/mini-program/config', '', '小程序配置查看'), 256)), 97,55, 'A', '小程序配置查看', '', 0, 'setting/mini-program/config', '', '', 0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/mini-program/save', '', '小程序配置保存'), 256)), 98,55, 'A', '小程序配置保存', '', 0, 'setting/mini-program/save',   '', '', 0, 1, 0)
;

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/article', '内容管理'), 256)), 45, 0, 'M', '内容管理', 'icon-file',        55, '',                       '/article',              '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'article.articleCate/lists', '/article/cate', '文章分类'), 256)), 46,45, 'C', '文章分类', 'icon-folder',      90, 'article.articleCate/lists',       '/article/cate',         'article/cate/index',     0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/add', '', '分类新增'), 256)), 47,46, 'A', '分类新增', '',                  0, 'article.articleCate/add',         '',                       '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/edit', '', '分类编辑'), 256)), 48,46, 'A', '分类编辑', '',                  0, 'article.articleCate/edit',        '',                       '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/delete', '', '分类删除'), 256)), 49,46, 'A', '分类删除', '',                  0, 'article.articleCate/delete',      '',                       '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'article.article/lists', '/article/list', '文章管理'), 256)), 50,45, 'C', '文章管理', 'icon-file',        80, 'article.article/lists',          '/article/list',         'article/list/index',     0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/add', '', '文章新增'), 256)), 51,50, 'A', '文章新增', '',                  0, 'article.article/add',            '',                       '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/edit', '', '文章编辑'), 256)), 52,50, 'A', '文章编辑', '',                  0, 'article.article/edit',           '',                       '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/delete', '', '文章删除'), 256)), 53,50, 'A', '文章删除', '',                  0, 'article.article/delete',         '',                       '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/detail', '', '分类详情'), 256)), 69,46, 'A', '分类详情', '',                  0, 'article.articleCate/detail',      '',                       '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/updateStatus', '', '分类状态'), 256)), 70,46, 'A', '分类状态', '',                  0, 'article.articleCate/updateStatus','',                       '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/detail', '', '文章详情'), 256)), 71,50, 'A', '文章详情', '',                  0, 'article.article/detail',         '',                       '',                       0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/updateStatus', '', '文章状态'), 256)), 72,50, 'A', '文章状态', '',                  0, 'article.article/updateStatus',   '',                       '',                       0, 1, 0)
;

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'file/lists', '/system/file', '素材管理'), 256)), 84, 1, 'C', '素材管理',   'icon-folder', 50, 'file/lists',       '/system/file', 'system/file/index', 0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/cate/lists', '', '分类查看'), 256)), 85,84, 'A', '分类查看',   '',             0, 'file/cate/lists',  '',             '',                  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'upload/image', '', '上传图片'), 256)), 86,84, 'A', '上传图片',   '',             0, 'upload/image',     '',             '',                  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'upload/video', '', '上传视频'), 256)), 87,84, 'A', '上传视频',   '',             0, 'upload/video',     '',             '',                  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'upload/file', '', '上传文件'), 256)), 88,84, 'A', '上传文件',   '',             0, 'upload/file',      '',             '',                  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/cate/add', '', '分类新增'), 256)), 89,84, 'A', '分类新增',   '',             0, 'file/cate/add',    '',             '',                  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/cate/edit', '', '分类编辑'), 256)), 90,84, 'A', '分类编辑',   '',             0, 'file/cate/edit',   '',             '',                  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/cate/delete', '', '分类删除'), 256)), 91,84, 'A', '分类删除',   '',             0, 'file/cate/delete', '',             '',                  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/move', '', '素材移动'), 256)), 92,84, 'A', '素材移动',   '',             0, 'file/move',        '',             '',                  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/rename', '', '素材重命名'), 256)), 93,84, 'A', '素材重命名', '',             0, 'file/rename',      '',             '',                  0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/delete', '', '素材删除'), 256)), 94,84, 'A', '素材删除',   '',             0, 'file/delete',      '',             '',                  0, 1, 0)
;

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'setting/pay/config', '/app-setting/pay', '支付配置'), 256)), 54,40,'C','支付配置',  'icon-payment',    70, 'setting/pay/config',      '/app-setting/pay',      'app-setting/pay/index',      0,1,0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'setting/channel/config', '/app-setting/channel', '渠道配置'), 256)), 55,40,'C','渠道配置',  'icon-share-alt',  60, 'setting/channel/config',  '/app-setting/channel',  'app-setting/channel/index',  0,1,0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'setting/decorate/config', '/app-setting/decorate', '页面装修'), 256)), 56,40,'C','页面装修',  'icon-brush',      50, 'setting/decorate/config', '/app-setting/decorate', 'app-setting/decorate/index', 0,1,0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'recharge.recharge/lists', '/finance/recharge', '充值记录'), 256)), 57,38,'C','充值记录',  'icon-thunderbolt', 80, 'recharge.recharge/lists', '/finance/recharge',     'finance/recharge/index',     0,1,0)
;

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'finance.refund/record', '/finance/refund', '退款记录'), 256)), 58,38,'C','退款记录','icon-undo',70,'finance.refund/record','/finance/refund','finance/refund/index',0,1,0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'recharge.recharge/refund', '', '退款'), 256)), 66,57,'A','退款','',0,'recharge.recharge/refund','','',0,1,0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'recharge.recharge/refundAgain', '', '重新退款'), 256)), 67,58,'A','重新退款','',0,'recharge.recharge/refundAgain','','',0,1,0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('A', 'finance.refund/log', '', '退款日志'), 256)), 68,58,'A','退款日志','',0,'finance.refund/log','','',0,1,0)
;

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', 'jobs/lists', '/system/jobs', '岗位管理'), 256)), 1, 'C', '岗位管理', 'icon-idcard', 60, 'jobs/lists', '/system/jobs', 'system/jobs/index', 0, 1, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `paths` = '/system/jobs' AND `type` = 'C'
);

SET @pa_jobs_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `paths` = '/system/jobs' AND `type` = 'C'
    ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'jobs/add', '', '新增岗位'), 256)), @pa_jobs_menu_id, 'A', '新增岗位', '', 30, 'jobs/add', '', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'jobs/add');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'jobs/edit', '', '编辑岗位'), 256)), @pa_jobs_menu_id, 'A', '编辑岗位', '', 20, 'jobs/edit', '', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'jobs/edit');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'jobs/delete', '', '删除岗位'), 256)), @pa_jobs_menu_id, 'A', '删除岗位', '', 10, 'jobs/delete', '', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'jobs/delete');

SET @pa_member_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `paths` = '/member/list' AND `type` = 'C'
    ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/add', '', '会员新增'), 256)), @pa_member_menu_id, 'A', '会员新增', '', 0, 'member/add', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'member/add');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/edit', '', '会员编辑'), 256)), @pa_member_menu_id, 'A', '会员编辑', '', 0, 'member/edit', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'member/edit');

INSERT IGNORE INTO `pa_system_menu`
  (`menu_key`, `id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
VALUES
  (CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/dashboard', '仪表盘'), 256)), 14, 0, 'M', '仪表盘', 'icon-dashboard', 110, '',                '/dashboard',           '',                          0, 1, 0),
  (CONCAT('seed.', SHA2(JSON_ARRAY('C', 'workbench/index', '/dashboard/workplace', '工作台'), 256)), 15,14, 'C', '工作台', 'icon-dashboard', 100, 'workbench/index', '/dashboard/workplace', 'dashboard/workplace/index', 0, 1, 0)
;

SET @pa_member_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `paths` = '/member/list' AND `type` = 'C'
    ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/adjustMoney', '', '余额调整'), 256)), @pa_member_menu_id, 'A', '余额调整', '', 0, 'member/adjustMoney', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'member/adjustMoney');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'member/profile/edit', '', '会员资料编辑'), 256)), @pa_member_menu_id, 'A', '会员资料编辑', '', 0, 'member/profile/edit', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'member/profile/edit');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'user.user/detail', '', '用户详情'), 256)), @pa_member_menu_id, 'A', '用户详情', '', 0, 'user.user/detail', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'user.user/detail');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'user.user/edit', '', '用户编辑'), 256)), @pa_member_menu_id, 'A', '用户编辑', '', 0, 'user.user/edit', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'user.user/edit');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'user.user/adjustMoney', '', '余额调整'), 256)), @pa_member_menu_id, 'A', '余额调整', '', 0, 'user.user/adjustMoney', '', '', 0, 1, 0
WHERE @pa_member_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'user.user/adjustMoney');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/finance', '财务管理'), 256)), 0, 'M', '财务管理', 'icon-fingerprint', 60, '', '/finance', '', 0, 1, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `type` = 'M' AND `paths` = '/finance'
);

SET @pa_finance_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'M' AND `paths` = '/finance'
    ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', 'finance.account_log/lists', '/finance/account-log', '余额明细'), 256)), @pa_finance_menu_id, 'C', '余额明细', 'icon-bar-chart', 90,
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
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/add', '', '分类新增'), 256)), @pa_article_cate_menu_id, 'A', '分类新增', '', 0, 'article.articleCate/add', '', '', 0, 1, 0
WHERE @pa_article_cate_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `pid` = @pa_article_cate_menu_id AND `perms` = 'article.articleCate/add'
  );

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/edit', '', '分类编辑'), 256)), @pa_article_cate_menu_id, 'A', '分类编辑', '', 0, 'article.articleCate/edit', '', '', 0, 1, 0
WHERE @pa_article_cate_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `pid` = @pa_article_cate_menu_id AND `perms` = 'article.articleCate/edit'
  );

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/delete', '', '分类删除'), 256)), @pa_article_cate_menu_id, 'A', '分类删除', '', 0, 'article.articleCate/delete', '', '', 0, 1, 0
WHERE @pa_article_cate_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `pid` = @pa_article_cate_menu_id AND `perms` = 'article.articleCate/delete'
  );

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/detail', '', '分类详情'), 256)), @pa_article_cate_menu_id, 'A', '分类详情', '', 0, 'article.articleCate/detail', '', '', 0, 1, 0
WHERE @pa_article_cate_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu`
      WHERE `pid` = @pa_article_cate_menu_id AND `perms` = 'article.articleCate/detail'
  );

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.articleCate/updateStatus', '', '分类状态'), 256)), @pa_article_cate_menu_id, 'A', '分类状态', '', 0, 'article.articleCate/updateStatus', '', '', 0, 1, 0
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
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/add', '', '文章新增'), 256)), @pa_article_menu_id, 'A', '文章新增', '', 0, 'article.article/add', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/add'
);

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/edit', '', '文章编辑'), 256)), @pa_article_menu_id, 'A', '文章编辑', '', 0, 'article.article/edit', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/edit'
);

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/delete', '', '文章删除'), 256)), @pa_article_menu_id, 'A', '文章删除', '', 0, 'article.article/delete', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/delete'
);

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/detail', '', '文章详情'), 256)), @pa_article_menu_id, 'A', '文章详情', '', 0, 'article.article/detail', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/detail'
);

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'article.article/updateStatus', '', '文章状态'), 256)), @pa_article_menu_id, 'A', '文章状态', '', 0, 'article.article/updateStatus', '', '', 0, 1, 0
WHERE @pa_article_menu_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `pid` = @pa_article_menu_id AND `perms` = 'article.article/updateStatus'
);

SET @pa_notice_scene_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'C' AND `paths` = '/notice/template'
    ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/scene/detail', '', '场景详情'), 256)), @pa_notice_scene_menu_id, 'A', '场景详情', '', 0, 'notice/scene/detail', '', '', 0, 1, 0
WHERE @pa_notice_scene_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'notice/scene/detail');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/scene/save', '', '场景设置'), 256)), @pa_notice_scene_menu_id, 'A', '场景设置', '', 0, 'notice/scene/save', '', '', 0, 1, 0
WHERE @pa_notice_scene_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'notice/scene/save');

SET @pa_notice_log_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'C' AND `paths` = '/notice/log'
    ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'notice/log/detail', '', '日志详情'), 256)), @pa_notice_log_menu_id, 'A', '日志详情', '', 0, 'notice/log/detail', '', '', 0, 1, 0
WHERE @pa_notice_log_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'notice/log/detail');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/finance', '财务管理'), 256)), 0, 'M', '财务管理', 'icon-fingerprint', 60, '', '/finance', '', 0, 1, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `type` = 'M' AND `paths` = '/finance'
);

SET @pa_finance_menu_id = (
    SELECT `id` FROM `pa_system_menu`
    WHERE `type` = 'M' AND `paths` = '/finance'
    ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', 'recharge.recharge/lists', '/finance/recharge', '充值记录'), 256)), @pa_finance_menu_id, 'C', '充值记录', 'icon-thunderbolt', 80,
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
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'recharge.recharge/refund', '', '退款'), 256)), @pa_recharge_menu_id, 'A', '退款', '', 0, 'recharge.recharge/refund', '', '', 0, 1, 0
WHERE @pa_recharge_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu` WHERE LOWER(`perms`) = 'recharge.recharge/refund'
  );

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', 'finance.refund/record', '/finance/refund', '退款记录'), 256)), @pa_finance_menu_id, 'C', '退款记录', 'icon-undo', 70,
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
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'recharge.recharge/refundAgain', '', '重新退款'), 256)), @pa_refund_menu_id, 'A', '重新退款', '', 0, 'recharge.recharge/refundAgain', '', '', 0, 1, 0
WHERE @pa_refund_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu` WHERE LOWER(`perms`) = 'recharge.recharge/refundagain'
  );

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'finance.refund/log', '', '退款日志'), 256)), @pa_refund_menu_id, 'A', '退款日志', '', 0, 'finance.refund/log', '', '', 0, 1, 0
WHERE @pa_refund_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `pa_system_menu` WHERE LOWER(`perms`) = 'finance.refund/log'
  );

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/dev-tools', '开发工具'), 256)), 0, 'M', '开发工具', 'icon-tool', 20, '', '/dev-tools', '', 0, 1, 0
WHERE NOT EXISTS (
  SELECT 1 FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/dev-tools'
);

SET @pa_dev_tools_menu_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `type`='M' AND `paths`='/dev-tools'
  ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', 'generator/lists', '/dev-tools/code', '代码生成器'), 256)), @pa_dev_tools_menu_id, 'C', '代码生成器', 'icon-code', 90,
       'generator/lists', '/dev-tools/code', 'dev-tools/code/index', 0, 1, 0
WHERE @pa_dev_tools_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/lists');

SET @pa_generator_menu_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `perms`='generator/lists'
  ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/source-tables', '', '读取数据表'), 256)), @pa_generator_menu_id, 'A', '读取数据表', '', 0, 'generator/source-tables', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/source-tables');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/import', '', '导入数据表'), 256)), @pa_generator_menu_id, 'A', '导入数据表', '', 0, 'generator/import', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/import');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/detail', '', '查看配置'), 256)), @pa_generator_menu_id, 'A', '查看配置', '', 0, 'generator/detail', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/detail');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/sync', '', '同步字段'), 256)), @pa_generator_menu_id, 'A', '同步字段', '', 0, 'generator/sync', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/sync');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/update', '', '编辑配置'), 256)), @pa_generator_menu_id, 'A', '编辑配置', '', 0, 'generator/update', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/update');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/delete', '', '删除配置'), 256)), @pa_generator_menu_id, 'A', '删除配置', '', 0, 'generator/delete', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/delete');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/preview', '', '预览代码'), 256)), @pa_generator_menu_id, 'A', '预览代码', '', 0, 'generator/preview', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/preview');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/generate', '', '生成代码'), 256)), @pa_generator_menu_id, 'A', '生成代码', '', 0, 'generator/generate', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/generate');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/download', '', '下载代码'), 256)), @pa_generator_menu_id, 'A', '下载代码', '', 0, 'generator/download', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/download');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'generator/models', '', '模型列表'), 256)), @pa_generator_menu_id, 'A', '模型列表', '', 0, 'generator/models', '', '', 0, 1, 0
WHERE @pa_generator_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms`='generator/models');

SET @pa_decoration_root_id = (
  SELECT `id` FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/decoration' ORDER BY `id` LIMIT 1
);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/decoration', '装修管理'), 256)), 0,'M','装修管理','icon-palette',30,'','/decoration','',0,1,0
WHERE @pa_decoration_root_id IS NULL;

SET @pa_decoration_root_id = (
  SELECT `id` FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/decoration' ORDER BY `id` LIMIT 1
);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', '', `seed`.`paths`, `seed`.`name`), 256)), @pa_decoration_root_id,'C',`seed`.`name`,'',`seed`.`sort`,'',`seed`.`paths`,`seed`.`component`,0,1,0
FROM (
  SELECT '移动端装修' AS `name`,30 AS `sort`,'/decoration/mobile' AS `paths`,'decoration/mobile/index' AS `component`
  UNION ALL SELECT 'Tabbar 装修',20,'/decoration/tabbar','decoration/tabbar/index'
  UNION ALL SELECT 'PC 装修',10,'/decoration/pc','decoration/pc/index'
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `paths`=`seed`.`paths`);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', `seed`.`perms`, '', `seed`.`name`), 256)), `parent`.`id`,'A',`seed`.`name`,'',0,`seed`.`perms`,'','',0,1,0
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
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', 'setting/channel/config', '/app-setting/channel', '渠道配置'), 256)), @pa_app_setting_menu_id, 'C', '渠道配置', 'icon-share-alt', 60,
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
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/web-page/config', '', 'H5渠道查看'), 256)), @pa_channel_menu_id, 'A', 'H5渠道查看', '', 0,
       'setting/web-page/config', '', '', 0, 1, 0
WHERE @pa_channel_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'setting/web-page/config'
  );

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/web-page/save', '', 'H5渠道保存'), 256)), @pa_channel_menu_id, 'A', 'H5渠道保存', '', 0,
       'setting/web-page/save', '', '', 0, 1, 0
WHERE @pa_channel_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'setting/web-page/save'
  );

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', 'file/lists', '/system/file', '素材管理'), 256)), 1, 'C', '素材管理', 'icon-folder', 50, 'file/lists', '/system/file', 'system/file/index', 0, 1, 0
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
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/cate/lists', '', '分类查看'), 256)), @pa_file_menu_id, 'A', '分类查看', '', 0, 'file/cate/lists', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/cate/lists');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'upload/image', '', '上传图片'), 256)), @pa_file_menu_id, 'A', '上传图片', '', 0, 'upload/image', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'upload/image');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'upload/video', '', '上传视频'), 256)), @pa_file_menu_id, 'A', '上传视频', '', 0, 'upload/video', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'upload/video');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'upload/file', '', '上传文件'), 256)), @pa_file_menu_id, 'A', '上传文件', '', 0, 'upload/file', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'upload/file');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/cate/add', '', '分类新增'), 256)), @pa_file_menu_id, 'A', '分类新增', '', 0, 'file/cate/add', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/cate/add');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/cate/edit', '', '分类编辑'), 256)), @pa_file_menu_id, 'A', '分类编辑', '', 0, 'file/cate/edit', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/cate/edit');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/cate/delete', '', '分类删除'), 256)), @pa_file_menu_id, 'A', '分类删除', '', 0, 'file/cate/delete', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/cate/delete');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/move', '', '素材移动'), 256)), @pa_file_menu_id, 'A', '素材移动', '', 0, 'file/move', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/move');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/rename', '', '素材重命名'), 256)), @pa_file_menu_id, 'A', '素材重命名', '', 0, 'file/rename', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/rename');

INSERT INTO `pa_system_menu`
    (`menu_key`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `is_cache`, `is_show`, `is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'file/delete', '', '素材删除'), 256)), @pa_file_menu_id, 'A', '素材删除', '', 0, 'file/delete', '', '', 0, 1, 0
WHERE @pa_file_menu_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'file/delete');

SET @pa_channel_menu_id = (
  SELECT `id` FROM `pa_system_menu`
  WHERE `type` = 'C' AND `paths` = '/app-setting/channel'
  ORDER BY `id` ASC LIMIT 1
);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/mini-program/config', '', '小程序配置查看'), 256)), @pa_channel_menu_id, 'A', '小程序配置查看', '', 0,
       'setting/mini-program/config', '', '', 0, 1, 0
WHERE @pa_channel_menu_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE `perms` = 'setting/mini-program/config'
  );

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'setting/mini-program/save', '', '小程序配置保存'), 256)), @pa_channel_menu_id, 'A', '小程序配置保存', '', 0,
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
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', `seed`.`perms`, '', `seed`.`name`), 256)), @pa_channel_menu_id, 'A', `seed`.`name`, '', 0, `seed`.`perms`, '', '', 0, 1, 0
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
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', seed.perms, '', seed.name), 256)), @pa_pay_setting_id, 'A', seed.name, '', 0, seed.perms, '', '', 0, 1, 0
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
  (`menu_key`, pid,type,name,icon,sort,perms,paths,component,is_cache,is_show,is_disable)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', '', seed.paths, seed.name), 256)), (SELECT `id` FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/app-setting' ORDER BY `id` LIMIT 1),'C',seed.name,seed.icon,seed.sort,'',seed.paths,seed.component,0,1,0
FROM (
  SELECT '网站设置' name,'icon-desktop' icon,90 sort,'/app-setting/website' paths,'system/config/index' component
  UNION ALL SELECT '用户设置','icon-user',80,'/app-setting/user','app-setting/user/index'
  UNION ALL SELECT '支付设置','icon-payment',70,'/app-setting/pay','app-setting/pay/index'
) seed
WHERE (SELECT `id` FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/app-setting' ORDER BY `id` LIMIT 1) IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM pa_system_menu m WHERE m.paths=seed.paths);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/user', '个人中心'), 256)), 0, 'M', '个人中心', 'icon-user', 40, '', '/user', '', 0, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `type`='M' AND `paths`='/user');

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', 'user:setting', '/user/setting', '个人设置'), 256)), parent.id, 'C', '个人设置', 'icon-user', 100, 'user:setting', '/user/setting', 'user/setting/index', 0, 1, 0
FROM `pa_system_menu` parent
WHERE parent.`type`='M' AND parent.`paths`='/user'
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `paths`='/user/setting');

INSERT INTO pa_system_menu
  (`menu_key`, pid,type,name,icon,sort,perms,paths,component,is_cache,is_show,is_disable)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', seed.perms, '', seed.name), 256)), parent.id,'A',seed.name,'',0,seed.perms,'','',0,1,0
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
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', '', `seed`.`paths`, `seed`.`name`), 256)), @pa_system_root_id,'C',`seed`.`name`,`seed`.`icon`,`seed`.`sort`,'',`seed`.`paths`,`seed`.`component`,0,1,0
FROM (
  SELECT '字典管理' AS `name`,'icon-book' AS `icon`,40 AS `sort`,'/system/dict' AS `paths`,'system/dict/index' AS `component`
  UNION ALL SELECT '定时任务','icon-clock-circle',30,'/system/crontab','system/crontab/index'
  UNION ALL SELECT '操作日志','icon-file',20,'/system/log','system/log/index'
  UNION ALL SELECT '系统维护','icon-tool',10,'/system/maintenance','system/maintenance/index'
) AS `seed`
WHERE @pa_system_root_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `pa_system_menu` WHERE `paths`=`seed`.`paths`);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', `seed`.`perms`, '', `seed`.`name`), 256)), `parent`.`id`,'A',`seed`.`name`,'',0,`seed`.`perms`,'','',0,1,0
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
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('A', 'log/export', '', '操作日志异步导出'), 256)), `parent`.`id`,'A','操作日志异步导出','',0,'log/export','','',0,1,0
FROM `pa_system_menu` AS `parent`
WHERE `parent`.`paths`='/system/log' AND `parent`.`type`='C'
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` WHERE LOWER(`perms`)='log/export'
  );

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
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY(seed.`type`, seed.`perms`, seed.`paths`, seed.`name`), 256)), COALESCE(
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE seed.`parent_permission`<>'' AND LOWER(p.`perms`)=LOWER(seed.`parent_permission`)), 0),
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE seed.`parent_path`<>'' AND p.`paths`=seed.`parent_path`), 0)
  ),
  seed.`type`, seed.`name`, '', 0, seed.`perms`, seed.`paths`, seed.`component`, 0, 1, 0
FROM `pa_admin_permission_seed` seed
WHERE seed.`type`='C' AND COALESCE(
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE seed.`parent_permission`<>'' AND LOWER(p.`perms`)=LOWER(seed.`parent_permission`)), 0),
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE seed.`parent_path`<>'' AND p.`paths`=seed.`parent_path`), 0)
  ) IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu` existing WHERE LOWER(existing.`perms`)=LOWER(seed.`perms`)
  );

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY(seed.`type`, seed.`perms`, seed.`paths`, seed.`name`), 256)), COALESCE(
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE seed.`parent_permission`<>'' AND LOWER(p.`perms`)=LOWER(seed.`parent_permission`)), 0),
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE seed.`parent_path`<>'' AND p.`paths`=seed.`parent_path`), 0)
  ),
  seed.`type`, seed.`name`, '', 0, seed.`perms`, seed.`paths`, seed.`component`, 0, 1, 0
FROM `pa_admin_permission_seed` seed
WHERE seed.`type`<>'C' AND COALESCE(
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE seed.`parent_permission`<>'' AND LOWER(p.`perms`)=LOWER(seed.`parent_permission`)), 0),
    NULLIF((SELECT MIN(`id`) FROM `pa_system_menu` p WHERE seed.`parent_path`<>'' AND p.`paths`=seed.`parent_path`), 0)
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
SELECT @pa_default_tenant_id, providers.`provider`, LOWER(HEX(RANDOM_BYTES(32))), SHA2(CONCAT('unconfigured:',@pa_default_tenant_code,':',providers.`provider`),256), '', JSON_OBJECT(), 0, 0, 0
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

CREATE TEMPORARY TABLE `pa_dept_action_seed` (
  `action_key` VARCHAR(160) NOT NULL,
  `action_name` VARCHAR(50) NOT NULL,
  `sort_order` SMALLINT NOT NULL,
  PRIMARY KEY (`action_key`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `pa_dept_action_seed` VALUES
  ('core.department.create','新增部门',40),
  ('core.department.update','编辑部门',30),
  ('core.department.move','移动部门',20),
  ('core.department.archive','归档部门',10);

CREATE TEMPORARY TABLE `pa_dept_action_assertion` (
  `phase` VARCHAR(64) NOT NULL,
  `valid` INT NOT NULL,
  CONSTRAINT `chk_dept_action_assertion` CHECK (`valid`=1)
);

INSERT INTO `pa_dept_action_assertion`
SELECT 'action_identity_conflict',IF(COUNT(*)=0,1,0)
FROM `pa_system_menu` m JOIN `pa_dept_action_seed` seed
  ON m.`menu_key`=seed.`action_key` OR m.`perms`=seed.`action_key`
WHERE NOT (m.`menu_key`<=>seed.`action_key` AND m.`perms`=seed.`action_key`
  AND m.`parent_key`<=>'core.department.list' AND m.`module_key`='core'
  AND m.`pid`=0 AND m.`type`='A' AND m.`name`=seed.`action_name`
  AND m.`sort`=seed.`sort_order` AND m.`icon`='' AND m.`paths`='' AND m.`component`=''
  AND m.`is_cache`=0 AND m.`is_show`=1 AND m.`is_disable`=0
  AND m.`upstream_defaults_json` IS NULL AND m.`menu_conflict_json` IS NULL);

INSERT INTO `pa_dept_action_assertion`
SELECT 'action_identity_ambiguous',IF(COUNT(*)=0,1,0) FROM (
  SELECT seed.`action_key` FROM `pa_dept_action_seed` seed
  JOIN `pa_system_menu` m ON m.`menu_key`=seed.`action_key` OR m.`perms`=seed.`action_key`
  GROUP BY seed.`action_key` HAVING COUNT(*)>1
) conflicts;

INSERT INTO `pa_system_menu`
  (`menu_key`,`parent_key`,`module_key`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT seed.`action_key`,'core.department.list','core',0,'A',seed.`action_name`,'',seed.`sort_order`,seed.`action_key`,'','',0,1,0
FROM `pa_dept_action_seed` seed
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` m WHERE m.`menu_key`=seed.`action_key`);

DROP TEMPORARY TABLE `pa_dept_action_assertion`;

DROP TEMPORARY TABLE `pa_dept_action_seed`;

INSERT INTO `pa_storage_account` (`account_key`,`driver`,`name`,`credential_ciphertext`,`credential_key_version`,`credential_rotated_at`,`status`,`created_at`,`updated_at`) VALUES ('local','local','本地存储',NULL,NULL,NULL,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));

INSERT INTO `pa_storage_space` (`space_key`,`account_id`,`name`,`access_type`,`local_path`,`status`,`created_at`,`updated_at`)
 SELECT 'local-public',`id`,'本地公开文件','public','public/storage','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM `pa_storage_account` WHERE `account_key`='local';

INSERT INTO `pa_storage_space` (`space_key`,`account_id`,`name`,`access_type`,`local_path`,`status`,`created_at`,`updated_at`)
 SELECT 'local-private',`id`,'本地私有文件','private','private/storage','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM `pa_storage_account` WHERE `account_key`='local';

INSERT INTO `pa_storage_route` (`route_key`,`access_type`,`space_id`,`updated_at`)
 SELECT 'default.public','public',`id`,UTC_TIMESTAMP(3) FROM `pa_storage_space` WHERE `space_key`='local-public';

INSERT INTO `pa_storage_route` (`route_key`,`access_type`,`space_id`,`updated_at`) SELECT 'default.private','private',`id`,UTC_TIMESTAMP(3) FROM `pa_storage_space` WHERE `space_key`='local-private';

-- peanut-baseline-catalog-boundary

CREATE TEMPORARY TABLE `pa_article_permission_owner_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `permission_count` INT NOT NULL,
  `expected_owner_count` INT NOT NULL,
  CONSTRAINT `chk_article_permission_owner_assertion`
    CHECK (`permission_count` = 13 AND `expected_owner_count` = 13)
);

INSERT INTO `pa_article_permission_owner_assertion`
  (`phase`, `permission_count`, `expected_owner_count`)
SELECT
  'before',
  COUNT(*),
  CASE
    WHEN SUM(CASE WHEN `module_key` = 'peanut.admin' THEN 1 ELSE 0 END) = 13 THEN 13
    WHEN SUM(CASE WHEN `module_key` = 'official.article' THEN 1 ELSE 0 END) = 13 THEN 13
    ELSE 0
  END
FROM `pa_permission`
WHERE `key` IN (
  'article.articleCate/lists',
  'article.articleCate/all',
  'article.articleCate/detail',
  'article.articleCate/add',
  'article.articleCate/edit',
  'article.articleCate/delete',
  'article.articleCate/updateStatus',
  'article.article/lists',
  'article.article/detail',
  'article.article/add',
  'article.article/edit',
  'article.article/delete',
  'article.article/updateStatus'
);

UPDATE `pa_permission`
SET `module_key` = 'official.article'
WHERE `module_key` = 'peanut.admin'
  AND `key` IN (
    'article.articleCate/lists',
    'article.articleCate/all',
    'article.articleCate/detail',
    'article.articleCate/add',
    'article.articleCate/edit',
    'article.articleCate/delete',
    'article.articleCate/updateStatus',
    'article.article/lists',
    'article.article/detail',
    'article.article/add',
    'article.article/edit',
    'article.article/delete',
    'article.article/updateStatus'
  );

INSERT INTO `pa_article_permission_owner_assertion`
  (`phase`, `permission_count`, `expected_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(CASE WHEN `module_key` = 'official.article' THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN (
  'article.articleCate/lists',
  'article.articleCate/all',
  'article.articleCate/detail',
  'article.articleCate/add',
  'article.articleCate/edit',
  'article.articleCate/delete',
  'article.articleCate/updateStatus',
  'article.article/lists',
  'article.article/detail',
  'article.article/add',
  'article.article/edit',
  'article.article/delete',
  'article.article/updateStatus'
);

DROP TEMPORARY TABLE `pa_article_permission_owner_assertion`;

INSERT INTO `pa_payment_tenant_channel_grant`
  (`tenant_id`, `provider`, `external_binding_id`, `merchant_account_ref`, `merchant_group_ref`,
   `status`, `revoked_at`, `create_time`, `update_time`)
SELECT
  b.`tenant_id`,
  b.`provider`,
  b.`id`,
  b.`identity_hash`,
  '',
  1,
  NULL,
  COALESCE(NULLIF(b.`create_time`, 0), UNIX_TIMESTAMP()),
  UNIX_TIMESTAMP()
FROM `pa_external_channel_binding` b
WHERE b.`provider` IN ('payment.wechat', 'payment.alipay');

SET @pa_app_setting_root_id = (
  SELECT `id`
  FROM `pa_system_menu`
  WHERE `type` = 'M' AND `paths` = '/app-setting'
  ORDER BY `id`
  LIMIT 1
);

INSERT INTO `pa_system_menu`
  (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('C', 'readiness/checklist', '/app-setting/readiness', '生产准备清单'), 256)), @pa_app_setting_root_id,'C','生产准备清单','icon-check-circle',95,
       'readiness/checklist','/app-setting/readiness','app-setting/readiness/index',0,1,0
WHERE @pa_app_setting_root_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu`
    WHERE `paths` = '/app-setting/readiness'
       OR LOWER(`perms`) = 'readiness/checklist'
  );

INSERT INTO `pa_permission`
  (`key`,`module_key`,`type`,`name`,`description`,`risk_level`,`status`,`manifest_version`,`created_at`,`updated_at`,`retired_at`)
VALUES
  ('platform.ops.upgrade.manage','peanut.admin','api','platform.ops.upgrade.manage','Execute the fixed registered application upgrade workflow.','critical','active','pc42-v1',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),NULL)
ON DUPLICATE KEY UPDATE
  `module_key`=VALUES(`module_key`),`type`=VALUES(`type`),`name`=VALUES(`name`),
  `description`=VALUES(`description`),`risk_level`=VALUES(`risk_level`),
  `status`='active',`manifest_version`=VALUES(`manifest_version`),
  `updated_at`=VALUES(`updated_at`),`retired_at`=NULL;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`,`permission_id`,`granted_at`)
SELECT role.`id`, permission.`id`, UTC_TIMESTAMP(3)
FROM `pa_platform_role` role
JOIN `pa_permission` permission ON permission.`key`='platform.ops.upgrade.manage'
WHERE role.`key`='platform.bootstrap-owner' AND role.`is_builtin`=1 AND role.`status`='active';

INSERT INTO `pa_permission`
  (`key`,`module_key`,`type`,`name`,`description`,`risk_level`,`status`,`manifest_version`,`created_at`,`updated_at`,`retired_at`)
VALUES
  ('platform.ops.module.manage','peanut.admin','api','platform.ops.module.manage','Submit a deployment-staged Module package operation.','critical','active','cr12-v1',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),NULL)
ON DUPLICATE KEY UPDATE
  `module_key`=VALUES(`module_key`),`type`=VALUES(`type`),`name`=VALUES(`name`),
  `description`=VALUES(`description`),`risk_level`=VALUES(`risk_level`),
  `status`='active',`manifest_version`=VALUES(`manifest_version`),
  `updated_at`=VALUES(`updated_at`),`retired_at`=NULL;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`,`permission_id`,`granted_at`)
SELECT role.`id`, permission.`id`, UTC_TIMESTAMP(3)
FROM `pa_platform_role` role
JOIN `pa_permission` permission ON permission.`key`='platform.ops.module.manage'
WHERE role.`key`='platform.bootstrap-owner' AND role.`is_builtin`=1 AND role.`status`='active';

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','core','scope','tenant','parent_key',NULL,'type','group','name','组织权限','route_name',NULL,'route_path',NULL,'component_key',NULL,'icon','Users','sort_order',10,'required_permission_id',NULL,'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='core.organization' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','core','scope','tenant','parent_key','core.organization','type','page','name','成员管理','route_name','tenant.members.list','route_path','/app/members','component_key','core.member.list','icon','UserRound','sort_order',11,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='core.member.read'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='core.member.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','core','scope','tenant','parent_key','core.organization','type','page','name','部门管理','route_name','tenant.departments.list','route_path','/app/departments','component_key','core.department.list','icon','Network','sort_order',12,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='core.department.read'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='core.department.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','core','scope','tenant','parent_key','core.organization','type','page','name','角色管理','route_name','tenant.roles.list','route_path','/app/roles','component_key','core.role.list','icon','Shield','sort_order',13,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='core.role.read'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='core.role.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','core','scope','tenant','parent_key','core.organization','type','page','name','权限治理','route_name','tenant.governance.workbench','route_path','/app/governance','component_key','core.governance.workbench','icon','SlidersHorizontal','sort_order',14,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='core.role.read'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='core.governance.workbench' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','core','scope','tenant','parent_key',NULL,'type','group','name','系统管理','route_name',NULL,'route_path',NULL,'component_key',NULL,'icon','Settings','sort_order',20,'required_permission_id',NULL,'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='core.system' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','core','scope','tenant','parent_key','core.system','type','page','name','模块管理','route_name','tenant.modules.list','route_path','/app/modules','component_key','core.module.list','icon','Blocks','sort_order',21,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='core.module.read'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='core.module.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','core','scope','tenant','parent_key','core.system','type','page','name','审计日志','route_name','tenant.audit.list','route_path','/app/audit','component_key','core.audit.list','icon','ScrollText','sort_order',22,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='core.audit.read'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='core.audit.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','platform','scope','platform','parent_key',NULL,'type','group','name','平台治理','route_name',NULL,'route_path',NULL,'component_key',NULL,'icon','Landmark','sort_order',10,'required_permission_id',NULL,'client_keys_json',CAST('["platform-web"]' AS JSON),'status','active') WHERE `key`='platform.governance' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','platform','scope','platform','parent_key','platform.governance','type','page','name','租户管理','route_name','platform.tenants.list','route_path','/platform/tenants','component_key','platform.tenant.list','icon','Building2','sort_order',11,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='platform.tenant.read'),'client_keys_json',CAST('["platform-web"]' AS JSON),'status','active') WHERE `key`='platform.tenant.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','platform','scope','platform','parent_key','platform.governance','type','page','name','平台操作员','route_name','platform.operators.list','route_path','/platform/operators','component_key','platform.operator.list','icon','UserCog','sort_order',12,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='platform.operator.read'),'client_keys_json',CAST('["platform-web"]' AS JSON),'status','active') WHERE `key`='platform.operator.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','platform','scope','platform','parent_key','platform.governance','type','page','name','平台角色','route_name','platform.roles.list','route_path','/platform/roles','component_key','platform.role.list','icon','ShieldCheck','sort_order',13,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='platform.role.read'),'client_keys_json',CAST('["platform-web"]' AS JSON),'status','active') WHERE `key`='platform.role.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','platform','scope','platform','parent_key','platform.governance','type','page','name','权限治理','route_name','platform.governance.workbench','route_path','/platform/governance','component_key','platform.governance.workbench','icon','SlidersHorizontal','sort_order',14,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='platform.role.read'),'client_keys_json',CAST('["platform-web"]' AS JSON),'status','active') WHERE `key`='platform.governance.workbench' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','platform','scope','platform','parent_key','platform.governance','type','page','name','平台审计','route_name','platform.audit.list','route_path','/platform/audit','component_key','platform.audit.list','icon','ScrollText','sort_order',15,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='platform.audit.read'),'client_keys_json',CAST('["platform-web"]' AS JSON),'status','active') WHERE `key`='platform.audit.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','platform','scope','platform','parent_key','platform.governance','type','page','name','版本与升级','route_name','platform.upgrade.status','route_path','/platform/upgrade','component_key','platform.upgrade.status','icon','ArrowUpCircle','sort_order',16,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='platform.upgrade.read'),'client_keys_json',CAST('["platform-web"]' AS JSON),'status','active') WHERE `key`='platform.upgrade.status' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','platform','scope','platform','parent_key','platform.governance','type','page','name','运维控制台','route_name','platform.ops.console','route_path','/platform/ops','component_key','platform.ops.console','icon','Activity','sort_order',17,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='platform.ops.read'),'client_keys_json',CAST('["platform-web"]' AS JSON),'status','active') WHERE `key`='platform.ops.console' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.article','scope','tenant','parent_key',NULL,'type','page','name','文章分类','route_name','ArticleCate','route_path','/article/cate','component_key','official.article.cate','icon','icon-folder','sort_order',90,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.article.category.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.article.categories' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.article','scope','tenant','parent_key',NULL,'type','page','name','文章管理','route_name','ArticleList','route_path','/article/list','component_key','official.article.list','icon','icon-file','sort_order',80,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.article.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.article.articles' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.file','scope','tenant','parent_key',NULL,'type','page','name','文件素材','route_name','SystemFile','route_path','/system/file','component_key','official.file.library','icon','icon-folder','sort_order',60,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.file.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.file.library' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.import_export','scope','tenant','parent_key',NULL,'type','page','name','配置转移','route_name','ConfigurationTransfer','route_path','/system/configuration-transfer','component_key','official.import-export.configuration','icon','icon-swap','sort_order',55,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.import-export.configuration.export'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.import-export.configuration' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.integration','scope','tenant','parent_key',NULL,'type','page','name','集成与安全','route_name','official.integration.index','route_path','/system/integration-security','component_key','official.integration.index','icon','icon-link','sort_order',54,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.integration.access'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.integration.security' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.member','scope','tenant','parent_key',NULL,'type','page','name','会员列表','route_name','MemberList','route_path','/member/list','component_key','official.member.list','icon','icon-user','sort_order',80,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.member.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.member.list' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.member','scope','tenant','parent_key',NULL,'type','page','name','会员标签','route_name','MemberTag','route_path','/member/tag','component_key','official.member.tag','icon','icon-tag','sort_order',70,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.member.tag.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.member.tag' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.member','scope','tenant','parent_key',NULL,'type','page','name','余额明细','route_name','FinanceAccountLog','route_path','/finance/account-log','component_key','official.member.account-log','icon','icon-list','sort_order',60,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.member.account-log.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.member.account-log' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.notification','scope','tenant','parent_key',NULL,'type','page','name','通知渠道','route_name','NoticeChannel','route_path','/notice/channel','component_key','official.notification.channel','icon','icon-notification','sort_order',70,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.notification.channel.detail'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.notification.channel' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.notification','scope','tenant','parent_key',NULL,'type','page','name','通知模板','route_name','NoticeTemplate','route_path','/notice/template','component_key','official.notification.template','icon','icon-file','sort_order',60,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.notification.scene.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.notification.template' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.notification','scope','tenant','parent_key',NULL,'type','page','name','通知日志','route_name','NoticeLog','route_path','/notice/log','component_key','official.notification.log','icon','icon-history','sort_order',50,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.notification.log.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.notification.log' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.oauth','scope','tenant','parent_key',NULL,'type','page','name','渠道与 OAuth','route_name','AppSettingChannel','route_path','/app-setting/channel','component_key','official.oauth.channel','icon','icon-link','sort_order',50,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.oauth.web-page.config'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.oauth.channels' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.payment','scope','tenant','parent_key',NULL,'type','page','name','支付设置','route_name','AppSettingPay','route_path','/app-setting/pay','component_key','official.payment.settings','icon','icon-settings','sort_order',70,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.payment.settings.detail'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.payment.settings' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.payment','scope','tenant','parent_key',NULL,'type','page','name','充值订单','route_name','FinanceRecharge','route_path','/finance/recharge','component_key','official.payment.recharge','icon','icon-pay-circle','sort_order',60,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.payment.recharge.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.payment.recharge' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.payment','scope','tenant','parent_key',NULL,'type','page','name','退款记录','route_name','FinanceRefund','route_path','/finance/refund','component_key','official.payment.refund','icon','icon-rollback','sort_order',50,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.payment.refund.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.payment.refund' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.reference_codes','scope','tenant','parent_key',NULL,'type','page','name','参考代码','route_name','OfficialReferenceCodes','route_path','/system/reference-codes','component_key','official.reference-codes.page','icon','icon-list','sort_order',71,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.reference-codes.read'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.reference-codes.page' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.rich_text','scope','tenant','parent_key',NULL,'type','page','name','富文本文档','route_name','RichTextDocuments','route_path','/rich-text/documents','component_key','official.rich-text.documents','icon','icon-edit','sort_order',70,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.rich-text.document.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.rich-text.documents' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.settings','scope','tenant','parent_key',NULL,'type','page','name','设置','route_name','OfficialSettings','route_path','/system/settings','component_key','official.settings.page','icon','icon-settings','sort_order',70,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.settings.read'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.settings.page' AND upstream_defaults_json IS NULL;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_OBJECT('module_key','official.task','scope','tenant','parent_key',NULL,'type','page','name','定时任务','route_name','SystemCrontab','route_path','/system/crontab','component_key','official.task.schedules','icon','icon-clock-circle','sort_order',50,'required_permission_id',(SELECT id FROM pa_permission WHERE `key`='official.task.list'),'client_keys_json',CAST('["admin-web"]' AS JSON),'status','active') WHERE `key`='official.task.schedules' AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',0,'type','M','name','权限管理','icon','icon-settings','sort',100,'perms','','paths','/system','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=1 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',1,'type','C','name','菜单管理','icon','icon-menu','sort',90,'perms','menu/lists','paths','/system/menu','component','system/menu/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=2 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',2,'type','A','name','菜单新增','icon','','sort',0,'perms','menu/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=3 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',2,'type','A','name','菜单编辑','icon','','sort',0,'perms','menu/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=4 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',2,'type','A','name','菜单删除','icon','','sort',0,'perms','menu/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=5 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',1,'type','C','name','角色管理','icon','icon-user-group','sort',80,'perms','role/lists','paths','/system/role','component','system/role/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=6 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',6,'type','A','name','角色新增','icon','','sort',0,'perms','role/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=7 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',6,'type','A','name','角色编辑','icon','','sort',0,'perms','role/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=8 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',6,'type','A','name','角色删除','icon','','sort',0,'perms','role/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=9 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',1,'type','C','name','管理员管理','icon','icon-user','sort',70,'perms','admin/lists','paths','/system/admin','component','system/admin/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=10 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',10,'type','A','name','管理员新增','icon','','sort',0,'perms','admin/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=11 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',10,'type','A','name','管理员编辑','icon','','sort',0,'perms','admin/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=12 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',10,'type','A','name','管理员删除','icon','','sort',0,'perms','admin/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=13 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',0,'type','M','name','仪表盘','icon','icon-dashboard','sort',110,'perms','','paths','/dashboard','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=14 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',14,'type','C','name','工作台','icon','icon-dashboard','sort',100,'perms','workbench/index','paths','/dashboard/workplace','component','dashboard/workplace/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=15 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',1,'type','C','name','岗位管理','icon','icon-idcard','sort',60,'perms','jobs/lists','paths','/system/jobs','component','system/jobs/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=16 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',16,'type','A','name','岗位新增','icon','','sort',30,'perms','jobs/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=17 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',16,'type','A','name','岗位编辑','icon','','sort',20,'perms','jobs/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=18 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',16,'type','A','name','岗位删除','icon','','sort',10,'perms','jobs/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=19 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',0,'type','M','name','会员管理','icon','icon-user','sort',80,'perms','','paths','/member','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=20 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',20,'type','C','name','会员列表','icon','icon-user-group','sort',90,'perms','member/lists','paths','/member/list','component','member/list/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=21 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','会员详情','icon','','sort',0,'perms','member/detail','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=22 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','会员状态','icon','','sort',0,'perms','member/status','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=23 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','余额调整','icon','','sort',0,'perms','member/adjustBalance','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=24 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','会员新增','icon','','sort',0,'perms','member/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=59 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','会员编辑','icon','','sort',0,'perms','member/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=60 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','余额调整','icon','','sort',0,'perms','member/adjustMoney','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=61 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','用户详情','icon','','sort',0,'perms','user.user/detail','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=62 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','用户编辑','icon','','sort',0,'perms','user.user/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=63 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','余额调整','icon','','sort',0,'perms','user.user/adjustMoney','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=64 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',21,'type','A','name','会员资料编辑','icon','','sort',0,'perms','member/profile/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=65 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',20,'type','C','name','会员标签','icon','icon-tag','sort',80,'perms','member/tag/lists','paths','/member/tag','component','member/tag/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=25 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',25,'type','A','name','标签新增','icon','','sort',0,'perms','member/tag/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=26 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',25,'type','A','name','标签编辑','icon','','sort',0,'perms','member/tag/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=27 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',25,'type','A','name','标签删除','icon','','sort',0,'perms','member/tag/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=28 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',0,'type','M','name','通知管理','icon','icon-notification','sort',70,'perms','','paths','/notice','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=30 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',30,'type','C','name','渠道配置','icon','icon-settings','sort',90,'perms','notice/channel/detail','paths','/notice/channel','component','notice/channel/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=31 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',31,'type','A','name','渠道保存','icon','','sort',0,'perms','notice/channel/save','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=32 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',30,'type','C','name','通知场景','icon','icon-file','sort',80,'perms','notice/scene/lists','paths','/notice/template','component','notice/template/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=33 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',33,'type','A','name','模板新增','icon','','sort',0,'perms','notice/template/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=34 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',33,'type','A','name','模板编辑','icon','','sort',0,'perms','notice/template/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=35 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',33,'type','A','name','模板删除','icon','','sort',0,'perms','notice/template/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=36 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',30,'type','C','name','发送日志','icon','icon-history','sort',70,'perms','notice/log/lists','paths','/notice/log','component','notice/log/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=37 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',33,'type','A','name','场景详情','icon','','sort',0,'perms','notice/scene/detail','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=73 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',33,'type','A','name','场景设置','icon','','sort',0,'perms','notice/scene/save','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=74 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',37,'type','A','name','日志详情','icon','','sort',0,'perms','notice/log/detail','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=75 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',0,'type','M','name','财务管理','icon','icon-fingerprint','sort',60,'perms','','paths','/finance','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=38 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',38,'type','C','name','余额明细','icon','icon-bar-chart','sort',90,'perms','finance.account_log/lists','paths','/finance/account-log','component','finance/account-log/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=39 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',0,'type','M','name','应用设置','icon','icon-apps','sort',50,'perms','','paths','/app-setting','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=40 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',40,'type','C','name','热门搜索','icon','icon-search','sort',90,'perms','setting/hot-search/config','paths','/app-setting/hot-search','component','app-setting/hot-search/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=41 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',41,'type','A','name','热门搜索保存','icon','','sort',0,'perms','setting/hot-search/save','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=42 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',40,'type','C','name','客服设置','icon','icon-customer-service','sort',80,'perms','setting/customer-service/config','paths','/app-setting/customer-service','component','app-setting/customer-service/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=43 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',43,'type','A','name','客服设置保存','icon','','sort',0,'perms','setting/customer-service/save','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=44 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',55,'type','A','name','H5渠道查看','icon','','sort',0,'perms','setting/web-page/config','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=95 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',55,'type','A','name','H5渠道保存','icon','','sort',0,'perms','setting/web-page/save','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=96 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',55,'type','A','name','小程序配置查看','icon','','sort',0,'perms','setting/mini-program/config','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=97 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',55,'type','A','name','小程序配置保存','icon','','sort',0,'perms','setting/mini-program/save','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=98 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',0,'type','M','name','内容管理','icon','icon-file','sort',55,'perms','','paths','/article','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=45 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',45,'type','C','name','文章分类','icon','icon-folder','sort',90,'perms','article.articleCate/lists','paths','/article/cate','component','article/cate/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=46 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',46,'type','A','name','分类新增','icon','','sort',0,'perms','article.articleCate/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=47 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',46,'type','A','name','分类编辑','icon','','sort',0,'perms','article.articleCate/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=48 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',46,'type','A','name','分类删除','icon','','sort',0,'perms','article.articleCate/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=49 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',45,'type','C','name','文章管理','icon','icon-file','sort',80,'perms','article.article/lists','paths','/article/list','component','article/list/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=50 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',50,'type','A','name','文章新增','icon','','sort',0,'perms','article.article/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=51 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',50,'type','A','name','文章编辑','icon','','sort',0,'perms','article.article/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=52 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',50,'type','A','name','文章删除','icon','','sort',0,'perms','article.article/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=53 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',46,'type','A','name','分类详情','icon','','sort',0,'perms','article.articleCate/detail','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=69 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',46,'type','A','name','分类状态','icon','','sort',0,'perms','article.articleCate/updateStatus','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=70 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',50,'type','A','name','文章详情','icon','','sort',0,'perms','article.article/detail','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=71 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',50,'type','A','name','文章状态','icon','','sort',0,'perms','article.article/updateStatus','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=72 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',1,'type','C','name','素材管理','icon','icon-folder','sort',50,'perms','file/lists','paths','/system/file','component','system/file/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=84 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','分类查看','icon','','sort',0,'perms','file/cate/lists','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=85 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','上传图片','icon','','sort',0,'perms','upload/image','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=86 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','上传视频','icon','','sort',0,'perms','upload/video','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=87 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','上传文件','icon','','sort',0,'perms','upload/file','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=88 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','分类新增','icon','','sort',0,'perms','file/cate/add','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=89 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','分类编辑','icon','','sort',0,'perms','file/cate/edit','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=90 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','分类删除','icon','','sort',0,'perms','file/cate/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=91 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','素材移动','icon','','sort',0,'perms','file/move','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=92 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','素材重命名','icon','','sort',0,'perms','file/rename','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=93 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',84,'type','A','name','素材删除','icon','','sort',0,'perms','file/delete','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=94 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',40,'type','C','name','支付配置','icon','icon-payment','sort',70,'perms','setting/pay/config','paths','/app-setting/pay','component','app-setting/pay/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=54 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',40,'type','C','name','渠道配置','icon','icon-share-alt','sort',60,'perms','setting/channel/config','paths','/app-setting/channel','component','app-setting/channel/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=55 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',40,'type','C','name','页面装修','icon','icon-brush','sort',50,'perms','setting/decorate/config','paths','/app-setting/decorate','component','app-setting/decorate/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=56 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',38,'type','C','name','充值记录','icon','icon-thunderbolt','sort',80,'perms','recharge.recharge/lists','paths','/finance/recharge','component','finance/recharge/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=57 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',38,'type','C','name','退款记录','icon','icon-undo','sort',70,'perms','finance.refund/record','paths','/finance/refund','component','finance/refund/index','is_cache',0,'is_show',1,'is_disable',0) WHERE id=58 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',57,'type','A','name','退款','icon','','sort',0,'perms','recharge.recharge/refund','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=66 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',58,'type','A','name','重新退款','icon','','sort',0,'perms','recharge.recharge/refundAgain','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=67 AND upstream_defaults_json IS NULL;

UPDATE pa_system_menu SET upstream_defaults_json=JSON_OBJECT('pid',58,'type','A','name','退款日志','icon','','sort',0,'perms','finance.refund/log','paths','','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE id=68 AND upstream_defaults_json IS NULL;

INSERT INTO pa_system_menu (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`,upstream_defaults_json)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/system-settings', '系统设置'), 256)), 0,'M','系统设置','icon-settings',30,'','/system-settings','',0,1,0,JSON_OBJECT('pid',0,'type','M','name','系统设置','icon','icon-settings','sort',30,'perms','','paths','/system-settings','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE NOT EXISTS (SELECT 1 FROM pa_system_menu WHERE paths='/system-settings');

SET @peanut_menu_settings = (SELECT IF(COUNT(*)=1,MIN(id),NULL) FROM pa_system_menu m WHERE paths='/system-settings' AND JSON_OBJECT('pid',m.`pid`,'type',m.`type`,'name',m.`name`,'icon',m.`icon`,'sort',m.`sort`,'perms',m.`perms`,'paths',m.`paths`,'component',m.`component`,'is_cache',m.`is_cache`,'is_show',m.`is_show`,'is_disable',m.`is_disable`)=m.upstream_defaults_json);

INSERT INTO pa_system_menu (`menu_key`, `pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`,upstream_defaults_json)
SELECT CONCAT('seed.', SHA2(JSON_ARRAY('M', '', '/operations', '运维与审计'), 256)), 0,'M','运维与审计','icon-history',20,'','/operations','',0,1,0,JSON_OBJECT('pid',0,'type','M','name','运维与审计','icon','icon-history','sort',20,'perms','','paths','/operations','component','','is_cache',0,'is_show',1,'is_disable',0) WHERE NOT EXISTS (SELECT 1 FROM pa_system_menu WHERE paths='/operations');

SET @peanut_menu_operations = (SELECT IF(COUNT(*)=1,MIN(id),NULL) FROM pa_system_menu m WHERE paths='/operations' AND JSON_OBJECT('pid',m.`pid`,'type',m.`type`,'name',m.`name`,'icon',m.`icon`,'sort',m.`sort`,'perms',m.`perms`,'paths',m.`paths`,'component',m.`component`,'is_cache',m.`is_cache`,'is_show',m.`is_show`,'is_disable',m.`is_disable`)=m.upstream_defaults_json);

CREATE TEMPORARY TABLE pa_menu_grouping_expected (id BIGINT PRIMARY KEY, target_defaults JSON NOT NULL, old_parent BIGINT NOT NULL);

INSERT INTO pa_menu_grouping_expected VALUES (1,JSON_OBJECT('pid',0,'type','M','name','组织与权限','icon','icon-settings','sort',100,'perms','','paths','/system','component','','is_cache',0,'is_show',1,'is_disable',0),0);

INSERT INTO pa_menu_grouping_expected VALUES (20,JSON_OBJECT('pid',0,'type','M','name','会员与财务','icon','icon-user','sort',80,'perms','','paths','/member','component','','is_cache',0,'is_show',1,'is_disable',0),0);

INSERT INTO pa_menu_grouping_expected VALUES (31,JSON_OBJECT('pid',40,'type','C','name','渠道配置','icon','icon-settings','sort',90,'perms','notice/channel/detail','paths','/notice/channel','component','notice/channel/index','is_cache',0,'is_show',1,'is_disable',0),30);

INSERT INTO pa_menu_grouping_expected VALUES (33,JSON_OBJECT('pid',40,'type','C','name','通知场景','icon','icon-file','sort',80,'perms','notice/scene/lists','paths','/notice/template','component','notice/template/index','is_cache',0,'is_show',1,'is_disable',0),30);

INSERT INTO pa_menu_grouping_expected VALUES (37,JSON_OBJECT('pid',@peanut_menu_operations,'type','C','name','发送日志','icon','icon-history','sort',70,'perms','notice/log/lists','paths','/notice/log','component','notice/log/index','is_cache',0,'is_show',1,'is_disable',0),30);

INSERT INTO pa_menu_grouping_expected VALUES (39,JSON_OBJECT('pid',20,'type','C','name','余额明细','icon','icon-bar-chart','sort',90,'perms','finance.account_log/lists','paths','/finance/account-log','component','finance/account-log/index','is_cache',0,'is_show',1,'is_disable',0),38);

INSERT INTO pa_menu_grouping_expected VALUES (40,JSON_OBJECT('pid',0,'type','M','name','应用与集成','icon','icon-apps','sort',50,'perms','','paths','/app-setting','component','','is_cache',0,'is_show',1,'is_disable',0),0);

INSERT INTO pa_menu_grouping_expected VALUES (45,JSON_OBJECT('pid',0,'type','M','name','内容与资源','icon','icon-file','sort',55,'perms','','paths','/article','component','','is_cache',0,'is_show',1,'is_disable',0),0);

INSERT INTO pa_menu_grouping_expected VALUES (84,JSON_OBJECT('pid',45,'type','C','name','素材管理','icon','icon-folder','sort',50,'perms','file/lists','paths','/system/file','component','system/file/index','is_cache',0,'is_show',1,'is_disable',0),1);

INSERT INTO pa_menu_grouping_expected VALUES (57,JSON_OBJECT('pid',20,'type','C','name','充值记录','icon','icon-thunderbolt','sort',80,'perms','recharge.recharge/lists','paths','/finance/recharge','component','finance/recharge/index','is_cache',0,'is_show',1,'is_disable',0),38);

INSERT INTO pa_menu_grouping_expected VALUES (58,JSON_OBJECT('pid',20,'type','C','name','退款记录','icon','icon-undo','sort',70,'perms','finance.refund/record','paths','/finance/refund','component','finance/refund/index','is_cache',0,'is_show',1,'is_disable',0),38);

UPDATE pa_system_menu m JOIN pa_menu_grouping_expected e ON e.id=m.id LEFT JOIN pa_system_menu p ON p.id=e.old_parent
LEFT JOIN pa_system_menu t ON t.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.target_defaults,'$.pid')) AS UNSIGNED)
SET m.pid=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.target_defaults,'$.pid')) AS UNSIGNED), m.name=JSON_UNQUOTE(JSON_EXTRACT(e.target_defaults,'$.name')), m.upstream_defaults_json=e.target_defaults, m.menu_conflict_json=NULL
WHERE JSON_OBJECT('pid',m.`pid`,'type',m.`type`,'name',m.`name`,'icon',m.`icon`,'sort',m.`sort`,'perms',m.`perms`,'paths',m.`paths`,'component',m.`component`,'is_cache',m.`is_cache`,'is_show',m.`is_show`,'is_disable',m.`is_disable`)=m.upstream_defaults_json AND JSON_EXTRACT(e.target_defaults,'$.pid')<>CAST('null' AS JSON)
AND (e.old_parent=0 OR JSON_OBJECT('pid',p.`pid`,'type',p.`type`,'name',p.`name`,'icon',p.`icon`,'sort',p.`sort`,'perms',p.`perms`,'paths',p.`paths`,'component',p.`component`,'is_cache',p.`is_cache`,'is_show',p.`is_show`,'is_disable',p.`is_disable`)=p.upstream_defaults_json)
AND (JSON_EXTRACT(e.target_defaults,'$.pid')=CAST('0' AS JSON) OR JSON_OBJECT('pid',t.`pid`,'type',t.`type`,'name',t.`name`,'icon',t.`icon`,'sort',t.`sort`,'perms',t.`perms`,'paths',t.`paths`,'component',t.`component`,'is_cache',t.`is_cache`,'is_show',t.`is_show`,'is_disable',t.`is_disable`)=t.upstream_defaults_json);

UPDATE pa_system_menu m JOIN pa_menu_grouping_expected e ON e.id=m.id
SET m.menu_conflict_json=JSON_OBJECT('reason','navigation_parent_conflict')
WHERE JSON_OBJECT('pid',m.`pid`,'type',m.`type`,'name',m.`name`,'icon',m.`icon`,'sort',m.`sort`,'perms',m.`perms`,'paths',m.`paths`,'component',m.`component`,'is_cache',m.`is_cache`,'is_show',m.`is_show`,'is_disable',m.`is_disable`)=m.upstream_defaults_json
AND m.upstream_defaults_json<>e.target_defaults;

UPDATE pa_system_menu m SET menu_conflict_json=JSON_OBJECT('reason','legacy_default_mismatch') WHERE m.upstream_defaults_json IS NOT NULL AND JSON_OBJECT('pid',m.`pid`,'type',m.`type`,'name',m.`name`,'icon',m.`icon`,'sort',m.`sort`,'perms',m.`perms`,'paths',m.`paths`,'component',m.`component`,'is_cache',m.`is_cache`,'is_show',m.`is_show`,'is_disable',m.`is_disable`)<>m.upstream_defaults_json;

DROP TEMPORARY TABLE pa_menu_grouping_expected;

CREATE TEMPORARY TABLE menu_identity_seed (type CHAR(1), paths VARCHAR(200), perms VARCHAR(100), menu_key VARCHAR(160), module_key VARCHAR(96)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO menu_identity_seed VALUES ('M','/system','','core.organization','core');

INSERT INTO menu_identity_seed VALUES ('C','/system/menu','menu/lists','system.menu.lists','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','menu/add','system.menu.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','menu/edit','system.menu.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','menu/delete','system.menu.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/system/role','role/lists','system.role.lists','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','role/add','system.role.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','role/edit','system.role.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','role/delete','system.role.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/system/admin','admin/lists','system.admin.lists','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','admin/add','system.admin.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','admin/edit','system.admin.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','admin/delete','system.admin.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('M','/dashboard','','system.dashboard','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/dashboard/workplace','workbench/index','system.workbench.index','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/system/jobs','jobs/lists','system.jobs.lists','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','jobs/add','system.jobs.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','jobs/edit','system.jobs.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','jobs/delete','system.jobs.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('M','/member','','core.business','core');

INSERT INTO menu_identity_seed VALUES ('C','/member/list','member/lists','official.member.list','official.member');

INSERT INTO menu_identity_seed VALUES ('A','','member/detail','system.member.detail','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','member/status','system.member.status','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','member/adjustBalance','system.member.adjustbalance','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','member/add','system.member.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','member/edit','system.member.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','member/adjustMoney','system.member.adjustmoney','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','user.user/detail','system.user.user.detail','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','user.user/edit','system.user.user.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','user.user/adjustMoney','system.user.user.adjustmoney','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','member/profile/edit','system.member.profile.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/member/tag','member/tag/lists','official.member.tag','official.member');

INSERT INTO menu_identity_seed VALUES ('A','','member/tag/add','system.member.tag.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','member/tag/edit','system.member.tag.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','member/tag/delete','system.member.tag.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('M','/notice','','system.notice','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/notice/channel','notice/channel/detail','official.notification.channel','official.notification');

INSERT INTO menu_identity_seed VALUES ('A','','notice/channel/save','system.notice.channel.save','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/notice/template','notice/scene/lists','official.notification.template','official.notification');

INSERT INTO menu_identity_seed VALUES ('A','','notice/template/add','system.notice.template.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','notice/template/edit','system.notice.template.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','notice/template/delete','system.notice.template.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/notice/log','notice/log/lists','official.notification.log','official.notification');

INSERT INTO menu_identity_seed VALUES ('A','','notice/scene/detail','system.notice.scene.detail','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','notice/scene/save','system.notice.scene.save','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','notice/log/detail','system.notice.log.detail','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('M','/finance','','system.finance','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/finance/account-log','finance.account_log/lists','official.member.account-log','official.member');

INSERT INTO menu_identity_seed VALUES ('M','/app-setting','','core.applications','core');

INSERT INTO menu_identity_seed VALUES ('C','/app-setting/hot-search','setting/hot-search/config','system.setting.hot.search.config','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','setting/hot-search/save','system.setting.hot.search.save','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/app-setting/customer-service','setting/customer-service/config','system.setting.customer.service.config','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','setting/customer-service/save','system.setting.customer.service.save','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','setting/web-page/config','system.setting.web.page.config','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','setting/web-page/save','system.setting.web.page.save','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','setting/mini-program/config','system.setting.mini.program.config','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','setting/mini-program/save','system.setting.mini.program.save','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('M','/article','','core.content','core');

INSERT INTO menu_identity_seed VALUES ('C','/article/cate','article.articleCate/lists','official.article.categories','official.article');

INSERT INTO menu_identity_seed VALUES ('A','','article.articleCate/add','system.article.articlecate.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','article.articleCate/edit','system.article.articlecate.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','article.articleCate/delete','system.article.articlecate.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/article/list','article.article/lists','official.article.articles','official.article');

INSERT INTO menu_identity_seed VALUES ('A','','article.article/add','system.article.article.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','article.article/edit','system.article.article.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','article.article/delete','system.article.article.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','article.articleCate/detail','system.article.articlecate.detail','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','article.articleCate/updateStatus','system.article.articlecate.updatestatus','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','article.article/detail','system.article.article.detail','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','article.article/updateStatus','system.article.article.updatestatus','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/system/file','file/lists','official.file.library','official.file');

INSERT INTO menu_identity_seed VALUES ('A','','file/cate/lists','system.file.cate.lists','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','upload/image','system.upload.image','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','upload/video','system.upload.video','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','upload/file','system.upload.file','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','file/cate/add','system.file.cate.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','file/cate/edit','system.file.cate.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','file/cate/delete','system.file.cate.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','file/move','system.file.move','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','file/rename','system.file.rename','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','file/delete','system.file.delete','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/app-setting/pay','setting/pay/config','official.payment.settings','official.payment');

INSERT INTO menu_identity_seed VALUES ('C','/app-setting/channel','setting/channel/config','official.oauth.channels','official.oauth');

INSERT INTO menu_identity_seed VALUES ('C','/app-setting/decorate','setting/decorate/config','system.setting.decorate.config','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('C','/finance/recharge','recharge.recharge/lists','official.payment.recharge','official.payment');

INSERT INTO menu_identity_seed VALUES ('C','/finance/refund','finance.refund/record','official.payment.refund','official.payment');

INSERT INTO menu_identity_seed VALUES ('A','','recharge.recharge/refund','system.recharge.recharge.refund','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','recharge.recharge/refundAgain','system.recharge.recharge.refundagain','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','finance.refund/log','system.finance.refund.log','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('M','/system-settings','','core.system','core');

INSERT INTO menu_identity_seed VALUES ('M','/operations','','core.operations','core');

INSERT INTO menu_identity_seed VALUES ('C','/system/dept','dept/lists','system.dept.lists','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','dept/add','system.dept.add','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','dept/edit','system.dept.edit','peanut.admin');

INSERT INTO menu_identity_seed VALUES ('A','','dept/delete','system.dept.delete','peanut.admin');

CREATE TEMPORARY TABLE menu_identity_matches AS SELECT m.id,s.menu_key,s.module_key FROM pa_system_menu m JOIN menu_identity_seed s ON m.type COLLATE utf8mb4_unicode_ci=s.type AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.upstream_defaults_json,'$.paths')),m.paths) COLLATE utf8mb4_unicode_ci=s.paths AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.upstream_defaults_json,'$.perms')),m.perms) COLLATE utf8mb4_unicode_ci=s.perms;

CREATE TEMPORARY TABLE menu_identity_unique AS SELECT menu_key,MIN(id) AS id FROM menu_identity_matches GROUP BY menu_key HAVING COUNT(*)=1;

UPDATE pa_system_menu m JOIN menu_identity_matches s ON s.id=m.id JOIN menu_identity_unique u ON u.id=s.id AND u.menu_key=s.menu_key SET m.menu_key=s.menu_key,m.module_key=s.module_key WHERE m.menu_key LIKE 'seed.%';

UPDATE pa_system_menu SET menu_key=CONCAT('custom.',id) WHERE menu_key LIKE 'seed.%';

UPDATE pa_system_menu m LEFT JOIN pa_system_menu p ON p.id=m.pid SET m.parent_key=p.menu_key WHERE m.parent_key IS NULL AND m.pid<>0;

UPDATE pa_menu_definition SET upstream_defaults_json=JSON_SET(upstream_defaults_json,'$.is_show',1,'$.is_cache',0,'$.is_disable',0) WHERE upstream_defaults_json IS NOT NULL AND JSON_EXTRACT(upstream_defaults_json,'$.is_show') IS NULL;

DROP TEMPORARY TABLE menu_identity_unique;

DROP TEMPORARY TABLE menu_identity_matches;

DROP TEMPORARY TABLE menu_identity_seed;

INSERT INTO `pa_permission`
  (`key`,`module_key`,`type`,`name`,`description`,`risk_level`,`status`,`manifest_version`,`created_at`,`updated_at`,`retired_at`)
VALUES
  ('readiness/checklist','peanut.admin','api','生产准备清单','Read the tenant first-run readiness checklist.','normal','active','5.0.1-readiness-v1',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),NULL)
ON DUPLICATE KEY UPDATE
  `module_key`=VALUES(`module_key`),`type`=VALUES(`type`),`name`=VALUES(`name`),
  `description`=VALUES(`description`),`risk_level`=VALUES(`risk_level`),
  `status`='active',`manifest_version`=VALUES(`manifest_version`),
  `updated_at`=VALUES(`updated_at`),`retired_at`=NULL;

CREATE TEMPORARY TABLE `pa_dept_permission_map` (
  `legacy_key` VARCHAR(160) NOT NULL,
  `canonical_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`legacy_key`,`canonical_key`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `pa_dept_permission_map` VALUES
  ('dept/lists','core.department.read'),
  ('dept/detail','core.department.read'),
  ('dept/all','core.department.read'),
  ('dept/leaderdept','core.department.read'),
  ('dept/add','core.department.create'),
  ('dept/edit','core.department.update'),
  ('dept/edit','core.department.move'),
  ('dept/delete','core.department.archive'),
  ('dept/status','core.department.update');

CREATE TEMPORARY TABLE `pa_dept_permission_assertion` (
  `phase` VARCHAR(64) NOT NULL,
  `valid` INT NOT NULL,
  CONSTRAINT `chk_dept_permission_assertion` CHECK (`valid`=1)
);

INSERT INTO `pa_dept_permission_assertion`
SELECT 'canonical_catalog', IF(COUNT(*)=5 AND SUM(`module_key`='core' AND `status`='active')=5,1,0)
FROM `pa_permission`
WHERE `key` IN ('core.department.read','core.department.create','core.department.update','core.department.move','core.department.archive');

INSERT INTO `pa_dept_permission_assertion`
SELECT 'legacy_provenance', IF(COUNT(*)=0,1,0)
FROM `pa_permission` p
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`)
  AND (p.`module_key` NOT IN ('application','peanut.admin') OR p.`manifest_version`<>'fresh-schema-v1');

INSERT INTO `pa_dept_permission_assertion`
SELECT 'platform_grants', IF(COUNT(*)=0,1,0)
FROM `pa_platform_role_permission` r JOIN `pa_permission` p ON p.`id`=r.`permission_id`
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`);

INSERT INTO `pa_dept_permission_assertion`
SELECT 'resource_operation_grants', IF(COUNT(*)=0,1,0)
FROM `pa_resource_operation_permission` r JOIN `pa_permission` p ON p.`id`=r.`permission_id`
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`);

INSERT INTO `pa_dept_permission_assertion`
SELECT 'resource_operation_selection', IF(COUNT(*)=0,1,0)
FROM `pa_resource_operation_target_type` r JOIN `pa_permission` p ON p.`id`=r.`policy_selection_permission_id`
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`);

INSERT INTO `pa_dept_permission_assertion`
SELECT 'native_menu_references', IF(COUNT(*)=0,1,0)
FROM `pa_menu_definition` m JOIN `pa_permission` p ON p.`id`=m.`required_permission_id`
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`);

CREATE TEMPORARY TABLE `pa_dept_action_seed` (
  `action_key` VARCHAR(160) NOT NULL,
  `action_name` VARCHAR(50) NOT NULL,
  `sort_order` SMALLINT NOT NULL,
  PRIMARY KEY (`action_key`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `pa_dept_action_seed` VALUES
  ('core.department.create','新增部门',40),
  ('core.department.update','编辑部门',30),
  ('core.department.move','移动部门',20),
  ('core.department.archive','归档部门',10);

CREATE TEMPORARY TABLE `pa_dept_action_assertion` (
  `phase` VARCHAR(64) NOT NULL,
  `valid` INT NOT NULL,
  CONSTRAINT `chk_dept_action_assertion` CHECK (`valid`=1)
);

INSERT INTO `pa_dept_action_assertion`
SELECT 'action_identity_conflict',IF(COUNT(*)=0,1,0)
FROM `pa_system_menu` m JOIN `pa_dept_action_seed` seed
  ON m.`menu_key`=seed.`action_key` OR m.`perms`=seed.`action_key`
WHERE NOT (m.`menu_key`<=>seed.`action_key` AND m.`perms`=seed.`action_key`
  AND m.`parent_key`<=>'core.department.list' AND m.`module_key`='core'
  AND m.`pid`=0 AND m.`type`='A' AND m.`name`=seed.`action_name`
  AND m.`sort`=seed.`sort_order` AND m.`icon`='' AND m.`paths`='' AND m.`component`=''
  AND m.`is_cache`=0 AND m.`is_show`=1 AND m.`is_disable`=0
  AND m.`upstream_defaults_json` IS NULL AND m.`menu_conflict_json` IS NULL);

INSERT INTO `pa_dept_action_assertion`
SELECT 'action_identity_ambiguous',IF(COUNT(*)=0,1,0) FROM (
  SELECT seed.`action_key` FROM `pa_dept_action_seed` seed
  JOIN `pa_system_menu` m ON m.`menu_key`=seed.`action_key` OR m.`perms`=seed.`action_key`
  GROUP BY seed.`action_key` HAVING COUNT(*)>1
) conflicts;

CREATE TEMPORARY TABLE `pa_dept_default_page_ids` AS
SELECT m.`id`,m.`menu_key` FROM `pa_system_menu` m
JOIN `pa_system_menu` parent ON parent.`id`=m.`pid` AND parent.`menu_key`=m.`parent_key`
WHERE m.`menu_key`='system.dept.lists' AND m.`module_key`='peanut.admin'
  AND m.`type`='C' AND m.`name`='部门管理' AND m.`perms`='dept/lists'
  AND m.`paths`='/system/dept' AND m.`component`='system/dept/index'
  AND m.`icon`='icon-mind-mapping' AND m.`sort`=70
  AND m.`is_cache`=0 AND m.`is_show`=1 AND m.`is_disable`=0
  AND parent.`menu_key`='core.organization' AND parent.`type`='M' AND parent.`paths`='/system'
  AND m.`menu_conflict_json` IS NULL
  AND (m.`upstream_defaults_json` IS NULL OR m.`upstream_defaults_json`=JSON_OBJECT('pid',m.`pid`,'type',m.`type`,'name',m.`name`,'icon',m.`icon`,'sort',m.`sort`,'perms',m.`perms`,'paths',m.`paths`,'component',m.`component`,'is_cache',m.`is_cache`,'is_show',m.`is_show`,'is_disable',m.`is_disable`));

CREATE TEMPORARY TABLE `pa_dept_default_menu_ids` AS
SELECT `id`,`menu_key` FROM `pa_dept_default_page_ids`;

INSERT INTO `pa_dept_default_menu_ids` (`id`,`menu_key`)
SELECT m.`id`,m.`menu_key` FROM `pa_system_menu` m
JOIN `pa_dept_default_page_ids` parent ON parent.`id`=m.`pid` AND parent.`menu_key`=m.`parent_key`
WHERE m.`type`='A' AND m.`paths`='' AND m.`component`='' AND m.`icon`=''
  AND m.`is_cache`=0 AND m.`is_show`=1 AND m.`is_disable`=0
  AND m.`upstream_defaults_json` IS NULL AND m.`menu_conflict_json` IS NULL
  AND ((m.`module_key`='peanut.admin' AND
      ((m.`menu_key`='system.dept.add' AND m.`perms`='dept/add' AND m.`name`='新增部门' AND m.`sort`=30)
        OR (m.`menu_key`='system.dept.edit' AND m.`perms`='dept/edit' AND m.`name`='编辑部门' AND m.`sort`=20)
        OR (m.`menu_key`='system.dept.delete' AND m.`perms`='dept/delete' AND m.`name`='删除部门' AND m.`sort`=10)))
    OR (m.`module_key`='application' AND m.`sort`=0 AND
      ((m.`perms`='dept/all' AND m.`name`='部门选择')
        OR (m.`perms`='dept/detail' AND m.`name`='部门详情')
        OR (m.`perms`='dept/leaderdept' AND m.`name`='负责人部门')
        OR (m.`perms`='dept/status' AND m.`name`='部门状态'))));

INSERT INTO `pa_dept_permission_assertion`
SELECT 'legacy_menu_custom_reference',IF(COUNT(*)=0,1,0)
FROM `pa_system_menu` m
LEFT JOIN `pa_dept_default_menu_ids` matched ON matched.`id`=m.`id`
WHERE matched.`id` IS NULL AND
  (m.`perms` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`)
    OR m.`menu_key` IN ('system.dept.lists','system.dept.add','system.dept.edit','system.dept.delete'));

INSERT INTO `pa_dept_permission_assertion`
SELECT 'legacy_menu_ambiguous_seed',IF(COUNT(*)=0,1,0) FROM (
  SELECT m.`perms` FROM `pa_system_menu` m
  JOIN `pa_dept_default_menu_ids` matched ON matched.`id`=m.`id`
  GROUP BY m.`perms` HAVING COUNT(*)>1
) duplicates;

CREATE TEMPORARY TABLE `pa_dept_default_menu_parents` AS
SELECT `id`,`menu_key` FROM `pa_dept_default_menu_ids`;

INSERT INTO `pa_dept_permission_assertion`
SELECT 'legacy_menu_custom_descendant',IF(COUNT(*)=0,1,0)
FROM `pa_system_menu` child
JOIN `pa_dept_default_menu_parents` parent
  ON child.`pid`=parent.`id` OR child.`parent_key`=parent.`menu_key`
LEFT JOIN `pa_dept_default_menu_ids` matched ON matched.`id`=child.`id`
WHERE matched.`id` IS NULL;

CREATE TEMPORARY TABLE `pa_dept_grant_moves` AS
SELECT r.`id` AS legacy_grant_id,r.`tenant_id`,r.`role_id`,canonical.`id` AS permission_id,
  r.`granted_by_member_id`,r.`granted_at`
FROM `pa_role_permission` r
JOIN `pa_permission` legacy ON legacy.`id`=r.`permission_id`
JOIN `pa_dept_permission_map` mapping ON mapping.`legacy_key`=legacy.`key`
JOIN `pa_permission` canonical ON canonical.`key`=mapping.`canonical_key`
WHERE legacy.`status`='active';

CREATE TEMPORARY TABLE `pa_dept_affected_roles` AS
SELECT DISTINCT `tenant_id`,`role_id` FROM `pa_dept_grant_moves`;

CREATE TEMPORARY TABLE `pa_dept_affected_tenants` AS
SELECT DISTINCT `tenant_id` FROM `pa_dept_affected_roles`;

INSERT INTO `pa_system_menu`
  (`menu_key`,`parent_key`,`module_key`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT seed.`action_key`,'core.department.list','core',0,'A',seed.`action_name`,'',seed.`sort_order`,seed.`action_key`,'','',0,1,0
FROM `pa_dept_action_seed` seed
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` m WHERE m.`menu_key`=seed.`action_key`);

INSERT INTO `pa_role_permission` (`tenant_id`,`role_id`,`permission_id`,`granted_by_member_id`,`granted_at`)
SELECT moved.`tenant_id`,moved.`role_id`,moved.`permission_id`,moved.`granted_by_member_id`,moved.`granted_at`
FROM (
  SELECT g.*, ROW_NUMBER() OVER (PARTITION BY `tenant_id`,`role_id`,`permission_id` ORDER BY `granted_at`,`legacy_grant_id`) AS grant_rank
  FROM `pa_dept_grant_moves` g
) moved
LEFT JOIN `pa_role_permission` existing ON existing.`tenant_id`=moved.`tenant_id`
  AND existing.`role_id`=moved.`role_id` AND existing.`permission_id`=moved.`permission_id`
WHERE moved.grant_rank=1 AND existing.`id` IS NULL;

DELETE r FROM `pa_role_permission` r
JOIN (SELECT DISTINCT legacy_grant_id FROM `pa_dept_grant_moves`) moved ON moved.legacy_grant_id=r.`id`;

UPDATE `pa_role` r JOIN `pa_dept_affected_roles` affected
  ON affected.`tenant_id`=r.`tenant_id` AND affected.`role_id`=r.`id`
SET r.`authorization_revision`=r.`authorization_revision`+1,r.`updated_at`=UTC_TIMESTAMP(3);

UPDATE `pa_tenant` t JOIN `pa_dept_affected_tenants` affected ON affected.`tenant_id`=t.`id`
SET t.`authorization_revision`=t.`authorization_revision`+1,t.`updated_at`=UTC_TIMESTAMP(3);

UPDATE `pa_permission` p
SET p.`status`='retired',p.`retired_at`=COALESCE(p.`retired_at`,UTC_TIMESTAMP(3)),p.`updated_at`=UTC_TIMESTAMP(3)
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`) AND p.`status`<>'retired';

UPDATE `pa_system_menu` m JOIN `pa_dept_default_menu_ids` matched ON matched.`id`=m.`id`
SET m.`is_show`=0,m.`is_disable`=1;

DROP TEMPORARY TABLE `pa_dept_default_menu_ids`;

DROP TEMPORARY TABLE `pa_dept_default_menu_parents`;

DROP TEMPORARY TABLE `pa_dept_default_page_ids`;

DROP TEMPORARY TABLE `pa_dept_affected_tenants`;

DROP TEMPORARY TABLE `pa_dept_affected_roles`;

DROP TEMPORARY TABLE `pa_dept_grant_moves`;

DROP TEMPORARY TABLE `pa_dept_permission_assertion`;

DROP TEMPORARY TABLE `pa_dept_permission_map`;

DROP TEMPORARY TABLE `pa_dept_action_assertion`;

DROP TEMPORARY TABLE `pa_dept_action_seed`;

CREATE TEMPORARY TABLE `pa_platform_ops_permission_owner_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `permission_count` INT NOT NULL,
  `expected_owner_count` INT NOT NULL,
  CONSTRAINT `chk_platform_ops_permission_owner_assertion`
    CHECK (`permission_count` = 2 AND `expected_owner_count` = 2)
);

INSERT INTO `pa_platform_ops_permission_owner_assertion`
  (`phase`, `permission_count`, `expected_owner_count`)
SELECT 'before', COUNT(*),
  SUM(CASE WHEN `module_key` IN ('peanut.admin', 'platform') THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN ('platform.ops.module.manage', 'platform.ops.upgrade.manage');

UPDATE `pa_permission`
SET `module_key` = 'platform'
WHERE `module_key` = 'peanut.admin'
  AND `key` IN ('platform.ops.module.manage', 'platform.ops.upgrade.manage');

INSERT INTO `pa_platform_ops_permission_owner_assertion`
  (`phase`, `permission_count`, `expected_owner_count`)
SELECT 'after', COUNT(*),
  SUM(CASE WHEN `module_key` = 'platform' THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN ('platform.ops.module.manage', 'platform.ops.upgrade.manage');

DROP TEMPORARY TABLE `pa_platform_ops_permission_owner_assertion`;

CREATE TEMPORARY TABLE `pa_seeded_permission_parent_candidates` AS
SELECT
  SUM(CASE WHEN `type`='M' AND `paths`='/app-setting' THEN 1 ELSE 0 END) AS group_count,
  MIN(CASE WHEN `type`='M' AND `paths`='/app-setting' THEN `id` END) AS group_id,
  SUM(CASE WHEN `perms`='setting/transaction/config' THEN 1 ELSE 0 END) AS config_count,
  MIN(CASE WHEN `perms`='setting/transaction/config' THEN `id` END) AS config_id,
  SUM(CASE WHEN `perms`='setting/transaction/save' THEN 1 ELSE 0 END) AS save_count,
  MIN(CASE WHEN `perms`='setting/transaction/save' THEN `id` END) AS save_id
FROM `pa_system_menu`;

UPDATE `pa_system_menu` child
JOIN `pa_seeded_permission_parent_candidates` candidates
  ON candidates.config_count=1 AND candidates.group_count=1
  AND child.`id`=candidates.config_id
JOIN `pa_system_menu` wrong_parent
  ON wrong_parent.`id`=child.`pid` AND wrong_parent.`menu_key`=child.`parent_key`
JOIN `pa_system_menu` parent
  ON parent.`id`=candidates.group_id AND parent.`type`='M' AND parent.`paths`='/app-setting'
SET child.`pid`=parent.`id`, child.`parent_key`=parent.`menu_key`
WHERE child.`perms`='setting/transaction/config'
  AND child.`name`='交易设置' AND child.`type`='C'
  AND child.`paths`='/app-setting/transaction'
  AND child.`component`='app-setting/transaction/index'
  AND child.`icon`='' AND child.`sort`=0
  AND child.`is_cache`=0 AND child.`is_show`=1 AND child.`is_disable`=0
  AND child.`module_key`='application' AND child.`menu_key` LIKE 'custom.%'
  AND child.`upstream_defaults_json` IS NULL AND child.`menu_conflict_json` IS NULL
  AND wrong_parent.`menu_key`='core.organization' AND wrong_parent.`paths`='/system'
  AND parent.`menu_key` IS NOT NULL AND parent.`menu_key`<>'';

UPDATE `pa_system_menu` child
JOIN `pa_seeded_permission_parent_candidates` candidates
  ON candidates.save_count=1 AND candidates.config_count=1 AND candidates.group_count=1
  AND child.`id`=candidates.save_id
JOIN `pa_system_menu` wrong_parent
  ON wrong_parent.`id`=child.`pid` AND wrong_parent.`menu_key`=child.`parent_key`
JOIN `pa_system_menu` parent
  ON parent.`id`=candidates.config_id AND parent.`perms`='setting/transaction/config' AND parent.`type`='C'
  AND parent.`paths`='/app-setting/transaction'
JOIN `pa_system_menu` group_parent
  ON group_parent.`id`=candidates.group_id AND group_parent.`id`=parent.`pid` AND group_parent.`menu_key`=parent.`parent_key`
  AND group_parent.`type`='M' AND group_parent.`paths`='/app-setting'
SET child.`pid`=parent.`id`, child.`parent_key`=parent.`menu_key`
WHERE child.`perms`='setting/transaction/save'
  AND child.`name`='交易设置保存' AND child.`type`='A'
  AND child.`paths`='' AND child.`component`='' AND child.`icon`='' AND child.`sort`=0
  AND child.`is_cache`=0 AND child.`is_show`=1 AND child.`is_disable`=0
  AND child.`module_key`='application' AND child.`menu_key` LIKE 'custom.%'
  AND child.`upstream_defaults_json` IS NULL AND child.`menu_conflict_json` IS NULL
  AND wrong_parent.`menu_key`='system.menu.add' AND wrong_parent.`perms`='menu/add';

DROP TEMPORARY TABLE `pa_seeded_permission_parent_candidates`;

CREATE TEMPORARY TABLE `pa_official_article_permission_owner_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `permission_count` INT NOT NULL,
  `allowed_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_article_permission_owner_assertion`
    CHECK (`permission_count` = 13 AND `allowed_owner_count` = 13)
);

INSERT INTO `pa_official_article_permission_owner_assertion`
  (`phase`, `permission_count`, `allowed_owner_count`)
SELECT
  'before',
  COUNT(*),
  SUM(CASE WHEN `module_key` IN ('peanut.admin', 'official.article') THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN (
  'article.articleCate/lists',
  'article.articlecate/all',
  'article.articleCate/detail',
  'article.articleCate/add',
  'article.articleCate/edit',
  'article.articleCate/delete',
  'article.articleCate/updateStatus',
  'article.article/lists',
  'article.article/detail',
  'article.article/add',
  'article.article/edit',
  'article.article/delete',
  'article.article/updateStatus'
);

UPDATE `pa_permission`
SET `module_key` = 'official.article'
WHERE `module_key` = 'peanut.admin'
  AND `key` IN (
    'article.articleCate/lists',
    'article.articlecate/all',
    'article.articleCate/detail',
    'article.articleCate/add',
    'article.articleCate/edit',
    'article.articleCate/delete',
    'article.articleCate/updateStatus',
    'article.article/lists',
    'article.article/detail',
    'article.article/add',
    'article.article/edit',
    'article.article/delete',
    'article.article/updateStatus'
  );

INSERT INTO `pa_official_article_permission_owner_assertion`
  (`phase`, `permission_count`, `allowed_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(CASE WHEN `module_key` = 'official.article' THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN (
  'article.articleCate/lists',
  'article.articlecate/all',
  'article.articleCate/detail',
  'article.articleCate/add',
  'article.articleCate/edit',
  'article.articleCate/delete',
  'article.articleCate/updateStatus',
  'article.article/lists',
  'article.article/detail',
  'article.article/add',
  'article.article/edit',
  'article.article/delete',
  'article.article/updateStatus'
);

DROP TEMPORARY TABLE `pa_official_article_permission_owner_assertion`;

CREATE TEMPORARY TABLE `pa_official_article_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL,
  `new_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`old_key`),
  UNIQUE KEY `uk_official_article_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_article_permission_key_map` (`old_key`, `new_key`) VALUES
  ('article.articleCate/lists',       'official.article.category.list'),
  ('article.articlecate/all',         'official.article.category.all'),
  ('article.articleCate/detail',      'official.article.category.detail'),
  ('article.articleCate/add',         'official.article.category.add'),
  ('article.articleCate/edit',        'official.article.category.edit'),
  ('article.articleCate/delete',      'official.article.category.delete'),
  ('article.articleCate/updateStatus','official.article.category.update-status'),
  ('article.article/lists',           'official.article.list'),
  ('article.article/detail',          'official.article.detail'),
  ('article.article/add',             'official.article.add'),
  ('article.article/edit',            'official.article.edit'),
  ('article.article/delete',          'official.article.delete'),
  ('article.article/updateStatus',    'official.article.update-status');

CREATE TEMPORARY TABLE `pa_official_article_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_article_permission_key_assertion`
    CHECK (`mapped_count` = 13 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_article_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE BINARY p.`key` = BINARY mapping.`old_key`
       OR BINARY p.`key` = BINARY mapping.`new_key`
  )),
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE (BINARY p.`key` = BINARY mapping.`old_key`
        OR BINARY p.`key` = BINARY mapping.`new_key`)
      AND p.`module_key` NOT IN ('peanut.admin', 'official.article')
  ))
FROM `pa_official_article_permission_key_map` mapping;

UPDATE `pa_system_menu` menu
JOIN `pa_official_article_permission_key_map` mapping
  ON BINARY menu.`perms` = BINARY mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` old_permission
JOIN `pa_official_article_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
LEFT JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`
SET old_permission.`key` = mapping.`new_key`,
    old_permission.`module_key` = 'official.article',
    old_permission.`status` = 'active',
    old_permission.`retired_at` = NULL,
    old_permission.`updated_at` = UTC_TIMESTAMP(3)
WHERE new_permission.`id` IS NULL;

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, new_permission.`id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_article_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, new_permission.`id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_article_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_article_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_article_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_article_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_article_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`
SET permission.`module_key` = 'official.article',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_article_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.article')
FROM `pa_permission` permission
JOIN `pa_official_article_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`;

DROP TEMPORARY TABLE `pa_official_article_permission_key_assertion`;

DROP TEMPORARY TABLE `pa_official_article_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_file_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL,
  `new_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`old_key`),
  UNIQUE KEY `uk_official_file_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_file_permission_key_map` (`old_key`, `new_key`) VALUES
  ('file/lists',      'official.file.list'),
  ('file/move',       'official.file.move'),
  ('file/rename',     'official.file.rename'),
  ('file/delete',     'official.file.delete'),
  ('file/cate/lists', 'official.file.category.list'),
  ('file/cate/add',   'official.file.category.add'),
  ('file/cate/edit',  'official.file.category.edit'),
  ('file/cate/delete','official.file.category.delete'),
  ('upload/image',    'official.file.upload.image'),
  ('upload/video',    'official.file.upload.video'),
  ('upload/file',     'official.file.upload.file');

CREATE TEMPORARY TABLE `pa_official_file_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_file_permission_key_assertion`
    CHECK (`mapped_count` = 11 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_file_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE BINARY p.`key` = BINARY mapping.`old_key`
       OR BINARY p.`key` = BINARY mapping.`new_key`
  )),
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE (BINARY p.`key` = BINARY mapping.`old_key`
        OR BINARY p.`key` = BINARY mapping.`new_key`)
      AND p.`module_key` NOT IN ('peanut.admin', 'official.file')
  ))
FROM `pa_official_file_permission_key_map` mapping;

UPDATE `pa_system_menu` menu
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY menu.`perms` = BINARY mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` old_permission
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
LEFT JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`
SET old_permission.`key` = mapping.`new_key`,
    old_permission.`module_key` = 'official.file',
    old_permission.`status` = 'active',
    old_permission.`retired_at` = NULL,
    old_permission.`updated_at` = UTC_TIMESTAMP(3)
WHERE new_permission.`id` IS NULL;

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, new_permission.`id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, new_permission.`id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`
SET permission.`module_key` = 'official.file',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_file_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.file')
FROM `pa_permission` permission
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`;

DROP TEMPORARY TABLE `pa_official_file_permission_key_assertion`;

DROP TEMPORARY TABLE `pa_official_file_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_import_export_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL,
  `new_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`old_key`),
  UNIQUE KEY `uk_official_import_export_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_import_export_permission_key_map` (`old_key`, `new_key`) VALUES
  ('log/export',          'official.import-export.operation-log.export'),
  ('log/export/status',   'official.import-export.operation.status'),
  ('log/export/download', 'official.import-export.result.download');

CREATE TEMPORARY TABLE `pa_official_import_export_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_import_export_permission_key_assertion`
    CHECK (`mapped_count` = 3 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_import_export_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` permission
    WHERE BINARY permission.`key` = BINARY mapping.`old_key`
       OR BINARY permission.`key` = BINARY mapping.`new_key`
  )),
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` permission
    WHERE (BINARY permission.`key` = BINARY mapping.`old_key`
        OR BINARY permission.`key` = BINARY mapping.`new_key`)
      AND permission.`module_key` NOT IN ('peanut.admin', 'official.import-export')
  ))
FROM `pa_official_import_export_permission_key_map` mapping;

UPDATE `pa_system_menu` menu
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY menu.`perms` = BINARY mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` old_permission
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
LEFT JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`
SET old_permission.`key` = mapping.`new_key`,
    old_permission.`module_key` = 'official.import-export',
    old_permission.`status` = 'active',
    old_permission.`retired_at` = NULL,
    old_permission.`updated_at` = UTC_TIMESTAMP(3)
WHERE new_permission.`id` IS NULL;

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, new_permission.`id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, new_permission.`id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`
SET permission.`module_key` = 'official.import-export',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_import_export_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.import-export')
FROM `pa_permission` permission
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`;

DROP TEMPORARY TABLE `pa_official_import_export_permission_key_assertion`;

DROP TEMPORARY TABLE `pa_official_import_export_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_member_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL COLLATE utf8mb4_0900_ai_ci,
  `new_key` VARCHAR(160) NOT NULL COLLATE utf8mb4_0900_ai_ci,
  PRIMARY KEY (`old_key`),
  KEY `idx_official_member_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_member_permission_key_map` (`old_key`, `new_key`) VALUES
  ('member/lists',                         'official.member.list'),
  ('member/detail',                        'official.member.detail'),
  ('user.user/detail',                     'official.member.detail'),
  ('member/add',                           'official.member.add'),
  ('member/edit',                          'official.member.edit'),
  ('member/profile/edit',                  'official.member.edit'),
  ('user.user/edit',                       'official.member.edit'),
  ('member/status',                        'official.member.update-status'),
  ('member/adjustbalance',                 'official.member.balance.adjust'),
  ('member/adjustmoney',                   'official.member.balance.adjust'),
  ('user.user/adjustmoney',                'official.member.balance.adjust'),
  ('member/tag/lists',                     'official.member.tag.list'),
  ('member/tag/add',                       'official.member.tag.add'),
  ('member/tag/edit',                      'official.member.tag.edit'),
  ('member/tag/delete',                    'official.member.tag.delete'),
  ('finance/account-log/lists',            'official.member.account-log.list'),
  ('finance.account_log/lists',            'official.member.account-log.list'),
  ('finance/account-log/change-types',     'official.member.account-log.change-types'),
  ('finance.account_log/getumchangetype',  'official.member.account-log.change-types');

CREATE TEMPORARY TABLE `pa_official_member_new_key_list` (
  `new_key` VARCHAR(160) NOT NULL COLLATE utf8mb4_0900_ai_ci,
  PRIMARY KEY (`new_key`)
);

INSERT INTO `pa_official_member_new_key_list` (`new_key`)
SELECT DISTINCT `new_key` FROM `pa_official_member_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_member_assertion_mapped_count` (
  `count_value` INT NOT NULL
);

INSERT INTO `pa_official_member_assertion_mapped_count` (`count_value`)
SELECT COUNT(*)
FROM `pa_official_member_new_key_list` nkl
WHERE EXISTS(
  SELECT 1
  FROM `pa_permission` permission
  WHERE BINARY permission.`key` = BINARY nkl.`new_key`
) OR EXISTS(
  SELECT 1
  FROM `pa_permission` p2
  JOIN `pa_official_member_permission_key_map` m2
    ON LOWER(p2.`key`) = m2.`old_key`
  WHERE BINARY m2.`new_key` = BINARY nkl.`new_key`
);

CREATE TEMPORARY TABLE `pa_official_member_assertion_bad_owner_count` (
  `count_value` INT NOT NULL
);

INSERT INTO `pa_official_member_assertion_bad_owner_count` (`count_value`)
SELECT COUNT(*)
FROM `pa_permission` permission
LEFT JOIN `pa_official_member_permission_key_map` m3
  ON LOWER(permission.`key`) = m3.`old_key`
WHERE (m3.`old_key` IS NOT NULL
    OR permission.`key` IN (
      SELECT `new_key` FROM `pa_official_member_new_key_list`
    ))
  AND permission.`module_key` NOT IN ('peanut.admin', 'official.member');

CREATE TEMPORARY TABLE `pa_official_member_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_member_permission_key_assertion`
    CHECK (`mapped_count` = 12 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_member_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  (SELECT `count_value` FROM `pa_official_member_assertion_mapped_count`),
  (SELECT `count_value` FROM `pa_official_member_assertion_bad_owner_count`);

CREATE TEMPORARY TABLE `pa_official_member_permission_target` (
  `new_key` VARCHAR(160) NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`new_key`),
  UNIQUE KEY `uk_official_member_permission_target_id` (`permission_id`)
);

INSERT INTO `pa_official_member_permission_target` (`new_key`, `permission_id`)
SELECT
  nkl.`new_key`,
  COALESCE(
    (
      SELECT MIN(permission.`id`)
      FROM `pa_permission` permission
      WHERE BINARY permission.`key` = BINARY nkl.`new_key`
    ),
    (
      SELECT MIN(p2.`id`)
      FROM `pa_permission` p2
      WHERE EXISTS(
        SELECT 1 FROM `pa_official_member_permission_key_map` m
        WHERE LOWER(p2.`key`) = m.`old_key`
          AND BINARY m.`new_key` = BINARY nkl.`new_key`
      )
    )
  )
FROM `pa_official_member_new_key_list` nkl;

CREATE TEMPORARY TABLE `pa_official_member_menu_update_map` (
  `old_perms` VARCHAR(160) NOT NULL COLLATE utf8mb4_0900_ai_ci,
  `new_perms` VARCHAR(160) NOT NULL COLLATE utf8mb4_0900_ai_ci,
  PRIMARY KEY (`old_perms`)
);

INSERT INTO `pa_official_member_menu_update_map` (`old_perms`, `new_perms`)
SELECT `old_key`, `new_key`
FROM `pa_official_member_permission_key_map`;

UPDATE `pa_system_menu` menu
JOIN `pa_official_member_menu_update_map` menu_map
  ON LOWER(menu.`perms`) = menu_map.`old_perms`
SET menu.`perms` = menu_map.`new_perms`;

UPDATE `pa_permission` permission
JOIN `pa_official_member_permission_target` target
  ON target.`permission_id` = permission.`id`
SET permission.`key` = target.`new_key`,
    permission.`module_key` = 'official.member',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

CREATE TEMPORARY TABLE `pa_official_member_role_binding_map` (
  `old_permission_id` BIGINT UNSIGNED NOT NULL,
  `new_permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`old_permission_id`)
);

INSERT INTO `pa_official_member_role_binding_map` (`old_permission_id`, `new_permission_id`)
SELECT old_permission.`id`, target.`permission_id`
FROM `pa_permission` old_permission
JOIN `pa_official_member_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`
JOIN `pa_official_member_permission_target` target
  ON BINARY target.`new_key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, rb_map.`new_permission_id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_official_member_role_binding_map` rb_map
  ON rb_map.`old_permission_id` = binding.`permission_id`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, rb_map.`new_permission_id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_official_member_role_binding_map` rb_map
  ON rb_map.`old_permission_id` = binding.`permission_id`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_official_member_role_binding_map` rb_map
  ON rb_map.`old_permission_id` = binding.`permission_id`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_official_member_role_binding_map` rb_map
  ON rb_map.`old_permission_id` = binding.`permission_id`;

CREATE TEMPORARY TABLE `pa_official_member_old_permission_ids` (
  `old_permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`old_permission_id`)
);

INSERT INTO `pa_official_member_old_permission_ids` (`old_permission_id`)
SELECT `old_permission_id`
FROM `pa_official_member_role_binding_map`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_member_old_permission_ids` old_ids
  ON old_ids.`old_permission_id` = old_permission.`id`;

UPDATE `pa_permission` permission
JOIN `pa_official_member_permission_target` target
  ON target.`permission_id` = permission.`id`
SET permission.`module_key` = 'official.member',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_member_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.member')
FROM `pa_permission` permission
JOIN `pa_official_member_permission_target` target
  ON BINARY permission.`key` = BINARY target.`new_key`;

DROP TEMPORARY TABLE `pa_official_member_old_permission_ids`;

DROP TEMPORARY TABLE `pa_official_member_role_binding_map`;

DROP TEMPORARY TABLE `pa_official_member_menu_update_map`;

DROP TEMPORARY TABLE `pa_official_member_permission_target`;

DROP TEMPORARY TABLE `pa_official_member_permission_key_assertion`;

DROP TEMPORARY TABLE `pa_official_member_assertion_bad_owner_count`;

DROP TEMPORARY TABLE `pa_official_member_assertion_mapped_count`;

DROP TEMPORARY TABLE `pa_official_member_new_key_list`;

DROP TEMPORARY TABLE `pa_official_member_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_notification_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL,
  `new_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`old_key`),
  UNIQUE KEY `uk_official_notification_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_notification_permission_key_map` (`old_key`, `new_key`) VALUES
  ('notice/channel/detail','official.notification.channel.detail'),
  ('notice/channel/save',  'official.notification.channel.save'),
  ('notice/log/lists',     'official.notification.log.list'),
  ('notice/log/detail',    'official.notification.log.detail'),
  ('notice/scene/lists',   'official.notification.scene.list'),
  ('notice/scene/detail',  'official.notification.scene.detail'),
  ('notice/scene/save',    'official.notification.scene.save');

CREATE TEMPORARY TABLE `pa_official_notification_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_notification_permission_key_assertion`
    CHECK (`mapped_count` = 7 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_notification_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE BINARY p.`key` = BINARY mapping.`old_key`
       OR BINARY p.`key` = BINARY mapping.`new_key`
  )),
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE (BINARY p.`key` = BINARY mapping.`old_key`
        OR BINARY p.`key` = BINARY mapping.`new_key`)
      AND p.`module_key` NOT IN ('peanut.admin', 'official.notification')
  ))
FROM `pa_official_notification_permission_key_map` mapping;

UPDATE `pa_system_menu` menu
JOIN `pa_official_notification_permission_key_map` mapping
  ON BINARY menu.`perms` = BINARY mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` old_permission
JOIN `pa_official_notification_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
LEFT JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`
SET old_permission.`key` = mapping.`new_key`,
    old_permission.`module_key` = 'official.notification',
    old_permission.`status` = 'active',
    old_permission.`retired_at` = NULL,
    old_permission.`updated_at` = UTC_TIMESTAMP(3)
WHERE new_permission.`id` IS NULL;

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, new_permission.`id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_notification_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, new_permission.`id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_notification_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_notification_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_notification_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_notification_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_notification_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`
SET permission.`module_key` = 'official.notification',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

UPDATE `pa_permission`
SET `status` = 'retired',
    `retired_at` = COALESCE(`retired_at`, UTC_TIMESTAMP(3)),
    `updated_at` = UTC_TIMESTAMP(3)
WHERE `module_key` IN ('peanut.admin', 'official.notification')
  AND `key` IN (
    'notice/template/add',
    'notice/template/edit',
    'notice/template/delete'
  );

INSERT INTO `pa_official_notification_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.notification')
FROM `pa_permission` permission
JOIN `pa_official_notification_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`;

DROP TEMPORARY TABLE `pa_official_notification_permission_key_assertion`;

DROP TEMPORARY TABLE `pa_official_notification_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_oauth_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL,
  `new_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`old_key`),
  UNIQUE KEY `uk_official_oauth_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_oauth_permission_key_map` (`old_key`, `new_key`) VALUES
  ('setting/web-page/config',                    'official.oauth.web-page.config'),
  ('setting/web-page/save',                      'official.oauth.web-page.save'),
  ('setting/mini-program/config',                'official.oauth.mini-program.config'),
  ('setting/mini-program/save',                  'official.oauth.mini-program.save'),
  ('setting/official-account/config',            'official.oauth.official-account.config'),
  ('setting/official-account/save',              'official.oauth.official-account.save'),
  ('setting/official-account/menu',              'official.oauth.official-account.menu.detail'),
  ('setting/official-account/menu/save',         'official.oauth.official-account.menu.save'),
  ('setting/official-account/menu/publish',      'official.oauth.official-account.menu.publish'),
  ('setting/official-account/reply/lists',       'official.oauth.official-account.reply.list'),
  ('setting/official-account/reply/detail',      'official.oauth.official-account.reply.detail'),
  ('setting/official-account/reply/add',         'official.oauth.official-account.reply.add'),
  ('setting/official-account/reply/edit',        'official.oauth.official-account.reply.edit'),
  ('setting/official-account/reply/delete',      'official.oauth.official-account.reply.delete'),
  ('setting/official-account/reply/status',      'official.oauth.official-account.reply.update-status'),
  ('setting/open-platform/config',               'official.oauth.open-platform.config'),
  ('setting/open-platform/save',                 'official.oauth.open-platform.save');

CREATE TEMPORARY TABLE `pa_official_oauth_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_oauth_permission_key_assertion`
    CHECK (`mapped_count` = 17 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_oauth_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` permission
    WHERE BINARY permission.`key` = BINARY mapping.`old_key`
       OR BINARY permission.`key` = BINARY mapping.`new_key`
  )),
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` permission
    WHERE (BINARY permission.`key` = BINARY mapping.`old_key`
        OR BINARY permission.`key` = BINARY mapping.`new_key`)
      AND permission.`module_key` NOT IN ('peanut.admin', 'official.oauth')
  ))
FROM `pa_official_oauth_permission_key_map` mapping;

UPDATE `pa_system_menu` menu
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY menu.`perms` = BINARY mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` old_permission
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
LEFT JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`
SET old_permission.`key` = mapping.`new_key`,
    old_permission.`module_key` = 'official.oauth',
    old_permission.`status` = 'active',
    old_permission.`retired_at` = NULL,
    old_permission.`updated_at` = UTC_TIMESTAMP(3)
WHERE new_permission.`id` IS NULL;

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, new_permission.`id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, new_permission.`id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`
SET permission.`module_key` = 'official.oauth',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_oauth_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.oauth')
FROM `pa_permission` permission
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`;

DROP TEMPORARY TABLE `pa_official_oauth_permission_key_assertion`;

DROP TEMPORARY TABLE `pa_official_oauth_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_payment_permission_key_map` (
  `old_key` VARCHAR(160) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `new_key` VARCHAR(160) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`old_key`),
  KEY `idx_official_payment_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_payment_permission_key_map` (`old_key`, `new_key`) VALUES
  ('setting/pay/config',               'official.payment.settings.detail'),
  ('setting/pay/save',                 'official.payment.settings.save'),
  ('setting/recharge/config',          'official.payment.recharge-settings.detail'),
  ('setting/recharge/save',            'official.payment.recharge-settings.save'),
  ('finance/recharge/lists',           'official.payment.recharge.list'),
  ('recharge.recharge/lists',          'official.payment.recharge.list'),
  ('finance/recharge/refund',          'official.payment.recharge.refund'),
  ('recharge.recharge/refund',         'official.payment.recharge.refund'),
  ('finance/recharge/refundagain',     'official.payment.refund.retry'),
  ('recharge.recharge/refundagain',    'official.payment.refund.retry'),
  ('finance/refund/stat',              'official.payment.refund.stat'),
  ('finance.refund/stat',              'official.payment.refund.stat'),
  ('finance/refund/record',            'official.payment.refund.list'),
  ('finance.refund/record',            'official.payment.refund.list'),
  ('finance/refund/log',               'official.payment.refund.log'),
  ('finance.refund/log',               'official.payment.refund.log');

CREATE TEMPORARY TABLE `pa_official_payment_new_key_list` (
  `new_key` VARCHAR(160) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`new_key`)
);

INSERT INTO `pa_official_payment_new_key_list` (`new_key`)
SELECT DISTINCT `new_key` FROM `pa_official_payment_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_payment_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_payment_permission_key_assertion`
    CHECK (`mapped_count` = 10 AND `bad_owner_count` = 0)
);

CREATE TEMPORARY TABLE `pa_official_payment_assertion_mapped_count` (
  `count` INT NOT NULL
);

INSERT INTO `pa_official_payment_assertion_mapped_count` (`count`)
SELECT COUNT(*)
FROM `pa_official_payment_new_key_list` nkl
WHERE EXISTS(
  SELECT 1
  FROM `pa_permission` permission
  WHERE BINARY permission.`key` = BINARY nkl.`new_key`
) OR EXISTS(
  SELECT 1
  FROM `pa_permission` permission
  JOIN `pa_official_payment_permission_key_map` map_exists
    ON LOWER(permission.`key`) = map_exists.`old_key`
  WHERE BINARY map_exists.`new_key` = BINARY nkl.`new_key`
);

CREATE TEMPORARY TABLE `pa_official_payment_assertion_bad_owner_count` (
  `count` INT NOT NULL
);

INSERT INTO `pa_official_payment_assertion_bad_owner_count` (`count`)
SELECT COUNT(*)
FROM `pa_permission` permission
LEFT JOIN `pa_official_payment_permission_key_map` map_left
  ON LOWER(permission.`key`) = map_left.`old_key`
WHERE (map_left.`old_key` IS NOT NULL
    OR permission.`key` IN (
      SELECT `new_key` FROM `pa_official_payment_new_key_list`
    ))
  AND permission.`module_key` NOT IN ('peanut.admin', 'official.payment');

INSERT INTO `pa_official_payment_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  (SELECT `count` FROM `pa_official_payment_assertion_mapped_count`),
  (SELECT `count` FROM `pa_official_payment_assertion_bad_owner_count`);

CREATE TEMPORARY TABLE `pa_official_payment_permission_target` (
  `new_key` VARCHAR(160) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`new_key`),
  UNIQUE KEY `uk_official_payment_permission_target_id` (`permission_id`)
);

INSERT INTO `pa_official_payment_permission_target` (`new_key`, `permission_id`)
SELECT
  nkl.`new_key`,
  COALESCE(
    (
      SELECT MIN(permission.`id`)
      FROM `pa_permission` permission
      WHERE BINARY permission.`key` = BINARY nkl.`new_key`
    ),
    (
      SELECT MIN(p2.`id`)
      FROM `pa_permission` p2
      WHERE EXISTS(
        SELECT 1 FROM `pa_official_payment_permission_key_map` m
        WHERE LOWER(p2.`key`) = m.`old_key`
          AND BINARY m.`new_key` = BINARY nkl.`new_key`
      )
    )
  )
FROM `pa_official_payment_new_key_list` nkl;

UPDATE `pa_system_menu` menu
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(menu.`perms`) = mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_payment_permission_target` target
  ON target.`permission_id` = permission.`id`
SET permission.`key` = target.`new_key`,
    permission.`module_key` = 'official.payment',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, target.`permission_id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission
  ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`
JOIN `pa_official_payment_permission_target` target
  ON BINARY target.`new_key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, target.`permission_id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission
  ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`
JOIN `pa_official_payment_permission_target` target
  ON BINARY target.`new_key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission
  ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission
  ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_payment_permission_target` target
  ON target.`permission_id` = permission.`id`
SET permission.`module_key` = 'official.payment',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_payment_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.payment')
FROM `pa_permission` permission
JOIN `pa_official_payment_permission_target` target
  ON BINARY permission.`key` = BINARY target.`new_key`;

DROP TEMPORARY TABLE `pa_official_payment_permission_target`;

DROP TEMPORARY TABLE `pa_official_payment_permission_key_assertion`;

DROP TEMPORARY TABLE `pa_official_payment_assertion_bad_owner_count`;

DROP TEMPORARY TABLE `pa_official_payment_assertion_mapped_count`;

DROP TEMPORARY TABLE `pa_official_payment_new_key_list`;

DROP TEMPORARY TABLE `pa_official_payment_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_task_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL,
  `new_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`old_key`),
  UNIQUE KEY `uk_official_task_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_task_permission_key_map` (`old_key`, `new_key`) VALUES
  ('crontab/lists',     'official.task.list'),
  ('crontab/detail',    'official.task.detail'),
  ('crontab/expression','official.task.expression'),
  ('crontab/add',       'official.task.add'),
  ('crontab/edit',      'official.task.edit'),
  ('crontab/delete',    'official.task.delete'),
  ('crontab/operate',   'official.task.operate');

CREATE TEMPORARY TABLE `pa_official_task_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_task_permission_key_assertion`
    CHECK (`mapped_count` = 7 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_task_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE BINARY p.`key` = BINARY mapping.`old_key`
       OR BINARY p.`key` = BINARY mapping.`new_key`
  )),
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE (BINARY p.`key` = BINARY mapping.`old_key`
        OR BINARY p.`key` = BINARY mapping.`new_key`)
      AND p.`module_key` NOT IN ('peanut.admin', 'official.task')
  ))
FROM `pa_official_task_permission_key_map` mapping;

UPDATE `pa_system_menu` menu
JOIN `pa_official_task_permission_key_map` mapping
  ON BINARY menu.`perms` = BINARY mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` old_permission
JOIN `pa_official_task_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
LEFT JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`
SET old_permission.`key` = mapping.`new_key`,
    old_permission.`module_key` = 'official.task',
    old_permission.`status` = 'active',
    old_permission.`retired_at` = NULL,
    old_permission.`updated_at` = UTC_TIMESTAMP(3)
WHERE new_permission.`id` IS NULL;

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, new_permission.`id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_task_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, new_permission.`id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_task_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_task_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_task_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_task_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_task_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`
SET permission.`module_key` = 'official.task',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_task_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.task')
FROM `pa_permission` permission
JOIN `pa_official_task_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`;

DROP TEMPORARY TABLE `pa_official_task_permission_key_assertion`;

DROP TEMPORARY TABLE `pa_official_task_permission_key_map`;
