<?php

namespace App\Services;

use RuntimeException;

/**
 * 算法排产服务异常（D2 降级：算法排产失败回退手工排产）
 */
class AiServiceException extends RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
