<?php
declare(strict_types=1);

namespace app\adminapi\services\generator;

use RuntimeException;

/** 将已完成归属校验的生成器表配置渲染为 Peanut 分层代码预览。 */
class GeneratorRenderService
{
    /**
     * @param array<string,mixed> $table 已由调用方校验归属的生成器表快照
     * @return array<int,array{path:string,language:string,content:string}>
     */
    public static function render(array $table): array
    {
        $context = self::context($table);

        return [
            self::file($context['modelPath'], 'php', self::renderModel($context)),
            self::file($context['servicePath'], 'php', self::renderService($context)),
            self::file($context['controllerPath'], 'php', self::renderController($context)),
            self::file($context['validatePath'], 'php', self::renderValidate($context)),
            self::file($context['apiPath'], 'typescript', self::renderApi($context)),
            self::file($context['viewPath'], 'vue', self::renderView($context)),
        ];
    }

    /** @return array{path:string,language:string,content:string} */
    private static function file(string $path, string $language, string $content): array
    {
        return compact('path', 'language', 'content');
    }

    /** @return array<string,mixed> */
    private static function context(array $table): array
    {
        $tableName = trim((string)($table['table_name'] ?? $table['name'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]*$/', $tableName) !== 1) {
            throw new RuntimeException('数据表名称不符合生成规范');
        }
        $modelName = str_starts_with($tableName, 'pa_') ? substr($tableName, 3) : $tableName;
        if ($modelName === '' || preg_match('/^[a-z][a-z0-9_]*$/', $modelName) !== 1) {
            throw new RuntimeException('无法从数据表推导实体名称');
        }

        $parts = explode('_', $modelName);
        $module = trim((string)($table['module_name'] ?? $parts[0]));
        $entity = trim((string)($table['entity_name'] ?? self::pascal($modelName)));
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $module) !== 1
            || preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/', $entity) !== 1) {
            throw new RuntimeException('模块或实体名称不符合生成规范');
        }
        $resource = str_replace('_', '-', $modelName);
        $title = trim((string)($table['table_comment'] ?? $table['comment'] ?? $entity));
        $title = $title !== '' ? $title : $entity;

        $rawColumns = $table['columns'] ?? [];
        if (is_string($rawColumns)) {
            $rawColumns = json_decode($rawColumns, true) ?? [];
        }
        if (!is_array($rawColumns) || $rawColumns === []) {
            throw new RuntimeException('数据表字段不能为空');
        }
        $columns = self::columns($rawColumns);
        $primary = self::primaryColumn($columns);
        $owner = strtolower(trim((string)($table['data_owner'] ?? $table['owner'] ?? $table['ownership'] ?? '')));
        if ($owner === '') {
            throw new RuntimeException('生成配置必须显式声明数据所有权');
        }
        $owner = match ($owner) {
            'tenant', 'tenant_owned', 'tenant-orm' => 'tenant-orm',
            'platform', 'instance', 'shared' => $owner,
            default => throw new RuntimeException('生成配置必须声明有效的数据所有权'),
        };
        $edition = strtolower(trim((string)($table['target_edition'] ?? $table['edition'] ?? '')));
        if (!in_array($edition, ['multi-tenant', 'standalone'], true)) {
            throw new RuntimeException('生成配置必须声明 standalone 或 multi-tenant Edition');
        }
        if ($owner === 'tenant-orm' && !in_array('tenant_id', array_column($columns, 'name'), true)) {
            throw new RuntimeException('Tenant-owned 生成实体必须包含 tenant_id 字段');
        }
        $tree = self::treeConfig($table['tree_config'] ?? [], $columns);
        $relations = self::relations($table['relations'] ?? [], $module, $owner, $edition);

