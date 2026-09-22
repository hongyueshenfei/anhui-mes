<?php

namespace Tests\Feature\Plm;

use App\Models\Mold;
use App\Modules\Plm\Services\MoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 模具台账测试（一期验收 A10：建模（模穴数/寿命/造价）→ 关联产品/机台 → 保养到期提醒；
 * 寿命折算 = 报工数 ÷ 模穴数，向上取整）
 */
class MoldLifeTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    public function test_life_openings_calculation(): void
    {
        // 4 穴模具：100 件 → 开模 25 次
        $this->assertSame(25, MoldService::lifeOpeningsForQty(100, 4));

        // 3 穴模具：10 件 → 开模 4 次（向上取整）
        $this->assertSame(4, MoldService::lifeOpeningsForQty(10, 3));

        // 1 穴模具：恰好 = 数量
        $this->assertSame(99, MoldService::lifeOpeningsForQty(99, 1));

        // 0 合格件：0 次
        $this->assertSame(0, MoldService::lifeOpeningsForQty(0, 4));
    }

    public function test_life_openings_invalid_args(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoldService::lifeOpeningsForQty(100, 0);
    }

    public function test_add_openings_writes_life_log(): void
    {
        $mold = Mold::where('code', 'M-001')->firstOrFail();
        $before = $mold->opened_count;

        // 4 穴模具报工 100 件 → 开模 25 次
        $mold = MoldService::addOpenings($mold, 100);

        $this->assertSame($before + 25, $mold->opened_count);
        $this->assertDatabaseHas('mold_life_logs', [
            'mold_id' => $mold->id,
            'opening_count' => 25,
        ]);
    }

    public function test_create_mold_with_relations(): void
    {
        $response = $this->api($this->token)->postJson('/api/plm/molds', [
            'code' => 'M-101',
            'name' => '新杯注塑模',
            'type' => 'injection',
            'status' => 'idle',
            'location' => 'C区-01',
            'cavity_count' => 8,
            'lifetime_openings' => 800000,
            'purchase_cost' => 120000,
            'purchase_date' => '2025-01-10',
            'vendor' => '测试供应商',
            'maintain_cycle_days' => 30,
            'product_ids' => [\App\Models\Product::where('code', 'P-0001')->value('id')],
            'machine_ids' => [\App\Models\Machine::where('code', 'I-01')->value('id')],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.cavity_count', 8)
            ->assertJsonCount(1, 'data.machines')
            ->assertJsonCount(1, 'data.products');
    }

    public function test_maintain_record_and_due_reminder(): void
    {
        // M-002 种子数据已设置 next_maintain_at 为过去 5 天 → 到期
        $mold = Mold::where('code', 'M-002')->firstOrFail();

        $detail = $this->api($this->token)->getJson("/api/plm/molds/{$mold->id}")->json('data');
        $this->assertTrue($detail['maintain_due'], '到期模具应被标记 maintain_due');

        // 登记保养：type=maintain 自动推算下次保养
        $response = $this->api($this->token)->postJson("/api/plm/molds/{$mold->id}/maintain", [
            'type' => 'maintain',
            'cost' => 800,
            'vendor' => '宁波吹塑模具',
            'remark' => '到期例行保养',
        ]);

        $response->assertStatus(201);
        $mold->refresh();

        $this->assertNotNull($mold->last_maintain_at);
        $this->assertNotNull($mold->next_maintain_at);

        // 保养后不再到期
        $detail = $this->api($this->token)->getJson("/api/plm/molds/{$mold->id}")->json('data');
        $this->assertFalse($detail['maintain_due']);
    }

    public function test_maintain_due_filter_in_list(): void
    {
        $response = $this->api($this->token)->getJson('/api/plm/molds?maintain_due=1');

        $response->assertOk();

        // 种子数据仅 M-002 到期
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_mold_compatibilities(): void
    {
        $mold = Mold::where('code', 'M-001')->firstOrFail();

        $response = $this->api($this->token)->getJson("/api/plm/molds/{$mold->id}/compatibilities");

        $response->assertOk()
            ->assertJsonCount(4, 'data.machines'); // I-01 ~ I-04
    }

    public function test_mold_life_logs_api(): void
    {
        $mold = Mold::where('code', 'M-001')->firstOrFail();
        MoldService::addOpenings($mold, 400); // 4 穴 → 100 次开模

        $response = $this->api($this->token)->getJson("/api/plm/molds/{$mold->id}/life");

        $response->assertOk()
            ->assertJsonPath('data.logs.0.opening_count', 100);
    }
}