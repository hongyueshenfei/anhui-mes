<?php

namespace App\Modules\Quote\Services;

use App\Models\MaterialPrice;
use App\Models\ProcessRate;
use App\Models\QuoteRule;

/**
 * 报价参数服务（五期 E1）
 *
 * - material_prices：材料价（生效日期版本化，同码同日期唯一，upsert）
 * - process_rates：工序费率（rate_key + 生效日期版本化）
 * - quote_rules：报价规则（loss_rate/mold_amort_rule/min_margin/anomaly_deviation）
 */
class QuoteParamService
{
    // ==================== 材料价格 ====================

    public function materialPrices(array $params = []): array
    {
        $query = MaterialPrice::query()
            ->when($params['keyword'] ?? '', fn ($q, $kw) => $q->where(fn ($w) => $w->where('material_code', 'like', "%{$kw}%")->orWhere('material_name', 'like', "%{$kw}%")))
            ->orderByDesc('effective_date');

        return $params['all'] ?? false
            ? $query->get()->toArray()
            : $query->paginate(min((int) ($params['per_page'] ?? 15), 100))->toArray();
    }

    public function upsertMaterialPrice(array $data): MaterialPrice
    {
        return MaterialPrice::updateOrCreate(
            ['material_code' => $data['material_code'], 'effective_date' => $data['effective_date']],
            [
                'material_name' => $data['material_name'] ?? '',
                'price_per_unit' => (float) ($data['price_per_unit'] ?? 0),
                'unit' => $data['unit'] ?? null,
                'source' => $data['source'] ?? 'manual',
            ]
        );
    }

    // ==================== 工序费率 ====================

    public function processRates(array $params = []): array
    {
        $query = ProcessRate::query()
            ->when($params['keyword'] ?? '', fn ($q, $kw) => $q->where('rate_key', 'like', "%{$kw}%"))
            ->orderByDesc('effective_date');

        return $params['all'] ?? false
            ? $query->get()->toArray()
            : $query->paginate(min((int) ($params['per_page'] ?? 15), 100))->toArray();
    }

    public function upsertProcessRate(array $data): ProcessRate
    {
        return ProcessRate::updateOrCreate(
            ['rate_key' => $data['rate_key'], 'effective_date' => $data['effective_date']],
            [
                'op_type' => $data['op_type'] ?? null,
                'rate_type' => $data['rate_type'] ?? 'machine',
                'rate' => (float) ($data['rate'] ?? 0),
            ]
        );
    }

    // ==================== 报价规则 ====================

    public function quoteRules(): array
    {
        return QuoteRule::orderBy('rule_key')->get()->toArray();
    }

    public function upsertQuoteRule(array $data): QuoteRule
    {
        return QuoteRule::updateOrCreate(
            ['rule_key' => $data['rule_key']],
            ['rule_value' => (float) ($data['rule_value'] ?? 0), 'remark' => $data['remark'] ?? null]
        );
    }
}
