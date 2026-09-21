<?php
declare(strict_types=1);

use app\api\services\OAuthApplicationService;
use app\api\services\OfficialAccountApplicationService;
use app\api\services\PaymentCallbackApplicationService;
use app\api\services\UserTokenService;
use app\common\dto\payment\CallbackRequest;
use app\common\dto\payment\PaymentEvent;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\modules\official\file\contracts\FileReferences;
use app\modules\official\integration\contracts\ExternalProvider;
use app\modules\official\integration\contracts\ExternalTenantBinding;
use app\modules\official\integration\contracts\ExternalTenantResolution;
use app\modules\official\integration\contracts\ExternalTenantResolutionService;
use app\modules\official\member\contracts\dto\MemberIdentitySnapshot;
use app\modules\official\oauth\contracts\OAuthCallbackLocator;
use app\modules\official\oauth\contracts\OAuthCommands;
use app\modules\official\oauth\contracts\OfficialAccountCallbacks;
use app\modules\official\oauth\contracts\dto\OAuthAuthorizationResult;
use app\modules\official\oauth\contracts\dto\OAuthLoginResult;
use app\modules\official\payment\contracts\PaymentMethod;
use app\modules\official\payment\contracts\RechargeCommands;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PHPUnit\Framework\TestCase;

/**
 * 用真实上下文栈和 ModuleGuard 验证提取后的用例；外部渠道与持久仓储使用受控替身。
 * 这是应用编排回归，不宣称真实微信／支付宝、数据库事务或完整 HTTP 链已验收。
 */
final class CallbackApplicationServicesTest extends TestCase
{
    private ExecutionContextStore $store;
    private ExternalTenantBinding $binding;

    protected function setUp(): void
    {
        $this->store = new ExecutionContextStore();
        $this->binding = new ExternalTenantBinding(31, 17, 'test.provider', 'binding-31', 'hash', 'hint', ['test' => true], true, true);
    }

    private function boundary(string $key, bool $enabled = true): ModuleExecutionBoundary
    {
        $repository = $this->createMock(ModuleRuntimeRepository::class);
        $repository->expects(self::any())->method('installation')->with($key)
            ->willReturn(new ModuleInstallationRecord($key, '1.0.0', 'active', 1, str_repeat('a', 64)));
        $repository->expects(self::any())->method('tenantModule')->with(17, $key)
            ->willReturn(new TenantModuleRecord(17, $key, $enabled ? 'enabled' : 'disabled', null, null, 1));
        return new ModuleExecutionBoundary(new CurrentExecutionContext($this->store), $repository);
    }

    private function resolution(string $operation, mixed $value = null): ExternalTenantResolution
    {
        return new ExternalTenantResolution(
            new TenantSystemContext(17, ExternalTenantResolutionService::SYSTEM_ACTOR, $operation, 'test-request'),
            $this->binding,
            $value,
        );
    }

    private function member(): MemberIdentitySnapshot
    {
        return new MemberIdentitySnapshot(7, 'M7', '测试顾客', 'avatar-ref', 'test-mobile', 1);
    }

    private function tokens(): UserTokenService
    {
        // 本组测试OAuth编排，会员持久会话由独立测试验证；这里仅替换其公开边界。
        $sessions = $this->createStub(\app\modules\official\member\contracts\MemberSessions::class);
        $sessions->method('issue')->willReturnCallback(
            static fn(int $memberId, int $issuedAt, int $expiresAt) => new \app\modules\official\member\contracts\dto\MemberSessionGrant(
                str_repeat('a', 43), 17, $memberId, 1, $issuedAt, $expiresAt,
            ),
        );
        return new UserTokenService(str_repeat('test-only-secret-', 3), 600, $sessions);
    }

