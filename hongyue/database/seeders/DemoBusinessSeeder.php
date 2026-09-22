<?php

namespace Database\Seeders;

use App\Models\Mold;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\PlmRoute;
use App\Models\TaskSchedule;
use App\Models\User;
use App\Models\WorkReport;
use App\Modules\Order\Services\OrderService;
use App\Modules\Production\Services\ReportService;
use App\Modules\Scheduling\Services\ManualScheduleService;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\Scheduling\Services\ScheduleEngineService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 业务模拟数据生成器（订单 → 任务 → 排程 → 报工 → 模具联动全链路）
 *
 * 目标：一套严格遵守业务逻辑的自洽模拟数据 ——
 * 1. 模具使用量 ↔ 订单/报工 严格对账（开模次数 = Σ报工合格数 ÷ 模穴数，走真实联动）
 * 2. 在产订单 ↔ 排产记录联动（排程状态/数量与任务/订单匹配，订单状态由 syncStatus 派生）
 * 3. 覆盖全部状态（pending/scheduled/in_progress/produced）+ 红灯场景（未排产）
 *
 * 用法：php artisan db:seed --class=DemoBusinessSeeder --force
 * 幂等：先清订单域（订单/行/任务/排程/报工/模具寿命日志）+ 模具使用量归零，再重建。
 */
