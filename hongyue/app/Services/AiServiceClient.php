<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * AI 计算服务客户端（Laravel → Python FastAPI，D2 异步排产）
 *
 * 契约（架构 §4）：
 * - POST {ai}/schedule/jobs → {job_id}
 * - GET  {ai}/schedule/jobs/{job_id} → {status: pending|running|done|failed, solution?}
 * - GET  {ai}/health
 *
 * 失败时抛 AiServiceException，上层回退手工排产（B6 降级路径）。
 */
class AiServiceClient
{
    /**
     * @throws AiServiceException
     */
    public function submitSchedule(array $payload): string
    {
        $response = $this->post('/schedule/jobs', $payload);

        $jobId = $response['job_id'] ?? null;
        if (! $jobId) {
            throw new AiServiceException('AI 服务未返回 job_id');
        }

        return (string) $jobId;
    }

    /**
     * 轮询求解结果
     *
     * @return array{status: string, job_id: string, solution?: array, meta?: array, error?: string}
     *
     * @throws AiServiceException
     */
    public function pollSchedule(string $jobId): array
    {
        return $this->get('/schedule/jobs/'.$jobId);
    }

    /**
     * 健康检查
     *
     * @return array{status: string, ...}
     */
    public function health(): array
    {
        try {
            return Http::timeout(3)->get($this->baseUrl().'/health')->json() ?? ['status' => 'unknown'];
        } catch (\Throwable) {
            return ['status' => 'down'];
        }
    }

    /**
     * 报价说明（五期：DeepSeek 辅助生成报价说明/规格解析）
     *
     * @throws AiServiceException
     */
    public function quoteExplain(array $payload): array
    {
        return $this->post('/quote/explain', $payload);
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('ai.service_url', env('AI_SERVICE_URL', 'http://127.0.0.1:8001')), '/');

        // .env 默认使用 Docker 服务名；本机 artisan serve 时即使代理/DNS
        // 将 ai-service 解析成占位地址，也必须切换到本机 AI 服务。
        // Docker 网络内保留 ai-service 服务名，不影响容器部署。
        $host = parse_url($url, PHP_URL_HOST);
        $runningInContainer = is_file('/.dockerenv') || is_dir('/run/.containerenv');
        if (app()->environment('local') && $host === 'ai-service' && ! $runningInContainer) {
            return preg_replace('#://ai-service(?::\\d+)?#', '://127.0.0.1:8001', $url) ?: 'http://127.0.0.1:8001';
        }

        return $url;
    }

    private function token(): ?string
    {
        $token = config('ai.service_token', env('AI_SERVICE_TOKEN'));

        return $token ?: null;
    }

    /**
     * @throws AiServiceException
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->when($this->token(), fn ($http, $token) => $http->withToken($token, 'X-AI-Token'))
                ->post($this->baseUrl().$path, $payload);
        } catch (ConnectionException $e) {
            throw new AiServiceException('算法排产服务连接失败', $e);
        }

        if (! $response->successful()) {
            throw new AiServiceException('算法排产服务响应异常：HTTP '.$response->status().' '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * @throws AiServiceException
     */
    private function get(string $path): array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->when($this->token(), fn ($http, $token) => $http->withToken($token, 'X-AI-Token'))
                ->get($this->baseUrl().$path);
        } catch (ConnectionException $e) {
            throw new AiServiceException('算法排产服务连接失败', $e);
        }

        if (! $response->successful()) {
            throw new AiServiceException('算法排产服务响应异常：HTTP '.$response->status().' '.$response->body());
        }

        return $response->json() ?? [];
    }
}
