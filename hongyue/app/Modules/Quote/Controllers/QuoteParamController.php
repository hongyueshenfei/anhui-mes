<?php

namespace App\Modules\Quote\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quote\Services\QuoteParamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 报价参数配置（五期 E1）
 *
 * - GET/POST /api/quotes/params/materials       材料价格（生效日期版本化）
 * - GET/POST /api/quotes/params/process-rates   工序费率
 * - GET/PUT  /api/quotes/params/rules           报价规则（损耗率/摊销/毛利下限/异常偏差）
 */
class QuoteParamController extends Controller
{
    public function __construct(private readonly QuoteParamService $service)
    {
    }

    // ==================== 材料价格 ====================

    public function materials(Request $request): JsonResponse
    {
        return $this->ok($this->service->materialPrices($request->only(['keyword', 'per_page', 'all'])));
    }

    public function upsertMaterial(Request $request): JsonResponse
    {
        $request->validate([
            'material_code' => ['required', 'string', 'max:64'],
            'effective_date' => ['required', 'date'],
            'price_per_unit' => ['required', 'numeric', 'min:0'],
        ]);

        $price = $this->service->upsertMaterialPrice($request->only(['material_code', 'material_name', 'price_per_unit', 'unit', 'effective_date', 'source']));

        return $this->ok($price, '材料价格已保存（生效日期版本化）', 201);
    }

    // ==================== 工序费率 ====================

    public function processRates(Request $request): JsonResponse
    {
        return $this->ok($this->service->processRates($request->only(['keyword', 'per_page', 'all'])));
    }

    public function upsertProcessRate(Request $request): JsonResponse
    {
        $request->validate([
            'rate_key' => ['required', 'string', 'max:32'],
            'effective_date' => ['required', 'date'],
            'rate' => ['required', 'numeric', 'min:0'],
        ]);

        $rate = $this->service->upsertProcessRate($request->only(['rate_key', 'op_type', 'rate_type', 'rate', 'effective_date']));

        return $this->ok($rate, '工序费率已保存（生效日期版本化）', 201);
    }

    // ==================== 报价规则 ====================

    public function rules(): JsonResponse
    {
        return $this->ok($this->service->quoteRules());
    }

    public function upsertRule(Request $request): JsonResponse
    {
        $request->validate([
            'rule_key' => ['required', 'string', 'max:32'],
            'rule_value' => ['required', 'numeric'],
        ]);

        $rule = $this->service->upsertQuoteRule($request->only(['rule_key', 'rule_value', 'remark']));

        return $this->ok($rule, '报价规则已保存');
    }
}
