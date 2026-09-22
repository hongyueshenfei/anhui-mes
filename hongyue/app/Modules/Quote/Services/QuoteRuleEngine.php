<?php

namespace App\Modules\Quote\Services;

use App\Models\BomLine;
use App\Models\Machine;
use App\Models\MaterialPrice;
use App\Models\Mold;
use App\Models\PlmRoute;
use App\Models\ProcessRate;
use App\Models\Product;
use App\Models\QuoteRule;
use App\Models\RouteOp;
use App\Modules\Dashboard\Services\DashboardService;

/**
 * 报价规则引擎（五期 E2/E4，确定性可解释）
 *
 * 成本构成（单位成本）：
 *   material_cost = Σ(BOM 用量 × 材料价)                    —— 材料价取生效日期最近一条
 *   process_cost  = Σ(工序 1/产能小时 × 工序费率)             —— 费率取 rate_key 生效日期最近一条
 *   mold_amort    = 模具造价 ÷ 订单数量                       —— 摊销随数量摊薄（E1）
 *   loss_cost     = (material + process) × 损耗率%
 *   total_cost    = material + process + mold_amort + loss
 *   suggest_price = total_cost ÷ (1 − 目标毛利%)              —— 同参数两次结果一致（PRD T6）
 *
 * 建议交期（E4）：总工时（数量×单位工时）÷ 可用机台小时/日 + 当前负荷缓冲
 */
