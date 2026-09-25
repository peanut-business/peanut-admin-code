<?php

declare(strict_types=1);

use app\common\service\payment\dto\TransportResponse;
use app\common\service\payment\support\PaymentCrypto;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectPaymentHost(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectPaymentHostThrows(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

/** @return array{private:string,public:string,certificate:string,serial:string} */
function paymentHostCertificate(): array
{
    $privateKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);
    if ($privateKey === false) {
        throw new RuntimeException('cannot create payment test key');
    }
    $csr = openssl_csr_new(['commonName' => 'pb07.invalid'], $privateKey, ['digest_alg' => 'sha256']);
    $certificate = $csr === false
        ? false
        : openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256'], 707);
    if ($certificate === false) {
        throw new RuntimeException('cannot create payment test certificate');
    }
    openssl_pkey_export($privateKey, $privatePem);
    openssl_x509_export($certificate, $certificatePem);
    $details = openssl_pkey_get_details($privateKey);
    $certificateInfo = openssl_x509_parse($certificatePem);
    if (!is_array($details) || !is_array($certificateInfo)) {
        throw new RuntimeException('cannot export payment test certificate');
    }
    return [
        'private' => $privatePem,
        'public' => (string) $details['key'],
        'certificate' => $certificatePem,
        'serial' => strtoupper((string) ($certificateInfo['serialNumberHex'] ?? '')),
    ];
}

$serverRoot = dirname(__DIR__, 2);
$repositoryRoot = dirname($serverRoot);
$certificate = paymentHostCertificate();

$wechatBody = '{"prepay_id":"pb07-prepay"}';
$wechatTimestamp = (string) time();
$wechatNonce = 'pb07-response-nonce';
openssl_sign(
    $wechatTimestamp . "\n" . $wechatNonce . "\n" . $wechatBody . "\n",
    $wechatSignature,
    $certificate['private'],
    OPENSSL_ALGO_SHA256,
);
$wechatResponse = new TransportResponse(200, $wechatBody, [
    'Wechatpay-Timestamp' => $wechatTimestamp,
    'Wechatpay-Nonce' => $wechatNonce,
    'Wechatpay-Serial' => $certificate['serial'],
    'Wechatpay-Signature' => base64_encode($wechatSignature),
]);
PaymentCrypto::verifyWechatResponse($wechatResponse, $certificate['certificate']);
expectPaymentHostThrows(
    static fn() => PaymentCrypto::verifyWechatResponse(
        new TransportResponse(200, $wechatBody . 'tampered', $wechatResponse->headers()),
        $certificate['certificate'],
    ),
    'tampered WeChat response is trusted',
);

$alipayNode = '{"code":"10000","msg":"Success","trade_no":"pb07-trade"}';
openssl_sign($alipayNode, $alipaySignature, $certificate['private'], OPENSSL_ALGO_SHA256);
$alipayBody = '{"alipay_trade_refund_response":' . $alipayNode
    . ',"sign":"' . base64_encode($alipaySignature) . '"}';
$alipayDecoded = json_decode($alipayBody, true, 512, JSON_THROW_ON_ERROR);
PaymentCrypto::verifyAlipayResponse(
    $alipayBody,
    $alipayDecoded,
    $certificate['public'],
    'alipay_trade_refund_response',
);
expectPaymentHostThrows(
    static fn() => PaymentCrypto::verifyAlipayResponse(
        str_replace('pb07-trade', 'tampered', $alipayBody),
        $alipayDecoded,
        $certificate['public'],
        'alipay_trade_refund_response',
    ),
    'tampered Alipay response is trusted',
);

$factory = (string) file_get_contents(
    $serverRoot . '/app/common/service/payment/PaymentServiceFactory.php',
);
foreach (['WechatPayGateway', 'AlipayGateway', 'WechatCallbackParser', 'AlipayCallbackParser',
    'WechatRefundGateway', 'AlipayRefundGateway'] as $gateway) {
    expectPaymentHost(str_contains($factory, 'new ' . $gateway), 'Payment Host misses ' . $gateway);
}
expectPaymentHost(str_contains($factory, 'ExternalTenantResolver::WECHAT_PAYMENT'), 'Payment Host is not tenant-bound');
expectPaymentHost(
    !is_file($serverRoot . '/app/common/service/RefundGatewayService.php'),
    'duplicate legacy refund gateway remains executable',
);

$wechatPrepay = (string) file_get_contents(
    $serverRoot . '/app/common/service/payment/gateway/WechatPayGateway.php',
);
$wechatRefund = (string) file_get_contents(
    $serverRoot . '/app/common/service/payment/gateway/WechatRefundGateway.php',
);
foreach ([$wechatPrepay, $wechatRefund] as $source) {
    expectPaymentHost(
        str_contains($source, 'PaymentCrypto::verifyWechatResponse'),
        'WeChat merchant response bypasses platform-certificate verification',
    );
}

$settlement = (string) file_get_contents($serverRoot . '/app/modules/official/payment/src/Service/RechargeApplicationService.php');
foreach (["where('sn', \$orderSn)->lock(true)", "\$currency !== 'CNY'",
    '$callbackCents !== $orderCents', '支付渠道不一致', '支付交易流水冲突'] as $marker) {
    expectPaymentHost(str_contains($settlement, $marker), 'settlement invariant missing: ' . $marker);
}
expectPaymentHost(
    str_contains($settlement, 'MemberBalanceCommands')
        && str_contains($settlement, 'memberBalances->applyInTransaction')
        && str_contains($settlement, 'MemberBalanceMutation'),
    'settlement does not use the public Member balance contract',
);
$adminRefund = (string) file_get_contents(
    $serverRoot . '/app/modules/official/payment/src/Service/RechargeAdministrationService.php',
);
$reconcile = (string) file_get_contents($serverRoot . '/app/modules/official/payment/src/Infrastructure/ThinkPhpRefundReconciliationCommands.php');
foreach ([$adminRefund, $reconcile] as $source) {
    expectPaymentHost(
        str_contains($source, 'PaymentServiceFactory'),
        'refund path bypasses the unique Payment Host',
    );
    expectPaymentHost(!str_contains($source, "['raw']"), 'raw provider response is persisted');
}
expectPaymentHost(
    str_contains($adminRefund, '(string)$record->sn')
        && str_contains($reconcile, '(string)$record->sn')
        && !str_contains($adminRefund, '(string)$log->sn')
        && !str_contains($reconcile, '(string)$log->sn'),
    'refund paths do not share the stable RefundRecord Provider key',
);
$resultTransaction = strpos($adminRefund, '// 渠道请求完成后使用新的短事务');
$receiptFinalize = strpos($adminRefund, 'self::finishRefundIdempotency', $resultTransaction ?: 0);
$resultCommit = strpos($adminRefund, "\n        });", $receiptFinalize ?: 0);
expectPaymentHost(
    $resultTransaction !== false && $receiptFinalize !== false && $resultCommit !== false
        && $resultTransaction < $receiptFinalize && $receiptFinalize < $resultCommit,
    'refund receipt finalization is outside the locked result transaction',
);

$payConfig = (string) file_get_contents(
    $serverRoot . '/app/modules/official/payment/src/Service/PayConfigApplicationService.php',
);
foreach (['wx_pay_secret', 'ali_pay_private_key', "'******'", 'transactions->run'] as $marker) {
    expectPaymentHost(str_contains($payConfig, $marker), 'payment config boundary missing: ' . $marker);
}
$legacyWebApi = (string) file_get_contents($repositoryRoot . '/web/src/api/app.ts');
expectPaymentHost(!str_contains($legacyWebApi, 'interface PayConfig'), 'duplicate Web payment facade remains');
expectPaymentHost(
    str_contains((string) file_get_contents($repositoryRoot . '/web/src/modules/official-payment/api.ts'), 'interface PayConfig'),
    'canonical Web payment facade is missing',
);

$paymentMigration = (string) file_get_contents(
    $serverRoot . '/database/init.sql',
);
foreach (['uk_pay_sn', 'uk_transaction_id'] as $index) {
    expectPaymentHost(str_contains($paymentMigration, $index), 'payment uniqueness guard missing: ' . $index);
}

$paymentEvidence = json_decode((string) file_get_contents(
    $repositoryRoot . '/output/playwright/s01/recharge-payment-summary.json',
), true, 512, JSON_THROW_ON_ERROR);
foreach (['first_callback_credited', 'duplicate_callback_idempotent',
    'conflicting_callback_rejected', 'balance_credited_once'] as $check) {
    expectPaymentHost(
        ($paymentEvidence['checks'][$check] ?? false) === true,
        'sealed S01 payment evidence missing: ' . $check,
    );
}
expectPaymentHost(($paymentEvidence['real_merchant_called'] ?? true) === false, 'S01 scope is overstated');

$refundEvidence = json_decode((string) file_get_contents(
    $repositoryRoot . '/output/playwright/f02/f02-15-audit.json',
), true, 512, JSON_THROW_ON_ERROR);
foreach (['one_refund_record_per_order', 'one_101_log_at_most_per_order'] as $check) {
    expectPaymentHost(
        ($refundEvidence['assertions'][$check]['pass'] ?? false) === true,
        'sealed F02 refund evidence missing: ' . $check,
    );
}

foreach ([$factory, $settlement, $adminRefund, $reconcile] as $source) {
    expectPaymentHost(
        preg_match('/PeanutAdmin\\\\[^;]*(?:Payment|Refund|Gateway|FinanceService)/', $source) !== 1,
        'application payment owner deep imports a Core payment implementation',
    );
}

echo "PB07-PAYMENT-HOST-001 passed\n";
