<?php

namespace Tests\Feature\Plm;

use App\Models\Machine;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 车间 CRUD + 机台 workshop_id（车间实体化：先有车间，车间下面是机台/产线）
 */
class WorkshopCrudTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    // ==================== 列表 ====================

    public function test_list_returns_seeded_workshops_with_machine_count(): void
    {
        $r = $this->api($this->token)->getJson('/api/plm/workshops');

        $r->assertOk();
        $codes = collect($r->json('data'))->pluck('code')->all();
        $this->assertContains('injection', $codes);
        $this->assertContains('blow', $codes);
        $this->assertContains('paint', $codes);
        $this->assertContains('pack', $codes);
        // 注塑车间应含 4 台注塑机
        $injection = collect($r->json('data'))->firstWhere('code', 'injection');
        $this->assertSame(4, (int) $injection['machines_count']);
    }

    public function test_list_active_only(): void
    {
        Workshop::where('code', 'effect')->update(['is_active' => 0]);

        $r = $this->api($this->token)->getJson('/api/plm/workshops?active_only=1');

        $r->assertOk();
        $codes = collect($r->json('data'))->pluck('code')->all();
        $this->assertNotContains('effect', $codes);
    }

    // ==================== 创建 / 更新 ====================

    public function test_create_workshop(): void
    {
        $r = $this->api($this->token)->postJson('/api/plm/workshops', [
            'code' => 'w-warehouse',
            'name' => '成品仓',
            'sort' => 6,
            'is_active' => 1,
        ]);

        $r->assertCreated();
        $this->assertDatabaseHas('workshops', ['code' => 'w-warehouse', 'name' => '成品仓']);
    }

    public function test_create_duplicate_code_rejected(): void
    {
        $r = $this->api($this->token)->postJson('/api/plm/workshops', [
            'code' => 'injection',
            'name' => '重复编码',
        ]);

        $r->assertStatus(422);
    }

    public function test_update_workshop(): void
    {
        $ws = Workshop::where('code', 'paint')->first();

        $r = $this->api($this->token)->putJson("/api/plm/workshops/{$ws->id}", [
            'name' => '喷漆车间（改）',
            'sort' => 10,
        ]);

        $r->assertOk()->assertJsonPath('data.name', '喷漆车间（改）');
    }

    // ==================== 删除（引用保护） ====================

    public function test_destroy_workshop_with_machines_rejected(): void
    {
        $ws = Workshop::where('code', 'injection')->first();

        $r = $this->api($this->token)->deleteJson("/api/plm/workshops/{$ws->id}");

        $r->assertStatus(409);
        $this->assertDatabaseHas('workshops', ['id' => $ws->id]);
    }

    public function test_destroy_empty_workshop_succeeds(): void
    {
        $ws = Workshop::create(['code' => 'w-empty', 'name' => '空车间', 'sort' => 99, 'is_active' => 1]);

        $r = $this->api($this->token)->deleteJson("/api/plm/workshops/{$ws->id}");

        $r->assertOk();
        $this->assertSoftDeleted('workshops', ['id' => $ws->id]);
    }

    // ==================== 机台 workshop_id 关联 ====================

    public function test_machines_have_workshop_id_backfilled(): void
    {
        $injection = Workshop::where('code', 'injection')->first();
        $machine = Machine::where('code', 'I-01')->first();

        $this->assertSame($injection->id, (int) $machine->workshop_id);
    }

    public function test_machine_list_returns_workshop_name(): void
    {
        $r = $this->api($this->token)->getJson('/api/plm/machines?all=1');

        $r->assertOk();
        $i01 = collect($r->json('data'))->firstWhere('code', 'I-01');
        $this->assertSame('注塑车间', $i01['workshop_name']);
    }

    public function test_machine_filter_by_workshop_id(): void
    {
        $paint = Workshop::where('code', 'paint')->first();

        $r = $this->api($this->token)->getJson('/api/plm/machines?all=1&workshop_id='.$paint->id);

        $r->assertOk();
        $codes = collect($r->json('data'))->pluck('code')->all();
        $this->assertContains('P-01', $codes);
        $this->assertNotContains('I-01', $codes);
    }
}
