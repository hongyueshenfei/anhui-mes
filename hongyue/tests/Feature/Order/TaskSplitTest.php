<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\Mold;
use App\Models\ProdTask;
use App\Models\WorkOrderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

class TaskSplitTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    public function test_unscheduled_task_can_be_split_into_independent_children(): void
    {
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '拆单客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1000]],
        ])->assertStatus(201);
        $orderId = $create->json('data.id');
        $task = ProdTask::firstOrFail();
        $task->update(['mold_id' => Mold::where('code', 'M-004')->firstOrFail()->id]);

        $response = $this->api($this->token)->postJson("/api/orders/tasks/{$task->id}/split", [
            'parts' => [
                ['qty' => 600],
                ['qty' => 400],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.parent_task_id', $task->id)
            ->assertJsonPath('data.split_total', 2);

        $parent = ProdTask::withoutGlobalScope('leaf_task')->findOrFail($task->id);
        $children = $parent->childTasks()->get();
        $this->assertSame(ProdTask::STATUS_SPLIT, $parent->status);
        $this->assertSame(1, (int) $parent->is_split_parent);
        $this->assertCount(2, $children);
        $this->assertSame(1000, (int) $children->sum('qty'));
        $this->assertTrue($children->every(fn (ProdTask $child) => $child->mold_id === null));
        $this->assertTrue($children->every(fn (ProdTask $child) => $child->status === ProdTask::STATUS_UNSCHEDULED));
        $this->assertTrue($children->pluck('task_no')->duplicates()->isEmpty());

        // 父任务不再作为可排产任务出现，工单中心只显示两个子任务。
        $list = $this->api($this->token)->getJson('/api/orders?keyword='.rawurlencode('拆单客户'));
        $list->assertOk()->assertJsonPath('data.total', 2);
        $this->assertTrue(collect($list->json('data.data'))->every(fn (array $row) => $row['is_split_child'] === true));

        $detail = $this->api($this->token)->getJson("/api/orders/{$orderId}");
        $detail->assertOk()->assertJsonCount(2, 'data.lines.0.tasks');
        $this->assertDatabaseHas('work_order_logs', [
            'order_id' => $orderId,
            'action' => WorkOrderLog::ACTION_SPLIT,
        ]);
    }

    public function test_splitting_started_or_scheduled_task_is_rejected(): void
    {
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '已排任务不可拆',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1000]],
        ])->assertStatus(201);
        $orderId = $create->json('data.id');
        $task = ProdTask::firstOrFail();

        $this->api($this->token)->postJson("/api/orders/{$orderId}/route-confirm")->assertOk();
        $machine = \App\Models\Machine::where('code', 'I-01')->firstOrFail();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $this->api($this->token)->postJson("/api/orders/tasks/{$task->id}/split", [
            'parts' => [['qty' => 500], ['qty' => 500]],
        ])->assertStatus(409);

        $this->assertDatabaseCount('prod_tasks', 1);
    }
}