class DemoBusinessSeeder extends Seeder
{
    public function run(): void
    {
        // ============ 0. 幂等清理（订单域 + 模具计数归零） ============
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['work_reports', 'stock_ins', 'task_schedules', 'prod_tasks', 'order_lines', 'orders', 'mold_life_logs'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        Mold::query()->update(['opened_count' => 0]);

        // 补吹塑车间工人（报工车间强匹配需要；无则吹塑任务无法报工）
        $blowWorker = User::firstOrCreate(
            ['employee_no' => 'W005'],
            [
                'name' => '郑吹塑', 'phone' => '13900000009',
                'password_hash' => Hash::make('123456'),
                'job_type' => User::JOB_WORKER,
                'workshop_id' => \App\Models\Workshop::where('code', 'blow')->value('id'),
                'wecom_userid' => null, 'status' => 1,
            ],
        );
        if (! $blowWorker->hasRole('worker')) {
            $blowWorker->syncRoles(['worker']);
        }

        // 工人车间兜底纠正（幂等）：老库用户 workshop_id 可能为 NULL（迁移未回填）
        $workerWorkshopMap = [
            'L001' => 'injection', 'W001' => 'injection', 'W002' => 'injection',
            'W003' => 'paint', 'W006' => 'effect', 'W004' => 'pack',
        ];
        foreach ($workerWorkshopMap as $no => $wsCode) {
            User::where('employee_no', $no)->whereNull('workshop_id')
                ->update(['workshop_id' => \App\Models\Workshop::where('code', $wsCode)->value('id')]);
        }

        $orderService = app(OrderService::class);
        $scheduleService = app(ManualScheduleService::class);
        $reportService = app(ReportService::class);

        // 模拟数据也走真实的“无重叠”校验。若示例起点与前面已落库的任务相交，
        // 只把当前任务推到冲突资源最晚结束后再重试，不使用 force，避免生成假冲突数据。
        $saveDemoSchedule = function (array $item) use ($scheduleService): void {
            $start = Carbon::parse($item['plan_start']);
            for ($attempt = 0; $attempt < 100; $attempt++) {
                try {
                    $item['plan_start'] = $start->format('Y-m-d H:i:s');
                    $scheduleService->save([$item], 1, TaskSchedule::SOURCE_MANUAL, false);
                    return;
                } catch (ScheduleException $exception) {
                    $conflicts = collect($exception->errors())
                        ->filter(fn ($error) => ($error['code'] ?? null) === 'resource_conflict');
                    if ($conflicts->isEmpty()) {
                        throw $exception;
                    }
                    $taskId = (int) $item['task_id'];
                    $shiftSeconds = $conflicts->map(function ($error) use ($taskId): int {
                        $isNewA = (int) ($error['task_id_a'] ?? 0) === $taskId;
                        $newStart = Carbon::parse($isNewA ? $error['start_a'] : $error['start_b']);
                        $otherEnd = Carbon::parse($isNewA ? $error['end_b'] : $error['end_a']);
                        // 同机台需要 30 分钟切换间隔；同模具并发只需等模具释放。
                        $gap = str_starts_with((string) ($error['resource'] ?? ''), 'machine:')
                            ? ScheduleEngineService::TASK_SWITCH_GAP_MIN * 60
                            : 0;

                        return max(1, $otherEnd->getTimestamp() + $gap - $newStart->getTimestamp());
                    })->max();
                    if (! $shiftSeconds) {
                        throw $exception;
                    }
                    $start = $start->addSeconds((int) $shiftSeconds);
                }
            }

            throw new \RuntimeException('模拟排产重试次数超过上限，请检查资源/模具配置');
        };

        // 产品 → 成型机台映射：模拟数据按“少量任务集中使用”排产，优先使用 I-01 / I-02 / B-01，
        // 其余机台保留为空闲，便于在甘特图上观察真实资源利用率；报工统一由统计员登记。
        $moldByProduct = fn (Product $p) => $p->default_mold_id ? Mold::find($p->default_mold_id) : null;
        $machineFor = function (Product $p) {
            $mold = $p->default_mold_id ? Mold::find($p->default_mold_id) : null;
            if ($mold?->type === 'blow') {
                return 'B-01';
            }

            // M-005/M-006 的适用清单从 I-02 开始；其他注塑模集中到 I-01。
            return in_array($p->code, ['P-0005', 'P-0006'], true) ? 'I-02' : 'I-01';
        };
        $moldingWorkerFor = fn (Product $p) => $p->default_mold_id
            && Mold::find($p->default_mold_id)?->type === 'blow' ? 'W005' : 'W001';
        $userNo = fn (string $no) => User::where('employee_no', $no)->first();
        $reportOperator = $userNo('T001') ?? $userNo('admin') ?? User::firstOrFail();

        // 演示数据必须能够走完“工艺确认 → 排产”流程；若存量产品的默认路线曾被手工改为草稿，
        // 仅修复本次演示数据引用到的路线，不扩散到其他未被引用的工艺版本。
        Product::query()->whereIn('code', ['P-0001', 'P-0002', 'P-0003', 'P-0004', 'P-0005'])
            ->with('defaultRoute')
            ->get()
            ->each(function (Product $product): void {
                if ($product->defaultRoute && ! $product->defaultRoute->isPublished()) {
                    $product->defaultRoute->update([
                        'status' => PlmRoute::STATUS_PUBLISHED,
                    ]);
                }
            });

        // ============ 1. 订单集（12 单，4 状态） ============
        // [状态, 客户, [ [产品编码, 数量], ... ], 创建偏移天, 排程起点偏移天]
        // 排程起点围绕当前日期组织：已完成任务落在近 2 周内，进行中任务落在近几天，
        // 待执行任务落在未来；同一资源由排程引擎按工序链和 30 分钟切换间隔顺排。
        $orders = [
            ['pending',      '日用品商超',      [['P-0003', 12000]], -12, null],
            ['pending',      '家居百货连锁',    [['P-0004', 10000]], -11, null],
            // 订单量按瓶颈产线的日有效产能设计，避免一笔小单只占几小时后留下长空档。
            ['scheduled',    '连锁餐饮集团',    [['P-0001', 36000]], -10, 0],
            ['scheduled',    '老字号食品厂',    [['P-0002', 30000]], -9, 1],
            ['in_progress',  '美妆电商',        [['P-0005', 36000]], -8, -6],
            ['in_progress',  '快消品集团',      [['P-0001', 30000]], -7, -5],
            ['in_progress',  '洗护用品公司',    [['P-0002', 32000]], -6, -4],
            ['in_progress',  '外贸出口商',      [['P-0005', 30000], ['P-0001', 24000]], -5, [-3, -2]],
            ['in_progress',  '一次性餐具厂',    [['P-0001', 36000]], -4, -1],
            ['produced',     '饮料灌装厂',      [['P-0001', 24000]], -10, -10],
            ['produced',     '碳酸饮料厂',      [['P-0002', 24000]], -13, -13],
            // 这笔已完成单的末工序落在当前“过去 7 天”窗口内，甘特图可同时看到计划与实际。
            // P-0005 已完成任务提前安排，保证完整工序链（尤其包装）在当前时刻之前结束，
            // 不出现“订单已完工但实际报工时间早于计划开始”的假数据。
            ['produced',     '美妆品牌二',      [['P-0005', 20000]], -10, -10],
        ];

        $created = [];
        foreach ($orders as [$state, $customer, $lines, $offset, $schedBase]) {
            $order = $orderService->createOrder([
                'customer_name' => $customer,
                'priority' => 3,
                'delivery_date' => Carbon::now()->addDays(20)->format('Y-m-d'),
                // 显式传 mold_id（订单-模具关联）：createOrder 不兜底默认模具（与导入路径不同）
                'lines' => array_map(function ($l) {
                    return [
                        'product_code' => $l[0],
                        'qty' => $l[1],
                        'mold_id' => Product::where('code', $l[0])->value('default_mold_id'),
                    ];
                }, $lines),
            ], 1);
            // 统一把创建时间推到过去（模拟历史订单）
            $createdAt = Carbon::now()->addDays($offset);
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $createdAt]);
            if ($state === 'pending') {
                // 待排产订单也完成工艺确认，避免在演示数据中停留在 route_confirm 状态。
                // 它们保留未排程任务，供排产页任务池展示。
                $orderService->confirmRoute($order->fresh(), 1);
            }
            $created[] = ['state' => $state, 'order' => $order->fresh(), 'offset' => $offset, 'sched_base' => $schedBase];
        }

