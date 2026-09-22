<?php

namespace App\Modules\Warehouse\Exceptions;

use RuntimeException;

/**
 * 入库业务异常（超量/任务不可入库等）
 */
class StockInException extends RuntimeException
{
    private string $errorCode;

    private array $payload;

    public function __construct(string $message, string $errorCode = 'stock_in_rejected', array $payload = [], int $status = 422)
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