<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

function expectLegacyDecorationConvergence(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$repositoryRoot = dirname($serverRoot);

$decorationEnum = (string) file_get_contents(
    $serverRoot . '/app/common/enum/decoration/DecorationEnum.php',
);
$decorationSchema = (string) file_get_contents(
    $serverRoot . '/app/common/service/decoration/DecorationSchemaService.php',
);
expectLegacyDecorationConvergence(
    str_contains($decorationEnum, 'public const MOBILE_CUSTOMER_SERVICE = 3')
        && str_contains($decorationEnum, 'self::MOBILE_CUSTOMER_SERVICE'),
    'customer-service type=3 is not part of the authoritative mobile Runtime',
);
expectLegacyDecorationConvergence(
    str_contains($decorationSchema, "DecorationEnum::MOBILE_CUSTOMER_SERVICE => ['customer-service']"),
    'customer-service type=3 does not own the customer-service component',
);
foreach (['title', 'time', 'mobile', 'qrcode', 'remark'] as $field) {
    expectLegacyDecorationConvergence(
        str_contains($decorationSchema, "'{$field}'"),
        'customer-service schema is missing contracted field: ' . $field,
    );
}
expectLegacyDecorationConvergence(
    str_contains($decorationSchema, 'resourcesForStorage')
        && str_contains($decorationSchema, "'qrcode'"),
    'customer-service QR is not normalized by the Decoration resource boundary',
);

$routeSource = peanut_route_registry_source($serverRoot);
foreach (['setting/customer-service/config', 'setting/customer-service/save', 'setting/decorate/config', 'setting/decorate/save'] as $legacyRoute) {
    expectLegacyDecorationConvergence(
        !str_contains($routeSource, $legacyRoute),
        'retired setting API remains routable: ' . $legacyRoute,
    );
}
foreach (['decoration/mobile/page/lists', 'decoration/mobile/page/detail', 'decoration/mobile/page/save'] as $route) {
    expectLegacyDecorationConvergence(str_contains($routeSource, $route), 'authoritative decoration route is missing: ' . $route);
}

foreach ([
    'app/adminapi/controller/setting/CustomerServiceController.php',
    'app/adminapi/application/setting/CustomerServiceLogic.php',
    'app/adminapi/controller/setting/DecorateController.php',
    'app/adminapi/application/setting/DecorateLogic.php',
] as $retiredPath) {
    expectLegacyDecorationConvergence(!is_file($serverRoot . '/' . $retiredPath), 'retired setting Runtime remains: ' . $retiredPath);
}

$webRoutes = (string) file_get_contents($repositoryRoot . '/web/src/router/routes/modules/app-setting.ts');
foreach (['customer-service', 'decorate'] as $legacyPath) {
    expectLegacyDecorationConvergence(
        preg_match(
            "/path: '{$legacyPath}'[\\s\\S]*?redirect: '\/decoration\/mobile'[\\s\\S]*?hideInMenu: true/",
            $webRoutes,
        ) === 1,
        'legacy Web URL does not explicitly redirect and stay hidden: ' . $legacyPath,
    );
}
expectLegacyDecorationConvergence(
    !str_contains($webRoutes, "@/views/app-setting/customer-service")
        && !str_contains($webRoutes, "@/views/app-setting/decorate"),
    'legacy setting page remains mounted',
);

$menuMigration = (string) file_get_contents(
    $serverRoot . '/database/init.sql',
);
foreach ([
    '/app-setting/decorate',
    '/app-setting/customer-service',
    'setting/decorate/config',
    'setting/decorate/save',
    'setting/customer-service/config',
    'setting/customer-service/save',
] as $retiredMenuKey) {
    expectLegacyDecorationConvergence(str_contains($menuMigration, $retiredMenuKey), 'menu retirement is missing: ' . $retiredMenuKey);
}
expectLegacyDecorationConvergence(
    str_contains($menuMigration, 'DELETE FROM `pa_system_role_menu`')
        && str_contains($menuMigration, 'DELETE FROM `pa_system_menu`'),
    'menu retirement does not remove role grants before menu nodes',
);
$decorationMenu = (string) file_get_contents(
    $serverRoot . '/database/init.sql',
);
expectLegacyDecorationConvergence(
    substr_count($decorationMenu, "'移动端装修' AS `name`") === 1
        && str_contains($decorationMenu, "'/decoration/mobile' AS `paths`"),
    'authoritative mobile decoration menu is missing or duplicated',
);

$editor = (string) file_get_contents($repositoryRoot . '/web/src/views/decoration/mobile/index.vue');
foreach (['title', 'time', 'mobile', 'qrcode', 'remark'] as $field) {
    expectLegacyDecorationConvergence(
        str_contains($editor, "content(component).{$field}"),
        'authoritative editor does not edit customer-service field: ' . $field,
    );
}
expectLegacyDecorationConvergence(
    str_contains($editor, 'saveMobileDecoration') && str_contains($editor, 'type: activeType.value'),
    'authoritative editor does not save through the typed Decoration Runtime',
);

$h5CustomerPage = (string) file_get_contents(
    $repositoryRoot . '/uniapp/src/pages/customer_service/customer_service.vue',
);
expectLegacyDecorationConvergence(
    str_contains($h5CustomerPage, 'getMobileDecoration(3)')
        && str_contains($h5CustomerPage, "getDecorationComponent(page, 'customer-service')")
        && str_contains($h5CustomerPage, 'onShow(loadService)'),
    'H5 customer page does not refresh from Decoration type=3',
);
foreach (['title', 'time', 'mobile', 'qrcode', 'remark'] as $field) {
    expectLegacyDecorationConvergence(
        str_contains($h5CustomerPage, "service.{$field}")
            && str_contains($h5CustomerPage, "content.{$field}"),
        'H5 refresh does not project customer-service field: ' . $field,
    );
}

echo "LEGACY-DECORATION-RUNTIME-CONVERGENCE-001 passed\n";
