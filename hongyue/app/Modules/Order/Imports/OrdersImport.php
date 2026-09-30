<?php

namespace App\Modules\Order\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

/**
 * 订单 Excel/CSV 导入（首行为表头）
 *
 * 支持的列（中文或英文表头均可）：
 * | 产品编码(product_code) | 数量(qty) | 交期(delivery_date) | 客户(customer_name) | 优先级(priority, 0-5，P0最紧急) | 备注(remark) |
 */
class OrdersImport implements ToCollection, WithHeadingRow, WithStartRow, WithCustomCsvSettings
{
    /** @var Collection<int, array> 原始行数据（含表头映射） */
    public Collection $rows;

    public function __construct()
    {
        $this->rows = collect();
    }

    public function headingRow(): int
    {
        return 1;
    }

    public function startRow(): int
    {
        return 2;
    }

    /**
     * CSV 读取设置：显式 UTF-8（中文表头必须，避免编码误判导致列名丢失）
     */
    public function getCsvSettings(): array
    {
        return [
            'input_encoding' => 'UTF-8',
            'delimiter' => ',',
            'enclosure' => '"',
        ];
    }

    public function collection(Collection $rows): void
    {
        $this->rows = $rows->values();
    }

    /**
     * 表头别名 → 标准字段
     */
    public static function normalizeHeader(array $header): array
    {
        $map = [
            '产品编码' => 'product_code', 'product_code' => 'product_code', '产品编号' => 'product_code', '物料编码' => 'product_code',
            '数量' => 'qty', 'qty' => 'qty', '数量(件)' => 'qty', '订货数量' => 'qty',
            '交期' => 'delivery_date', 'delivery_date' => 'delivery_date', '交货日期' => 'delivery_date', '交期日期' => 'delivery_date',
            '客户' => 'customer_name', 'customer_name' => 'customer_name', '客户名称' => 'customer_name',
            '优先级' => 'priority', 'priority' => 'priority', '紧急度' => 'priority',
            '备注' => 'remark', 'remark' => 'remark',
        ];

        $normalized = [];
        foreach ($header as $key) {
            $key = trim((string) $key);
            $normalized[] = $map[$key] ?? strtolower($key);
        }

        return $normalized;
    }
}
