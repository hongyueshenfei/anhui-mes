<?php

namespace Tests\Feature\Plm;

use App\Models\Machine;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 机台 CRUD 冒烟测试（一期验收 A5：新增 I-01 → 列表可查 → 编辑 → 停用）
 */
class MachineCrudTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /** 注塑车间 id（种子已建） */
    private function injectionId(): int
    {
        return (int) Workshop::where('code', 'injection')->firstOrFail()->id;
    }

    public function test_create_machine(): void
    {
        $response = $this->api($this->token)->postJson('/api/plm/machines', [
            'code' => 'I-09',
            'name' => '注塑机 9 号',
            'type' => 'injection',
            'workshop_id' => $this->injectionId(),
            'capacity_per_hour' => 450,
            'mold_change_min' => 25,
            'shift_mode' => 'two_shift',
            'status' => 'running',
            'is_active' => 1,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.code', 'I-09');

        $this->assertDatabaseHas('machines', ['code' => 'I-09', 'capacity_per_hour' => 450]);
    }

    public function test_create_machine_with_duplicate_code_fails(): void
    {
        $response = $this->api($this->token)->postJson('/api/plm/machines', [
            'code' => 'I-01', // 种子数据已有
            'name' => '重复编码',
            'type' => 'injection',
            'workshop_id' => $this->injectionId(),
            'capacity_per_hour' => 100,
            'mold_change_min' => 10,
            'shift_mode' => 'two_shift',
            'status' => 'running',
        ]);

        $response->assertStatus(422);
    }

    public function test_list_machines_contains_seed_data(): void
    {
        $response = $this->api($this->token)->getJson('/api/plm/machines');

        // 种子数据：6 台成型机台 + 3 条产线（P-01 喷漆 / P-02 效果 / P-03 包装）
        $response->assertOk()
            ->assertJsonPath('data.total', 9)
            ->assertJsonPath('data.data.0.code', 'B-01'); // 按 code 排序

        $codes = collect($response->json('data.data'))->pluck('code')->all();
        $this->assertContains('P-01', $codes);
        $this->assertContains('P-02', $codes);
    }

    public function test_update_machine(): void
    {
        $machine = Machine::where('code', 'I-02')->firstOrFail();

        $response = $this->api($this->token)->putJson("/api/plm/machines/{$machine->id}", [
            'code' => 'I-02',
            'name' => '注塑机 2 号（升级后）',
            'type' => 'injection',
            'workshop_id' => $this->injectionId(),
            'capacity_per_hour' => 500,
            'mold_change_min' => 20,
            'shift_mode' => 'two_shift',
            'status' => 'running',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.capacity_per_hour', 500)
            ->assertJsonPath('data.name', '注塑机 2 号（升级后）');
    }

    public function test_deactivate_machine(): void
    {
        $machine = Machine::where('code', 'I-03')->firstOrFail();

        // 停用（验收 A5：停用）
        $this->api($this->token)->putJson("/api/plm/machines/{$machine->id}", [
            'code' => 'I-03',
            'name' => $machine->name,
            'type' => $machine->type,
            'workshop_id' => $machine->workshop_id,
            'capacity_per_hour' => $machine->capacity_per_hour,
            'mold_change_min' => $machine->mold_change_min,
            'shift_mode' => $machine->shift_mode,
            'status' => 'stopped',
            'is_active' => 0,
        ])->assertOk();

        $this->assertDatabaseHas('machines', ['id' => $machine->id, 'is_active' => 0, 'status' => 'stopped']);
    }

    public function test_delete_machine(): void
    {
        $machine = Machine::create([
            'code' => 'T-01',
            'name' => '临时测试机台',
            'type' => 'injection',
            'workshop_id' => $this->injectionId(),
            'capacity_per_hour' => 100,
            'mold_change_min' => 10,
            'shift_mode' => 'day',
            'status' => 'running',
            'is_active' => 1,
        ]);

        $this->api($this->token)->deleteJson("/api/plm/machines/{$machine->id}")->assertOk();

        $this->assertSoftDeleted('machines', ['id' => $machine->id]);
    }
}