    public function testOAuthBeginUsesExplicitBindingAndRestoresContext(): void
    {
        $commands = $this->createMock(OAuthCommands::class);
        $locator = $this->createStub(OAuthCallbackLocator::class);
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $files = $this->createStub(FileReferences::class);
        $resolver->expects(self::once())->method('clientIdentity')
            ->with(ExternalProvider::oauth('oa'), 'client-1', 'oauth.begin', 'trace-1')
            ->willReturn($this->resolution('oauth.begin'));
        $commands->expects(self::once())->method('begin')->willReturnCallback(
            function (TenantSystemContext $context, string $scene, string $returnPath, string $redirect, ExternalTenantBinding $binding) {
                self::assertSame($context, (new CurrentExecutionContext($this->store))->system());
                self::assertSame(17, $context->tenantId);
                self::assertSame('oa', $scene);
                self::assertSame('/orders', $returnPath);
                self::assertSame($this->binding, $binding);
                self::assertStringStartsWith('https://tenant.example', $redirect);
                return new OAuthAuthorizationResult('https://provider.example/authorize', 120);
            },
        );
        $service = new OAuthApplicationService($commands, $locator, $this->store, $this->boundary('official.oauth'), $resolver, $this->tokens(), $files);
        self::assertSame(['authorization_url' => 'https://provider.example/authorize', 'expires_in' => 120],
            $service->begin(['scene' => 'oa', 'client_id' => ' client-1 ', 'return_path' => '/orders'], 'https://tenant.example', 'trace-1'));
        self::assertTrue($this->store->isEmpty());
    }

    public function testOAuthCallbackPreservesLocatedStateAndLoginResult(): void
    {
        $commands = $this->createMock(OAuthCommands::class);
        $locator = $this->createMock(OAuthCallbackLocator::class);
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $files = $this->createMock(FileReferences::class);
        $provider = ExternalProvider::oauth('oa');
        $locator->expects(self::once())->method('locateState')->with($provider, hash('sha256', 'state-value'))->willReturn([$this->binding]);
        $resolver->expects(self::once())->method('verifiedCandidates')
            ->with([$this->binding], $provider, ' state-value ', 'oauth.callback', 'trace-2')
            ->willReturn($this->resolution('oauth.callback'));
        $commands->expects(self::once())->method('callback')->willReturnCallback(function ($context, $scene, $code, $state, $binding, $ip) {
            self::assertSame($context, (new CurrentExecutionContext($this->store))->system());
            self::assertSame(['oa', 'code-value', ' state-value ', $this->binding, '192.0.2.1'], [$scene, $code, $state, $binding, $ip]);
            return (new OAuthLoginResult(true, $this->member()))->withReturnPath('/profile');
        });
        $files->expects(self::once())->method('getFileUrl')->with('avatar-ref')->willReturn('/private/avatar');
        $tokens = $this->tokens();
        $service = new OAuthApplicationService($commands, $locator, $this->store, $this->boundary('official.oauth'), $resolver, $tokens, $files);
        $result = $service->callback(['scene' => 'oa', 'code' => 'code-value', 'state' => ' state-value '], '192.0.2.1', 'trace-2');
        self::assertTrue($result['completed']);
        self::assertSame(7, $tokens->parseToken($result['token']));
        self::assertSame('/private/avatar', $result['member']['avatar']);
        self::assertSame('/profile', $result['return_path']);
        self::assertTrue($this->store->isEmpty());
    }

    public function testMiniProgramIncompleteLoginNeverIssuesToken(): void
    {
        $commands = $this->createMock(OAuthCommands::class);
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $resolver->expects(self::once())->method('onlyActiveBinding')
            ->with(ExternalProvider::WECHAT_MINI_PROGRAM, 'oauth.mini-program', 'trace-3')
            ->willReturn($this->resolution('oauth.mini-program'));
        $commands->expects(self::once())->method('miniProgramLogin')
            ->willReturn(new OAuthLoginResult(false, $this->member(), 'completion-ticket', 60, true, true));
        $files = $this->createMock(FileReferences::class);
        $files->expects(self::once())->method('getFileUrl')->willReturn('/avatar');
        $service = new OAuthApplicationService($commands, $this->createStub(OAuthCallbackLocator::class), $this->store, $this->boundary('official.oauth'), $resolver, $this->tokens(), $files);
        $result = $service->miniProgram(['code' => 'mini-code'], '192.0.2.1', 'trace-3');
        self::assertFalse($result['completed']);
        self::assertArrayNotHasKey('token', $result);
        self::assertSame('completion-ticket', $result['completion_ticket']);
        self::assertSame(60, $result['expires_in']);
        self::assertTrue($this->store->isEmpty());
    }

