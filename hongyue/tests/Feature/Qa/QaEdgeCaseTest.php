<?php

namespace Tests\Feature\Qa;

use App\Models\AuditLog;
use App\Models\Machine;
use App\Models\Mold;
use App\Models\PlmRoute;
use App\Models\Product;
use App\Models\User;
use App\Models\Workshop;
use App\Modules\Plm\Services\MoldService;
use App\Modules\Plm\Services\ProductService;
use App\Modules\Plm\Services\RouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * QA 回归：删除引用保护（P1）+ 同类孤儿引用加固 + 边界陷阱
 *
 * 对应 QA 报告：
 * - Bug1: 删除被模具引用的机台应 409 拒绝（mold_machines 孤儿 pivot），响应带关联清单
 * - Bug2: 删除被模具/工艺/BOM 引用的产品应 409 拒绝（孤儿 pivot），响应带 references 清单
 * - 加固项：模具(寿命/产品引用)、用户(车间班组长) 删除保护统一 409
 *
 * 说明：本文件为修复后重建版（原 QA 版本被误覆盖，见致 QA 消息），
 * 报告中两个用例的方法名与断言与 QA 原案保持一致，并扩展至边界用例。
 */
class QaEdgeCaseTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $adminToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminToken = $this->loginToken('admin');
    }

    // ================= Bug1：机台删除引用保护 =================

    public function test_delete_machine_referenced_by_mold_is_blocked(): void
    {
        // I-01 被 M-001（以及 M-003）经 mold_machines 引用 → 删除被拒 409
        $machineId = \App\Models\Machine::where('code', 'I-01')->value('id');

        $response = $this->api($this->adminToken)->deleteJson("/api/plm/machines/{$machineId}");

        $response->assertStatus(409)
            ->assertJsonPath('code', 409);

        // 响应附带关联模具清单（code 校验）
        $moldCodes = collect($response->json('data.molds'))->pluck('code')->all();
        $this->assertContains('M-001', $moldCodes, '409 响应应带关联模具清单');

        // 未被任何模具引用的机台可正常删除
        $freeMachine = \App\Models\Machine::create([
            'code' => 'I-77',
            'name' => '无引用测试机',
            'type' => 'injection',
            'workshop_id' => Workshop::where('code', 'injection')->firstOrFail()->id,
            'capacity_per_hour' => 100,
            'mold_change_min' => 10,
            'shift_mode' => 'day',
            'status' => 'running',
            'is_active' => 1,
        ]);

        $this->api($this->adminToken)->deleteJson("/api/plm/machines/{$freeMachine->id}")->assertOk();
        $this->assertSoftDeleted('machines', ['id' => $freeMachine->id]);
    }

    // ================= Bug2：产品删除引用保护 =================

    public function test_delete_product_referenced_by_mold_is_blocked(): void
    {
        // 新建无 BOM/工艺的产品，关联模具后删除应被拒（mold_product 引用）
        $product = app(ProductService::class)->create([
            'code' => 'P-QA-001',
            'name' => 'QA 防孤儿产品',
            'spec' => '测试',
            'unit' => '只',
            'status' => 'developing',
        ]);

        $mold = Mold::where('code', 'M-005')->firstOrFail();
        $mold->products()->syncWithoutDetaching([$product->id => ['is_default' => 0]]);

        $response = $this->api($this->adminToken)->deleteJson("/api/plm/products/{$product->id}");
        $response->assertStatus(409)
            ->assertJsonPath('code', 409)
            ->assertJsonPath('data.references.molds', 1);

        // 解除模具关联后（仍无 BOM/工艺）可删除
        $mold->products()->detach($product->id);
        $this->api($this->adminToken)->deleteJson("/api/plm/products/{$product->id}")->assertOk();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_delete_product_with_bom_reference_blocked(): void
    {
        // 新建产品 + BOM → 409 references.boms=1
        $product = app(ProductService::class)->create([
            'code' => 'P-QA-002', 'name' => 'QA BOM 引用产品', 'status' => 'developing',
        ]);

        app(\App\Modules\Plm\Services\BomService::class)->createVersion($product, [
            'version' => 'V1.0',
            'lines' => [
                ['item_code' => 'RAW-X', 'item_name' => '原料 X', 'qty' => 1, 'unit' => 'kg', 'loss_rate' => 0, 'is_key' => 1],
            ],
        ]);

        $response = $this->api($this->adminToken)->deleteJson("/api/plm/products/{$product->id}");

        $response->assertStatus(409)
            ->assertJsonPath('data.references.boms', 1);
        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_delete_product_with_route_reference_allowed(): void
    {
        // 工艺路线为独立实体：产品通过 default_route_id 引用不构成删除阻塞，
        // 删除产品后路线仍保留（路线库与产品解耦）
        $route = PlmRoute::create(['name' => 'QA 独立工艺', 'version' => 'R1', 'status' => PlmRoute::STATUS_PUBLISHED]);
        $product = app(ProductService::class)->create([
            'code' => 'P-QA-003', 'name' => 'QA 工艺引用产品', 'status' => 'developing',
        ]);
        $product->update(['default_route_id' => $route->id]);

        $response = $this->api($this->adminToken)->deleteJson("/api/plm/products/{$product->id}");

        $response->assertOk();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('routes', ['id' => $route->id, 'name' => 'QA 独立工艺']);
    }

    // ================= 加固项：模具/用户/班组删除保护 =================

    public function test_delete_mold_referenced_by_product_is_blocked(): void
    {
        // 新建模具关联产品后删除被拒（409）
        $newMold = Mold::create([
            'code' => 'M-QA-01', 'name' => 'QA 模具', 'type' => 'injection',
            'status' => 'idle', 'cavity_count' => 2, 'lifetime_openings' => 100000, 'opened_count' => 0,
        ]);

        $product = Product::where('code', 'P-0005')->firstOrFail();
        $newMold->products()->syncWithoutDetaching([$product->id]);

        $response = $this->api($this->adminToken)->deleteJson("/api/plm/molds/{$newMold->id}");
        $response->assertStatus(409)
            ->assertJsonPath('data.products.0.code', 'P-0005');

        // 无引用的孤立模具可删除
        $orphanMold = Mold::create([
            'code' => 'M-QA-02', 'name' => 'QA 孤立模具', 'type' => 'injection',
            'status' => 'idle', 'cavity_count' => 1, 'lifetime_openings' => 1000, 'opened_count' => 0,
        ]);

        $this->api($this->adminToken)->deleteJson("/api/plm/molds/{$orphanMold->id}")->assertOk();
    }

    public function test_delete_mold_with_life_record_blocked(): void
    {
        // 孤立模具但已有开模次数（寿命流水）→ 409
        $mold = Mold::create([
            'code' => 'M-QA-03', 'name' => 'QA 已用模具', 'type' => 'injection',
            'status' => 'in_use', 'cavity_count' => 4, 'lifetime_openings' => 100000, 'opened_count' => 0,
        ]);

        MoldService::addOpenings($mold, 400); // 4 穴 × 100 次

        $response = $this->api($this->adminToken)->deleteJson("/api/plm/molds/{$mold->id}");

        $response->assertStatus(409)
            ->assertJsonPath('data.opened_count', 100);
    }

    public function test_delete_workshop_leader_user_is_blocked(): void
    {
        // L001 担任车间班组长（job_type=leader）→ 删除被拒 409
        $leader = User::where('employee_no', 'L001')->firstOrFail();

        $response = $this->api($this->adminToken)->deleteJson("/api/system/users/{$leader->id}");
        $response->assertStatus(409);

        // 普通工人 W003（非班组长）可删除
        $worker = User::where('employee_no', 'W003')->firstOrFail();
        $this->api($this->adminToken)->deleteJson("/api/system/users/{$worker->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $worker->id]);
    }

    public function test_delete_admin_user_blocked(): void
    {
        $admin = User::where('employee_no', 'admin')->firstOrFail();

        $this->api($this->adminToken)->deleteJson("/api/system/users/{$admin->id}")->assertStatus(409);
    }

    // ================= 状态流转与审计细节 =================

    public function test_invalid_status_transition_unchanged_and_no_service_audit(): void
    {
        // P-0004 developing → mass 非法（须经 trial）：422 且产品状态不变
        $product = Product::where('code', 'P-0004')->firstOrFail();

        $this->api($this->adminToken)
            ->postJson("/api/plm/products/{$product->id}/status", ['status' => 'mass'])
            ->assertStatus(422);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'developing']);

        // Service 审计不记录非法流转：不存在 from=developing → mass 的 from/to 记录
        $illegal = AuditLog::where('action', 'product_status')
            ->where('target_id', $product->id)
            ->get()
            ->contains(fn ($item) => ($item->detail['from'] ?? null) === 'developing' && ($item->detail['to'] ?? null) === 'mass');

        $this->assertFalse($illegal, '非法状态流转不应写入 from/to 审计');
    }

    public function test_status_transition_idempotent_no_duplicate_service_audit(): void
    {
        $product = Product::where('code', 'P-0004')->firstOrFail();

        $this->api($this->adminToken)->postJson("/api/plm/products/{$product->id}/status", ['status' => 'trial'])->assertOk();
        // 同状态再次流转：幂等返回，不再新增 from/to 审计
        $this->api($this->adminToken)->postJson("/api/plm/products/{$product->id}/status", ['status' => 'trial'])->assertOk();

        $serviceLogs = AuditLog::where('action', 'product_status')
            ->where('target_id', $product->id)
            ->get()
            ->filter(fn ($item) => isset($item->detail['from'], $item->detail['to']));

        $this->assertCount(1, $serviceLogs, '幂等流转不应重复写入 from/to 审计');
    }

    public function test_product_status_audit_records_complementary(): void
    {
        // P2 观察项回归：一条请求级记录（method/path）+ 一条业务级记录（from/to），内容互补
        $product = Product::where('code', 'P-0004')->firstOrFail();

        $this->api($this->adminToken)->postJson("/api/plm/products/{$product->id}/status", ['status' => 'trial'])->assertOk();

        $logs = AuditLog::where('action', 'product_status')->where('target_id', $product->id)->get();

        $hasRequestLevel = $logs->contains(fn ($item) => isset($item->detail['method']) && $item->detail['method'] === 'POST');
        $hasBizLevel = $logs->contains(fn ($item) => isset($item->detail['from'], $item->detail['to']));

        $this->assertTrue($hasRequestLevel, '中间件应记录请求级审计（method/path/params）');
        $this->assertTrue($hasBizLevel, 'Service 应记录业务级审计（from/to）');
    }

    // ================= 编码唯一性 =================

    public function test_delete_machine_success_writes_audit(): void
    {
        // 删除成功链路需留审计（machine_delete 含操作人与动作）
        $freeMachine = \App\Models\Machine::create([
            'code' => 'I-88',
            'name' => '删除审计机台',
            'type' => 'injection',
            'workshop_id' => Workshop::where('code', 'injection')->firstOrFail()->id,
            'capacity_per_hour' => 100,
            'mold_change_min' => 10,
            'shift_mode' => 'day',
            'status' => 'running',
            'is_active' => 1,
        ]);

        $this->api($this->adminToken)->deleteJson("/api/plm/machines/{$freeMachine->id}")->assertOk();

        $log = AuditLog::where('action', 'machine_delete')
            ->where('target_id', $freeMachine->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('plm', $log->module);
        $this->assertSame('admin', User::find($log->user_id)->employee_no, '删除审计应记录操作人');
    }

    public function test_duplicate_mold_code_rejected(): void
    {
        $response = $this->api($this->adminToken)->postJson('/api/plm/molds', [
            'code' => 'M-001', // 种子已有
            'name' => '重复编码模具',
            'type' => 'injection',
            'cavity_count' => 4,
            'lifetime_openings' => 100000,
        ]);

        $response->assertStatus(422);
    }

    // ================= QA 原案补回（重建版被覆盖丢失的用例） =================
    // 说明：以下用例为 QA 独立验收原版 tests/Feature/Qa/QaEdgeCaseTest.php 中
    // 被误覆盖的用例（模具寿命防御/BOM 引用/停用用户/登录不泄漏/幂等），
    // 由 QA 重新合并，避免验收任务要求场景的回归盲区。

    public function test_mold_life_qty_zero_is_safe(): void
    {
        // 寿命=0 且模穴数正常：不得抛异常，开模次数为 0
        $mold = Mold::create([
            'code' => 'M-QA-100', 'name' => '零寿命测试模', 'type' => 'injection',
            'cavity_count' => 4, 'lifetime_openings' => 0, 'opened_count' => 0,
        ]);

        $result = MoldService::addOpenings($mold, 0);

        $this->assertSame(0, $result->opened_count);
        $this->assertDatabaseMissing('mold_life_logs', ['mold_id' => $mold->id]);
    }

    public function test_mold_life_zero_cavity_rejected_at_api(): void
    {
        // 模穴数=0 防御：API 层拒绝
        $response = $this->api($this->adminToken)->postJson('/api/plm/molds', [
            'code' => 'M-QA-101', 'name' => '零穴模', 'type' => 'injection',
            'cavity_count' => 0, 'lifetime_openings' => 100000,
        ]);

        $this->assertContains($response->status(), [422, 409], '模穴数为 0 应被拒绝');
    }

    public function test_mold_life_negative_cavity_rejected_at_api(): void
    {
        // 模穴数负数防御
        $response = $this->api($this->adminToken)->postJson('/api/plm/molds', [
            'code' => 'M-QA-102', 'name' => '负穴模', 'type' => 'injection',
            'cavity_count' => -2, 'lifetime_openings' => 100000,
        ]);

        $this->assertContains($response->status(), [422, 409], '模穴数为负应被拒绝');
    }

    public function test_mold_life_service_negative_qty_throws(): void
    {
        // 服务层防御：报工数为负抛异常
        $this->expectException(\InvalidArgumentException::class);
        MoldService::lifeOpeningsForQty(-100, 4);
    }

    public function test_mold_life_divide_keep_integer_ceiling(): void
    {
        // 边界：非整除向上取整（3 件 ÷ 2 穴 = 2 次开模）
        $this->assertSame(2, MoldService::lifeOpeningsForQty(3, 2));
        // 整除：4 件 ÷ 2 穴 = 2 次
        $this->assertSame(2, MoldService::lifeOpeningsForQty(4, 2));
        // 1 穴 = 数量本身
        $this->assertSame(7, MoldService::lifeOpeningsForQty(7, 1));
    }

    public function test_draft_bom_not_exposed_as_published(): void
    {
        $product = Product::where('code', 'P-0002')->firstOrFail();

        // 建一个草稿 V9.9
        $this->api($this->adminToken)->postJson("/api/plm/products/{$product->id}/boms", [
            'version' => 'V9.9',
            'lines' => [
                ['item_code' => 'RAW-X1', 'item_name' => '原料X', 'qty' => 1, 'unit' => 'kg', 'loss_rate' => 0, 'is_key' => 1],
            ],
        ])->assertStatus(201);

        // published 接口不得返回草稿
        $response = $this->api($this->adminToken)->getJson("/api/plm/products/{$product->id}/boms/published");
        $response->assertOk();
        $this->assertNotSame('V9.9', $response->json('data.version'), '草稿版本不应出现在 published 接口');
    }

    public function test_draft_bom_cannot_be_scheduled_reference(): void
    {
        // 未发布版本不可被引用：创建草稿后 published 接口仍返回原发布版
        $product = Product::where('code', 'P-0003')->firstOrFail();
        $publishedBefore = $product->getPublishedBom();

        $this->api($this->adminToken)->postJson("/api/plm/products/{$product->id}/boms", [
            'version' => 'V-DRAFT',
            'lines' => [['item_code' => 'RAW-Y', 'item_name' => '原料Y', 'qty' => 1, 'unit' => 'kg', 'loss_rate' => 0, 'is_key' => 1]],
        ])->assertStatus(201);

        $response = $this->api($this->adminToken)->getJson("/api/plm/products/{$product->id}/boms/published");
        $this->assertSame($publishedBefore->id, $response->json('data.id'), '未发布版本被拒绝后 published 版本不变');
    }

    public function test_disabled_user_token_invalid_immediately(): void
    {
        // 工程师登录拿 token
        $engineerToken = $this->loginToken('E001');
        $this->api($engineerToken)->getJson('/api/auth/me')->assertOk();

        // 管理员停用 E001
        User::where('employee_no', 'E001')->update(['status' => 0]);

        // 停用后旧 token 必须立即失效（AuthenticateApi 停用拦截）
        $response = $this->api($engineerToken)->getJson('/api/auth/me');
        $this->assertContains($response->status(), [401, 403], '停用用户旧 token 应失效');
        $this->assertStringStartsWith('账号已停用', (string) $response->json('message'));
    }

    public function test_disabled_user_token_cannot_write(): void
    {
        $engineerToken = $this->loginToken('E001');
        User::where('employee_no', 'E001')->update(['status' => 0]);

        // 停用后连写操作也被拒（不只读接口）
        $response = $this->api($engineerToken)->postJson('/api/plm/machines', [
            'code' => 'I-99', 'name' => '不应创建', 'type' => 'injection',
            'workshop_id' => Workshop::where('code', 'injection')->firstOrFail()->id,
            'capacity_per_hour' => 100, 'mold_change_min' => 10, 'shift_mode' => 'two_shift', 'status' => 'running',
        ]);

        $this->assertContains($response->status(), [401, 403], '停用用户写操作应被拒');
        $this->assertDatabaseMissing('machines', ['code' => 'I-99']);
    }

    public function test_login_error_message_does_not_leak_account_existence(): void
    {
        // 账号存在但密码错
        $wrongPwd = $this->postJson('/api/auth/login', ['employee_no' => 'E001', 'password' => 'wrong-pass']);
        // 账号不存在
        $noAccount = $this->postJson('/api/auth/login', ['employee_no' => 'NO-SUCH-USER', 'password' => 'wrong-pass']);

        $wrongPwd->assertStatus(401);
        $noAccount->assertStatus(401);

        // 两者 message 必须一致，不泄漏"账号是否存在"
        $this->assertSame(
            $wrongPwd->json('message'),
            $noAccount->json('message'),
            '登录失败提示不应区分账号不存在与密码错误'
        );
    }

    public function test_repeated_publish_same_bom_is_idempotent(): void
    {
        // 同产品唯一已发布版本规则：先退回既有 published（P-0001 V2.0），再发布 draft
        $published = \App\Models\Bom::where('status', 'published')->first();
        if ($published) {
            $this->api($this->adminToken)->postJson("/api/plm/boms/{$published->id}/revoke")->assertOk();
        }

        $bom = \App\Models\Bom::where('status', 'draft')->firstOrFail();
        $this->api($this->adminToken)->postJson("/api/plm/boms/{$bom->id}/publish")->assertOk();

        $logsAfterFirst = AuditLog::where('action', 'publish_bom')->where('target_id', $bom->id)->count();
        $this->assertSame(1, $logsAfterFirst, '首次发布应写 1 条审计');

        // 重复发布：应幂等成功，且不重复写审计
        $this->api($this->adminToken)->postJson("/api/plm/boms/{$bom->id}/publish")->assertOk();
        $logsAfterSecond = AuditLog::where('action', 'publish_bom')->where('target_id', $bom->id)->count();
        $this->assertSame($logsAfterFirst, $logsAfterSecond, '重复发布不应产生重复审计');
    }

    public function test_repeated_create_same_product_code_rejected(): void
    {
        // 已有 P-0001，重复创建同编码
        $response = $this->api($this->adminToken)->postJson('/api/plm/products', [
            'code' => 'P-0001',
            'name' => '重复产品',
        ]);

        $this->assertContains($response->status(), [409, 422], '重复编码创建应被拒绝');
        $this->assertSame(1, Product::where('code', 'P-0001')->count(), '不得产生重复产品');
    }
}
