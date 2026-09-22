<?php

namespace App\Modules\Quote\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quote\Services\AiQuoteOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI 快速估价（五期 E2/E3/E4）
 *
 * POST /api/quotes/ai-estimate
 * 请求：{product_id?|spec_text?, qty, margin?}
 * 响应：成本构成明细 + 建议价 + 建议交期 + LLM 辅助状态
 */
class AiEstimateController extends Controller
{
    public function __construct(private readonly AiQuoteOrchestrator $orchestrator)
    {
    }

    public function estimate(Request $request): JsonResponse
    {
        $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer'],
            'spec_text' => ['nullable', 'string', 'max:512'],
            'margin' => ['nullable', 'numeric', 'min:0', 'max:90'],
        ]);

        $result = $this->orchestrator->estimate($request->only(['product_id', 'spec_text', 'qty', 'margin']));

        if (! $result['ok']) {
            return $this->fail($result['message'], 422, 422);
        }

        return $this->ok($result['data'], $result['message']);
    }
}
