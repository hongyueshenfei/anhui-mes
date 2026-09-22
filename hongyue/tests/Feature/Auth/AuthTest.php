<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 认证通道测试（一期验收 A2：账号密码回退可用；企微骨架就绪）
 */
class AuthTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    public function test_admin_can_login_with_password(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'employee_no' => 'admin',
            'password' => '123456',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['roles', 'permissions']]]);
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'employee_no' => 'admin',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 401);
    }

    public function test_login_with_disabled_account_fails(): void
    {
        // 停用 E001 账号
        User::where('employee_no', 'E001')->update(['status' => 0]);

        $response = $this->postJson('/api/auth/login', [
            'employee_no' => 'E001',
            'password' => '123456',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 403);
    }

    public function test_me_returns_user_with_roles_and_permissions(): void
    {
        $token = $this->loginToken('E001');

        $response = $this->api($token)->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.employee_no', 'E001')
            ->assertJsonPath('data.roles.0', 'engineer');

        $permissions = $response->json('data.permissions');
        $this->assertContains('plm.product.manage', $permissions);
    }

    public function test_me_without_token_returns_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        $token = $this->loginToken();

        $this->api($token)->postJson('/api/auth/logout')->assertOk();

        // 旧 Token 已吊销
        $this->api($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_wecom_login_returns_501_when_not_configured(): void
    {
        // 测试环境未配置企微（.env.testing 为空），应返回 501 引导回退账号密码
        $response = $this->postJson('/api/auth/wecom-login', ['code' => 'test-code']);

        $response->assertStatus(501);
    }

    public function test_wx_login_with_unbound_openid_returns_need_bind(): void
    {
        $response = $this->postJson('/api/auth/wx-login', ['openid' => 'openid_not_bound_yet']);

        $response->assertOk()
            ->assertJsonPath('data.need_bind', true)
            ->assertJsonPath('data.channel', 'wx');
    }

    public function test_wx_bind_employee_then_login(): void
    {
        // 第一步：绑定工号（微信通道）
        $bind = $this->postJson('/api/auth/bind-employee', [
            'channel' => 'wx',
            'openid' => 'openid_abc_123',
            'employee_no' => 'W001',
        ]);

        $bind->assertOk()
            ->assertJsonStructure(['data' => ['token']]);

        // 第二步：二次扫码免密直登（验收 A3）
        $login = $this->postJson('/api/auth/wx-login', ['openid' => 'openid_abc_123']);

        $login->assertOk()
            ->assertJsonPath('data.user.employee_no', 'W001');
    }

    public function test_wx_bind_employee_rejects_unknown_employee_no(): void
    {
        $response = $this->postJson('/api/auth/bind-employee', [
            'channel' => 'wx',
            'openid' => 'openid_xyz',
            'employee_no' => 'NOPE-999',
        ]);

        $response->assertStatus(404);
    }
}