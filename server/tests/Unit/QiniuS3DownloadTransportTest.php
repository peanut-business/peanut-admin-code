<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\http\OutboundHttpTransport;
use app\common\exception\BusinessException;
use app\common\value\http\OutboundHttpRequest;
use app\common\value\http\OutboundHttpResponse;
use PeanutAdmin\FileMedia\Storage\StorageHttpTransport;
use PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuS3DownloadTransport;
use PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuStorageHttpTransport;
use PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuVisibilityOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qiniu\Auth;

/** Offline protocol boundaries only; no cloud, database, default credential provider or filesystem sink is opened. */
final class QiniuS3DownloadTransportTest extends TestCase
{
    private const ORIGIN = 'https://example-bucket.s3.cn-north-1.qiniucs.com';

    private function capture(): StorageHttpTransport
    {
        return new class implements StorageHttpTransport {
            public array $requests = [];
            public function request(array $request): array
            {
                $this->requests[] = $request;
                return ['status' => 200, 'body' => '', 'headers' => ['x-reqid' => 'offline-only']];
            }
        };
    }

    private function signer(StorageHttpTransport $transport, string $domain = self::ORIGIN, string $bucket = 'example-bucket', string $region = 'cn-north-1'): QiniuS3DownloadTransport
    {
        return new QiniuS3DownloadTransport($transport, $domain, $bucket, $region,
            ['access_key' => 'AKIDEXAMPLE', 'secret_key' => 'synthetic-only-test-secret']);
    }

    public function testRealAwsS3SignerReplacesNativeTokenWithoutChangingDownloadSemantics(): void
    {
        $transport = $this->capture();
        $request = ['method' => 'GET', 'url' => self::ORIGIN . '/tenants/v1/1/material/file/file_a.txt?e=2000000000&token=native-only',
            'sink' => '/not-opened-by-offline-transport', 'connectTimeout' => 5, 'timeout' => 15, 'retrySafe' => true];
        $this->signer($transport)->request($request);
        self::assertCount(1, $transport->requests);
        $sent = $transport->requests[0];
        self::assertSame(self::ORIGIN . '/tenants/v1/1/material/file/file_a.txt', $sent['url']);
        self::assertStringNotContainsString('native-only', json_encode($sent, JSON_THROW_ON_ERROR));
        $headers = array_change_key_case($sent['headers'], CASE_LOWER);
        self::assertMatchesRegularExpression('~^AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/[0-9]{8}/cn-north-1/s3/aws4_request,~', $headers['authorization']);
        self::assertSame('example-bucket.s3.cn-north-1.qiniucs.com', $headers['host']);
        self::assertMatchesRegularExpression('/^[0-9]{8}T[0-9]{6}Z$/D', $headers['x-amz-date']);
        self::assertSame(hash('sha256', ''), $headers['x-amz-content-sha256']);
        foreach (['sink','connectTimeout','timeout','retrySafe','method'] as $key) {
            self::assertSame($request[$key], $sent[$key]);
        }
    }

    public function testKodoMutationProtocolPassesThroughWithoutAwsCredentials(): void
    {
        $transport = $this->capture();
        $request = ['method' => 'POST', 'url' => 'https://rs.qiniuapi.com/delete/opaque-entry',
            'headers' => ['Authorization' => 'QBox synthetic-only'], 'timeout' => 30];
        $this->signer($transport)->request($request);
        self::assertSame([$request], $transport->requests);
    }

