<?php

/**
 * 报价中心配置（五期）
 */
return [
    // 工序费率兜底（route_op 未配置 rate_key 或费率表无生效价时）
    'machine_rate_default' => (float) env('QUOTE_MACHINE_RATE', 40),
    'team_rate_default' => (float) env('QUOTE_TEAM_RATE', 25),

    // 建议交期：每日可用机台小时（两班制 2×8=16）
    'daily_available_hours' => (float) env('QUOTE_DAILY_HOURS', 16),

    // 无法计算工时时的默认交期（天）
    'default_delivery_days' => (int) env('QUOTE_DEFAULT_DELIVERY_DAYS', 7),
];