class QuoteRuleEngine
{
    /**
     * 按产品估价（近似老品参照 / 规格匹配产品）
     *
     * @return array{ok: bool, message: string, estimate: array}
     */
    public function estimate(?Product $product, string $specText, int $qty, float $margin = 20): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'message' => '数量必须大于 0', 'estimate' => null];
        }

        $lossRate = QuoteRule::valueOf(QuoteRule::RULE_LOSS_RATE, 2.0);
        $anomalyDeviation = QuoteRule::valueOf(QuoteRule::RULE_ANOMALY_DEVIATION, 30.0);

        // ---- 材料成本 ----
        $materialCost = 0.0;
        $materialDetail = [];
        if ($product) {
            $bom = $product->getPublishedBom();
            if ($bom) {
                foreach ($bom->lines as $line) {
                    $price = MaterialPrice::priceAt($line->item_code)?->price_per_unit ?? 0;
                    $unitCost = (float) $line->qty * (float) $price;
                    $materialCost += $unitCost;
                    $materialDetail[] = [
                        'item_code' => $line->item_code,
                        'item_name' => $line->item_name,
                        'qty' => (float) $line->qty,
                        'price' => (float) $price,
                        'cost' => round($unitCost, 4),
                    ];
                }
            }
        }

        // ---- 加工成本 + 总工时（交期） ----
        $processCost = 0.0;
        $totalHours = 0.0;
        $processDetail = [];
        $route = $product?->defaultRoute;
        if ($route && $route->ops->isNotEmpty()) {
            foreach ($route->ops as $op) {
                // 工艺路线不承载产能（2026-08-31 优化）：按工序默认机台/产线产能兜底，
                // 未指定默认资源时按 machine_type 取该类型启用的机台产能（报价阶段的机台侧理论产能）
                $capacity = null;
                $defaultResource = $op->machine ?? $op->line;
                if ($defaultResource) {
                    $capacity = $defaultResource->capacity_per_hour;
                }
                if (! $capacity && $op->machine_type) {
                    $capacity = Machine::where('type', $op->machine_type)->where('is_active', 1)->value('capacity_per_hour');
                }
                $hoursPerUnit = $capacity > 0 ? 1 / (float) $capacity : 0;
                $rate = $this->opRate($op);
                $unitCost = $hoursPerUnit * $rate;
                $processCost += $unitCost;
                $totalHours += $hoursPerUnit * $qty;
                $processDetail[] = [
                    'op_type' => $op->op_type,
                    'op_name' => $op->op_name,
                    'hours_per_unit' => round($hoursPerUnit, 6),
                    'rate' => round($rate, 4),
                    'cost' => round($unitCost, 4),
                ];
            }
        }

        // ---- 模具摊销 ----
        $moldAmort = 0.0;
        $mold = $product?->defaultMold;
        if ($mold && $mold->purchase_cost > 0) {
            $moldAmort = (float) $mold->purchase_cost / $qty;
        }

        // ---- 损耗 ----
        $lossCost = ($materialCost + $processCost) * ($lossRate / 100);

        $totalCost = $materialCost + $processCost + $moldAmort + $lossCost;
        $marginPct = max(0, min(90, $margin));
        $suggestPrice = $marginPct >= 100 ? $totalCost : $totalCost / (1 - $marginPct / 100);

        // ---- 建议交期（E4：工时÷可用产能 + 负荷缓冲） ----
        $deliveryDays = $this->deliveryDays($totalHours, $route);

        // ---- 异常价校验（E3：与历史成交均价对比 ±30%） ----
        $anomaly = $this->anomalyCheck($product?->id, $suggestPrice, $anomalyDeviation);

        return [
            'ok' => true,
            'message' => '估价完成',
            'estimate' => [
                'product_id' => $product?->id,
                'product_code' => $product?->code,
                'product_name' => $product?->name,
                'spec_text' => $specText,
                'qty' => $qty,
                'material_cost' => round($materialCost, 4),
                'process_cost' => round($processCost, 4),
                'mold_amort' => round($moldAmort, 4),
                'loss_cost' => round($lossCost, 4),
                'total_cost' => round($totalCost, 4),
                'loss_rate' => $lossRate,
                'margin' => $marginPct,
                'suggest_price' => round($suggestPrice, 2),
                'delivery_days' => $deliveryDays,
                'anomaly_flag' => $anomaly['flag'],
                'anomaly_message' => $anomaly['message'],
                'material_detail' => $materialDetail,
                'process_detail' => $processDetail,
                'mold' => $mold ? [
                    'mold_code' => $mold->code,
                    'mold_name' => $mold->name,
                    'purchase_cost' => (float) $mold->purchase_cost,
                    'amort_per_unit' => round($moldAmort, 4),
                ] : null,
            ],
        ];
    }

    /**
     * 工序费率：优先 route_op.rate_key 生效费率；无则按资源类型兜底（成型 40 元/时 / 人工线 25 元/时）
     */
    private function opRate(RouteOp $op): float
    {
        if ($op->rate_key) {
            $rate = ProcessRate::rateAt($op->rate_key)?->rate;
            if ($rate !== null) {
                return (float) $rate;
            }
        }

        $isMolding = in_array($op->op_type, [RouteOp::OP_MOLDING_INJECTION, RouteOp::OP_MOLDING_BLOW], true);

        return $isMolding
            ? (float) config('quote.machine_rate_default', 40)
            : (float) config('quote.team_rate_default', 25);
    }

    /**
     * 建议交期（天）：总工时 ÷ 每日可用机台小时 + 负荷缓冲
     */
    private function deliveryDays(float $totalHours, ?PlmRoute $route): int
    {
        if ($totalHours <= 0) {
            return (int) config('quote.default_delivery_days', 7);
        }

        $dailyHours = (float) config('quote.daily_available_hours', 16); // 两班制 2×8
        $days = (int) ceil($totalHours / max(1, $dailyHours));

        // 当前产能负荷缓冲（E4：满负荷阶段交期明显变长）
        try {
            $heatmap = app(DashboardService::class)->loadHeatmap();
            $avgLoad = (float) ($heatmap['summary']['avg_load'] ?? 0);
            $buffer = (int) round($avgLoad / 30); // 负荷 100% 约 +3 天
        } catch (\Throwable) {
            $buffer = 0;
        }

        return max(1, $days + $buffer);
    }

    /**
     * 异常价校验（E3）：与历史成交（won 报价单均价）对比，偏离 ±deviation% 提示
     *
     * @return array{flag: bool, message: string}
     */
    public function anomalyCheck(?int $productId, float $suggestPrice, float $deviation): array
    {
        if (! $productId || $suggestPrice <= 0) {
            return ['flag' => false, 'message' => ''];
        }

        $avg = \App\Models\QuoteLine::where('product_id', $productId)
            ->whereHas('quote', fn ($q) => $q->where('status', \App\Models\Quote::STATUS_WON))
            ->where('suggest_price', '>', 0)
            ->avg('suggest_price');

        if (! $avg || (float) $avg <= 0) {
            return ['flag' => false, 'message' => ''];
        }

        $pct = abs($suggestPrice - (float) $avg) / (float) $avg * 100;
        if ($pct <= $deviation) {
            return ['flag' => false, 'message' => ''];
        }

        return [
            'flag' => true,
            'message' => sprintf('建议价 %.2f 偏离历史成交均价 %.2f 达 %.1f%%（阈值 %s%%），请复核', $suggestPrice, (float) $avg, $pct, $deviation),
        ];
    }
}
