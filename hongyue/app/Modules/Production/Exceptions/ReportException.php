<?php

namespace App\Modules\Production\Exceptions;

use RuntimeException;

/**
 * 报产业务异常（超量/未排产/代报归属/冲减校验等）
 */
class ReportException extends RuntimeException
{
    /** @var string 业务错误码 */
    private string $errorCode;

    /** @var array 附加数据 */
    private array $payload;

    public function __construct(string $message, string $errorCode = 'report_rejected', array $payload = [], int $status = 422)
    {
        parent::__construct($message, $status);
        $this->errorCode = $errorCode;
        $this->payload = $payload;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}