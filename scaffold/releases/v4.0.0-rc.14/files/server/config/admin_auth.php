<?php

declare(strict_types=1);

return [
    // LikeAdmin 1.9.4 管理端认证参数。
    'token_expire_duration' => (int) env('ADMIN_TOKEN_EXPIRE', 8 * 60 * 60),
    'renew_before_expire'   => (int) env('ADMIN_TOKEN_RENEW_BEFORE', 60 * 60),
    'password_error_times'  => (int) env('ADMIN_PASSWORD_ERROR_TIMES', 5),
    'lock_minutes'          => (int) env('ADMIN_LOGIN_LOCK_MINUTES', 30),
];
