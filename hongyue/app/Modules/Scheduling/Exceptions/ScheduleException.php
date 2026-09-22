<?php

namespace App\Modules\Scheduling\Exceptions;

use RuntimeException;

/**
 * 排产业务异常（三匹配拒绝/冲突/锁定冲突等）
 *
 * 携带可读错误清单，控制器转为 409/422 统一响应。
 */
class ScheduleException extends RuntimeException
{
    /** @var list<array{code: string, message: string, task_id?: int|null, task_no?: string|null, data?: mixed}> */
    private array $errors;

    /**
     * @param  string  $message  概要信息
     * @param  list<array>  $errors  结构化错误清单
     */
    public function __construct(string $message, array $errors = [], int $code = 409)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    /**
     * 结构化错误清单
     */
    public function errors(): array
    {
        return $this->errors;
    }
}