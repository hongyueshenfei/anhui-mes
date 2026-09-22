<?php

namespace App\Modules\Integration\Services;

use App\Models\Bom;

/**
 * BOM 发布直发金蝶报文适配（架构 §5.1 K3 / 验收 D5）
 *
 * 字段映射：
 * - 成品物料 ← bom.product.code
 * - 父项物料 ← bom_lines.item_code
 * - 用量/损耗 ← bom_lines.qty / loss_rate
 * - 版本号     ← bom.version（写入 FNumber 便于对账）
 */
class K3BomPushAdapter
{
    /**
     * 组装 K3 Save 报文（金蝶动态表单格式）
     *
     * @return array{formid: string, model: array}
     */
    public function adapt(Bom $bom): array
    {
        $productCode = $bom->product?->code ?? '';

        $entity = $bom->lines()->orderBy('seq')->get()->map(function ($line) {
            return [
                'FMaterialId' => ['FNumber' => $line->item_code],
                'FQty' => (float) $line->qty,
                'FLossRate' => (float) $line->loss_rate,
                'FUnitId' => ['FNumber' => $line->unit],
                'FNote' => $line->item_name,
            ];
        })->values()->all();

        return [
            'formid' => (string) config('k3.bom_formid', 'ENG_BOM'),
            'model' => [
                'FBillTypeID' => ['FNumber' => (string) config('k3.bom_bill_type_id', 'BOM')],
                'FNumber' => sprintf('BOM-%s-%s', $productCode, $bom->version),
                'FMaterialId' => ['FNumber' => $productCode],
                'FUseStatus' => 'A',
                'FEntity' => $entity,
            ],
        ];
    }
}
