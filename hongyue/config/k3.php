<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 金蝶云星空（K3Cloud）连接配置
    |--------------------------------------------------------------------------
    | 真实联调：填写账套/账号/密码，静态表单 ID（生产入库单），默认仓库/单据类型。
    | 沙箱联调：base_url 指向 scripts/k3-mock-server.php 起的模拟端点（见 README 标注），
    | mock=true 时跳过登录直接 Save（模拟端点不校验 Cookie）。
    */
    'base_url' => env('K3_BASE_URL', 'http://127.0.0.1:8002'),
    'acct_id' => env('K3_ACCT_ID', '5f4f2e2e5a3b20018d2583e5'),
    'username' => env('K3_USERNAME', 'demo'),
    'password' => env('K3_PASSWORD', ''),
    'stock_id' => env('K3_STOCK_ID', '100080'),
    'bill_type_id' => env('K3_BILL_TYPE_ID', 'SCM000018'),
    'creator_id' => env('K3_CREATOR_ID', '48'),
    'mock' => env('K3_MOCK', true),
    'login_path' => '/K3Cloud/Kingdee.BOS.WebApi.ServicesStub.AuthService.Login',
    'save_path' => '/K3Cloud/Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Save',
    'query_path' => '/K3Cloud/Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.ExecuteBillQuery',
    'timeout' => 10,
    /*
    |--------------------------------------------------------------------------
    | 生产订单拉取游标（四期）
    |--------------------------------------------------------------------------
    | 增量游标 = 金蝶分录创建时间（FCreatorTime，格式 YYYY-MM-DD HH:mm:ss），
    | 以「游标时间 + 单据号」双键去重（同一分钟多单不会漏）。mock_orders 为演示/CI 数据源。
    */
    'pull_cursor_key' => 'k3.pull_prod_order_cursor',
    'mock_orders' => [
        [
            'FBillNo' => 'MO-MOCK-0001',
            'FCreatorTime' => '2026-01-01 09:00:00',
            'FDocumentStatus' => 'A',
            'FCustName' => '金蝶同步·演示客户',
            'FEntity' => [
                ['FMaterialId' => ['FNumber' => 'P-0001'], 'FQty' => 1200, 'FPlanFinishDate' => '2026-02-01'],
                ['FMaterialId' => ['FNumber' => 'P-0002'], 'FQty' => 800, 'FPlanFinishDate' => '2026-02-05'],
            ],
        ],
        [
            'FBillNo' => 'MO-MOCK-0002',
            'FCreatorTime' => '2026-01-02 10:30:00',
            'FDocumentStatus' => 'A',
            'FCustName' => '金蝶同步·华东客户',
            'FEntity' => [
                ['FMaterialId' => ['FNumber' => 'P-0003'], 'FQty' => 600, 'FPlanFinishDate' => '2026-02-10'],
            ],
        ],
        [
            'FBillNo' => 'MO-MOCK-0003',
            'FCreatorTime' => '2026-01-03 14:00:00',
            'FDocumentStatus' => 'A',
            'FCustName' => '金蝶同步·华南客户',
            'FEntity' => [
                ['FMaterialId' => ['FNumber' => 'P-0009'], 'FQty' => 400, 'FPlanFinishDate' => '2026-02-12'],
            ],
        ],
    ],
];