<?php

declare(strict_types=1);

function expectDecorationAlignment(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 3);
$sources = [
    'mobile-home' => (string) file_get_contents($root . '/uniapp/src/pages/index/index.vue'),
    'mobile-runtime' => (string) file_get_contents($root . '/uniapp/src/utils/decoration.ts'),
    'pc-runtime' => (string) file_get_contents($root . '/pc/pages/index.vue'),
    'mobile-editor' => (string) file_get_contents($root . '/web/src/views/decoration/mobile/index.vue'),
    'tabbar-editor' => (string) file_get_contents($root . '/web/src/views/decoration/tabbar/index.vue'),
    'pc-editor' => (string) file_get_contents($root . '/web/src/views/decoration/pc/index.vue'),
    'schema' => (string) file_get_contents($root . '/server/app/common/services/decoration/DecorationSchemaService.php'),
];

$matrix = [
    ['mobile-home', "hasModuleSlot('home')", '移动首页必须使用已选模块贡献'],
    ['mobile-home', 'resolveBrandName', '无模块贡献时移动首页必须显示品牌'],
    ['pc-runtime', '<ModuleSlot name="home"', 'PC 首页必须使用已选模块贡献'],
    ['mobile-runtime', 'isDecorationComponentEnabled', '组件 enabled 未形成共享消费规则'],
    ['mobile-runtime', 'setNavigationBarTitle', 'page-meta title 未消费'],
    ['mobile-editor', 'getDecorationArticleOptions', '移动编辑器未使用文章选项 API'],
    ['tabbar-editor', 'getDecorationArticleOptions', 'Tabbar 编辑器未使用文章选项 API'],
    ['pc-editor', 'getDecorationArticleOptions', 'PC 编辑器未使用文章选项 API'],
    ['pc-editor', 'query.web_url', 'PC 编辑器未提供小程序浏览器回退 URL'],
    ['schema', 'PC 小程序链接必须提供 http/https 回退网址', 'PC Schema 未约束小程序浏览器回退'],
    ['schema', 'validatePcStyles', 'PC Schema 未启用样式白名单'],
];

foreach ($matrix as [$source, $needle, $message]) {
    expectDecorationAlignment(str_contains($sources[$source], $needle), $message);
}

expectDecorationAlignment(
    substr_count($sources['mobile-editor'], ':article-options="articleOptions"') === 2,
    '移动编辑器的两个条目编辑面未同时接入文章选项',
);
expectDecorationAlignment(
    str_contains($sources['schema'], "['position', 'left', 'top', 'width', 'height']"),
    'PC 样式白名单字段不精确',
);

echo "DEEP-DECORATION-ALIGNMENT-001 passed\n";
