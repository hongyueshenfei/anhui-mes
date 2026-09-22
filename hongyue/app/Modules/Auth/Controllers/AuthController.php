<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Requests\BindEmployeeRequest;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Services\WecomAuthService;
use App\Modules\Auth\Services\WxAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 认证控制器
 *
 * 通道优先级（架构 §5.3）：企微 OAuth（优先）→ 微信扫码（H5）→ 账号密码（PC 回退）
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly WecomAuthService $wecomAuthService,
        private readonly WxAuthService $wxAuthService,
    ) {
    }

    /**
     * 账号密码登录（PC 回退通道）
     * 请求体：{employee_no, password, device?}
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::verifyPassword($request->input('employee_no'), $request->input('password'));

        if (! $user) {
            return $this->fail('工号或密码错误', 401, 401);
        }

        if (! $user->isActive()) {
            return $this->fail('账号已停用，请联系管理员', 403, 403);
        }

        return $this->ok($this->issueToken($user, $request), '登录成功');
    }

    /**
     * 企微扫码登录（一期骨架）
     *
     * 请求体：{code}（企微 OAuth2 授权码）
     * 真实环境：WecomAuthService::exchangeCodeForUserid() 调企微接口换取 userid 后匹配；
     * 未配置企微（corp_id 为空）时返回 501，前端应回退账号密码登录。
     */
    public function wecomLogin(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        try {
            $user = $this->wecomAuthService->loginByCode($request->input('code'));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 501, 501);
        }

        if ($user === null) {
            // 企微账号未绑定平台员工：返回 need_bind，携带通道标识供前端发起绑工号
            return response()->json([
                'code' => 0,
                'message' => '企微账号未绑定员工，请先绑定工号',
                'data' => [
                    'need_bind' => true,
                    'channel' => 'wecom',
                    'code' => $request->input('code'),
                ],
            ]);
        }

        return $this->ok($this->issueToken($user, $request), '企微登录成功');
    }

    /**
     * 微信扫码登录（H5 车间端）
     * 请求体：{openid}（小程序/公众号扫码获取）
     * 未绑定返回 need_bind，前端跳转绑工号页。
     */
    public function wxLogin(Request $request): JsonResponse
    {
        $request->validate(['openid' => ['required', 'string']]);

        $user = $this->wxAuthService->loginByOpenid($request->input('openid'));

        if ($user === null) {
            return response()->json([
                'code' => 0,
                'message' => '微信未绑定员工，请先绑定工号',
                'data' => [
                    'need_bind' => true,
                    'channel' => 'wx',
                    'openid' => $request->input('openid'),
                ],
            ]);
        }

        return $this->ok($this->issueToken($user, $request), '微信登录成功');
    }

    /**
     * 绑定工号（企微/微信首次扫码后）
     * 请求体：{channel: wecom|wx, code|openid, employee_no, password?}
     * password 可选：有则同时开通账号密码登录，无则仅免密通道。
     */
    public function bindEmployee(BindEmployeeRequest $request): JsonResponse
    {
        $channel = $request->input('channel');
        $employeeNo = $request->input('employee_no');

        $user = User::where('employee_no', $employeeNo)->first();
        if (! $user) {
            return $this->fail('工号不存在，请联系管理员创建账号', 404, 404);
        }

        // 设置绑定字段（防重复绑定覆盖：仅当目标字段为空时写入）
        if ($channel === 'wecom') {
            if ($user->wecom_userid !== null) {
                return $this->fail('该员工已绑定企微账号', 422, 422);
            }
            // 骨架实现：直接以 code 作为绑定标识；真实环境应先用 code 换取企微 userid 再写入：
            // $user->wecom_userid = $this->wecomAuthService->exchangeCodeForUserid($request->input('code'));
            $user->wecom_userid = $request->input('code');
        } elseif ($channel === 'wx') {
            if ($user->wx_openid !== null) {
                return $this->fail('该员工已绑定微信', 422, 422);
            }
            $user->wx_openid = $request->input('openid');
        } else {
            return $this->fail('channel 仅支持 wecom / wx', 422, 422);
        }

        // 可选：开通账号密码
        if ($request->filled('password')) {
            $user->password_hash = \Illuminate\Support\Facades\Hash::make($request->input('password'));
        }

        $user->save();

        Log::info('员工绑定完成', ['employee_no' => $employeeNo, 'channel' => $channel]);

        return $this->ok($this->issueToken($user, $request), '绑定成功，已自动登录');
    }

    /**
     * 当前用户信息（含角色与权限，前端菜单/按钮控制）
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'id' => $user->id,
            'employee_no' => $user->employee_no,
            'name' => $user->name,
            'phone' => $user->phone,
            'job_type' => $user->job_type,
            'workshop' => $user->workshop ? ['id' => $user->workshop->id, 'name' => $user->workshop->name] : null,
            'wecom_bound' => $user->wecom_userid !== null,
            'wx_bound' => $user->wx_openid !== null,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    /**
     * 登出（吊销当前 Token）
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // 显式按 Token 哈希删除（不依赖 Sanctum 内部 accessToken 属性）
        // Sanctum Token 形如 "id|plainText"，库中存 sha256(plainText)
        $bearerToken = $request->bearerToken();
        if ($bearerToken) {
            $parts = explode('|', $bearerToken, 2);
            $tokenHash = count($parts) === 2 ? hash('sha256', $parts[1]) : hash('sha256', $bearerToken);
            $user?->tokens()->where('token', $tokenHash)->delete();
        }

        return $this->ok(null, '已退出登录');
    }

    /**
     * 签发 Sanctum Token 并组装登录响应
     */
    private function issueToken(User $user, Request $request): array
    {
        $device = $request->input('device', 'pc');
        $token = $user->createToken($device, ['*']);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => config('sanctum.expiration') * 60,
            'user' => [
                'id' => $user->id,
                'employee_no' => $user->employee_no,
                'name' => $user->name,
                'job_type' => $user->job_type,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
        ];
    }
}