<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

use app\platform\services\provider\PlatformProviderQualificationService;
use app\platform\contract\provider\ProviderQualificationContributor;
use app\platform\value\provider\ProviderQualificationSubject;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
$app = new \think\App(dirname(__DIR__, 2));
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ThinkPhpTestConnection::fromPdo($pdo);
$pdo->exec('CREATE TABLE pa_provider_qualification_evidence (
    id INTEGER PRIMARY KEY, evidence_key TEXT, provider_key TEXT, scope_type TEXT,
    tenant_id INTEGER, scope_reference TEXT, evidence_type TEXT, outcome TEXT,
    config_digest TEXT, status_code TEXT, observed_at TEXT, expires_at TEXT
)');

function expectProviderQualification(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FakeProviderQualificationContributor implements ProviderQualificationContributor
{
    public int $calls = 0;

    /** @param list<ProviderQualificationSubject> $subjects */
    public function __construct(private array $provided) {}

    public function subjects(): array
    {
        $this->calls++;
        return $this->provided;
    }
}

final readonly class FakeProviderQualificationPermission implements PlatformPermissionChecker
{
    public function __construct(private bool $allowed) {}

    public function allows(PlatformContext $context, string $permissionKey): bool
    {
        expectProviderQualification($permissionKey === 'platform.ops.read', 'unexpected permission key');
        return $this->allowed;
    }
}

/** The fake permission adapter never reads context state. */
$context = (new ReflectionClass(PlatformContext::class))->newInstanceWithoutConstructor();
$now = new DateTimeImmutable('2026-08-28T12:00:00Z');
$tenantA = new ProviderQualificationSubject(
    'payment.wechat',
    'payment',
    'tenant',
    11,
    'payment.wechat',
    true,
    true,
    null,
    str_repeat('a', 64),
);
$tenantB = new ProviderQualificationSubject(
    'payment.wechat',
    'payment',
    'tenant',
    22,
    'payment.wechat',
    true,
    true,
    null,
    str_repeat('b', 64),
);
$email = new ProviderQualificationSubject(
    'notification.email',
    'notification',
    'tenant',
    11,
    'notification.email',
    false,
    false,
    null,
    str_repeat('e', 64),
    false,
);
$contributor = new FakeProviderQualificationContributor([$tenantA, $tenantB, $email]);
foreach (['connectivity', 'callback', 'production'] as $offset => $type) {
    $row = [
        'evidence_key' => 'pqe_' . str_repeat((string) ($offset + 1), 32),
        'provider_key' => 'payment.wechat',
        'scope_type' => 'tenant',
        'tenant_id' => 11,
        'scope_reference' => 'payment.wechat',
        'evidence_type' => $type,
        'outcome' => 'passed',
        'config_digest' => str_repeat('a', 64),
        'status_code' => 'PROVIDER_CHECK_PASSED',
        'observed_at' => '2026-08-28 10:0' . $offset . ':00.000000',
        'expires_at' => '2026-09-04 10:00:00.000000',
    ];
    $pdo->prepare('INSERT INTO pa_provider_qualification_evidence (' . implode(',', array_keys($row))
        . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
}
$service = new PlatformProviderQualificationService(
    new FakeProviderQualificationPermission(true),
    [$contributor],
    str_repeat('s', 32),
    static fn(): DateTimeImmutable => $now,
);
$snapshot = $service->snapshot($context);
expectProviderQualification(array_keys($snapshot) === ['schema_version', 'generated_at', 'providers', 'page', 'page_size', 'total'], 'top-level DTO changed');
expectProviderQualification($snapshot['page'] === 1 && $snapshot['page_size'] === 20 && $snapshot['total'] === 3, 'pagination defaults changed');
expectProviderQualification(count($snapshot['providers']) === 3, 'provider subjects were lost');
$byIdentity = [];
foreach ($snapshot['providers'] as $provider) {
    $byIdentity[$provider['provider_key'] . ':' . $provider['scope']['key']] = $provider;
    expectProviderQualification(array_keys($provider) === [
        'provider_key', 'category', 'scope', 'configured', 'connected', 'callback_verified',
        'credential_rotated_at', 'observed_at', 'expires_at', 'qualified', 'status_code',
        'recent_failure', 'evidence_digest',
    ], 'public provider DTO changed');
}
$payments = array_values(array_filter($snapshot['providers'], static fn(array $row): bool => $row['provider_key'] === 'payment.wechat'));
expectProviderQualification($payments[0]['scope']['key'] !== $payments[1]['scope']['key'], 'Tenant scopes collided');
$qualified = array_values(array_filter($payments, static fn(array $row): bool => $row['qualified']));
expectProviderQualification(count($qualified) === 1, 'Tenant A evidence leaked into Tenant B');
expectProviderQualification($qualified[0]['status_code'] === 'PROVIDER_PRODUCTION_QUALIFIED', 'qualified state missing');
$emailProjection = array_values(array_filter($snapshot['providers'], static fn(array $row): bool => $row['provider_key'] === 'notification.email'))[0];
expectProviderQualification($emailProjection['status_code'] === 'NOT_IMPLEMENTED', 'email must remain not implemented');
$encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
foreach (['"tenant_id"', '"config_digest"', str_repeat('a', 64), 'recipient', 'order_id', 'raw_error'] as $forbidden) {
    expectProviderQualification(!str_contains($encoded, $forbidden), 'public DTO leaked forbidden data: ' . $forbidden);
}

$changed = new ProviderQualificationSubject(
    'payment.wechat',
    'payment',
    'tenant',
    11,
    'payment.wechat',
    true,
    true,
    null,
    str_repeat('c', 64),
);
$staleService = new PlatformProviderQualificationService(
    new FakeProviderQualificationPermission(true),
    [new FakeProviderQualificationContributor([$changed])],
    str_repeat('s', 32),
    static fn(): DateTimeImmutable => $now,
);
$stale = $staleService->snapshot($context)['providers'][0];
expectProviderQualification(!$stale['qualified'] && $stale['status_code'] === 'PROVIDER_EVIDENCE_STALE', 'config change did not invalidate evidence');

$expiredNow = new DateTimeImmutable('2026-09-05T12:00:00Z');
$expiredService = new PlatformProviderQualificationService(
    new FakeProviderQualificationPermission(true),
    [new FakeProviderQualificationContributor([$tenantA])],
    str_repeat('s', 32),
    static fn(): DateTimeImmutable => $expiredNow,
);
$expired = $expiredService->snapshot($context)['providers'][0];
expectProviderQualification(!$expired['qualified'] && $expired['status_code'] === 'PROVIDER_EVIDENCE_STALE', 'TTL expiry did not invalidate evidence');

$deniedContributor = new FakeProviderQualificationContributor([$tenantA]);
$deniedService = new PlatformProviderQualificationService(
    new FakeProviderQualificationPermission(false),
    [$deniedContributor],
    str_repeat('s', 32),
);
try {
    $deniedService->snapshot($context);
    throw new RuntimeException('permission denial was not enforced');
} catch (OpsConsoleException) {
    expectProviderQualification($deniedContributor->calls === 0, 'contributors ran before Platform permission check');
}

$routes = peanut_route_registry_source(dirname(__DIR__, 2));
expectProviderQualification(
    str_contains($routes, "Route::get('v1/ops/providers'")
        && !str_contains($routes, "Route::post('v1/ops/providers'"),
    'Provider qualification HTTP boundary must remain read-only',
);
foreach ([
    'PaymentQualificationContributor.php', 'NotificationQualificationContributor.php',
    'OauthQualificationContributor.php', 'StorageQualificationContributor.php',
] as $file) {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/platform/infrastructure/provider/' . $file);
    foreach (['curl_', 'file_get_contents("http', 'file_get_contents(\'http', 'Prepay', 'sendSms('] as $forbiddenCall) {
        expectProviderQualification(!str_contains($source, $forbiddenCall), 'read contributor performs an external or financial action');
    }
}

echo "PC60-PROVIDER-QUALIFICATION-001 passed\n";