        // ============ 2. 排程（scheduled / in_progress / produced） ============
        // 先落历史，再落进行中和未来任务。这样真实冲突校验顺延时，历史任务不会被未来任务挤到今天，
        // 报工时间也能与计划时间保持同一时间段，甘特图才能同时展示计划与实际。
        $scheduleEntries = collect($created)
            ->filter(fn ($entry) => $entry['state'] !== 'pending' && $entry['sched_base'] !== null)
            ->sortBy(fn ($entry) => is_array($entry['sched_base']) ? min($entry['sched_base']) : $entry['sched_base']);
        foreach ($scheduleEntries as $entry) {
            $state = $entry['state'];
            $order = $entry['order'];
            $schedBase = $entry['sched_base'];

            $base = Carbon::now()->addDays(is_array($schedBase) ? $schedBase[0] : $schedBase);
            // 新流程：排产前必须完成工艺确认（订单创建默认 route_confirm；
            // 确认通过后转 pending 再排程；产品默认工艺均已发布，直接确认即可）
            $orderService->confirmRoute($order->fresh(), 1);
            $lineIdx = 0;
            foreach ($order->lines as $line) {
                $product = $line->product;
                $task = $line->tasks->first();
                // 排程起点：双行订单按数组给两个起点；单行订单用数组首个
                $startOffset = is_array($schedBase) ? $schedBase[$lineIdx] : $schedBase;
                $start = Carbon::now()->addDays($startOffset);
                $lineIdx++;

                $saveDemoSchedule([
                    'task_id' => $task->id,
                    'machine_id' => \App\Models\Machine::where('code', $machineFor($product))->value('id'),
                    'mold_id' => $product->default_mold_id,
                    'plan_start' => $start->format('Y-m-d H:i:s'),
                ]);

                // 排程后任务置 scheduled（链中排程引擎可能已置；保证幂等）
                if ($task->status !== ProdTask::STATUS_IN_PROGRESS) {
                    $task->update(['status' => ProdTask::STATUS_SCHEDULED]);
                }
            }
        }

