<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 订单 Excel/CSV 导入测试（二期验收 B1）
 * - 合法行建单（含生产任务）
 * - 编码不匹配行明确报错（行号 + 编码 + 原因），不阻塞合法行
 * - 全非法 → 422；数量非法单行报错
 */
class OrderImportTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /**
     * 生成 CSV 文件（不含 BOM，避免表头解析歧义）
     */
    private function csvFile(array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hy_import_');
        file_put_contents($path, implode("\n", $lines)."\n");

        return new UploadedFile($path, 'orders.csv', 'text/csv', null, true);
    }

    public function test_import_valid_rows_creates_order(): void
    {
        $file = $this->csvFile([
            '产品编码,数量,交期,客户,优先级',
            'P-0001,1900,2025-08-31,导入客户A,0',
            'P-0002,2000,2025-09-05,导入客户A,4',
        ]);

        $response = $this->api($this->token)->post('/api/orders/import-excel', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.skipped_count', 0)
            ->assertJsonPath('data.order.src', 'excel');

        $orderId = $response->json('data.order.id');
        $this->assertDatabaseHas('orders', ['id' => $orderId]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'priority' => 0]);
        $this->assertDatabaseCount('order_lines', 2);
        $this->assertDatabaseCount('prod_tasks', 2);
    }

    public function test_import_reports_bad_product_code_rows(): void
    {
        $file = $this->csvFile([
            '产品编码,数量,交期,客户,优先级',
            'P-0001,1900,2025-08-31,导入客户B,3',
            'NO-SUCH-999,500,2025-08-10,导入客户B,3',
            'P-0003,300,2025-08-12,导入客户B,4',
        ]);

        $response = $this->api($this->token)->post('/api/orders/import-excel', ['file' => $file]);

        // 207：部分成功（2 行导入，1 行跳过并明确报错）
        $response->assertStatus(207)
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.error_rows.0.row', 3) // NO-SUCH-999 位于文件第 3 行
            ->assertJsonPath('data.error_rows.0.product_code', 'NO-SUCH-999');

        $this->assertStringContainsString('不存在', $response->json('data.error_rows.0.error'));
        $this->assertDatabaseCount('order_lines', 2);
    }

    public function test_import_all_invalid_rejected(): void
    {
        $file = $this->csvFile([
            'product_code,qty,delivery_date',
            'NOPE-1,100,2025-08-10',
            'NOPE-2,200,2025-08-10',
        ]);

        $response = $this->api($this->token)->post('/api/orders/import-excel', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonCount(2, 'data.error_rows');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_import_invalid_qty_row_reported(): void
    {
        $file = $this->csvFile([
            '产品编码,数量,交期',
            'P-0001,0,2025-08-31',
        ]);

        $response = $this->api($this->token)->post('/api/orders/import-excel', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('data.error_rows.0.error', '数量必须为正整数');
    }

    public function test_import_invalid_date_row_reported(): void
    {
        $file = $this->csvFile([
            '产品编码,数量,交期',
            'P-0001,100,not-a-date',
        ]);

        $response = $this->api($this->token)->post('/api/orders/import-excel', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('data.error_rows.0.row', 2);
        $this->assertStringContainsString('交期', $response->json('data.error_rows.0.error'));
    }

    public function test_import_empty_file_rejected(): void
    {
        $response = $this->api($this->token)->post('/api/orders/import-excel', ['file' => null]);

        $response->assertStatus(422);
    }

    public function test_import_template_download(): void
    {
        $response = $this->api($this->token)->get('/api/orders/import-template');

        $response->assertOk();
        $this->assertStringContainsString('产品编码', $response->streamedContent());
    }
}