    public function testCompletionPreservesTicketAndVerificationCodeMapping(): void
    {
        $commands = $this->createMock(OAuthCommands::class);
        $locator = $this->createMock(OAuthCallbackLocator::class);
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $locator->expects(self::once())->method('locateTicket')->with(hash('sha256', 'ticket'))->willReturn([$this->binding]);
        $resolver->expects(self::once())->method('verifiedCandidates')
            ->with([$this->binding], 'oauth.wechat.completion', ' ticket ', 'oauth.complete', 'trace-4', false)
            ->willReturn($this->resolution('oauth.complete'));
        $commands->expects(self::once())->method('complete')->willReturnCallback(function ($context, $params, $ip) {
            self::assertSame('123456', $params['code']);
            self::assertSame(' ticket ', $params['ticket']);
            self::assertSame(17, $context->tenantId);
            return new OAuthLoginResult(true, $this->member());
        });
        $files = $this->createMock(FileReferences::class);
        $files->expects(self::once())->method('getFileUrl')->willReturn('');
        $service = new OAuthApplicationService($commands, $locator, $this->store, $this->boundary('official.oauth'), $resolver, $this->tokens(), $files);
        self::assertTrue($service->complete(['ticket' => ' ticket ', 'verification_code' => '123456'], '192.0.2.1', 'trace-4')['completed']);
        self::assertTrue($this->store->isEmpty());
    }

    public function testOAuthDisabledModulePreventsCommandAndRestoresContext(): void
    {
        $commands = $this->createMock(OAuthCommands::class);
        $commands->expects(self::never())->method('miniProgramLogin');
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $resolver->expects(self::once())->method('onlyActiveBinding')->willReturn($this->resolution('oauth.mini-program'));
        $service = new OAuthApplicationService($commands, $this->createStub(OAuthCallbackLocator::class), $this->store, $this->boundary('official.oauth', false), $resolver, $this->tokens(), $this->createStub(FileReferences::class));
        try {
            $service->miniProgram(['code' => 'mini-code'], '192.0.2.1', 'trace-5');
            self::fail('Disabled module must be refused.');
        } catch (ModuleException $error) {
            self::assertSame('MODULE_TENANT_DISABLED', $error->errorCode);
        }
        self::assertTrue($this->store->isEmpty());
    }

    public function testOAuthBusinessFailureRestoresContextAndPropagatesOriginalError(): void
    {
        $failure = new DomainException('EXPECTED_BUSINESS_FAILURE');
        $commands = $this->createMock(OAuthCommands::class);
        $commands->expects(self::once())->method('miniProgramLogin')->willThrowException($failure);
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $resolver->expects(self::once())->method('onlyActiveBinding')->willReturn($this->resolution('oauth.mini-program'));
        $service = new OAuthApplicationService($commands, $this->createStub(OAuthCallbackLocator::class), $this->store, $this->boundary('official.oauth'), $resolver, $this->tokens(), $this->createStub(FileReferences::class));
        try {
            $service->miniProgram(['code' => 'mini-code'], '192.0.2.1', 'trace-6');
            self::fail('Expected business failure.');
        } catch (DomainException $error) {
            self::assertSame($failure, $error);
        }
        self::assertTrue($this->store->isEmpty());
    }

    public function testOAuthBindingUsesTypedMemberInsteadOfIndependentRawId(): void
    {
        $member = new AuthenticatedMemberContext(17, 7, 'synthetic-fingerprint', 'trace-7');
        $commands = $this->createMock(OAuthCommands::class);
        $commands->expects(self::once())->method('bind')->with($member, 7, 'oa', 'bind-code')->willReturn(true);
        $service = new OAuthApplicationService($commands, $this->createStub(OAuthCallbackLocator::class), $this->store, $this->boundary('official.oauth'), $this->createStub(ExternalTenantResolutionService::class), $this->tokens(), $this->createStub(FileReferences::class));
        $service->bind($member, 'oa', 'bind-code');
    }

    public function testPaymentSuccessUsesVerifiedEventBindingAndRestoresContext(): void
    {
        $request = new CallbackRequest('raw-signed-body', ['signature' => 'synthetic']);
        $event = new PaymentEvent('wechat', 'O1', 'TX1', 100, 'CNY', 'success', 'merchant', 'app');
        $commands = $this->createMock(RechargeCommands::class);
        $commands->expects(self::once())->method('parseCallback')->with('wechat', ['test' => true], $request)->willReturn($event);
        $commands->expects(self::once())->method('settleVerifiedCallback')->with(31, $event, PaymentMethod::WECHAT)
            ->willReturnCallback(function () {
                self::assertSame(17, (new CurrentExecutionContext($this->store))->system()->tenantId);
                return true;
            });
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $resolver->expects(self::once())->method('verifiedCallback')
            ->willReturnCallback(function ($provider, $binding, $operation, $trace, $verify) use ($event) {
                self::assertSame([ExternalProvider::WECHAT_PAYMENT, 'binding-31', 'payment.settle', 'trace-8'], [$provider, $binding, $operation, $trace]);
                self::assertTrue($this->store->isEmpty());
                self::assertSame($event, $verify(['test' => true]));
                return $this->resolution($operation, $event);
            });
        $service = new PaymentCallbackApplicationService($commands, $this->store, $this->boundary('official.payment'), $resolver);
        $service->wechat($request, 'binding-31', 'trace-8');
        self::assertTrue($this->store->isEmpty());
    }

