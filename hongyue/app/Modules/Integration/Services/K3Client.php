<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 金蝶云星空 K3Cloud WebAPI 客户端（架构 §5.1 通用契约）
 *
 * - 鉴权：POST AuthService.Login（{acct_id, username, password}）→ 会话 Cookie（kdservice-sessionid）缓存 1 小时
 * - 通用调用：DynamicFormService.Save / ExecuteBillQuery
 * - mock 模式（K3_MOCK=true）：跳过登录，Save 直达模拟端点（沙箱/CI 可用）
 */
class K3Client
{
    private const SESSION_CACHE_KEY = 'k3.session.cookie';

    /**
     * 保存单据（Save 通用调用）
     *
     * @return array 金蝶响应（含 Id/billNo 回执）
     *
     * @throws \RuntimeException 调用异常（HTTP/网络/业务错误）
     */
    public function save(array $payload): array
    {
        $cookie = $this->sessionCookie();

        $response = Http::timeout((int) config('k3.timeout', 10))
            ->acceptJson()
            ->when($cookie !== '', fn ($http) => $http->withHeaders(['Cookie' => $cookie]))
            ->post(rtrim((string) config('k3.base_url'), '/').(string) config('k3.save_path'), $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('K3 Save HTTP '.$response->status().'：'.mb_substr($response->body(), 0, 300));
        }

        $body = $response->json() ?? [];

        // 金蝶业务错误：ResponseStatus.IsSuccess=false
        $isSuccess = data_get($body, 'Result.ResponseStatus.IsSuccess', true);

        if (! $isSuccess) {
            $errors = data_get($body, 'Result.ResponseStatus.Errors', []);
            $msg = collect($errors)->pluck('Message')->implode('；') ?: 'K3 业务校验失败';

            throw new \RuntimeException('K3 Save 业务失败：'.mb_substr($msg, 0, 300));
        }

        return $body;
    }

    /**
     * 查询单据（ExecuteBillQuery 通用调用）—— 四期：生产订单拉取 / 物料同步
     *
     * @param  array  $payload  ExecuteBillQuery 请求体（FormId/FieldKeys/FilterString/OrderString/TopRowCount）
     *
     * @return array 金蝶响应（Result 为行数组，含 FField 键）
     *
     * @throws \RuntimeException 调用异常（HTTP/网络/业务错误）
     */
    public function executeBillQuery(array $payload): array
    {
        $cookie = $this->sessionCookie();

        $response = Http::timeout((int) config('k3.timeout', 10))
            ->acceptJson()
            ->when($cookie !== '', fn ($http) => $http->withHeaders(['Cookie' => $cookie]))
            ->post(rtrim((string) config('k3.base_url'), '/').(string) config('k3.query_path'), $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('K3 ExecuteBillQuery HTTP '.$response->status().'：'.mb_substr($response->body(), 0, 300));
        }

        $body = $response->json() ?? [];

        $isSuccess = data_get($body, 'Result.ResponseStatus.IsSuccess', true);
        if (! $isSuccess) {
            $errors = data_get($body, 'Result.ResponseStatus.Errors', []);
            $msg = collect($errors)->pluck('Message')->implode('；') ?: 'K3 查询业务失败';

            throw new \RuntimeException('K3 ExecuteBillQuery 业务失败：'.mb_substr($msg, 0, 300));
        }

        return $body;
    }

    /**
     * 登录获取会话 Cookie（缓存 1 小时；mock 模式直接返回空）
     */
    public function sessionCookie(): string
    {
        if ((bool) config('k3.mock', true)) {
            return '';
        }

        return Cache::remember(self::SESSION_CACHE_KEY, 3600, function (): string {
            $account = [
                'acct_id' => (string) config('k3.acct_id'),
                'username' => (string) config('k3.username'),
                'password' => (string) config('k3.password'),
            ];

            $response = Http::timeout((int) config('k3.timeout', 10))
                ->acceptJson()
                ->post(rtrim((string) config('k3.base_url'), '/').(string) config('k3.login_path'), $account);

            if (! $response->successful()) {
                throw new \RuntimeException('K3 登录失败：HTTP '.$response->status());
            }

            $cookie = collect($response->cookies()->toArray())
                ->map(fn ($c) => $c['Name'].'='.$c['Value'])
                ->implode('; ');

            if ($cookie === '') {
                throw new \RuntimeException('K3 登录未返回会话 Cookie（请检查账套配置）');
            }

            return $cookie;
        });
    }

    /**
     * 健康检查（联调配置探测）
     */
    public function health(): array
    {
        try {
            $this->sessionCookie();

            return ['ok' => true, 'mock' => (bool) config('k3.mock'), 'message' => 'K3 连接配置可用'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mock' => (bool) config('k3.mock'), 'message' => $e->getMessage()];
        }
    }
}