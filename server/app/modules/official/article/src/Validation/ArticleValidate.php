<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Validation;

use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Model\ArticleCate;
use app\common\validate\PageSizeRule;
use app\common\validate\TenantContextValidate;

class ArticleValidate extends TenantContextValidate
{
    use PageSizeRule;

    protected $rule = [
        'id'         => 'require|checkArticle',
        'title'      => 'require|length:1,255',
        'cid'        => 'require|integer|gt:0|checkCategory',
        'is_show'    => 'require|in:0,1',
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
        'id.require'    => '资讯id不能为空',
        'title.require' => '标题不能为空',
        'title.length'  => '标题长度须在1-255位字符',
        'cid.require'   => '所属栏目必须存在',
        'page_end.egt'  => '导出范围设置不正确，请重新选择',
        'end_time.gt'   => '搜索的时间范围不正确',
    ];

    protected $scene = [
        'lists'  => [
            'page_no', 'page_size', 'page_start', 'page_end', 'page_type',
            'order_by', 'start_time', 'end_time', 'start', 'end', 'export',
        ],
        'add'    => ['title', 'cid', 'is_show'],
        'edit'   => ['id', 'title', 'cid', 'is_show'],
        'delete' => ['id'],
        'detail' => ['id'],
        'status' => ['id', 'is_show'],
    ];

    protected function checkArticle($value): bool|string
    {
        $this->requireTenantContext();
        return Article::where([])->where('id', (int) $value)->findOrEmpty()->isEmpty()
            ? '资讯不存在' : true;
    }

    protected function checkCategory($value): bool|string
    {
        $this->requireTenantContext();
        return ArticleCate::where([])->where('id', (int) $value)->findOrEmpty()->isEmpty()
            ? '所属栏目必须存在' : true;
    }

}
