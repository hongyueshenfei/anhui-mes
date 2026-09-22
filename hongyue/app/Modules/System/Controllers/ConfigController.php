<?php

namespace App\Modules\System\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 系统配置控制器（班制/看板阈值/集成参数等）
 */
class ConfigController extends Controller
{
    /**
     * 配置列表（config_value 脱敏：含 password/secret 的键仅显示掩码）
     */
    public function index(): JsonResponse
    {
        $configs = SystemConfig::orderBy('config_key')->get()->map(function (SystemConfig $config) {
            $value = $config->config_value;

            // 敏感配置脱敏
            if (str_contains($config->config_key, 'secret') || str_contains($config->config_key, 'password')) {
                $value = '***';
            }

            return [
                'id' => $config->id,
                'config_key' => $config->config_key,
                'config_value' => $value,
                'remark' => $config->remark,
                'updated_at' => $config->updated_at?->toDateTimeString(),
            ];
        });

        return $this->ok($configs);
    }

    /**
     * 新增/更新配置（updateOrCreate）
     * 请求体：{config_key, config_value(JSON), remark?}
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'config_key' => ['required', 'string', 'max:64'],
            'config_value' => ['required'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $config = SystemConfig::updateOrCreate(
            ['config_key' => $request->input('config_key')],
            [
                'config_value' => $request->input('config_value'),
                'remark' => $request->input('remark', null),
            ]
        );

        return $this->ok(['id' => $config->id, 'config_key' => $config->config_key], '配置保存成功');
    }
}