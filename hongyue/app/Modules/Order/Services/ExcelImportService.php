<?php

namespace App\Modules\Order\Services;

use App\Models\Product;
use App\Modules\Order\Imports\OrdersImport;
use Illuminate\Support\Arr;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 订单 Excel 导入服务
 *
 * 流程：解析 → 逐行校验（产品编码唯一匹配 / 数量正整数 / 交期格式）→ 错误行收集 →
 * 合法行批量建单（含生产任务）。错误行明确反馈（行号 + 编码 + 原因），不阻塞合法行导入。
 */
class ExcelImportService
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * 导入订单文件
     *
     * @param  string|null  $readerType  Maatwebsite\Excel\Excel::CSV / XLSX / XLS / ODS；null=按扩展名推断
     * @return array{success: bool, order: ?\App\Models\Order, imported_count: int, skipped_count: int, error_rows: array}
     */
    public function import(string $filePath, ?int $userId = null, ?string $readerType = null): array
    {
        if (! is_file($filePath)) {
            throw new \InvalidArgumentException('导入文件不存在：'.$filePath);
        }

        // 按扩展名推断读取器（避免 tempnam 无扩展名时无法自动检测类型）
        if ($readerType === null) {
            $readerType = $this->inferReaderType($filePath);
        }

        $import = new OrdersImport();
        Excel::import($import, $filePath, null, $readerType);

        $importedRows = [];
        $errorRows = [];

        foreach ($import->rows as $index => $rawRow) {
            $rowNo = $index + 2; // 表头占 1 行，Excel 行号 = index + 2
            $row = $this->normalizeRow($rawRow);

            $error = $this->validateRow($row);
            if ($error !== null) {
                $errorRows[] = [
                    'row' => $rowNo,
                    'product_code' => $row['product_code'] ?? '',
                    'error' => $error,
                ];
                continue;
            }

            $importedRows[] = $row;
        }

        if ($importedRows === []) {
            return [
                'success' => false,
                'order' => null,
                'imported_count' => 0,
                'skipped_count' => count($errorRows),
                'error_rows' => $errorRows,
            ];
        }

        $order = $this->orderService->createOrderFromRows($importedRows, \App\Models\Order::SRC_EXCEL, $userId);

        return [
            'success' => true,
            'order' => $order,
            'imported_count' => count($importedRows),
            'skipped_count' => count($errorRows),
            'error_rows' => $errorRows,
        ];
    }

    /**
     * 按文件扩展名推断 Maatwebsite 读取器类型
     */
    private function inferReaderType(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'xlsx' => \Maatwebsite\Excel\Excel::XLSX,
            'xls' => \Maatwebsite\Excel\Excel::XLS,
            'ods' => \Maatwebsite\Excel\Excel::ODS,
            default => \Maatwebsite\Excel\Excel::CSV,
        };
    }

    /**
     * 表头别名标准化（中文表头 → 标准字段）
     */
    private function normalizeRow($rawRow): array
    {
        if ($rawRow instanceof \Illuminate\Support\Collection) {
            $rawRow = $rawRow->toArray();
        }

        // Maatwebsite 以原始表头为键；先按标准字段取，再按中文别名兜底
        $aliases = [
            'product_code' => ['产品编码', '产品编号', '物料编码'],
            'qty' => ['数量', '数量(件)', '订货数量'],
            'delivery_date' => ['交期', '交货日期', '交期日期'],
            'customer_name' => ['客户', '客户名称'],
            'priority' => ['优先级', '紧急度'],
            'remark' => ['备注'],
        ];

        $row = [];
        foreach ($aliases as $key => $names) {
            $value = $rawRow[$key] ?? null;
            if ($value === null) {
                foreach ($names as $name) {
                    if (array_key_exists($name, $rawRow)) {
                        $value = $rawRow[$name];
                        break;
                    }
                }
            }
            $row[$key] = $value;
        }

        return $row;
    }

    /**
     * 单行校验（引用传递：校验后回写规范化字段）；返回错误信息，null=合法
     */
    private function validateRow(array &$row): ?string
    {
        $code = trim((string) ($row['product_code'] ?? ''));
        if ($code === '') {
            return '产品编码为空';
        }

        $product = Product::where('code', $code)->first();
        if (! $product) {
            return '产品编码不存在（未在产品档案中维护）';
        }

        $qty = (int) ($row['qty'] ?? 0);
        if ($qty <= 0) {
            return '数量必须为正整数';
        }

        $delivery = $row['delivery_date'] ?? null;
        if ($delivery !== null && trim((string) $delivery) !== '') {
            try {
                $row['delivery_date'] = $this->parseDate((string) $delivery);
            } catch (\Throwable) {
                return '交期格式不正确（应为 YYYY-MM-DD）';
            }
        } else {
            $row['delivery_date'] = null;
        }

        $priority = $row['priority'] === null || trim((string) $row['priority']) === ''
            ? 3
            : (int) $row['priority'];
        if ($priority < 1 || $priority > 5) {
            return '优先级必须在 1-5 之间';
        }

        $row['product_code'] = $code;
        $row['qty'] = $qty;
        $row['priority'] = $priority;

        return null;
    }

    /**
     * 日期解析：兼容 Y-m-d / Y/m/d / Ymd / 日期序列号（Excel 数值）
     */
    private function parseDate(string $value): string
    {
        if (is_numeric($value)) {
            // Excel 日期序列号 → 1970-01-01 起算（1900 系统）
            return \Carbon\Carbon::createFromFormat('Y-m-d', '1899-12-30')->addDays((int) $value)->format('Y-m-d');
        }

        return \Carbon\Carbon::parse($value)->format('Y-m-d');
    }
}