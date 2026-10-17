<?php

declare(strict_types=1);

namespace app\common\exception\installation;

final class InstallationExecutionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        ?\Throwable $previous = null,
        public readonly array $diagnostic = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function details(): array
    {
        return $this->diagnostic + [
            'stage' => str_starts_with($this->errorCode, 'INSTALL_CONFIGURATION') ? 'configuration' : 'execution_admission',
            'reason' => $this->errorCode,
            'remedy' => '按错误原因修复输入或前置条件后重新检查状态；不得清除现有数据或安装身份。',
            'writes' => ['outcome' => 'not_confirmed_by_this_error'],
            'safe_retry' => false,
        ];
    }
}