        $base = [
            'module' => $module,
            'entity' => $entity,
            'entityVar' => lcfirst($entity),
            'resource' => $resource,
            'tableName' => $modelName,
            'title' => self::plainText($title),
            'columns' => $columns,
            'primary' => $primary,
            'tree' => $tree,
            'relations' => $relations,
            'owner' => $owner,
            'edition' => $edition,
        ];
        return $base + [
            'modelPath' => "server/app/common/model/{$module}/{$entity}.php",
            'servicePath' => "server/app/adminapi/services/{$module}/{$entity}Service.php",
            'controllerPath' => "server/app/adminapi/controller/{$module}/{$entity}Controller.php",
            'validatePath' => "server/app/adminapi/validate/{$module}/{$entity}Validate.php",
            'apiPath' => "web/src/api/{$module}/{$resource}.ts",
            'viewPath' => "web/src/views/{$module}/{$resource}/index.vue",
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function columns(array $rawColumns): array
    {
        $columns = [];
        foreach ($rawColumns as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $name = trim((string)($raw['column_name'] ?? $raw['name'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                throw new RuntimeException('数据表包含不安全的字段名称');
            }
            $type = strtolower((string)($raw['php_type'] ?? $raw['data_type'] ?? $raw['type'] ?? 'string'));
            $comment = self::plainText((string)($raw['column_comment'] ?? $raw['comment'] ?? $name));
            $columns[] = [
                'name' => $name,
                'type' => $type,
                'comment' => $comment !== '' ? $comment : $name,
                'primary' => self::truthy($raw['is_pk'] ?? $raw['primary'] ?? false),
                'required' => self::truthy($raw['is_required'] ?? false)
                    || (($raw['is_nullable'] ?? 'YES') === 'NO'),
                'list' => self::truthy($raw['is_lists'] ?? $raw['is_list'] ?? false),
                'query' => self::truthy($raw['is_query'] ?? false),
                'insert' => self::truthy($raw['is_insert'] ?? false),
                'update' => self::truthy($raw['is_update'] ?? false),
                'length' => self::columnLength($raw),
                'tsType' => self::tsType($type),
                'rule' => self::validationRule($type, self::columnLength($raw)),
            ];
        }
        if ($columns === []) {
            throw new RuntimeException('没有可生成的安全字段');
        }
        return $columns;
    }

    /** @param array<int,array<string,mixed>> $columns */
    private static function primaryColumn(array $columns): string
    {
        foreach ($columns as $column) {
            if ($column['primary']) {
                return $column['name'];
            }
        }
        foreach ($columns as $column) {
            if ($column['name'] === 'id') {
                return 'id';
            }
        }
        throw new RuntimeException('生成实体必须具有主键');
    }

    /** @param array<int,array<string,mixed>> $columns */
    private static function treeConfig(mixed $rawTree, array $columns): array
    {
        if (is_string($rawTree)) {
            $rawTree = json_decode($rawTree, true) ?? [];
        }
        if (!is_array($rawTree) || $rawTree === []) {
            return [];
        }
        $names = array_column($columns, 'name');
        $parent = (string)($rawTree['parent_field'] ?? $rawTree['pid_field'] ?? 'pid');
        $label = (string)($rawTree['label_field'] ?? $rawTree['name_field'] ?? 'name');
        if (!in_array($parent, $names, true) || !in_array($label, $names, true)) {
            throw new RuntimeException('树形配置引用了不存在的字段');
        }
        return ['parent' => $parent, 'label' => $label];
    }

    /** @return array<int,array<string,string>> */
    private static function relations(
        mixed $rawRelations,
        string $defaultModule,
        string $owner,
        string $edition,
    ): array
    {
        if (is_string($rawRelations)) {
            try {
                $rawRelations = json_decode($rawRelations, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('关联配置 JSON 无效', 0, $exception);
            }
        }
        if (!is_array($rawRelations)) {
            throw new RuntimeException('关联配置必须是数组');
        }
        $relations = [];
        $used = [];
        foreach ($rawRelations as $index => $raw) {
            if (!is_array($raw)) {
                throw new RuntimeException('关联配置项格式无效');
            }
            $model = trim((string)($raw['model'] ?? ''));
            $module = trim((string)($raw['module'] ?? $defaultModule));
            $configuredName = trim((string)($raw['name'] ?? ''));
            if ($model !== '') {
                if (preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/', $model) !== 1
                    || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $module) !== 1
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $configuredName) !== 1) {
                    throw new RuntimeException('关联模型配置不符合生成规范');
                }
                $relatedName = $model;
                $relatedParts = [$module];
            } else {
                $relatedTable = trim((string)($raw['related_table'] ?? $raw['table_name'] ?? ''));
                if (preg_match('/^[a-z][a-z0-9_]*$/', $relatedTable) !== 1) {
                    throw new RuntimeException('关联表名称不符合生成规范');
                }
                $relatedName = str_starts_with($relatedTable, 'pa_') ? substr($relatedTable, 3) : $relatedTable;
                $relatedParts = explode('_', $relatedName);
            }
            $type = strtolower((string)($raw['relation_type'] ?? $raw['type'] ?? 'belongsTo'));
            $method = match ($type) {
                'hasone', 'has_one' => 'hasOne',
                'hasmany', 'has_many' => 'hasMany',
                'belongsto', 'belongs_to' => 'belongsTo',
                default => throw new RuntimeException('关联类型只允许 belongsTo、hasOne 或 hasMany'),
            };
            $relatedOwner = strtolower(trim((string)($raw['data_owner'] ?? '')));
            $relatedEdition = strtolower(trim((string)($raw['target_edition'] ?? '')));
            if (!in_array($relatedOwner, ['tenant-orm', 'platform', 'instance', 'shared'], true)
                || $relatedEdition !== $edition) {
                throw new RuntimeException('关联模型必须声明相同 Edition 的有效数据所有权');
            }
            if (($owner === 'tenant-orm' && !in_array($relatedOwner, ['tenant-orm', 'shared'], true))
                || ($owner !== 'tenant-orm' && $relatedOwner === 'tenant-orm')) {
                throw new RuntimeException('关联模型的数据所有权边界不兼容');
            }
            $localKey = self::safeIdentifier((string)($raw['local_key'] ?? 'id'));
            $foreignKey = self::safeIdentifier((string)($raw['foreign_key'] ?? 'id'));
            $name = $configuredName !== '' ? $configuredName : lcfirst(self::pascal($relatedName));
            if (isset($used[$name])) {
                $name .= (string)($index + 1);
            }
            $used[$name] = true;
            $relations[] = [
                'name' => $name,
                'method' => $method,
                'module' => $relatedParts[0],
                'entity' => $model !== '' ? $model : self::pascal($relatedName),
                'localKey' => $localKey,
                'foreignKey' => $foreignKey,
                'dataOwner' => $relatedOwner,
                'edition' => $relatedEdition,
            ];
        }
        return $relations;
    }

    private static function renderModel(array $c): string
    {
        $relationMethods = '';
        foreach ($c['relations'] as $relation) {
            $arguments = $relation['method'] === 'belongsTo'
                ? "'{$relation['localKey']}', '{$relation['foreignKey']}'"
                : "'{$relation['foreignKey']}', '{$relation['localKey']}'";
            $relationMethods .= "\n    public function {$relation['name']}()\n    {\n"
                . "        return \$this->{$relation['method']}(\\app\\common\\model\\{$relation['module']}\\{$relation['entity']}::class, {$arguments});\n"
                . "    }\n";
        }
        $baseModel = match ($c['owner']) {
            'tenant-orm' => 'TenantOwnedModel',
            'platform' => 'PlatformOwnedModel',
            'instance' => 'InstanceOwnedModel',
            'shared' => 'SharedModel',
        };
        $baseImport = "use app\\common\\model\\{$baseModel};";
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace app\adminapi\services\generator;

{{baseImport}}

class {{entity}} extends {{baseModel}}
{
    protected $name = '{{tableName}}';
    protected $pk = '{{primary}}';
{{relationMethods}}}
PHP, $c + [
            'relationMethods' => $relationMethods,
            'baseModel' => $baseModel,
            'baseImport' => $baseImport,
        ]);
    }

    private static function renderController(array $c): string
    {
        $listResponse = $c['tree'] !== []
            ? "return \$this->data(\$result);"
            : "return \$this->dataLists(\$result);";
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace app\adminapi\services\generator;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\{{module}}\{{entity}}Service;
use app\adminapi\validate\{{module}}\{{entity}}Validate;

class {{entity}}Controller extends BaseAdminController
{
    protected function service(): {{entity}}Service
    {
        return $this->app->make({{entity}}Service::class);
    }

    public function lists()
    {
        $result = $this->service()->lists($this->request->get());
        {{listResponse}}
    }

    public function detail()
    {
        $params = $this->request->get();
        $this->validate($params, {{entity}}Validate::class . '.detail');
        return $this->data($this->service()->detail($params['{{primary}}']));
    }

    public function add()
    {
        $params = $this->request->post();
        $this->validate($params, {{entity}}Validate::class . '.add');
        $this->service()->add($params);
        return $this->success('操作成功');
    }

    public function edit()
    {
        $params = $this->request->post();
        $this->validate($params, {{entity}}Validate::class . '.edit');
        $this->service()->edit($params);
        return $this->success('操作成功');
    }

    public function delete()
    {
        $params = $this->request->post();
        $this->validate($params, {{entity}}Validate::class . '.delete');
        $this->service()->delete($params['{{primary}}']);
        return $this->success('操作成功');
    }
}
PHP, $c + ['listResponse' => $listResponse]);
    }

