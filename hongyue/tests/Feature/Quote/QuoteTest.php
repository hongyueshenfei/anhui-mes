<?php

namespace Tests\Feature\Quote;

use App\Models\AuditLog;
use App\Models\MaterialPrice;
use App\Models\ProcessRate;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * AI 报价模块测试（五期 E1/E2/E3/E5）
 *
 * - 规则引擎：价格构成/毛利目标/摊销随数量变化/损耗（确定性）
 * - 参数生效日期版本化（E1）
 * - LLM 辅助降级（沙箱无 key → not_enabled，规则引擎结果可用）
 * - 异常价校验 ±30%（E3）
 * - 报价单 CRUD + 估价转单 + 调价审计 + 状态流转（E5）
 */
class QuoteTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');

        // 种子参数：材料价 + 工序费率 + 规则
        MaterialPrice::create(['material_code' => 'RAW-PP-001', 'material_name' => 'PP 原料', 'price_per_unit' => 8.5, 'unit' => 'kg', 'effective_date' => now()->toDateString(), 'source' => 'manual']);
        MaterialPrice::create(['material_code' => 'PKG-BOX-001', 'material_name' => '中号彩盒', 'price_per_unit' => 0.8, 'unit' => '个', 'effective_date' => now()->toDateString(), 'source' => 'manual']);
        foreach (['molding' => 55, 'paint' => 30, 'effect' => 28, 'pack' => 20] as $key => $rate) {
            ProcessRate::create(['rate_key' => $key, 'op_type' => $key, 'rate_type' => 'machine', 'rate' => $rate, 'effective_date' => now()->toDateString()]);
        }
        QuoteRule::create(['rule_key' => QuoteRule::RULE_LOSS_RATE, 'rule_value' => 2.0, 'remark' => '行业损耗率']);
        QuoteRule::create(['rule_key' => QuoteRule::RULE_ANOMALY_DEVIATION, 'rule_value' => 30, 'remark' => '异常价偏差']);
    }

    private function estimatePayload(array $overrides = []): array
    {
        return array_merge([
            'product_id' => Product::where('code', 'P-0001')->firstOrFail()->id,
            'qty' => 1000,
            'margin' => 20,
        ], $overrides);
    }

    public function test_estimate_returns_cost_breakdown_and_suggest_price(): void
    {
        $resp = $this->api($this->token)->postJson('/api/quotes/ai-estimate', $this->estimatePayload());
        $resp->assertOk();

        $d = $resp->json('data');
        $this->assertGreaterThan(0, $d['material_cost'], '材料成本应 > 0（BOM×材料价）');
        $this->assertGreaterThan(0, $d['process_cost'], '加工成本应 > 0（工时×工序费率）');
        $this->assertGreaterThan(0, $d['mold_amort'], '模具摊销应 > 0（造价÷数量）');
        $this->assertGreaterThan(0, $d['loss_cost'], '损耗成本应 > 0');
        $this->assertGreaterThan(0, $d['suggest_price'], '建议价应 > 0');
        $this->assertGreaterThan(0, $d['delivery_days'], '建议交期应 > 0');
        $this->assertArrayHasKey('material_detail', $d);
        $this->assertArrayHasKey('process_detail', $d);

        // 确定性（PRD T6）：同参数两次估价一致
        $again = $this->api($this->token)->postJson('/api/quotes/ai-estimate', $this->estimatePayload())->json('data');
        $this->assertSame($d['suggest_price'], $again['suggest_price']);
        $this->assertSame($d['total_cost'], $again['total_cost']);

        // 毛利校验：suggest_price = total_cost / (1 - 20%)
        $this->assertEqualsWithDelta($d['total_cost'] / 0.8, $d['suggest_price'], 0.01, '毛利目标 20% 应作用于建议价');
    }

    public function test_mold_amort_decreases_with_qty(): void
    {
        $small = $this->api($this->token)->postJson('/api/quotes/ai-estimate', $this->estimatePayload(['qty' => 100]))->json('data');
        $large = $this->api($this->token)->postJson('/api/quotes/ai-estimate', $this->estimatePayload(['qty' => 10000]))->json('data');

        $this->assertGreaterThan($large['mold_amort'], $small['mold_amort'], '摊销应随数量摊薄');
        $this->assertGreaterThan($large['suggest_price'], $small['suggest_price'], '小批量建议价应更高（摊销占比大）');
    }

    public function test_material_price_effective_date_versioning(): void
    {
        // 次日生效新价（PP 8.5 → 9.5）
        MaterialPrice::create(['material_code' => 'RAW-PP-001', 'material_name' => 'PP 原料', 'price_per_unit' => 9.5, 'unit' => 'kg', 'effective_date' => now()->addDay()->toDateString(), 'source' => 'manual']);

        $today = $this->api($this->token)->postJson('/api/quotes/ai-estimate', $this->estimatePayload())->json('data');
        // 历史报价不受影响：直接按生效日期口径断言「今天仍取 8.5」
        // （BOM 5 行：PP 原料 0.022kg × 8.5 + 彩盒 1 个 × 0.8；色母/外箱/标签无料价=0，ProductSeeder 按产品克重换算单件用量）
        $this->assertEqualsWithDelta(0.022 * 8.5 + 1.0 * 0.8, $today['material_cost'], 0.001, '生效日期版本化：今日估价仍取旧价（历史报价不受影响）');
        $this->assertSame('8.5', (string) MaterialPrice::priceAt('RAW-PP-001')->price_per_unit);
    }

    public function test_llm_assist_degrades_when_ai_unreachable(): void
    {
        config(['ai.service_url' => 'http://127.0.0.1:59999']); // 不可达 → 降级

        $resp = $this->api($this->token)->postJson('/api/quotes/ai-estimate', $this->estimatePayload());
        $resp->assertOk();

        $d = $resp->json('data');
        $this->assertSame('not_enabled', $d['ai_assist'], 'ai-service 不可达应降级 not_enabled');
        $this->assertStringContainsString('未启用', (string) $d['llm_remark'], 'LLM 字段应标记 AI 辅助未启用');
        $this->assertGreaterThan(0, $d['suggest_price'], '规则引擎结果仍可用');
    }

    public function test_anomaly_flag_when_price_deviates_from_history(): void
    {
        // 先造一条历史成交（won）报价：P-0001 建议价 5.0
        $quote = Quote::create(['quote_no' => 'QT-HIST-001', 'customer_name' => '历史客户', 'source' => Quote::SOURCE_SALES, 'status' => Quote::STATUS_WON, 'target_margin' => 20, 'final_price' => 5.0]);
        \App\Models\QuoteLine::create(['quote_id' => $quote->id, 'spec_text' => '历史单', 'qty' => 1000, 'product_id' => Product::where('code', 'P-0001')->firstOrFail()->id, 'suggest_price' => 5.0, 'total_cost' => 4.0, 'delivery_days' => 7]);

        // 高毛利 → 建议价明显高于历史均价 → 异常标记
        $resp = $this->api($this->token)->postJson('/api/quotes/ai-estimate', $this->estimatePayload(['margin' => 60]));
        $resp->assertOk();
        $d = $resp->json('data');
        $this->assertTrue($d['anomaly_flag'], '建议价偏离历史成交应标记异常');
        $this->assertStringContainsString('偏离', (string) $d['anomaly_message']);
    }

    public function test_quote_crud_and_estimate_to_quote(): void
    {
        // AI 估价 → 一键转报价单
        $estimate = $this->api($this->token)->postJson('/api/quotes/ai-estimate', $this->estimatePayload())->json('data');

        $create = $this->api($this->token)->postJson('/api/quotes', [
            'from_estimate' => true,
            'customer_name' => '估价客户',
            'estimate' => $estimate,
        ]);
        $create->assertStatus(201);
        $quoteId = $create->json('data.id');
        $this->assertSame('draft', $create->json('data.status'));
        $this->assertSame(1, count($create->json('data.lines')));
        // 估价返回的 suggest_price 经 JSON 往返可能为 int，报价单行经数据库往返为 float，统一浮点比较
        $this->assertEqualsWithDelta((float) $estimate['suggest_price'], (float) $create->json('data.lines.0.suggest_price'), 0.001, '估价转单后建议价一致');

        // 详情
        $this->api($this->token)->getJson("/api/quotes/{$quoteId}")->assertOk();

        // 调价留痕
        $this->api($this->token)->patchJson("/api/quotes/{$quoteId}/price", ['final_price' => 6.5, 'remark' => '客户议价'])
            ->assertOk()->assertJsonPath('data.final_price', 6.5);
        $this->assertDatabaseHas('audit_logs', ['module' => 'quote', 'action' => 'quote_price_change', 'target_id' => $quoteId]);

        // 状态流转 → 成交
        $this->api($this->token)->patchJson("/api/quotes/{$quoteId}/status", ['status' => 'won'])->assertOk();
        $this->assertSame('won', Quote::find($quoteId)->status);

        // 列表过滤
        $list = $this->api($this->token)->getJson('/api/quotes?status=won');
        $list->assertOk()->assertJsonPath('data.total', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'quote', 'action' => 'quote_status', 'target_id' => $quoteId]);
    }

    public function test_quote_rule_params_upsert(): void
    {
        // 材料价 upsert（生效日期版本化）
        $this->api($this->token)->postJson('/api/quotes/params/materials', [
            'material_code' => 'RAW-PP-001', 'material_name' => 'PP 原料', 'price_per_unit' => 9.0, 'unit' => 'kg', 'effective_date' => now()->addDays(2)->toDateString(),
        ])->assertStatus(201);

        $list = $this->api($this->token)->getJson('/api/quotes/params/materials?all=1');
        $list->assertOk();
        $this->assertSame(3, count($list->json('data')), '材料价含同日两条 + 新版本一条');

        // 规则 upsert
        $this->api($this->token)->putJson('/api/quotes/params/rules', ['rule_key' => QuoteRule::RULE_MIN_MARGIN, 'rule_value' => 15, 'remark' => '毛利下限'])->assertOk();
        $this->assertSame(15.0, QuoteRule::valueOf(QuoteRule::RULE_MIN_MARGIN));
    }
}
