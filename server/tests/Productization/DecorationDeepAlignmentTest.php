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
    'mobile-profile' => (string) file_get_contents($root . '/uniapp/src/pages/user/user.vue'),
    'mobile-runtime' => (string) file_get_contents($root . '/uniapp/src/utils/decoration.ts'),
    'pc-runtime' => (string) file_get_contents($root . '/pc/pages/index.vue'),
    'mobile-editor' => (string) file_get_contents($root . '/web/src/views/decoration/mobile/index.vue'),
    'tabbar-editor' => (string) file_get_contents($root . '/web/src/views/decoration/tabbar/index.vue'),
    'pc-editor' => (string) file_get_contents($root . '/web/src/views/decoration/pc/index.vue'),
    'schema' => (string) file_get_contents($root . '/server/app/common/service/decoration/DecorationSchemaService.php'),
];

$matrix = [
    ['mobile-home', 'v-for="decorationComponent in renderComponents"', '首页未按保存的组件数组顺序渲染'],
    ['mobile-profile', 'v-for="decorationComponent in renderComponents"', '个人页未按保存的组件数组顺序渲染'],
    ['mobile-home', 'data-decoration-component="search"', '首页搜索组件未渲染'],
    ['mobile-home', 'banner-style-', 'Banner style 未消费'],
    ['mobile-home', 'content.bg_style', 'Banner bg_style 未消费'],
    ['mobile-home', 'content.show_line', '导航 show_line 未消费'],
    ['mobile-home', 'content.per_line', '导航 per_line 未消费'],
    ['mobile-profile', 'service-style-', '我的服务 style 未消费'],
    ['mobile-runtime', 'isDecorationComponentEnabled', '组件 enabled 未形成共享消费规则'],
    ['mobile-runtime', 'setNavigationBarTitle', 'page-meta title 未消费'],
    ['mobile-home', 'meta.title_img', '首页 title_img 未消费'],
    ['mobile-profile', 'meta.title_img', '个人页 title_img 未消费'],
    ['mobile-home', 'meta.value.text_color', '首页 text_color 未消费'],
    ['mobile-profile', 'meta.value.text_color', '个人页 text_color 未消费'],
    ['mobile-profile', 'meta.value.bg_image', '个人页 bg_image 未消费'],
    ['mobile-editor', 'getDecorationArticleOptions', '移动编辑器未使用文章选项 API'],
    ['tabbar-editor', 'getDecorationArticleOptions', 'Tabbar 编辑器未使用文章选项 API'],
    ['pc-editor', 'getDecorationArticleOptions', 'PC 编辑器未使用文章选项 API'],
    ['pc-editor', 'query.web_url', 'PC 编辑器未提供小程序浏览器回退 URL'],
    ['schema', 'PC 小程序链接必须提供 http/https 回退网址', 'PC Schema 未约束小程序浏览器回退'],
    ['schema', 'validatePcStyles', 'PC Schema 未启用样式白名单'],
    ['pc-runtime', 'safeCssLength', 'PC Runtime 未防御性过滤装修样式'],
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