    public function testPaymentNonSuccessNeverSettles(): void
    {
        $event = new PaymentEvent('alipay', 'O1', 'TX1', 100, 'CNY', 'closed', 'merchant', 'app');
        $commands = $this->createMock(RechargeCommands::class);
        $commands->expects(self::never())->method('settleVerifiedCallback');
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $resolver->expects(self::once())->method('verifiedCallback')->willReturn($this->resolution('payment.settle', $event));
        $service = new PaymentCallbackApplicationService($commands, $this->store, $this->boundary('official.payment'), $resolver);
        $service->alipay(new CallbackRequest('', [], ['signed' => 'data']), 'binding-31', 'trace-9');
        self::assertTrue($this->store->isEmpty());
    }

    public function testPaymentRejectedVerificationNeverRunsBusiness(): void
    {
        $commands = $this->createMock(RechargeCommands::class);
        $commands->expects(self::never())->method('settleVerifiedCallback');
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $resolver->expects(self::once())->method('verifiedCallback')->willThrowException(new DomainException('SIGNATURE_REJECTED'));
        $service = new PaymentCallbackApplicationService($commands, $this->store, $this->boundary('official.payment'), $resolver);
        try {
            $service->wechat(new CallbackRequest('bad-body', []), 'binding-31', 'trace-10');
            self::fail('Verification must reject.');
        } catch (DomainException $error) {
            self::assertSame('SIGNATURE_REJECTED', $error->getMessage());
        }
        self::assertTrue($this->store->isEmpty());
    }

    public function testOfficialAccountCallbackReceivesUntouchedBodyAndContext(): void
    {
        $callbacks = $this->createMock(OfficialAccountCallbacks::class);
        $callbacks->expects(self::once())->method('verify')->with(['signature' => 'test'], ['test' => true])->willReturn(true);
        $callbacks->expects(self::once())->method('handlePlain')->willReturnCallback(function ($context, $body) {
            self::assertSame("<xml> signed payload \n</xml>", $body);
            self::assertSame($context, (new CurrentExecutionContext($this->store))->system());
            return '<xml>response</xml>';
        });
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $resolver->expects(self::once())->method('verifiedCallback')->willReturnCallback(function ($provider, $binding, $operation, $trace, $verify) {
            self::assertTrue($verify(['test' => true]));
            return $this->resolution($operation, true);
        });
        $service = new OfficialAccountApplicationService($callbacks, $this->store, $this->boundary('official.oauth'), $resolver);
        self::assertSame('<xml>response</xml>', $service->callback('binding-31', ['signature' => 'test'], "<xml> signed payload \n</xml>", 'trace-11'));
        self::assertTrue($this->store->isEmpty());
    }

    public function testOfficialAccountAesIsStillRejectedBeforePlainHandler(): void
    {
        $callbacks = $this->createMock(OfficialAccountCallbacks::class);
        $callbacks->expects(self::never())->method('verify');
        $callbacks->expects(self::never())->method('handlePlain');
        $resolver = $this->createMock(ExternalTenantResolutionService::class);
        $resolver->expects(self::once())->method('verifiedCallback')->willReturnCallback(function ($provider, $binding, $operation, $trace, $verify) {
            self::assertFalse($verify(['test' => true]));
            throw new DomainException('AES_REJECTED');
        });
        $service = new OfficialAccountApplicationService($callbacks, $this->store, $this->boundary('official.oauth'), $resolver);
        try {
            $service->callback('binding-31', ['encrypt_type' => 'AES'], 'ciphertext', 'trace-12');
            self::fail('AES must not enter plaintext processing.');
        } catch (DomainException $error) {
            self::assertSame('AES_REJECTED', $error->getMessage());
        }
        self::assertTrue($this->store->isEmpty());
    }
}