        // ============ 3. 状态联动（scheduled → in_progress → produced） ============
        foreach ($created as &$entry) {
            $state = $entry['state'];
            $order = $entry['order'];

            if ($state === 'produced') {
                // 报工全量（每道工序处理全量产品：工序报工 = 任务量；工人按车间匹配）
                foreach ($order->lines as $line) {
                    $task = $line->tasks->first();
                    $product = $line->product;
                    $worker = $userNo($moldingWorkerFor($product));
                    $schedules = $task->schedules()->orderBy('seq')->get();

                    foreach ($schedules as $schedule) {
                        $opType = $schedule->op_type;
                        $opWorker = match (true) {
                            str_starts_with($opType, 'molding_') => $worker,
                            $opType === 'paint' => $userNo('W003'),
                            $opType === 'effect' => $userNo('W006'),
                            $opType === 'pack' => $userNo('W004'),
                            default => $userNo('W001'),
                        };
                        $qty = (int) $task->qty; // 每道工序全量报工；成品口径取最后工序
                        $planStart = Carbon::parse($schedule->plan_start);
                        $planEnd = Carbon::parse($schedule->plan_end);
                        // 模拟“班组长晚间汇总报工”时仍保留实际起止：同一工序拆成两笔流水，
                        // 第一笔形成 actual_start，第二笔完成数量后形成 actual_end。
                        $firstReportTime = $planStart->copy()->addMinutes(30);
                        $lastReportTime = $planEnd->copy()->subMinutes(30);
                        if ($lastReportTime->lte($firstReportTime)) {
                            $lastReportTime = $firstReportTime->copy()->addMinutes(30);
                        }
                        foreach ([0.6, 0.4] as $index => $ratio) {
                            $reportTime = $index === 0 ? $firstReportTime : $lastReportTime;
                            $reportService->report([
                                'task_id' => $task->id,
                                'schedule_id' => $schedule->id,
                                'machine_id' => $schedule->machine_id,
                                'qty' => $index === 1 ? $qty - (int) round($qty * 0.6) : (int) round($qty * $ratio),
                                'defect_qty' => 0,
                                'worker_id' => null,
                                'source' => WorkReport::SOURCE_LEADER_BATCH,
                                'report_time' => $reportTime->min(now()->subDay())->format('Y-m-d H:i:s'),
                            ], $reportOperator);
                        }
                        $schedule->fresh()->update([
                            'status' => TaskSchedule::STATUS_DONE,
                            'done_qty' => $qty,
                        ]);
                    }

                    // 成品入库：最后工序报工完成后全量入库（中间工序不入库）
                    app(\App\Modules\Warehouse\Services\StockInService::class)
                        ->create($task->id, (int) $task->qty, 1, '种子数据：成品全量入库');
                }
                // 完结：报工联动通常已自动流转 produced（任务全量 → syncStatus）；
                // 未流转则显式完结；已流转仅补 completed_at
                $fresh = $order->fresh();
                if ($fresh->status !== Order::STATUS_PRODUCED) {
                    $orderService->completeOrder($fresh, 1);
                } else {
                    DB::table('orders')->where('id', $fresh->id)->whereNull('completed_at')
                        ->update(['completed_at' => Carbon::now()]);
                }
            } elseif ($state === 'in_progress') {
                // 开工：订单 in_progress + started_at
                $orderService->startOrder($order->fresh(), 1);

                // 部分报工（模拟进行中）：前 1-2 道工序全量，后续部分/未开始
                foreach ($order->lines as $line) {
                    $task = $line->tasks->first();
                    $product = $line->product;
                    $worker = $userNo($moldingWorkerFor($product));
                    $schedules = $task->schedules()->orderBy('seq')->get();
                    $now = Carbon::now();

                    foreach ($schedules as $i => $schedule) {
                        $opType = $schedule->op_type;
                        $opWorker = match (true) {
                            str_starts_with($opType, 'molding_') => $worker,
                            $opType === 'paint' => $userNo('W003'),
                            $opType === 'effect' => $userNo('W006'),
                            $opType === 'pack' => $userNo('W004'),
                            default => $userNo('W001'),
                        };
                        // 进度配置：前两段合计 50%（成型 35% + 喷漆/效果 15%），任务保持 in_progress
                        $ratios = [0.35, 0.15, 0.0, 0.0, 0.0];
                        $qty = (int) round(((float) $task->qty) * ($ratios[$i] ?? 0.0));
                        if ($qty <= 0) {
                            continue;
                        }
                        $planStart = Carbon::parse($schedule->plan_start);
                        // 模拟实际报工不能早于该工序计划开始，也不能给尚未开始的下游工序提前造报工。
                        // 这样成型→喷漆等工序链在甘特图上会保持真实的先后关系。
                        if ($planStart->isAfter($now->copy()->subMinutes(5))) {
                            continue;
                        }
                        $reportTime = $planStart->copy()->addMinutes(30);
                        if ($reportTime->isAfter($now)) {
                            $reportTime = $now->copy()->subMinutes(5);
                        }
                        $reportService->report([
                            'task_id' => $task->id,
                            'schedule_id' => $schedule->id,
                            'machine_id' => $schedule->machine_id,
                            'qty' => $qty,
                            'defect_qty' => 0,
                            'worker_id' => null,
                            'source' => WorkReport::SOURCE_LEADER_BATCH,
                            'report_time' => $reportTime->format('Y-m-d H:i:s'),
                        ], $reportOperator);
                        $reportedQty = (int) WorkReport::query()
                            ->where('schedule_id', $schedule->id)
                            ->sum(DB::raw('qty'));
                        $schedule->fresh()->update([
                            'status' => $reportedQty >= (int) $schedule->qty
                                ? TaskSchedule::STATUS_DONE
                                : TaskSchedule::STATUS_RUNNING,
                            'done_qty' => $reportedQty,
                        ]);
                    }

                    // 前段/中段已有报工但末工序尚未完工时，保持“进行中”，不要被任务进度服务误留在“已排产”。
                    if ((int) $task->fresh()->done_qty < (int) $task->qty) {
                        $task->fresh()->update(['status' => ProdTask::STATUS_IN_PROGRESS]);
                    }
                }
            }
        }

        // ============ 4. 订单/行状态统一由任务状态派生 ============
        foreach ($created as $entry) {
            $orderService->syncStatus($entry['order']->fresh());
        }

        // ============ 输出汇总 ============
        $this->command?->info('DemoBusinessSeeder 完成：');
        $this->command?->info('  订单: '.Order::count().
            ' ('.Order::where('status', 'pending')->count().' pending / '.
            Order::where('status', 'scheduled')->count().' scheduled / '.
            Order::where('status', 'in_progress')->count().' in_progress / '.
            Order::where('status', 'produced')->count().' produced)');
        $this->command?->info('  任务: '.ProdTask::count().' / 排程: '.TaskSchedule::count().' / 报工: '.WorkReport::count());
        $this->command?->info('  模具使用量: '.collect(Mold::all())->sum(fn ($m) => $m->opened_count).' 次（mold_life_logs: '.DB::table('mold_life_logs')->count().' 条）');
    }
}
