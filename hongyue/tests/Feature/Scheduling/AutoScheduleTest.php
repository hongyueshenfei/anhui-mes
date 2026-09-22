<?php

namespace Tests\Feature\Scheduling;

use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\RouteOp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 智能排产编排测试（B6/PRD T10）
 * - 提交（Http 模拟 Python）→ 返回 job_id
 * - 轮询 → 结果状态
 * - 整版接受/局部采纳 → 应用排程 + 锁定 + 版本 ai
 * - Python 不可用 → 503 降级提示手工排产
 */
class AutoScheduleTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');

        // 指向模拟 AI 服务地址（Http::fake 拦截）
        config(['ai.service_url' => 'http://ai-service.test']);
    }

    private function createTask(string $productCode = 'P-0001', int $qty = 1900): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '智能排产测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产（订单-工艺-排产流程）
        $this->api($this->token)->postJson('/api/orders/'.$response->json('data.id').'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($response->json('data.id'))->lines->first()->id);
    }

    public function test_auto_submit_returns_job_id(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->first();
        $payload = null;

        \Illuminate\Support\Facades\Http::fake([
            'ai-service.test/schedule/jobs' => function ($request) use (&$payload) {
                $payload = $request->data();

                return \Illuminate\Support\Facades\Http::response([
                    'job_id' => 'job-abc-123',
                    'status' => 'queued',
                ], 200);
            },
        ]);

        $response = $this->api($this->token)->postJson('/api/schedule/auto', ['task_ids' => [$task->id]]);

        $response->assertOk()->assertJsonPath('data.job_id', 'job-abc-123');
        $this->assertSame(1, $response->json('data.task_count'));
        $this->assertSame(now()->format('Y-m-d H:i:s'), $payload['settings']['start_at'] ?? null);
    }

    public function test_auto_submit_excludes_non_running_machines(): void
    {
        $task = $this->createTask();
        Machine::where('code', 'I-01')->update(['status' => Machine::STATUS_MAINTENANCE]);

        $payload = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$payload) {
            $payload = $request->data();

            return \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-running-only',
                'status' => 'queued',
            ], 200);
        });

        $this->api($this->token)->postJson('/api/schedule/auto', ['task_ids' => [$task->id]])->assertOk();

        $machineCodes = collect($payload['machines'] ?? [])->pluck('code')->all();
        $this->assertNotContains('I-01', $machineCodes);
        $this->assertNotContains('I-04', $machineCodes, '维修中的 I-04 不得进入算法资源池');
        $this->assertContains('I-02', $machineCodes);
    }

    public function test_auto_submit_passes_machine_specific_molding_duration(): void
    {
        $task = $this->createTask('P-0001', 1900);
        // 路线不承载固定成型产能，算法载荷必须按每台运行中机台的实时产能分别计算。
        $machineI01 = Machine::where('code', 'I-01')->firstOrFail();
        $machineI02 = Machine::where('code', 'I-02')->firstOrFail();
        $machineI02->update(['capacity_per_hour' => 800]);

        $payload = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$payload) {
            $payload = $request->data();

            return \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-machine-duration',
                'status' => 'queued',
            ], 200);
        });

        $this->api($this->token)->postJson('/api/schedule/auto', ['task_ids' => [$task->id]])->assertOk();

        $durationByMachine = $payload['tasks'][0]['molding_op']['duration_seconds_by_machine'] ?? [];
        $this->assertArrayHasKey((string) $machineI01->id, $durationByMachine);
        $this->assertArrayHasKey((string) $machineI02->id, $durationByMachine);
        $this->assertLessThan(
            (int) $durationByMachine[(string) $machineI01->id],
            (int) $durationByMachine[(string) $machineI02->id],
            '高产能机台的成型时长应更短',
        );
    }

    public function test_auto_submit_filters_candidates_by_molding_workshop(): void
    {
        $task = $this->createTask('P-0002', 1900); // P-0002 为吹塑产品
        $payload = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$payload) {
            $payload = $request->data();

            return \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-workshop-filter',
                'status' => 'queued',
            ], 200);
        });

        $this->api($this->token)->postJson('/api/schedule/auto', [
            'task_ids' => [$task->id],
        ])->assertOk();

        $submittedTask = collect($payload['tasks'])->firstWhere('id', $task->id);
        $eligibleIds = array_map('intval', $submittedTask['eligible_machine_ids'] ?? []);
        $blowMachineIds = Machine::where('type', Machine::TYPE_BLOW)
            ->where('workshop_id', $submittedTask['molding_op']['workshop_id'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertNotEmpty($eligibleIds);
        $this->assertSame([], array_values(array_diff($eligibleIds, $blowMachineIds)));
        $this->assertNotContains(
            Machine::where('code', 'I-01')->value('id'),
            $eligibleIds,
            '吹塑工艺不能把注塑车间机台作为算法候选',
        );
    }

    public function test_auto_submit_uses_order_operation_quantity_for_molding_duration(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $task->update([
            'route_ops_override' => [[
                'seq' => 1,
                'op_type' => RouteOp::OP_MOLDING_INJECTION,
                'op_name' => '注塑成型',
                'qty' => 800,
            ], [
                'seq' => 2,
                'op_type' => RouteOp::OP_PAINT,
                'op_name' => '喷漆',
                'qty' => 1900,
            ]],
        ]);

        $payload = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$payload) {
            $payload = $request->data();

            return \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-operation-quantity',
                'status' => 'queued',
            ], 200);
        });

        $this->api($this->token)->postJson('/api/schedule/auto', ['task_ids' => [$task->id]])->assertOk();

        $molding = collect($payload['tasks'][0]['ops'] ?? [])->firstWhere('seq', 1);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $expected = \App\Modules\Plm\Services\CapacityCalcService::estimateSeconds(
            800,
            (float) $machine->capacity_per_hour,
            (int) ($machine->mold_change_min ?? 0),
        );

        $this->assertNotNull($molding);
        $this->assertSame($expected, (int) $molding['duration_seconds']);
        $this->assertSame($expected, (int) $molding['duration_seconds_by_machine'][(string) $machine->id]);
    }

    public function test_auto_submit_filters_mold_incompatible_machines(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $incompatible = Machine::where('code', 'I-02')->firstOrFail();
        $task->mold?->machines()->detach($incompatible->id);

        $payload = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$payload) {
            $payload = $request->data();

            return \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-mold-machines',
                'status' => 'queued',
            ], 200);
        });

        $this->api($this->token)->postJson('/api/schedule/auto', ['task_ids' => [$task->id]])->assertOk();

        $eligibleIds = $payload['tasks'][0]['eligible_machine_ids'] ?? [];
        $this->assertNotContains($incompatible->id, array_map('intval', $eligibleIds));
        $this->assertContains(
            Machine::where('code', 'I-01')->value('id'),
            array_map('intval', $eligibleIds),
            '模具仍适用的运行中机台必须保留',
        );
    }

    public function test_auto_submit_resolves_product_default_mold_when_task_mold_is_empty(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $task->update(['mold_id' => null]);
        $defaultMoldId = $task->product->default_mold_id;
        $incompatible = Machine::where('code', 'I-02')->firstOrFail();
        \App\Models\Mold::findOrFail($defaultMoldId)->machines()->detach($incompatible->id);

        $payload = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$payload) {
            $payload = $request->data();

            return \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-default-mold',
                'status' => 'queued',
            ], 200);
        });

        $this->api($this->token)->postJson('/api/schedule/auto', ['task_ids' => [$task->id]])->assertOk();

        $submittedTask = $payload['tasks'][0];
        $this->assertSame($defaultMoldId, (int) $submittedTask['mold_id']);
        $this->assertContains($defaultMoldId, array_map('intval', array_column($payload['molds'], 'id')));
        $this->assertNotContains($incompatible->id, array_map('intval', $submittedTask['eligible_machine_ids']));
    }

    public function test_auto_submit_filters_machine_below_required_tonnage(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $tooSmall = Machine::where('code', 'I-02')->firstOrFail();
        $tooSmall->update(['tonnage' => 100]);

        $payload = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$payload) {
            $payload = $request->data();

            return \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-tonnage',
                'status' => 'queued',
            ], 200);
        });

        $this->api($this->token)->postJson('/api/schedule/auto', ['task_ids' => [$task->id]])->assertOk();

        $eligibleIds = array_map('intval', $payload['tasks'][0]['eligible_machine_ids'] ?? []);
        $this->assertNotContains($tooSmall->id, $eligibleIds);
        $this->assertContains(Machine::where('code', 'I-01')->value('id'), $eligibleIds);
    }

    public function test_auto_submit_includes_unfinished_existing_molding_occupancy(): void
    {
        $occupiedTask = $this->createTask('P-0001', 1900);
        $waitingTask = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $base = now()->addDays(2)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $occupiedTask->id,
                'machine_id' => $machine->id,
                'plan_start' => $base->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $occupiedSchedule = $occupiedTask->fresh()->schedules()
            ->where('op_type', 'molding_injection')
            ->firstOrFail();
        $forecastEnd = now()->addHours(2);
        // 预测已晚于原计划结束，查询条件也必须能命中 forecast_end，不能只看 plan_end。
        $occupiedSchedule->update([
            'plan_end' => now()->subMinute(),
            'forecast_end' => $forecastEnd,
        ]);

        $payload = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$payload) {
            $payload = $request->data();

            return \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-with-occupancy',
                'status' => 'queued',
            ], 200);
        });

        $this->api($this->token)->postJson('/api/schedule/auto', [
            'task_ids' => [$waitingTask->id],
        ])->assertOk();

        $occupancy = collect($payload['occupied_intervals'] ?? [])
            ->firstWhere('task_id', $occupiedTask->id);
        $this->assertNotNull($occupancy, '算法载荷必须包含已有未完工成型排程');
        $this->assertSame($machine->id, (int) $occupancy['machine_id']);
        $this->assertSame($base->format('Y-m-d H:i:s'), $occupancy['start']);
        $this->assertSame($forecastEnd->format('Y-m-d H:i:s'), $occupancy['end'], '晚点预测应继续占用机台');
    }

    public function test_auto_submit_does_not_reserve_completed_existing_occupancy(): void
    {
        $occupiedTask = $this->createTask('P-0001', 1900);
        $waitingTask = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $base = now()->addDays(2)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $occupiedTask->id,
                'machine_id' => $machine->id,
                'plan_start' => $base->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        TaskSchedule::where('task_id', $occupiedTask->id)
            ->where('op_type', 'molding_injection')
            ->update([
                'status' => TaskSchedule::STATUS_DONE,
                // 模拟补录：计划区间仍在未来，但实际已经提前完成，资源不应被计划尾端继续占用。
                'actual_end' => now()->subMinute(),
            ]);

        $payload = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$payload) {
            $payload = $request->data();

            return \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-without-completed-occupancy',
                'status' => 'queued',
            ], 200);
        });

        $this->api($this->token)->postJson('/api/schedule/auto', [
            'task_ids' => [$waitingTask->id],
        ])->assertOk();

        $this->assertNull(
            collect($payload['occupied_intervals'] ?? [])->firstWhere('task_id', $occupiedTask->id),
            '已实际完工的成型排程不应继续占用算法资源'
        );
    }

    public function test_auto_submit_blocks_started_schedule_without_future_end_boundary(): void
    {
        $occupiedTask = $this->createTask('P-0001', 1900);
        $waitingTask = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $base = now()->subDays(2)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $occupiedTask->id,
                'machine_id' => $machine->id,
                'plan_start' => $base->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        TaskSchedule::where('task_id', $occupiedTask->id)
            ->where('op_type', 'molding_injection')
            ->update([
                'plan_end' => now()->subMinute(),
                'forecast_end' => null,
                'actual_start' => now()->subHours(2),
                'actual_end' => null,
                'status' => TaskSchedule::STATUS_RUNNING,
            ]);

        $response = $this->api($this->token)->postJson('/api/schedule/auto', [
            'task_ids' => [$waitingTask->id],
        ]);

        $response->assertStatus(503);
        $this->assertStringContainsString('已开工但没有有效预测结束时间', (string) $response->json('message'));
    }

    public function test_auto_submit_lists_task_with_unavailable_mold_as_exception(): void
    {
        $task = $this->createTask();
        $mold = \App\Models\Mold::where('code', 'M-001')->firstOrFail();
        $mold->update(['status' => \App\Models\Mold::STATUS_REPAIR]);

        $response = $this->api($this->token)->postJson('/api/schedule/auto', ['task_ids' => [$task->id]]);

        $response->assertOk();
        $this->assertNull($response->json('data.job_id'));
        $this->assertSame('no_valid_tasks', $response->json('data.status'));
        $this->assertSame($task->id, $response->json('data.exceptions.0.task_id'));
        $this->assertStringContainsString('维修中', $response->json('data.exceptions.0.reason'));
    }

    public function test_auto_submit_continues_valid_tasks_and_returns_invalid_task_list(): void
    {
        $invalidTask = $this->createTask('P-0001');
        $validTask = $this->createTask('P-0003');
        \App\Models\Mold::where('code', 'M-001')->update(['status' => \App\Models\Mold::STATUS_REPAIR]);

        $payload = null;
        \Illuminate\Support\Facades\Http::fake([
            'ai-service.test/schedule/jobs' => function ($request) use (&$payload) {
                $payload = $request->data();

                return \Illuminate\Support\Facades\Http::response([
                    'job_id' => 'job-valid-with-exception',
                    'status' => 'queued',
                ], 200);
            },
        ]);

        $response = $this->api($this->token)->postJson('/api/schedule/auto', [
            'task_ids' => [$invalidTask->id, $validTask->id],
        ]);

        $response->assertOk()->assertJsonPath('data.job_id', 'job-valid-with-exception');
        $this->assertSame(1, $response->json('data.task_count'));
        $this->assertSame($invalidTask->id, $response->json('data.exceptions.0.task_id'));
        $this->assertSame([$validTask->id], array_column($payload['tasks'] ?? [], 'id'));
    }

    public function test_auto_submit_without_task_ids_uses_unstarted_pool_and_scheduled_tasks(): void
    {
        $unscheduled = $this->createTask();
        $scheduled = $this->createTask('P-0003');
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $scheduled->id,
                'machine_id' => $machine->id,
                'plan_start' => now()->addDays(10)->startOfDay()->addHours(8)->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $payload = null;

        \Illuminate\Support\Facades\Http::fake([
            'ai-service.test/schedule/jobs' => function ($request) use (&$payload) {
                $payload = $request->data();

                return \Illuminate\Support\Facades\Http::response(['job_id' => 'job-all', 'status' => 'queued'], 200);
            },
        ]);

        $this->api($this->token)->postJson('/api/schedule/auto', [])
            ->assertOk()
            ->assertJsonPath('data.task_count', 2);

        $this->assertSame(
            [$unscheduled->id, $scheduled->id],
            collect($payload['tasks'] ?? [])->pluck('id')->sort()->values()->all(),
            '未指定任务时，算法应同时接收任务池和已排但未开工的任务',
        );
    }

    public function test_auto_poll_returns_solution(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'ai-service.test/schedule/jobs/job-poll' => \Illuminate\Support\Facades\Http::response([
                'job_id' => 'job-poll',
                'status' => 'done',
                'solution' => ['assignments' => []],
                'meta' => ['makespan_hours' => 48.5, 'algorithm' => 'heuristic_greedy'],
            ], 200),
        ]);

        $this->api($this->token)->getJson('/api/schedule/auto/job-poll')
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.meta.algorithm', 'heuristic_greedy');
    }

    public function test_auto_accept_full_applies_and_locks(): void
    {
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0003', 1900);
        $machineI = Machine::where('code', 'I-01')->first();

        $now = now()->addDays(30)->startOfDay()->addHours(8);

        $response = $this->api($this->token)->postJson('/api/schedule/auto/accept', [
            'job_id' => 'job-xyz',
            'mode' => 'full',
            'assignments' => [
                ['task_id' => $taskA->id, 'machine_id' => $machineI->id, 'plan_start' => $now->format('Y-m-d 08:00:00')],
                // B 与 A 同机台；喷漆、效果、包装各按对应产线互斥，B 仍需避开成型机与模具占用
                ['task_id' => $taskB->id, 'machine_id' => $machineI->id, 'plan_start' => $now->copy()->addDays(6)->format('Y-m-d 08:00:00')],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.mode', 'full');

        $taskA = $taskA->fresh();
        $this->assertSame('scheduled', $taskA->status);
        $this->assertSame(1, $taskA->is_locked, '智能排产接受后同样保存即锁定');
        $this->assertDatabaseHas('task_schedules', ['task_id' => $taskA->id, 'source' => 'ai']);

        $this->assertDatabaseHas('schedule_versions', ['type' => 'ai', 'status' => 'applied']);
    }

    public function test_auto_accept_partial_applies_only_selected(): void
    {
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->first();

        $base = now()->addDays(30)->startOfDay()->addHours(8);

        $response = $this->api($this->token)->postJson('/api/schedule/auto/accept', [
            'job_id' => 'job-partial',
            'mode' => 'partial',
            'assignments' => [
                ['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => $base->format('Y-m-d 08:00:00')],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.mode', 'partial');

        $this->assertSame('scheduled', $taskA->fresh()->status);
        $this->assertSame('unscheduled', $taskB->fresh()->status, '局部采纳：未选择的 B 保持待排');
    }

    public function test_auto_accept_normalizes_shared_line_conflict(): void
    {
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0003', 1900);
        $machineI01 = Machine::where('code', 'I-01')->firstOrFail();
        $machineI02 = Machine::where('code', 'I-02')->firstOrFail();
        $base = now()->addDays(30)->startOfDay()->addHours(8);

        // 两台注塑机的成型资源不同，但后续都要经过同一条喷漆/效果/包装线。
        // 求解服务只输出成型机台和起点，接受前必须由后端把共享产线冲突顺延掉。
        $response = $this->api($this->token)->postJson('/api/schedule/auto/accept', [
            'job_id' => 'job-shared-line',
            'mode' => 'full',
            'assignments' => [
                ['task_id' => $taskA->id, 'machine_id' => $machineI01->id, 'plan_start' => $base->format('Y-m-d H:i:s')],
                ['task_id' => $taskB->id, 'machine_id' => $machineI02->id, 'plan_start' => $base->format('Y-m-d H:i:s')],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.adjusted_assignments.0.task_id', $taskB->id);

        $aPaint = TaskSchedule::where('task_id', $taskA->id)->where('op_type', RouteOp::OP_PAINT)->firstOrFail();
        $bPaint = TaskSchedule::where('task_id', $taskB->id)->where('op_type', RouteOp::OP_PAINT)->firstOrFail();
        $this->assertGreaterThanOrEqual(
            $aPaint->plan_end->getTimestamp() + (30 * 60),
            $bPaint->plan_start->getTimestamp(),
            '算法接受后共享喷漆线必须保留30分钟切换间隔',
        );
        $this->assertSame('ai', $bPaint->source);
    }

    public function test_auto_accept_delays_shared_mold_tasks_after_replacing_their_old_chains(): void
    {
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);
        $machineI01 = Machine::where('code', 'I-01')->firstOrFail();
        $machineI02 = Machine::where('code', 'I-02')->firstOrFail();
        $oldBase = now()->addDays(10)->startOfDay()->addHours(8);

        // 两个任务原本已经各自排过且保存即锁定；自动重排时必须先移除它们的旧链，
        // 否则共享模具会把新建议再次判成与“自己的旧排程”冲突。
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskA->id,
                'machine_id' => $machineI01->id,
                'plan_start' => $oldBase->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskB->id,
                'machine_id' => $machineI02->id,
                'plan_start' => $oldBase->copy()->addDays(5)->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $newBase = now()->addDays(30)->startOfDay()->addHours(8);
        $response = $this->api($this->token)->postJson('/api/schedule/auto/accept', [
            'job_id' => 'job-shared-mold-replan',
            'mode' => 'full',
            'assignments' => [
                ['task_id' => $taskA->id, 'machine_id' => $machineI01->id, 'plan_start' => $newBase->format('Y-m-d H:i:s')],
                ['task_id' => $taskB->id, 'machine_id' => $machineI02->id, 'plan_start' => $newBase->format('Y-m-d H:i:s')],
            ],
        ]);

        $response->assertOk();
        $aMolding = $taskA->fresh()->schedules()->where('op_type', RouteOp::OP_MOLDING_INJECTION)->firstOrFail();
        $bMolding = $taskB->fresh()->schedules()->where('op_type', RouteOp::OP_MOLDING_INJECTION)->firstOrFail();

        $this->assertGreaterThanOrEqual(
            $aMolding->plan_end->getTimestamp(),
            $bMolding->plan_start->getTimestamp(),
            '共享模具不能并发使用，后一个任务应等待模具释放后顺延',
        );
        $this->assertSame(1, $taskA->fresh()->is_locked);
        $this->assertSame(1, $taskB->fresh()->is_locked);
    }

    public function test_auto_service_down_falls_back_to_manual(): void
    {
        $task = $this->createTask();

        // AI 服务不可达 → 503 + 提示手工排产
        \Illuminate\Support\Facades\Http::fake([
            'ai-service.test/*' => \Illuminate\Support\Facades\Http::response('service unavailable', 503),
        ]);

        $response = $this->api($this->token)->postJson('/api/schedule/auto', ['task_ids' => [$task->id]]);

        $response->assertStatus(503);
        $this->assertStringContainsString('手工排产', $response->json('message'));

        // 降级路径：手工排产仍可用
        $machine = Machine::where('code', 'I-01')->first();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ])->assertOk();
        $this->assertSame('scheduled', $task->fresh()->status);
    }

    public function test_auto_accept_shifts_around_locked_existing_task(): void
    {
        // 现有已排任务占用机台 → 算法建议与它冲突 → 接受时顺延新任务，不能覆盖已锁定任务
        $taskA = $this->createTask('P-0001', 3800);
        $taskB = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->first();

        $base = now()->addDays(30)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => $base->format('Y-m-d 08:00:00')]],
        ])->assertOk();

        // 与 A 重叠的算法建议（A 已锁定，不能移动 A）
        $response = $this->api($this->token)->postJson('/api/schedule/auto/accept', [
            'job_id' => 'job-bad',
            'mode' => 'full',
            'assignments' => [
                ['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => $base->copy()->addMinutes(30)->format('Y-m-d H:i:s')],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.adjusted_assignments.0.task_id', $taskB->id);
        $this->assertSame('scheduled', $taskB->fresh()->status);
        $this->assertSame(1, $taskA->fresh()->is_locked);
        $this->assertSame(1, $taskB->fresh()->is_locked);

        $aMolding = TaskSchedule::where('task_id', $taskA->id)->where('op_type', 'molding_injection')->firstOrFail();
        $bMolding = TaskSchedule::where('task_id', $taskB->id)->where('op_type', 'molding_injection')->firstOrFail();
        $this->assertGreaterThanOrEqual(
            $aMolding->plan_end->getTimestamp() + (30 * 60),
            $bMolding->plan_start->getTimestamp(),
            '算法接受后新任务必须避开已锁定机台并保留切换间隔',
        );
    }

    public function test_auto_accept_replans_scheduled_task_outside_freeze_window_and_relocks_it(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $originalStart = now()->addDays(10)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $originalStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $newStart = now()->addDays(20)->startOfDay()->addHours(8);
        $response = $this->api($this->token)->postJson('/api/schedule/auto/accept', [
            'job_id' => 'job-replan-existing',
            'mode' => 'full',
            'assignments' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $newStart->format('Y-m-d H:i:s'),
            ]],
        ]);

        $response->assertOk();
        $task = $task->fresh();
        $molding = $task->schedules()->where('op_type', RouteOp::OP_MOLDING_INJECTION)->firstOrFail();

        $this->assertSame('scheduled', $task->status);
        $this->assertSame(1, $task->is_locked, '接受自动重排后仍应保存即锁定');
        $this->assertTrue($molding->plan_start->greaterThanOrEqualTo($newStart));
        $this->assertNotEquals($originalStart->format('Y-m-d H:i:s'), $molding->plan_start->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'unlock_task',
            'target_id' => $task->id,
        ]);
    }

    public function test_auto_accept_replaces_stale_machine_suggestion_with_current_compatible_machine(): void
    {
        $task = $this->createTask('P-0003', 1900); // P-0003 使用 M-003，M-003 不适配 I-03
        $invalidMachine = Machine::where('code', 'I-03')->firstOrFail();
        $base = now()->addDays(30)->startOfDay()->addHours(8);

        $response = $this->api($this->token)->postJson('/api/schedule/auto/accept', [
            'job_id' => 'job-stale-machine',
            'mode' => 'full',
            'assignments' => [[
                'task_id' => $task->id,
                'machine_id' => $invalidMachine->id,
                'plan_start' => $base->format('Y-m-d H:i:s'),
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.adjusted_assignments.0.task_id', $task->id);

        $molding = $task->fresh()->schedules()->where('op_type', RouteOp::OP_MOLDING_INJECTION)->firstOrFail();
        $compatibleMachineIds = \App\Models\Mold::where('code', 'M-003')->firstOrFail()
            ->machines()->pluck('machines.id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $molding->machine_id, $compatibleMachineIds);
        $this->assertNotSame($invalidMachine->id, (int) $molding->machine_id);
        $this->assertSame(1, $task->fresh()->is_locked);
    }
}
