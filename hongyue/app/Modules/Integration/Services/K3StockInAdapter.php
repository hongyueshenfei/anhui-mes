<?php

namespace App\Modules\Integration\Services;

use App\Models\StockIn;

/**
 * 生产入库单 → 金蝶 K3 报文适配（架构 §5.1 K2 生产入库单推送契约）
 *
 * 字段映射：
 * - FEntity.FMaterialId ← product.code（物料编码）
 * - FEntity.FRealQty   ← stock_in.qty（实收数量）
 * - FEntity.FSrcBillNo ← 源单号（任务对应生产订单号，无则用任务号）—— 四期拉单后回填 k3_bill_no
 * - FStockId           ← config k3.stock_id（默认仓库）
 * - FDate              ← 单据日期（今天）
 * - FBillTypeId        ← config k3.bill_type_id（生产入库单类型）
 * - FCreatorId         ← config k3.creator_id（创建人）
 * - FNote              ← 备注（ins_no 标识，便于对账）
 */
class K3StockInAdapter
{
    /**
     * 组装 K3 Save 报文（金蝶动态表单格式）
     *
     * @return array{formid: string, model: array}
     */
    public function adapt(StockIn $stockIn): array
    {
        $productCode = $stockIn->task?->product?->code ?? '';
        $srcBillNo = $stockIn->task?->line?->order?->k3_bill_no
            ?? $stockIn->task?->line?->order?->order_no
            ?? $stockIn->task?->task_no
            ?? '';

        return [
            'formid' => 'STK_MISCELLANEOUS',
            'model' => [
                'FBillTypeID' => ['FNumber' => (string) config('k3.bill_type_id')],
                'FStockId' => ['FNumber' => (string) config('k3.stock_id')],
                'FDate' => now()->format('Y-m-d'),
                'FCreatorId' => ['FUserID' => (int) config('k3.creator_id', 0)],
                'FNote' => sprintf('虹越入库单 %s', $stockIn->ins_no),
                'FEntity' => [
                    [
                        'FMaterialId' => ['FNumber' => $productCode],
                        'FRealQty' => (float) $stockIn->qty,
                        'FSrcBillNo' => $srcBillNo,
                    ],
                ],
            ],
        ];
    }
}