<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Validation;

use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Model\ArticleCate;
use app\common\validate\PageSizeRule;
use app\common\validate\TenantContextValidate;

class ArticleCateValidate extends TenantContextValidate
{
    use PageSizeRule;

    protected $rule = [
        'id'         => 'require|checkArticleCate',
        'name'       => 'require|length:1,90',
        'is_show'    => 'require|in:0,1',
        'sort'       => 'egt:0',
        'page_no'    => 'integer|gt:0',
        'page_size'  => 'integer|gt:0|pageSizeMax',
        'page_start' => 'integer|gt:0',
        'page_end'   => 'integer|gt:0|egt:page_start',
        'page_type'  => 'in:0,1',
        'order_by'   => 'in:desc,asc',
        'start_time' => 'date',
        'end_time'   => 'date|gt:start_time',
        'start'      => 'number',
        'end'        => 'number',
        'export'     => 'in:1,2',
    ];

    protected $message = [
        'id.require'     => '资讯分类id不能为空',
        'name.require'   => '资讯分类不能为空',
        'name.length'    => '资讯分类长度须在1-90位字符',
        'sort.egt'       => '排序值不正确',
        'page_end.egt'   => '导出范围设置不正确，请重新选择',
        'end_time.gt'    => '搜索的时间范围不正确',
    ];

    protected $scene = [
        'lists'  => [
            'page_no', 'page_size', 'page_start', 'page_end', 'page_type',
            'order_by', 'start_time', 'end_time', 'start', 'end', 'export',
        ],
        'add'    => ['name', 'is_show', 'sort'],
        'edit'   => ['id', 'name', 'is_show', 'sort'],
        'delete' => ['id'],
        'detail' => ['id'],
        'status' => ['id', 'is_show'],
    ];

    public function sceneDelete(): self
    {
        return $this->only(['id'])->append('id', 'checkDeleteArticleCate');
    }

    protected function checkArticleCate($value): bool|string
    {
        $this->requireTenantContext();
        return ArticleCate::where([])->where('id', (int) $value)->findOrEmpty()->isEmpty()
            ? '资讯分类不存在' : true;
    }

    protected function checkDeleteArticleCate($value): bool|string
    {
        $this->requireTenantContext();
        return Article::where([])->where('cid', (int) $value)->findOrEmpty()->isEmpty()
            ? true
            : '资讯分类已使用，请先删除绑定该资讯分类的资讯';
    }

}
