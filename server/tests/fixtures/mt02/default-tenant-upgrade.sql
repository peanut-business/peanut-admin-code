INSERT INTO `pa_admin`
    (`id`,`username`,`nickname`,`password`,`salt`,`root`,`disable`,`create_time`,`update_time`)
VALUES
    (2,'editor','内容编辑',MD5(CONCAT(MD5('EditorPass2026'),'feedfacefeedface')),'feedfacefeedface',0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
    (3,'disabled','停用管理员',MD5(CONCAT(MD5('DisabledPass2026'),'deadbeefdeadbeef')),'deadbeefdeadbeef',0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `pa_system_role` (`id`,`name`,`desc`,`sort`,`create_time`,`update_time`)
VALUES (2,'内容编辑','MT02 fixture role',20,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `pa_dept`
    (`id`,`pid`,`name`,`leader`,`mobile`,`sort`,`is_disable`,`status`,`create_time`,`update_time`)
VALUES
    (1,0,'总部','','',10,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
    (2,1,'内容部','','',20,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `pa_job`
    (`id`,`name`,`code`,`sort`,`is_disable`,`status`,`remark`,`create_time`,`update_time`)
VALUES (1,'编辑','editor',10,0,1,'MT02 fixture job',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `pa_admin_role` (`admin_id`,`role_id`) VALUES (2,2);
INSERT INTO `pa_admin_dept` (`admin_id`,`dept_id`) VALUES (2,1),(2,2);
INSERT INTO `pa_admin_jobs` (`admin_id`,`jobs_id`) VALUES (2,1);
INSERT IGNORE INTO `pa_system_role_menu` (`role_id`,`menu_id`) VALUES (2,1),(2,2);
