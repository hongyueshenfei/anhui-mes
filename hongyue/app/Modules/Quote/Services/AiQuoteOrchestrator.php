<?php

namespace App\Modules\Quote\Services;

use App\Models\Product;
use App\Services\AiServiceException;
use App\Services\AiServiceClient;
use Illuminate\Support\Facades\Log;

/**
 * AI 估价编排（五期 E2/E3）
 *
 * 1) 规则引擎（QuoteRuleEngine）：确定性成本构成 + 建议价 + 建议交期（任何环境可用）
 * 2) DeepSeek 辅助（ai-service）：规格解析 / 报价说明 / 异常价校验；
 *    ai-service 不可达或无 DeepSeek key 时降级：ai_assist=not_enabled，
 *    llm_remark 标记「AI 辅助未启用」，规则引擎结果照常返回（沙箱无 key 可用）。
 */
class AiQuoteOrchestrator
{
    public function __construct(
        private readonly QuoteRuleEngine $ruleEngine,
        private readonly AiServiceClient $aiClient,
    ) {
    }

    /**
     * 估价编排
     *
     * @param  array  $input  ['product_id'|'spec_text', 'qty', 'margin']
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function estimate(array $input): array
    {
        $product = isset($input['product_id']) ? Product::with('boms', 'defaultRoute.ops', 'defaultMold')->find($input['product_id']) : null;
        $qty = (int) ($input['qty'] ?? 0);
        $margin = (float) ($input['margin'] ?? 20);
        $specText = (string) ($input['spec_text'] ?? ($product?->spec ?? $product?->name ?? ''));

        $engine = $this->ruleEngine->estimate($product, $specText, $qty, $margin);
        if (! $engine['ok']) {
            return ['ok' => false, 'message' => $engine['message'], 'data' => null];
        }

        $estimate = $engine['estimate'];

        // ---- LLM 辅助（降级安全） ----
        [$llmRemark, $aiAssist, $parsedSpec] = $this->llmAssist($product, $specText, $qty);

        if ($parsedSpec !== null && $estimate['spec_text'] === '') {
            $estimate['spec_text'] = $parsedSpec;
        }

        $estimate['llm_remark'] = $llmRemark;
        $estimate['ai_assist'] = $aiAssist;

        return ['ok' => true, 'message' => '估价完成（AI 辅助：'.($aiAssist === 'enabled' ? '已启用' : '未启用').'）', 'data' => $estimate];
    }

    /**
     * LLM 辅助：规格解析 + 报价说明；任何失败降级 not_enabled（不阻断估价）
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function llmAssist(?Product $product, string $specText, int $qty): array
    {
        try {
            $explain = $this->aiClient->quoteExplain([
                'spec_text' => $specText,
                'qty' => $qty,
                'product_name' => $product?->name,
            ]);

            $remark = (string) data_get($explain, 'explanation', '');
            $parsed = data_get($explain, 'parsed_spec') ?: null;

            return [$remark !== '' ? $remark : null, 'enabled', $parsed];
        } catch (\Throwable $e) {
            Log::info('AI 报价辅助未启用（降级）', ['error' => $e->getMessage()]);

            return ['AI 辅助未启用（未配置 DeepSeek key 或 ai-service 不可达），以上为规则引擎确定性估算', 'not_enabled', null];
        }
    }
}