    public static function invalidConfigurations(): array
    {
        return [
            'cross-bucket' => [self::ORIGIN, 'other-bucket', 'cn-north-1'],
            'cross-region' => [self::ORIGIN, 'example-bucket', 'cn-east-1'],
            'userinfo' => ['https://user@example-bucket.s3.cn-north-1.qiniucs.com', 'example-bucket', 'cn-north-1'],
            'userinfo-password' => ['https://user:pass@example-bucket.s3.cn-north-1.qiniucs.com', 'example-bucket', 'cn-north-1'],
            'explicit-port' => [self::ORIGIN . ':443', 'example-bucket', 'cn-north-1'],
            'insecure' => [str_replace('https:', 'http:', self::ORIGIN), 'example-bucket', 'cn-north-1'],
            'prefix' => [self::ORIGIN . '/prefix', 'example-bucket', 'cn-north-1'],
            'query' => [self::ORIGIN . '?query=1', 'example-bucket', 'cn-north-1'],
            'fragment' => [self::ORIGIN . '#fragment', 'example-bucket', 'cn-north-1'],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidEndpointIdentityCannotConstructASigner(string $domain, string $bucket, string $region): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->signer($this->capture(), $domain, $bucket, $region);
    }

    public function testS3EdgeCannotSignAnUpload(): void
    {
        $transport = $this->capture();
        try {
            $this->signer($transport)->request(['method' => 'PUT', 'url' => self::ORIGIN . '/object?e=1&token=native', 'sink' => '/unused']);
            self::fail('The download edge must reject mutation methods.');
        } catch (\InvalidArgumentException $expected) {
            self::assertSame('QINIU_S3_DOWNLOAD_REQUEST_INVALID', $expected->getMessage());
        }
        self::assertSame([], $transport->requests);
    }

    private function origin(array $payloads, ?object &$capture = null): QiniuVisibilityOrigin
    {
        $capture = new class($payloads) implements OutboundHttpTransport {
            public array $requests = [];
            public function __construct(private array $payloads) {}
            public function send(OutboundHttpRequest $request): OutboundHttpResponse
            {
                $this->requests[] = $request;
                if ($this->payloads === []) {
                    throw new \RuntimeException('Unexpected extra network operation');
                }
                return new OutboundHttpResponse(200, json_encode(array_shift($this->payloads), JSON_THROW_ON_ERROR), []);
            }
        };
        return new QiniuVisibilityOrigin(new Auth('AKIDEXAMPLE', 'synthetic-only-test-secret'),
            new QiniuStorageHttpTransport($capture), 'example-bucket', 'cn-north-1', self::ORIGIN);
    }

    public function testOriginQualificationUsesSeparateCompleteOriginAndCdnObservations(): void
    {
        $origin = $this->origin([['private' => 0, 'versioning' => false, 'region' => 'z1'], [], ['domains' => [], 'marker' => '']], $capture);
        $facts = $origin->observe();
        self::assertFalse($facts['versioning']);
        self::assertSame([], $facts['known_cdn']);
        self::assertTrue($facts['domain_listing_complete']);
        self::assertCount(3, $capture->requests);
        foreach ($capture->requests as $request) {
            self::assertStringStartsWith('https://', $request->url);
            self::assertFalse($request->allowRedirects);
            self::assertFalse($request->retrySafe);
            self::assertSame(15, $request->timeoutSeconds);
        }
        self::assertSame('uc.qiniuapi.com', parse_url($capture->requests[1]->url, PHP_URL_HOST));
        parse_str((string) parse_url($capture->requests[2]->url, PHP_URL_QUERY), $query);
        self::assertSame('qiniuBucket', $query['sourceTypes']);
        self::assertSame('example-bucket', $query['sourceQiniuBucket']);
    }

    public static function unqualifiedOrigins(): array
    {
        $bucket = ['private' => 0, 'versioning' => false, 'region' => 'z1'];
        return [
            'versioned' => [[['private' => 0, 'versioning' => true, 'region' => 'z1']]],
            'version-unknown' => [[['private' => 0, 'region' => 'z1']]],
            'private-source' => [[['private' => 1, 'versioning' => false, 'region' => 'z1']]],
            'other-region' => [[['private' => 0, 'versioning' => false, 'region' => 'z2']]],
            'bound-origin' => [[$bucket, [['domain' => 'custom.example']]]],
            'bound-cdn' => [[$bucket, [], ['domains' => [['name' => 'cdn.example']], 'marker' => '']]],
            'partial-cdn' => [[$bucket, [], ['domains' => [], 'marker' => 'next-page']]],
        ];
    }

    #[DataProvider('unqualifiedOrigins')]
    public function testUnknownVersionOrExposureCannotQualify(array $payloads): void
    {
        $this->expectException(BusinessException::class);
        $this->origin($payloads)->observe();
    }
}
