<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Facades\Log;

/**
 * 金蝶物料主数据同步适配器（架构 §5.1 K4 / 验收 D6）
 *
 * - 拉取物料列表（FNumber/FName/FSpecification/FBaseUnit/FCategoryId 名称）
 * - mock 模式：读取 config('k3.mock_materials')
 * - 增量 upsert 到 materials 表（编码唯一）
 */
class K3MaterialSyncAdapter
{
    public function __construct(private readonly K3Client $client)
    {
    }

    /**
     * 拉取物料列表
     *
     * @return array<int, array{code: string, name: string, spec: ?string, unit: ?string, category: ?string}>
     */
    public function fetch(): array
    {
        if ((bool) config('k3.mock', true)) {
            return $this->mockMaterials();
        }

        try {
            $body = $this->client->executeBillQuery([
                'FormId' => 'BD_MATERIAL',
                'FieldKeys' => 'FMaterialId.FNumber,FMaterialId.FName,FMaterialId.FSpecification,FMaterialId.FBaseUnitId.FName,FMaterialId.FCategoryId.FName,FMaterialId.FForbidStatus',
                'FilterString' => "FMaterialId.FForbidStatus = 'A'",
                'OrderString' => 'FMaterialId.FNumber ASC',
                'TopRowCount' => 5000,
            ]);
        } catch (\Throwable $e) {
            Log::warning('金蝶物料查询失败', ['error' => $e->getMessage()]);
            throw $e;
        }

        $result = data_get($body, 'Result', []);
        $materials = [];
        foreach ((array) $result as $row) {
            $code = (string) data_get($row, 'FMaterialId.FNumber');
            if ($code === '') {
                continue;
            }
            $materials[] = [
                'code' => $code,
                'name' => (string) data_get($row, 'FMaterialId.FName', ''),
                'spec' => data_get($row, 'FMaterialId.FSpecification') ?: null,
                'unit' => data_get($row, 'FMaterialId.FBaseUnitId.FName') ?: null,
                'category' => data_get($row, 'FMaterialId.FCategoryId.FName') ?: null,
            ];
        }

        return $materials;
    }

    /**
     * mock 数据源（与一期产品编码 P-0001~P-0005 对齐，含 2 条未匹配样例）
     */
    private function mockMaterials(): array
    {
        $base = [
            ['code' => 'P-0001', 'name' => '直口杯 500ml', 'spec' => 'PP 材质 · 口径 8cm', 'unit' => '只', 'category' => '成品'],
            ['code' => 'P-0002', 'name' => '圆瓶 1L', 'spec' => 'PET 材质 · 扁圆瓶', 'unit' => '只', 'category' => '成品'],
            ['code' => 'P-0003', 'name' => '方盒 800ml', 'spec' => 'PP 材质 · 带盖', 'unit' => '只', 'category' => '成品'],
            ['code' => 'P-0004', 'name' => '提手桶 5L', 'spec' => 'PE 材质 · 带提手', 'unit' => '只', 'category' => '成品'],
            ['code' => 'P-0005', 'name' => '喷雾瓶 300ml', 'spec' => 'PP 材质 · 泵头款', 'unit' => '只', 'category' => '成品'],
            ['code' => 'RAW-PP-001', 'name' => 'PP 树脂（燕山石化）', 'spec' => '25kg/袋', 'unit' => 'kg', 'category' => '原料'],
            ['code' => 'RAW-PET-001', 'name' => 'PET 瓶坯', 'spec' => '300ml 标准', 'unit' => '个', 'category' => '原料'],
            ['code' => 'PUMP-300-01', 'name' => '300ml 泵头总成', 'spec' => '标准泵头', 'unit' => '个', 'category' => '辅料'],
            ['code' => 'PKG-BOX-001', 'name' => '中号彩盒（直口杯）', 'spec' => '定制印刷', 'unit' => '个', 'category' => '包装'],
            ['code' => 'PKG-BOX-002', 'name' => '圆瓶彩盒', 'spec' => '定制印刷', 'unit' => '个', 'category' => '包装'],
            ['code' => 'PKG-BOX-003', 'name' => '方盒彩盒', 'spec' => '定制印刷', 'unit' => '个', 'category' => '包装'],
            ['code' => 'PKG-BOX-004', 'name' => '餐盒彩盒', 'spec' => '定制印刷', 'unit' => '个', 'category' => '包装'],
            ['code' => 'CLR-WHITE-01', 'name' => '白色母粒', 'spec' => '25kg/袋', 'unit' => 'kg', 'category' => '原料'],
            ['code' => 'FILM-12-01', 'name' => '封口膜', 'spec' => '12μm', 'unit' => '张', 'category' => '包装'],
            ['code' => 'RAW-PP-999', 'name' => 'PP 树脂（未备案）', 'spec' => '进口', 'unit' => 'kg', 'category' => '原料'],
            ['code' => 'PUMP-300-02', 'name' => '500ml 泵头总成', 'spec' => '加大泵头', 'unit' => '个', 'category' => '辅料'],
            ['code' => 'HY-1001', 'name' => '直口杯 500ml（旧编码）', 'spec' => '历史编码', 'unit' => '只', 'category' => '成品'],
            ['code' => 'HY-1002', 'name' => '圆瓶 1L（旧编码）', 'spec' => '历史编码', 'unit' => '只', 'category' => '成品'],
        ];

        return $base;
    }
}
