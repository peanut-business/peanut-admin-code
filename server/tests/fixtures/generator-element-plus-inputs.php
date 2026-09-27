<?php

declare(strict_types=1);

/**
 * 合成的现有模块 CRUD 预览输入；仅返回定义，不建表、不应用生成预览。
 * A 的 PHP 生成回归与 C 的前端消费者均通过 GeneratorRenderService::render 使用这些原始输入。
 */
$definitions = [];
foreach (['string' => 'uuid', 'int' => 'id'] as $type => $primary) {
    foreach (['plain' => false, 'recycle' => true] as $mode => $softDelete) {
        $name = ucfirst($type) . ucfirst($mode) . 'ViewNote';
        $definition = [
            'table_name' => 'pa_fixture_' . $type . '_' . $mode . '_view_note',
            'table_comment' => '生成页面验证',
            'module_name' => 'fixture.delivery-record',
            'entity_name' => $name,
            'data_owner' => 'tenant-orm',
            'target_edition' => 'multi-tenant',
            'columns' => [
                ['column_name' => $primary, 'php_type' => $type, 'column_type' => $type === 'int' ? 'bigint' : 'varchar(36)', 'is_pk' => true, 'is_required' => true, 'is_lists' => true],
                ['column_name' => 'tenant_id', 'php_type' => 'int', 'is_required' => true, 'is_lists' => true],
                ['column_name' => 'title', 'column_comment' => '标题', 'php_type' => 'string', 'column_type' => 'varchar(100)', 'is_required' => true, 'is_insert' => true, 'is_update' => true, 'is_lists' => true, 'is_query' => true],
                ['column_name' => 'api_secret', 'php_type' => 'string', 'is_insert' => true, 'is_update' => true, 'is_lists' => true],
                ['column_name' => 'delete_time', 'php_type' => 'int'],
                ['column_name' => 'removed_at', 'php_type' => 'int'],
            ],
        ];
        if ($softDelete) {
            $definition['soft_delete'] = ['enabled' => true, 'field' => 'removed_at'];
        }
        $definitions[$type . '-' . $mode] = $definition;
    }
}
return $definitions;
