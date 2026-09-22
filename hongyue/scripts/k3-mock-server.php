<?php

/**
 * 金蝶 K3Cloud 模拟端点（沙箱/CI 驱动集成测试用）
 *
 * 用法：php -S 127.0.0.1:8002 scripts/k3-mock-server.php
 *       （Laravel 侧 config/k3.php：base_url=http://127.0.0.1:8002，mock=true 跳过登录）
 *
 * 支持：
 * - POST /K3Cloud/.../AuthService.Login        → 返回空（mock 模式不校验 Cookie）
 * - POST /K3Cloud/.../DynamicFormService.Save  → 成功回执（Id/billNo）
 * - 可配置失败：环境变量 K3_MOCK_FAIL=1 时 Save 返回业务失败（ResponseStatus.IsSuccess=false）
 *   或 K3_MOCK_HTTP_FAIL=1 时返回 HTTP 500（触发重试）
 *
 * 真实联调：无需本文件，配置 config/k3.php 指向客户金蝶 WebAPI 地址即可（联调点见 README）。
 */

// 统一 JSON 响应头
header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$failMode = getenv('K3_MOCK_FAIL');
$httpFailMode = getenv('K3_MOCK_HTTP_FAIL');

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 登录：返回会话 Cookie（P1-2：真实 HTTP 模式下 K3Client::sessionCookie() 依赖 Set-Cookie，
// 缺少会抛"K3 登录未返回会话 Cookie"。沙箱 mock server 必须模拟金蝶返回 kdservice-sessionid）
if (str_contains($path, 'AuthService.Login')) {
    $sessionId = 'mock-session-'.bin2hex(random_bytes(8));
    header('Set-Cookie: kdservice-sessionid='.$sessionId.'; Path=/');
    echo json_encode(['status' => 'ok']);
    exit;
}

// Save：生产入库单推送
if (str_contains($path, 'DynamicFormService.Save')) {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

    if ($httpFailMode) {
        http_response_code(500);
        echo json_encode(['error' => 'internal server error (mock)']);
        exit;
    }

    if ($failMode) {
        echo json_encode([
            'Result' => [
                'ResponseStatus' => [
                    'IsSuccess' => false,
                    'Errors' => [['Message' => 'mock: K3 业务校验失败（K3_MOCK_FAIL=1）']],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 成功回执（billNo 取自单据号窗口：FNote 中"入库单 RK-xxx"或模型 FNumber）
    $note = $body['model']['FNote'] ?? '';
    $billNo = 'K3-IN-'.date('Ymd').'-'.substr(md5((string) $note), 0, 6);

    echo json_encode([
        'Result' => [
            'ResponseStatus' => [
                'IsSuccess' => true,
                'Errors' => [],
                'SuccessMessages' => [],
                'MessageCode' => 'SUCCESS',
            ],
            'Id' => random_int(1000000, 9999999),
            'Number' => $billNo,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ExecuteBillQuery：生产订单拉取 / 物料同步（四期）
if (str_contains($path, 'DynamicFormService.ExecuteBillQuery')) {
    if ($httpFailMode) {
        http_response_code(500);
        echo json_encode(['error' => 'internal server error (mock)']);
        exit;
    }

    // 可配置：K3_MOCK_ORDERS 为 JSON 数组（金蝶生产订单行），否则用内置样例
    $ordersEnv = getenv('K3_MOCK_ORDERS');
    $orders = $ordersEnv !== false && $ordersEnv !== '' ? json_decode($ordersEnv, true) : [
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
    ];

    echo json_encode(['Result' => $orders], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode(['error' => "mock: unknown path {$path}"], JSON_UNESCAPED_UNICODE);