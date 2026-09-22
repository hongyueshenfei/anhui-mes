<?php

namespace Tests\Feature\Qa;

use App\Models\AuditLog;
use App\Models\Bom;
use App\Models\Machine;
use App\Models\Mold;
use App\Models\Product;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * QA 独立 RBAC 矩阵 + 审计日志细节验证
 *
 * ④ 角色权限矩阵逐角色抽样（6 角色各抽 1-2 权限点）
 * A11 操作审计：BOM 发布、模具修改写入 audit_logs（含 user/ip/action）
 */
class QaRbacAuditTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    // ================= RBAC 矩阵（6 角色抽样） =================

    public function test_admin_can_access_system_users_roles_audit(): void
    {
        $token = $this->loginToken('admin');

        $this->api($token)->getJson('/api/system/users')->assertOk();
        $this->api($token)->getJson('/api/system/roles')->assertOk();
        $this->api($token)->getJson('/api/system/audit-logs')->assertOk();
        $this->api($token)->getJson('/api/system/configs')->assertOk();
    }

    public function test_engineer_full_plm_manage_but_no_system_manage(): void
    {
        $token = $this->loginToken('E001');

        // 有：PLM 全量 manage
        $this->api($token)->postJson('/api/plm/machines', [
            'code' => 'I-E1', 'name' => '工程师机台', 'type' => 'injection',
            'workshop_id' => Workshop::where('code', 'injection')->firstOrFail()->id,
            'capacity_per_hour' => 100, 'mold_change_min' => 10, 'shift_mode' => 'two_shift', 'status' => 'running',
        ])->assertStatus(201);

        // 无：system.user.manage
        $this->api($token)->postJson('/api/system/users', [
            'employee_no' => 'X001', 'name' => '越权', 'password' => '123456',
        ])->assertStatus(403);

        // 无：system.config.manage
        $this->api($token)->postJson('/api/system/configs', [
            'config_key' => 'qa.test', 'config_value' => 1,
        ])->assertStatus(403);

        // 无：audit.view（工程师角色无 audit.view —— 但 routes 里 get audit-logs 是 check.permission:audit.view）
        $this->api($token)->getJson('/api/system/audit-logs')->assertStatus(403);
    }

    public function test_planner_readonly_no_write(): void
    {
        $token = $this->loginToken('P001');

        // 读：可以
        $this->api($token)->getJson('/api/plm/products')->assertOk();
        $this->api($token)->getJson('/api/plm/machines')->assertOk();

        // 写：不可以
        $this->api($token)->postJson('/api/plm/machines', [
            'code' => 'I-P1', 'name' => '计划员机台', 'type' => 'injection',
            'workshop_id' => Workshop::where('code', 'injection')->firstOrFail()->id,
            'capacity_per_hour' => 100, 'mold_change_min' => 10, 'shift_mode' => 'two_shift', 'status' => 'running',
        ])->assertStatus(403);

        // 写：不可以（模具）
        $this->api($token)->postJson('/api/plm/molds', [
            'code' => 'M-P1', 'name' => '计划员模具', 'type' => 'injection',
            'cavity_count' => 4, 'lifetime_openings' => 100000,
        ])->assertStatus(403);
    }

    public function test_leader_readonly_limited(): void
    {
        $token = $this->loginToken('L001');

        // 读产品/机台/车间 OK
        $this->api($token)->getJson('/api/plm/products')->assertOk();
        $this->api($token)->getJson('/api/plm/machines')->assertOk();
        $this->api($token)->getJson('/api/plm/workshops')->assertOk();

        // 写被拒
        $this->api($token)->postJson('/api/plm/products', [
            'code' => 'P-L1', 'name' => '班组长产品',
        ])->assertStatus(403);

        // BOM 发布被拒（planner 已测过，leader 也抽一下）
        $bomId = Bom::where('status', 'draft')->value('id');
        $this->api($token)->postJson("/api/plm/boms/{$bomId}/publish")->assertStatus(403);
    }

    public function test_worker_readonly_limited_to_machine_product(): void
    {
        $token = $this->loginToken('W001');

        // 有：machine.view / product.view（车间随 machine.view 可读）
        $this->api($token)->getJson('/api/plm/machines')->assertOk();
        $this->api($token)->getJson('/api/plm/products')->assertOk();
        $this->api($token)->getJson('/api/plm/workshops')->assertOk();

        // 无：bom.view / mold.view / system.users
        $this->api($token)->getJson('/api/plm/molds')->assertStatus(403);
        $this->api($token)->getJson('/api/system/users')->assertStatus(403);
    }

    public function test_sales_readonly_mold_product_no_machine(): void
    {
        $token = $this->loginToken('S001');

        // 有：product.view / mold.view
        $this->api($token)->getJson('/api/plm/products')->assertOk();
        $this->api($token)->getJson('/api/plm/molds')->assertOk();

        // 无：machine.view（sales 角色仅是 product/mold view —— 看 RoleSeeder）
        $this->api($token)->getJson('/api/plm/machines')->assertStatus(403);

        // 无：写
        $this->api($token)->postJson('/api/plm/products', [
            'code' => 'P-S1', 'name' => '业务员产品',
        ])->assertStatus(403);
    }

    // ================= A11 审计日志细节 =================

    public function test_bom_publish_audit_contains_user_ip_action(): void
    {
        $token = $this->loginToken('E001');

        // 同产品唯一已发布版本规则：先退回既有 published（P-0001 V2.0），再发布 draft
        $published = Bom::where('status', 'published')->first();
        if ($published) {
            $this->api($token)->postJson("/api/plm/boms/{$published->id}/revoke")->assertOk();
        }

        $bom = Bom::where('status', 'draft')->firstOrFail();

        $this->api($token)->postJson("/api/plm/boms/{$bom->id}/publish")->assertOk();

        $log = AuditLog::where('action', 'publish_bom')
            ->where('target_id', $bom->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('plm', $log->module);
        $this->assertSame('publish_bom', $log->action);
        $this->assertSame('bom', $log->target_type);
        $this->assertNotNull($log->user_id, '审计必须记录操作人 user_id');
        $this->assertSame('E001', \App\Models\User::find($log->user_id)->employee_no, '审计 user_id 应为操作人');

        $detail = $log->detail;
        $this->assertArrayHasKey('product_id', $detail, '审计 detail 应含产品');
        $this->assertArrayHasKey('version', $detail, '审计 detail 应含版本');
    }

    public function test_mold_update_audit_written(): void
    {
        $token = $this->loginToken('E001');
        $mold = Mold::where('code', 'M-003')->firstOrFail();

        $this->api($token)->putJson("/api/plm/molds/{$mold->id}", [
            'code' => 'M-003',
            'name' => '方盒 800ml 注塑模（改）',
            'type' => 'injection',
            'cavity_count' => 4,
            'lifetime_openings' => 400000,
        ])->assertOk();

        $log = AuditLog::where('action', 'mold_update')
            ->where('target_id', $mold->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('plm', $log->module);
        $this->assertNotNull($log->user_id, '模具修改审计必须记录 user_id');
        $this->assertSame('E001', \App\Models\User::find($log->user_id)->employee_no);
    }

    public function test_audit_log_captures_ip(): void
    {
        $token = $this->loginToken('admin');

        $this->api($token)->postJson('/api/plm/machines', [
            'code' => 'I-IP1', 'name' => 'IP 审计机台', 'type' => 'injection',
            'workshop_id' => Workshop::where('code', 'injection')->firstOrFail()->id,
            'capacity_per_hour' => 100, 'mold_change_min' => 10, 'shift_mode' => 'two_shift', 'status' => 'running',
        ])->assertStatus(201);

        $log = AuditLog::where('action', 'machine_create')->latest('id')->firstOrFail();
        $this->assertNotNull($log->ip, '审计必须记录来源 IP');
        $this->assertNotEmpty($log->ip);
        $this->assertNotNull($log->user_id, '审计必须记录 user_id');
    }

    public function test_product_status_transition_audit_has_from_to(): void
    {
        $token = $this->loginToken('admin');
        $product = Product::where('code', 'P-0004')->firstOrFail(); // developing

        $this->api($token)->postJson("/api/plm/products/{$product->id}/status", ['status' => 'trial'])->assertOk();

        // 注意：中间件 AuditLog + Service 手动记录可能各写一条，取 detail 含 from/to 的那条
        $log = AuditLog::where('action', 'product_status')
            ->where('target_id', $product->id)
            ->get()
            ->first(fn ($item) => isset($item->detail['from']) && isset($item->detail['to']));

        $this->assertNotNull($log, '应存在 detail 含 from/to 的状态流转审计（Service 记录）');
        $this->assertSame('developing', $log->detail['from']);
        $this->assertSame('trial', $log->detail['to']);
    }
}