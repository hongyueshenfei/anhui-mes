<?php

namespace Tests\Feature\Rbac;

use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * RBAC 权限测试（一期验收 A4：角色权限生效，越权访问 403）
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    public function test_worker_cannot_manage_machines(): void
    {
        $token = $this->loginToken('W001');

        $response = $this->api($token)->postJson('/api/plm/machines', [
            'code' => 'I-99',
            'name' => '越权机台',
            'type' => 'injection',
            'workshop_id' => Workshop::where('code', 'injection')->firstOrFail()->id,
            'capacity_per_hour' => 100,
            'mold_change_min' => 30,
            'shift_mode' => 'two_shift',
            'status' => 'running',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 403);
    }

    public function test_worker_can_view_machines(): void
    {
        $token = $this->loginToken('W001');

        $this->api($token)->getJson('/api/plm/machines')->assertOk();
    }

    public function test_engineer_cannot_manage_users(): void
    {
        $token = $this->loginToken('E001');

        $response = $this->api($token)->postJson('/api/system/users', [
            'employee_no' => 'E100',
            'name' => '越权用户',
            'password' => '123456',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 403);
    }

    public function test_planner_cannot_publish_bom(): void
    {
        $token = $this->loginToken('P001');

        // 找产品 P-0001 的草稿 BOM（V1.0）
        $bomId = \App\Models\Bom::where('status', 'draft')->value('id');
        $this->assertNotNull($bomId);

        $response = $this->api($token)->postJson("/api/plm/boms/{$bomId}/publish");

        $response->assertStatus(403);
    }

    public function test_engineer_can_publish_bom(): void
    {
        $token = $this->loginToken('E001');

        // 同产品唯一已发布版本规则：先退回既有 published（P-0001 V2.0），再发布 draft
        $published = \App\Models\Bom::where('status', 'published')->first();
        if ($published) {
            $this->api($token)->postJson("/api/plm/boms/{$published->id}/revoke")->assertOk();
        }

        $bomId = \App\Models\Bom::where('status', 'draft')->value('id');
        $this->assertNotNull($bomId);

        $this->api($token)->postJson("/api/plm/boms/{$bomId}/publish")->assertOk();
    }

    public function test_admin_can_manage_everything(): void
    {
        $token = $this->loginToken('admin');

        // 用户管理
        $this->api($token)->getJson('/api/system/users')->assertOk();

        // 角色管理
        $this->api($token)->getJson('/api/system/roles')
            ->assertOk()
            ->assertJsonCount(7, 'data');

        // 审计日志
        $this->api($token)->getJson('/api/system/audit-logs')->assertOk();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/plm/machines')->assertStatus(401);
    }
}
