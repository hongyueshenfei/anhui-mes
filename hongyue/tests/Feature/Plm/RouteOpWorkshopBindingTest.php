<?php

namespace Tests\Feature\Plm;

use App\Models\Machine;
use App\Models\Product;
use App\Models\RouteOp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 工艺路线「按车间选机台/产线」强对应校验（验收：工序默认资源）
 *
 * 业务规则：每个工艺只能在能做该工艺的车间里选择机台/产线。
 * - 成型类（molding_injection/molding_blow）：必选车间机台，且类型一致
 * - 人工线类（paint/effect/pack）：必选车间产线，且已绑定班组
 */
class RouteOpWorkshopBindingTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /**
     * 以给定工序创建一个工艺路线（POST /api/plm/routes，路线为独立实体）
     */
    private function createRoute(array $ops, string $version): TestResponse
    {
        return $this->api($this->token)->postJson('/api/plm/routes', [
            'name' => "测试工艺-{$version}",
            'version' => $version,
            'ops' => $ops,
        ]);
    }

    /**
     * 1) 成型工序 + 正确车间机台 → 201，且落库
     */
    public function test_create_molding_step_with_correct_workshop_machine(): void
    {
        $machine = Machine::where('code', 'I-01')->firstOrFail(); // injection / injection

        $response = $this->createRoute([
            [
                'seq' => 10,
                'op_type' => RouteOp::OP_MOLDING_INJECTION,
                'op_name' => '注塑成型',
                'default_machine_id' => $machine->id,
            ],
        ], 'R-MOLD-OK');

        $response->assertStatus(201);
        $this->assertDatabaseHas('route_ops', [
            'op_type' => RouteOp::OP_MOLDING_INJECTION,
            'default_machine_id' => $machine->id,
        ]);
    }

    /**
     * 2) 成型工序 + 错误车间机台（吹塑机台）→ 422，错误含「车间」
     */
    public function test_create_molding_step_with_wrong_workshop_machine_rejected(): void
    {
        $wrongMachine = Machine::where('code', 'B-01')->firstOrFail(); // blow / blow

        $response = $this->createRoute([
            [
                'seq' => 10,
                'op_type' => RouteOp::OP_MOLDING_INJECTION,
                'op_name' => '注塑成型',
                'default_machine_id' => $wrongMachine->id,
            ],
        ], 'R-MOLD-WRONG-WS');

        $response->assertStatus(422);
        $data = $response->json('data');
        $this->assertArrayHasKey('ops.0.default_machine_id', $data);
        $this->assertStringContainsString('车间', (string) $data['ops.0.default_machine_id'][0]);
    }

    /**
     * 3) 喷漆工序 + 正确车间产线（已绑班组）→ 201
     */
    public function test_create_paint_step_with_correct_workshop_line(): void
    {
        $line = Machine::where('code', 'P-01')->firstOrFail(); // paint_line / paint / workshop 已绑

        $response = $this->createRoute([
            [
                'seq' => 20,
                'op_type' => RouteOp::OP_PAINT,
                'op_name' => '喷漆',
                'default_line_id' => $line->id,
            ],
        ], 'R-PAINT-OK');

        $response->assertStatus(201);
        $this->assertDatabaseHas('route_ops', [
            'op_type' => RouteOp::OP_PAINT,
            'default_line_id' => $line->id,
        ]);
    }

    /**
     * 3b) 效果工序 + 效果车间产线（P-02）→ 201，不能再误用喷漆线
     */
    public function test_create_effect_step_with_correct_effect_workshop_line(): void
    {
        $line = Machine::where('code', 'P-02')->firstOrFail();

        $response = $this->createRoute([
            [
                'seq' => 30,
                'op_type' => RouteOp::OP_EFFECT,
                'op_name' => '效果处理',
                'default_line_id' => $line->id,
            ],
        ], 'R-EFFECT-OK');

        $response->assertStatus(201);
        $this->assertDatabaseHas('route_ops', [
            'op_type' => RouteOp::OP_EFFECT,
            'default_line_id' => $line->id,
        ]);
    }

    /**
     * 4) 喷漆工序 + 用成型机台当产线（类型/车间不符）→ 422
     */
    public function test_create_paint_step_with_molding_machine_as_line_rejected(): void
    {
        $moldingMachine = Machine::where('code', 'I-01')->firstOrFail(); // injection / injection

        $response = $this->createRoute([
            [
                'seq' => 20,
                'op_type' => RouteOp::OP_PAINT,
                'op_name' => '喷漆',
                'default_line_id' => $moldingMachine->id,
            ],
        ], 'R-PAINT-WRONG-LINE');

        $response->assertStatus(422);
    }

    /**
     * 5) 成型工序缺省默认机台 → 201（需求5：默认机台/产线为手动选择项，可选）
     */
    public function test_create_molding_step_missing_default_machine_allowed(): void
    {
        $response = $this->createRoute([
            [
                'seq' => 10,
                'op_type' => RouteOp::OP_MOLDING_INJECTION,
                'op_name' => '注塑成型',
                // default_machine_id 缺省（可选手动选择）
            ],
        ], 'R-MOLD-OPTIONAL');

        $response->assertStatus(201);
        $this->assertDatabaseHas('route_ops', [
            'op_type' => RouteOp::OP_MOLDING_INJECTION,
            'default_machine_id' => null,
        ]);
    }

    /**
     * 5b) 人工线工序缺省默认产线 → 201（需求5：默认机台/产线为手动选择项，可选）
     */
    public function test_create_paint_step_missing_default_line_allowed(): void
    {
        $response = $this->createRoute([
            [
                'seq' => 20,
                'op_type' => RouteOp::OP_PAINT,
                'op_name' => '喷漆',
                // default_line_id 缺省（可选手动选择）
            ],
        ], 'R-PAINT-OPTIONAL');

        $response->assertStatus(201);
        $this->assertDatabaseHas('route_ops', [
            'op_type' => RouteOp::OP_PAINT,
            'default_line_id' => null,
        ]);
    }

    /**
     * 6) workshopForOpType 映射正确性
     */
    public function test_workshop_for_op_type_returns_correct_mapping(): void
    {
        $this->assertSame('injection', RouteOp::workshopForOpType(RouteOp::OP_MOLDING_INJECTION));
        $this->assertSame('blow', RouteOp::workshopForOpType(RouteOp::OP_MOLDING_BLOW));
        $this->assertSame('paint', RouteOp::workshopForOpType(RouteOp::OP_PAINT));
        $this->assertSame('effect', RouteOp::workshopForOpType(RouteOp::OP_EFFECT));
        $this->assertSame('pack', RouteOp::workshopForOpType(RouteOp::OP_PACK));
        $this->assertNull(RouteOp::workshopForOpType('unknown_op'));

        // 资源类型映射同样正确
        $this->assertSame(\App\Models\Machine::TYPE_INJECTION, RouteOp::resourceTypeFor(RouteOp::OP_MOLDING_INJECTION));
        $this->assertSame(\App\Models\Machine::TYPE_BLOW, RouteOp::resourceTypeFor(RouteOp::OP_MOLDING_BLOW));
        $this->assertSame(\App\Models\Machine::TYPE_PAINT_LINE, RouteOp::resourceTypeFor(RouteOp::OP_PAINT));
        $this->assertSame(\App\Models\Machine::TYPE_EFFECT_LINE, RouteOp::resourceTypeFor(RouteOp::OP_EFFECT));
        $this->assertSame(\App\Models\Machine::TYPE_PACK_LINE, RouteOp::resourceTypeFor(RouteOp::OP_PACK));
    }
}
