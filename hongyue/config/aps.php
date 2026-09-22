<?php

/**
 * APS 一期排程增强配置
 *
 * 注意：班历（08:00-20:00 等）一律读 system_configs（ConfigService::shiftPeriods），
 * 不得写进本配置或代码常量（2 班制，架构约定）。
 */
return [
    // 冻结天数：当前起未来 N 天内的排程不可直接解锁重排，窗口外可解锁后调整
    'freeze_days' => env('APS_FREEZE_DAYS', 3),

    // 偏差阈值%：报工偏差超此值生成顺延建议（对齐看板红灯「落后>10%」口径）
    'deviation_threshold_pct' => env('APS_DEVIATION_THRESHOLD', 10),

    // 倒排能力开关（三期接入：交期倒推，失败降级顺排）
    'backward_enabled' => env('APS_BACKWARD_ENABLED', true),
];
