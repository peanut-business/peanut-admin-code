<?php

declare(strict_types=1);

use app\common\dto\payment\TransportResponse;
use app\common\infrastructure\payment\PaymentCrypto;
use app\common\value\Money;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

expectPaymentHost(Money::toCents('10.10') === 1010, 'decimal amount conversion changed');
expectPaymentHost(Money::toCents(0.1) === 10, 'float amount conversion changed');
expectPaymentHost(Money::fromCents(1010) === '10.10', 'money formatting changed');

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
    $serverRoot . '/app/common/composition/payment/PaymentServiceFactory.php',
);
foreach (['WechatPayGateway', 'AlipayGateway', 'WechatCallbackParser', 'AlipayCallbackParser',
    'WechatRefundGateway', 'AlipayRefundGateway'] as $gateway) {
    expectPaymentHost(str_contains($factory, 'new ' . $gateway), 'Payment Host misses ' . $gateway);
}
expectPaymentHost(str_contains($factory, 'ExternalProvider::WECHAT_PAYMENT'), 'Payment Host is not tenant-bound');
expectPaymentHost(
    !is_file($serverRoot . '/app/common/service/RefundGatewayService.php'),
    'duplicate legacy refund gateway remains executable',
);

$wechatPrepay = (string) file_get_contents(
    $serverRoot . '/app/common/infrastructure/payment/WechatPayGateway.php',
);
$wechatRefund = (string) file_get_contents(
    $serverRoot . '/app/common/infrastructure/payment/WechatRefundGateway.php',
);
foreach ([$wechatPrepay, $wechatRefund] as $source) {
    expectPaymentHost(
        str_contains($source, 'PaymentCrypto::verifyWechatResponse'),
        'WeChat merchant response bypasses platform-certificate verification',
    );
}

expectPaymentHost(
    preg_match('/PeanutAdmin\\\\[^;]*(?:Payment|Refund|Gateway|FinanceService)/', $factory) !== 1,
    'application payment owner deep imports a Core payment implementation',
);

echo "PB07-PAYMENT-HOST-001 passed (host crypto, gateways and money only)\n";
