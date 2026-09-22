<?php

namespace Tests\Feature\Plm;

use App\Models\Bom;
use App\Models\BomLine;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * BOM 列表模式（金蝶物料清单样式）：listAll 混合行 + 半成品跳转 + byProductCode
 *
 * 注意：WithSeedData 会灌入 P-0001~P-0005 的种子 BOM，
 * 因此本组用例统一使用自定义产品编码（P-TST-*）构造数据，并按自身 bom_id 隔离断言。
 */
class BomListFlatTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function makeProduct(string $code, string $name): Product
    {
        return Product::create([
            'code' => $code,
            'name' => $name,
            'spec' => '测试规格',
            'unit' => '只',
            'status' => Product::STATUS_MASS,
        ]);
    }

    private function makeBom(Product $product, string $version, string $status = Bom::STATUS_DRAFT): Bom
    {
        return Bom::create([
            'product_id' => $product->id,
            'version' => $version,
            'status' => $status,
            'k3_push_status' => Bom::K3_NONE,
            'published_at' => $status === Bom::STATUS_PUBLISHED ? now() : null,
            'remark' => "测试{$version}",
        ]);
    }

    private function makeLine(Bom $bom, string $code, string $name, int $seq, int $isKey = 0): BomLine
    {
        return BomLine::create([
            'bom_id' => $bom->id,
            'seq' => $seq,
            'item_code' => $code,
            'item_name' => $name,
            'qty' => 1,
            'unit' => '个',
            'loss_rate' => 0,
            'is_key' => $isKey,
        ]);
    }

    public function test_list_all_returns_bom_header_and_lines_interleaved(): void
    {
        $productA = $this->makeProduct('P-TST-1', '半成品杯体');
        $productB = $this->makeProduct('P-TST-2', '成品直口杯');

        $bomA = $this->makeBom($productA, 'V1.0', Bom::STATUS_PUBLISHED);
        $this->makeLine($bomA, 'RAW-PP-001', 'PP 原料', 10, 1);
        $this->makeLine($bomA, 'PKG-BOX-001', '彩盒', 20);
        $this->makeLine($bomA, 'P-TST-2', '成品直口杯', 30); // 半成品：item_code 命中另一产品编码

        $bomB = $this->makeBom($productB, 'V2.0', Bom::STATUS_PUBLISHED);
        $this->makeLine($bomB, 'RAW-PET-001', 'PET 原料', 10, 1);
        $this->makeLine($bomB, 'LBL-001', '标签', 20);
        $this->makeLine($bomB, 'PKG-BOX-002', '外箱', 30);

        $response = $this->api($this->token)->getJson('/api/plm/boms');
        $response->assertOk()->assertJsonPath('code', 0);

        // 仅取本次构造的 2 个 BOM 行集（种子 BOM 不参与断言），验证交错顺序与行类型
        $rows = collect($response->json('data'))
            ->filter(fn (array $r) => in_array($r['bom_id'], [$bomA->id, $bomB->id], true))
            ->values();

        $this->assertCount(8, $rows);
        $this->assertSame(
            ['bom', 'line', 'line', 'line', 'bom', 'line', 'line', 'line'],
            $rows->pluck('row_type')->all()
        );

        $headerA = $rows[0];
        $this->assertSame('bom', $headerA['row_type']);
        $this->assertSame($bomA->id, $headerA['bom_id']);
        $this->assertSame('P-TST-1', $headerA['product_code']);
        $this->assertSame('半成品杯体', $headerA['product_name']);
        $this->assertSame('V1.0', $headerA['version']);
        $this->assertSame('published', $headerA['status']);
        $this->assertSame(3, $headerA['lines_count']);

        // 半成品行判定
        $semiLine = $rows->firstWhere('item_code', 'P-TST-2');
        $this->assertNotNull($semiLine);
        $this->assertTrue($semiLine['is_semi_finished']);
        $this->assertSame($productB->id, $semiLine['semi_finished_product_id']);

        $rawLine = $rows->firstWhere('item_code', 'RAW-PP-001');
        $this->assertNotNull($rawLine);
        $this->assertFalse($rawLine['is_semi_finished']);
        $this->assertNull($rawLine['semi_finished_product_id']);
    }

    public function test_list_all_filtered_by_product_code(): void
    {
        $productC = $this->makeProduct('P-TST-3', '测试产品C');
        $productD = $this->makeProduct('P-TST-4', '测试产品D');

        $bomC = $this->makeBom($productC, 'V1.0');
        $this->makeLine($bomC, 'RAW-C-1', '原料一', 10);
        $this->makeLine($bomC, 'RAW-C-2', '原料二', 20);
        $this->makeLine($bomC, 'RAW-C-3', '原料三', 30);

        $bomD = $this->makeBom($productD, 'V1.0');
        $this->makeLine($bomD, 'RAW-D-1', '原料四', 10);

        $response = $this->api($this->token)->getJson('/api/plm/boms?product_code=P-TST-3');
        $response->assertOk()->assertJsonPath('code', 0);

        $rows = $response->json('data');
        $this->assertCount(4, $rows);
        $this->assertSame(['bom', 'line', 'line', 'line'], array_column($rows, 'row_type'));
        $this->assertSame('P-TST-3', $rows[0]['product_code']);
        foreach ($rows as $row) {
            $this->assertSame($bomC->id, $row['bom_id']);
        }
        $this->assertNotContains($bomD->id, array_column($rows, 'bom_id'));
    }

    public function test_list_all_filtered_by_status(): void
    {
        $productE = $this->makeProduct('P-TST-5', '测试产品E');

        $draftBom = $this->makeBom($productE, 'V1.0', Bom::STATUS_DRAFT);
        $this->makeLine($draftBom, 'RAW-D-1', '草稿原料', 10);
        $this->makeLine($draftBom, 'RAW-D-2', '草稿原料二', 20);

        $publishedBom = $this->makeBom($productE, 'V2.0', Bom::STATUS_PUBLISHED);
        $this->makeLine($publishedBom, 'RAW-P-1', '发布原料', 10);

        $response = $this->api($this->token)->getJson('/api/plm/boms?status=draft');
        $response->assertOk()->assertJsonPath('code', 0);

        $rows = collect($response->json('data'));

        // 该产品下只应返回草稿 BOM（1 头 + 2 明细；line 行无 product_code，按 bom_id 过滤）
        $productRows = $rows->where('bom_id', $draftBom->id)->values();
        $this->assertCount(3, $productRows);
        $this->assertSame('draft', $productRows->firstWhere('row_type', 'bom')['status']);
        $this->assertSame(['bom', 'line', 'line'], $productRows->pluck('row_type')->all());

        // 已发布 BOM 不应出现
        $this->assertNotContains($publishedBom->id, $rows->pluck('bom_id')->all());

        // 全部头行都应为 draft
        $this->assertTrue($rows->where('row_type', 'bom')->every(fn (array $r) => $r['status'] === 'draft'));
    }

    public function test_semi_finished_flag_when_item_code_matches_product_code(): void
    {
        $productF = $this->makeProduct('P-TST-6', '测试半成品F');
        $productG = $this->makeProduct('P-TST-7', '测试半成品G');

        $bomF = $this->makeBom($productF, 'V1.0');
        $this->makeLine($bomF, 'P-TST-7', '引用半成品 G', 10);
        $this->makeLine($bomF, 'NO-SUCH-CODE-999', '不存在物料', 20);

        $response = $this->api($this->token)->getJson('/api/plm/boms?product_code=P-TST-6');
        $response->assertOk()->assertJsonPath('code', 0);

        $rows = collect($response->json('data'));

        $semiRow = $rows->firstWhere('item_code', 'P-TST-7');
        $this->assertNotNull($semiRow);
        $this->assertTrue($semiRow['is_semi_finished']);
        $this->assertSame($productG->id, $semiRow['semi_finished_product_id']);

        $unknownRow = $rows->firstWhere('item_code', 'NO-SUCH-CODE-999');
        $this->assertNotNull($unknownRow);
        $this->assertFalse($unknownRow['is_semi_finished']);
        $this->assertNull($unknownRow['semi_finished_product_id']);
    }

    public function test_by_product_code_returns_boms_for_product(): void
    {
        $productH = $this->makeProduct('P-TST-8', '测试产品H');
        $this->makeBom($productH, 'V1.0', Bom::STATUS_DRAFT);
        $this->makeBom($productH, 'V2.0', Bom::STATUS_PUBLISHED);

        $response = $this->api($this->token)->getJson('/api/plm/products/by-code/P-TST-8/boms');
        $response->assertOk()->assertJsonPath('code', 0);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertContains('V1.0', array_column($data, 'version'));
        $this->assertContains('V2.0', array_column($data, 'version'));
        $this->assertSame($productH->id, $data[0]['product_id']);
        $this->assertArrayHasKey('lines_count', $data[0]);

        // 产品不存在 → 404
        $this->api($this->token)->getJson('/api/plm/products/by-code/NO-SUCH/boms')->assertStatus(404);
    }
}