    private static function renderService(array $c): string
    {
        $systemFields = [$c['primary'], 'tenant_id', 'create_time', 'update_time', 'delete_time'];
        $insertFields = array_values(array_map(
            static fn(array $column): string => $column['name'],
            array_filter($c['columns'], static fn(array $column): bool =>
                $column['insert'] && !in_array($column['name'], $systemFields, true)),
        ));
        $updateFields = array_values(array_map(
            static fn(array $column): string => $column['name'],
            array_filter($c['columns'], static fn(array $column): bool =>
                $column['update'] && !in_array($column['name'], $systemFields, true)),
        ));
        $queryCode = '';
        foreach ($c['columns'] as $column) {
            if ($column['query']) {
                $operator = in_array($column['type'], ['varchar', 'char', 'text'], true) ? 'whereLike' : 'where';
                $value = $operator === 'whereLike'
                    ? "'%' . trim((string)\$params['{$column['name']}']) . '%'"
                    : "\$params['{$column['name']}']";
                $queryCode .= "        if (isset(\$params['{$column['name']}']) && \$params['{$column['name']}'] !== '') {\n"
                    . "            \$query->{$operator}('{$column['name']}', {$value});\n        }\n";
            }
        }
        $withCode = $c['relations'] === [] ? '' : "\n            ->with(" . var_export(array_column($c['relations'], 'name'), true) . ')';
        if ($c['tree'] !== []) {
            $listBody = "        \$rows = \$query{$withCode}->order(['{$c['primary']}' => 'desc'])->select()->toArray();\n"
                . "        return linear_to_tree(\$rows, 'children', '{$c['primary']}', '{$c['tree']['parent']}');";
            $childGuard = "            if ({$c['entity']}::where('{$c['tree']['parent']}', \$id)->count() > 0) {\n"
                . "                throw new \\RuntimeException('请先删除下级节点');\n            }\n";
        } else {
            $listBody = "        \$page = PaginationInput::from(\$params)->result(\$query{$withCode}->order(['{$c['primary']}' => 'desc']));\n"
                . "        return \$page->map(static fn(\$item): array => \$item instanceof \\think\\Model ? \$item->toArray() : (array)\$item);";
            $childGuard = '';
        }
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace app\adminapi\services\generator;

use app\common\http\PageResult;
use app\common\model\{{module}}\{{entity}};
use think\facade\Db;
use app\common\support\PaginationInput;

final readonly class {{entity}}Service
{
    private const INSERT_FIELDS = {{insertFields}};
    private const UPDATE_FIELDS = {{updateFields}};

    public function lists(array $params): array|PageResult
    {
        $query = {{entity}}::where([]);
{{queryCode}}{{listBody}}
    }

    public function detail(int|string $id): array
    {
        return $this->find($id)->toArray();
    }

    public function add(array $params): void
    {
        {{entity}}::create(array_intersect_key($params, array_flip(self::INSERT_FIELDS)));
    }

    public function edit(array $params): void
    {
        $this->mutate($params['{{primary}}'], function ({{entity}} $model) use ($params): void {
            $model->save(array_intersect_key($params, array_flip(self::UPDATE_FIELDS)));
        });
    }

    public function delete(int|string $id): void
    {
        $this->mutate($id, function ({{entity}} $model) use ($id): void {
{{childGuard}}            $model->delete();
        });
    }

    private function mutate(int|string $id, callable $callback): void
    {
        Db::transaction(function () use ($id, $callback): void {
            $callback($this->find($id, true));
        });
    }

    private function find(int|string $id, bool $lock = false): {{entity}}
    {
        $query = {{entity}}::where('{{primary}}', $id);
        if ($lock) {
            $query->lock(true);
        }
        $model = $query->findOrEmpty();
        if ($model->isEmpty()) {
            throw new \DomainException('{{title}}不存在');
        }
        return $model;
    }
}
PHP, $c + [
            'insertFields' => var_export($insertFields, true),
            'updateFields' => var_export($updateFields, true),
            'queryCode' => $queryCode,
            'listBody' => $listBody,
            'childGuard' => $childGuard,
        ]);
    }

    private static function renderValidate(array $c): string
    {
        $rules = [];
        $messages = [];
        $addFields = [];
        $updateFields = [];
        foreach ($c['columns'] as $column) {
            if (in_array($column['name'], ['tenant_id', 'create_time', 'update_time', 'delete_time'], true)) {
                continue;
            }
            if (!$column['primary'] && !$column['insert'] && !$column['update']) {
                continue;
            }
            $parts = [];
            if ($column['required'] || $column['primary']) {
                $parts[] = 'require';
            }
            if ($column['rule'] !== '') {
                $parts[] = $column['rule'];
            }
            if ($parts !== []) {
                $rules[$column['name']] = implode('|', array_unique($parts));
            }
            if ($column['required'] && !$column['primary'] && $column['insert']) {
                $messages[$column['name'] . '.require'] = $column['comment'] . '不能为空';
            }
            if (!$column['primary'] && $column['insert']) {
                $addFields[] = $column['name'];
            }
            if (!$column['primary'] && $column['update']) {
                $updateFields[] = $column['name'];
            }
        }
        $editFields = array_merge([$c['primary']], $updateFields);
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace app\adminapi\services\generator;

use think\Validate;

class {{entity}}Validate extends Validate
{
    protected $rule = {{rules}};
    protected $message = {{messages}};
    protected $scene = [
        'add' => {{addFields}},
        'edit' => {{editFields}},
        'detail' => ['{{primary}}'],
        'delete' => ['{{primary}}'],
    ];
}
PHP, $c + [
            'rules' => var_export($rules, true),
            'messages' => var_export($messages, true),
            'addFields' => var_export($addFields, true),
            'editFields' => var_export($editFields, true),
        ]);
    }

    private static function renderApi(array $c): string
    {
        $fields = '';
        foreach ($c['columns'] as $column) {
            $fields .= "  {$column['name']}" . ($column['required'] ? '' : '?') . ": {$column['tsType']};\n";
        }
        $treeField = $c['tree'] === [] ? '' : "  children?: {$c['entity']}Record[];\n";
        $endpoint = "/adminapi/{$c['module']}/{$c['resource']}";
        return self::replace(<<<'TS'
import axios from 'axios';

export interface {{entity}}Record {
{{fields}}{{treeField}}}

export interface {{entity}}ListResult {
  lists: {{entity}}Record[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export function get{{entity}}List(params?: Record<string, unknown>) {
  return axios.get<{{listType}}>('{{endpoint}}/lists', { params });
}

export function get{{entity}}Detail({{primary}}: number | string) {
  return axios.get<{{entity}}Record>('{{endpoint}}/detail', { params: { {{primary}} } });
}

export function add{{entity}}(data: Partial<{{entity}}Record>) {
  return axios.post('{{endpoint}}/add', data);
}

export function edit{{entity}}(data: Partial<{{entity}}Record>) {
  return axios.post('{{endpoint}}/edit', data);
}

export function delete{{entity}}({{primary}}: number | string) {
  return axios.post('{{endpoint}}/delete', { {{primary}} });
}
TS, $c + [
            'fields' => $fields,
            'treeField' => $treeField,
            'endpoint' => $endpoint,
            'listType' => $c['tree'] === [] ? $c['entity'] . 'ListResult' : $c['entity'] . 'Record[]',
        ]);
    }

    private static function renderView(array $c): string
    {
        $visible = array_values(array_filter($c['columns'], static fn(array $column): bool => $column['list']));
        $visible = array_slice($visible, 0, 8);
        $tableColumns = '';
        foreach ($visible as $column) {
            $tableColumns .= "    { title: '{$column['comment']}', dataIndex: '{$column['name']}' },\n";
        }
        $treeProps = $c['tree'] === [] ? '' : "\n      :default-expand-all-rows=\"true\"";
        $dataRead = $c['tree'] === [] ? 'response.data.lists' : 'response.data';
        $paginationRead = $c['tree'] === []
            ? "\n    pagination.total = response.data.count;"
            : '';
        return self::replace(<<<'VUE'
<template>
  <div class="container">
    <Breadcrumb :items="['menu.{{module}}', '{{title}}']" />
    <a-card class="general-card" title="{{title}}">
      <a-space style="margin-bottom: 16px">
        <a-button type="primary" @click="fetchData">刷新</a-button>
      </a-space>
      <a-table
        row-key="{{primary}}"
        :loading="loading"
        :columns="columns"
        :data="records"
        :pagination="{{pagination}}"{{treeProps}}
        :bordered="{ cell: true }"
        @page-change="fetchData"
      />
    </a-card>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref } from 'vue';
  import type { TableColumnData } from '@arco-design/web-vue';
  import { get{{entity}}List, type {{entity}}Record } from '@/api/{{module}}/{{resource}}';

  const loading = ref(false);
  const records = ref<{{entity}}Record[]>([]);
  const pagination = reactive({ current: 1, pageSize: 15, total: 0 });
  const columns: TableColumnData[] = [
{{tableColumns}}  ];

  const fetchData = async (page = 1) => {
    loading.value = true;
    try {
      const response = await get{{entity}}List({ page_no: page, page_size: pagination.pageSize });
      records.value = {{dataRead}};{{paginationRead}}
      pagination.current = page;
    } finally {
      loading.value = false;
    }
  };

  fetchData();
</script>

<style scoped lang="less"></style>
VUE, $c + [
            'tableColumns' => $tableColumns,
            'treeProps' => $treeProps,
            'pagination' => $c['tree'] === [] ? 'pagination' : 'false',
            'dataRead' => $dataRead,
            'paginationRead' => $paginationRead,
        ]);
    }

    private static function replace(string $template, array $values): string
    {
        $replace = [];
        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $replace['{{' . $key . '}}'] = (string)$value;
            }
        }
        return strtr($template, $replace) . "\n";
    }

    private static function pascal(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $value)));
    }

    private static function safeIdentifier(string $value): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw new RuntimeException('关联字段名称不符合生成规范');
        }
        return $value;
    }

    private static function plainText(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0", "'", '"', '\\', '<', '>', '{', '}'], '', $value);
        return trim($value);
    }

    private static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'YES', 'true'], true);
    }

    private static function columnLength(array $column): int
    {
        if (isset($column['max_length'])) {
            return max(0, (int)$column['max_length']);
        }
        $columnType = (string)($column['column_type'] ?? '');
        return preg_match('/\((\d+)\)/', $columnType, $matches) === 1 ? (int)$matches[1] : 0;
    }

    private static function tsType(string $type): string
    {
        return in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'float', 'double'], true)
            ? 'number' : 'string';
    }

    private static function validationRule(string $type, int $length): string
    {
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
            return 'integer';
        }
        if (in_array($type, ['decimal', 'float', 'double'], true)) {
            return 'float';
        }
        return $length > 0 ? 'max:' . $length : '';
    }
}
