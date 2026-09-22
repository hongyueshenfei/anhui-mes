<?php

namespace Database\Seeders;

use App\Models\Machine;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 车间 / 机台 / 业务用户 示例数据（班组概念已下线）
 *
 * - 车间：注塑 / 吹塑 / 喷漆 / 效果 / 包装（5 个，workshops 表）
 * - 机台：I-01~I-04（注塑）、B-01~B-02（吹塑），全部挂 workshop_id
 * - 产线：P-01 喷漆线、P-02 效果线、P-03 包装线，分别挂对应 workshop_id
 * - 用户：工人/班组长按车间归属（workshop_id），统计员为全厂报工岗
 */
class WorkshopSeeder extends Seeder
{
    public function run(): void
    {
        // ---------- 车间 5 个（车间实体化：先有车间，车间下面是机台/产线） ----------
        $workshopCodes = [
            Workshop::CODE_INJECTION,
            Workshop::CODE_BLOW,
            Workshop::CODE_PAINT,
            Workshop::CODE_EFFECT,
            Workshop::CODE_PACK,
        ];
        $workshopIds = [];
        foreach ($workshopCodes as $i => $code) {
            $ws = Workshop::updateOrCreate(
                ['code' => $code],
                ['name' => Workshop::NAME_LABELS[$code], 'sort' => $i + 1, 'is_active' => 1, 'remark' => null],
            );
            $workshopIds[$code] = $ws->id;
        }

        // ---------- 机台 6 台 + 产线 3 条（挂 workshop_id） ----------
        $machines = [
            ['code' => 'I-01', 'name' => '注塑机 1 号', 'type' => Machine::TYPE_INJECTION, 'capacity_per_hour' => 400, 'mold_change_min' => 30, 'tonnage' => 280, 'shift_mode' => 'two_shift', 'status' => 'running', 'is_active' => 1],
            ['code' => 'I-02', 'name' => '注塑机 2 号', 'type' => Machine::TYPE_INJECTION, 'capacity_per_hour' => 400, 'mold_change_min' => 30, 'tonnage' => 280, 'shift_mode' => 'two_shift', 'status' => 'running', 'is_active' => 1],
            ['code' => 'I-03', 'name' => '注塑机 3 号', 'type' => Machine::TYPE_INJECTION, 'capacity_per_hour' => 350, 'mold_change_min' => 35, 'tonnage' => 200, 'shift_mode' => 'two_shift', 'status' => 'running', 'is_active' => 1],
            ['code' => 'I-04', 'name' => '注塑机 4 号', 'type' => Machine::TYPE_INJECTION, 'capacity_per_hour' => 350, 'mold_change_min' => 35, 'tonnage' => 200, 'shift_mode' => 'two_shift', 'status' => 'maintenance', 'is_active' => 1],
            ['code' => 'B-01', 'name' => '吹塑机 1 号', 'type' => Machine::TYPE_BLOW, 'capacity_per_hour' => 300, 'mold_change_min' => 20, 'tonnage' => 120, 'shift_mode' => 'two_shift', 'status' => 'running', 'is_active' => 1],
            ['code' => 'B-02', 'name' => '吹塑机 2 号', 'type' => Machine::TYPE_BLOW, 'capacity_per_hour' => 300, 'mold_change_min' => 20, 'tonnage' => 120, 'shift_mode' => 'two_shift', 'status' => 'stopped', 'is_active' => 0],
            ['code' => 'P-01', 'name' => '喷漆线', 'type' => Machine::TYPE_PAINT_LINE, 'capacity_per_hour' => 1500, 'mold_change_min' => 0, 'shift_mode' => 'day', 'status' => 'running', 'is_active' => 1],
            ['code' => 'P-02', 'name' => '效果线', 'type' => Machine::TYPE_EFFECT_LINE, 'capacity_per_hour' => 1500, 'mold_change_min' => 0, 'shift_mode' => 'day', 'status' => 'running', 'is_active' => 1],
            ['code' => 'P-03', 'name' => '包装线', 'type' => Machine::TYPE_PACK_LINE, 'capacity_per_hour' => 3000, 'mold_change_min' => 0, 'shift_mode' => 'day', 'status' => 'running', 'is_active' => 1],
        ];

        $machineModels = [];
        foreach ($machines as $item) {
            $workshopCode = match (true) {
                str_starts_with($item['code'], 'I-') => Workshop::CODE_INJECTION,
                str_starts_with($item['code'], 'B-') => Workshop::CODE_BLOW,
                $item['code'] === 'P-01' => Workshop::CODE_PAINT,
                $item['code'] === 'P-02' => Workshop::CODE_EFFECT,
                $item['code'] === 'P-03' => Workshop::CODE_PACK,
                default => null,
            };
            $workshopId = $workshopIds[$workshopCode] ?? null;
            // updateOrCreate 用于修正存量演示/开发库中 P-02 曾被误标为包装线的记录。
            $machineModels[$item['code']] = Machine::updateOrCreate(
                ['code' => $item['code']],
                array_merge($item, ['workshop_id' => $workshopId]),
            );
        }

        // ---------- 业务用户（各角色示例，工人/班组长按车间归属） ----------
        $users = [
            ['employee_no' => 'E001', 'name' => '张工', 'phone' => '13900000001', 'job_type' => User::JOB_ENGINEER, 'role' => 'engineer', 'workshop' => null, 'wecom_userid' => 'wecom_zhang'],
            ['employee_no' => 'P001', 'name' => '李计划', 'phone' => '13900000002', 'job_type' => User::JOB_PLANNER, 'role' => 'planner', 'workshop' => null, 'wecom_userid' => 'wecom_li'],
            ['employee_no' => 'L001', 'name' => '王班长', 'phone' => '13900000003', 'job_type' => User::JOB_LEADER, 'role' => 'leader', 'workshop' => 'injection', 'wecom_userid' => 'wecom_wang'],
            ['employee_no' => 'T001', 'name' => '陈统计', 'phone' => '13900000013', 'job_type' => User::JOB_STATISTICIAN, 'role' => 'statistician', 'workshop' => null, 'wecom_userid' => 'wecom_statistician'],
            ['employee_no' => 'W001', 'name' => '赵师傅', 'phone' => '13900000004', 'job_type' => User::JOB_WORKER, 'role' => 'worker', 'workshop' => 'injection', 'wecom_userid' => 'wecom_zhao'],
            ['employee_no' => 'W002', 'name' => '钱师傅', 'phone' => '13900000005', 'job_type' => User::JOB_WORKER, 'role' => 'worker', 'workshop' => 'injection', 'wecom_userid' => 'wecom_qian'],
            ['employee_no' => 'S001', 'name' => '孙业务', 'phone' => '13900000006', 'job_type' => User::JOB_SALES, 'role' => 'sales', 'workshop' => null, 'wecom_userid' => 'wecom_sun'],
            ['employee_no' => 'W003', 'name' => '周喷涂', 'phone' => '13900000007', 'job_type' => User::JOB_WORKER, 'role' => 'worker', 'workshop' => 'paint', 'wecom_userid' => null],
            ['employee_no' => 'W006', 'name' => '郑效果', 'phone' => '13900000010', 'job_type' => User::JOB_WORKER, 'role' => 'worker', 'workshop' => 'effect', 'wecom_userid' => null],
            ['employee_no' => 'W004', 'name' => '吴包装', 'phone' => '13900000008', 'job_type' => User::JOB_WORKER, 'role' => 'worker', 'workshop' => 'pack', 'wecom_userid' => null],
        ];

        foreach ($users as $item) {
            $user = User::firstOrCreate(
                ['employee_no' => $item['employee_no']],
                [
                    'name' => $item['name'],
                    'phone' => $item['phone'],
                    'password_hash' => Hash::make('123456'),
                    'job_type' => $item['job_type'],
                    'workshop_id' => $item['workshop'] ? $workshopIds[$item['workshop']] ?? null : null,
                    'wecom_userid' => $item['wecom_userid'],
                    'status' => 1,
                ]
            );
            $user->syncRoles([$item['role']]);
        }
    }
}
