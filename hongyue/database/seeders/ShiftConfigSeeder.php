<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use Illuminate\Database\Seeder;

/**
 * 班制 / 业务默认配置（架构 §3.1 system_configs 示例）
 * - shift.two_shift：2 班 = 08:00-20:00 / 20:00-08:00（可配置）
 * - shift.day：白班 = 08:00-17:00
 */
class ShiftConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            [
                'config_key' => 'shift.two_shift',
                'config_value' => [
                    'shift1' => ['name' => '白班', 'start' => '08:00', 'end' => '20:00'],
                    'shift2' => ['name' => '夜班', 'start' => '20:00', 'end' => '08:00'],
                ],
                'remark' => '两班制时段（制造执行校准）',
            ],
            [
                'config_key' => 'shift.day',
                'config_value' => ['start' => '08:00', 'end' => '17:00'],
                'remark' => '白班制时段（人工线）',
            ],
            [
                'config_key' => 'redlight.deviation_pct',
                'config_value' => 10,
                'remark' => '红灯看板：进度落后超 10% 标红（四期启用）',
            ],
            [
                'config_key' => 'schedule.over_report_pct',
                'config_value' => 100,
                'remark' => '允许超报比例上限 %（三期报工校验）',
            ],
            [
                'config_key' => 'k3.poll_interval_min',
                'config_value' => 10,
                'remark' => '金蝶生产订单轮询间隔（四期启用）',
            ],
        ];

        foreach ($configs as $config) {
            SystemConfig::firstOrCreate(
                ['config_key' => $config['config_key']],
                ['config_value' => $config['config_value'], 'remark' => $config['remark']]
            );
        }
    }
}