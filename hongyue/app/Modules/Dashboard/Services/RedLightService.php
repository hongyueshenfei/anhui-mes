<?php

namespace App\Modules\Dashboard\Services;

use App\Models\Machine;
use App\Models\ProdTask;
use App\Models\RedLightClose;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * 红灯看板 · 四源规则引擎（架构 §3.7 / 验收 D1）
 *
 * 实时计算（不建物化表）+ Redis/Array 缓存 10s：
 *   ① unscheduled 未排产：任务无排程且交期临近/已到期（可配 days）
 *   ② no_report    未报工：任务超过计划起点 N 小时（可配）仍无报工记录
 *   ③ overdue      逾期：交期 < now 且未完工
 *   ④ behind       落后：deviation_pct > 阈值（可配，默认 10）
 *
 * 关闭：写 red_light_closes（唯一键 source+task_id）+ audit_logs，重算跳过。
 * 红灯 id 采用确定性编码：id = task_id * 10 + source_code（1..4），可逆用于关闭。
 */
class RedLightService
{
    public const CACHE_KEY = 'dashboard.red_lights';

    public const SOURCE_UNSCHEDULED = 'unscheduled';
    public const SOURCE_NO_REPORT = 'no_report';
    public const SOURCE_OVERDUE = 'overdue';
    public const SOURCE_BEHIND = 'behind';

    public const SOURCE_LABELS = [
        self::SOURCE_UNSCHEDULED => '未排产',
        self::SOURCE_NO_REPORT => '未报工',
        self::SOURCE_OVERDUE => '逾期',
        self::SOURCE_BEHIND => '落后',
    ];

    /** source → 编码位（1..4），用于可逆 id */
    private const SOURCE_CODE = [
        self::SOURCE_UNSCHEDULED => 1,
        self::SOURCE_NO_REPORT => 2,
        self::SOURCE_OVERDUE => 3,
        self::SOURCE_BEHIND => 4,
    ];

    /**
     * 规则参数（可配置；测试可直接覆盖 config）
     */
    public function config(): array
    {
        return [
            'no_report_hours' => (float) config('dashboard.no_report_hours', 3),
            'deviation_pct' => (float) config('dashboard.deviation_pct', 10),
            'unscheduled_due_soon_days' => (int) config('dashboard.unscheduled_due_soon_days', 3),
        ];
    }

    /**
     * 红灯列表（缓存 10s；$fresh=true 强制重算）
     */
    public function list(bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $items = $this->calculate();

        Cache::put(self::CACHE_KEY, $items, 10);

        return $items;
    }

    /**
     * 四源计算（核心规则引擎）
     *
     * @return array<int, array<string, mixed>>
     */
    public function calculate(): array
    {
        $cfg = $this->config();
        $closed = RedLightClose::closedKeys();
        $items = [];

        $tasks = ProdTask::query()
            ->with(['line.order', 'product', 'schedules', 'mold'])
            ->get();

        foreach ($tasks as $task) {
            // ---- ① 未排产：无排程且交期临近/已到期 ----
            if ($task->status === ProdTask::STATUS_UNSCHEDULED
                && $task->delivery_date
                && $task->delivery_date->lte(now()->addDays((int) $cfg['unscheduled_due_soon_days'])->endOfDay())
                && ! isset($closed[$this->key(self::SOURCE_UNSCHEDULED, $task->id)]
                )) {
                $items[] = $this->item(
                    self::SOURCE_UNSCHEDULED,
                    $task,
                    sprintf('任务已建未排产，交期 %s（剩余 %d 天）', $task->delivery_date->format('Y-m-d'), max(0, (int) now()->startOfDay()->diffInDays($task->delivery_date, false))),
                    'high'
                );
            }

            $hasReport = $task->reports()->exists();

            // ---- ② 未报工：超过计划起点 N 小时仍无报工 ----
            if (in_array($task->status, [ProdTask::STATUS_SCHEDULED, ProdTask::STATUS_IN_PROGRESS, ProdTask::STATUS_PAUSED], true)
                && $task->plan_start
                && $task->plan_start->lt(now()->subHours((float) $cfg['no_report_hours']))
                && ! $hasReport
                && ! isset($closed[$this->key(self::SOURCE_NO_REPORT, $task->id)]
                )) {
                $hours = round(now()->diffInHours($task->plan_start), 1);
                $items[] = $this->item(
                    self::SOURCE_NO_REPORT,
                    $task,
                    sprintf('计划 %s 开始，已过 %s 小时仍无任何报工记录', $task->plan_start->format('m-d H:i'), $hours),
                    'high'
                );
            }

            // ---- ③ 逾期：交期 < now 且未完工 ----
            if ($task->status !== ProdTask::STATUS_DONE
                && $task->delivery_date
                && $task->delivery_date->lt(now())
                && ! isset($closed[$this->key(self::SOURCE_OVERDUE, $task->id)]
                )) {
                $days = max(1, (int) ceil(now()->diffInDays($task->delivery_date)));
                $items[] = $this->item(
                    self::SOURCE_OVERDUE,
                    $task,
                    sprintf('交期 %s 已逾期 %d 天，当前完成 %d/%d', $task->delivery_date->format('Y-m-d'), $days, $task->done_qty, $task->qty),
                    'high'
                );
            }

            // ---- ④ 落后 > 阈值：deviation_pct 落后（计划进度 vs 实际） ----
            if (in_array($task->status, [ProdTask::STATUS_SCHEDULED, ProdTask::STATUS_IN_PROGRESS, ProdTask::STATUS_PAUSED], true)
                && (float) $task->deviation_pct > (float) $cfg['deviation_pct']
                && ! isset($closed[$this->key(self::SOURCE_BEHIND, $task->id)]
                )) {
                $items[] = $this->item(
                    self::SOURCE_BEHIND,
                    $task,
                    sprintf('实际进度落后计划 %s%%（阈值 %s%%），建议重排', rtrim(rtrim(number_format((float) $task->deviation_pct, 1), '0'), '.'), rtrim(rtrim(number_format((float) $cfg['deviation_pct'], 1), '0'), '.')),
                    'high'
                );
            }
        }

        return $items;
    }

    /**
     * 按责任人聚合（前端红灯管理页结构）
     */
    public function grouped(array $items, ?string $source = null, ?string $status = null): array
    {
        $open = array_values(array_filter($items, fn ($r) => $r['status'] === 'open'));
        $filtered = array_values(array_filter($items, function ($r) use ($source, $status) {
            if ($source && $r['source'] !== $source) {
                return false;
            }
            if ($status && $r['status'] !== $status) {
                return false;
            }

            return true;
        }));

        $bySource = [];
        foreach ($open as $r) {
            $key = $r['source'];
            $bySource[$key] = ['source' => $key, 'source_label' => $r['source_label'], 'count' => ($bySource[$key]['count'] ?? 0) + 1];
        }

        $groups = [];
        foreach ($filtered as $r) {
            $key = $r['owner_id'] ?? 'none';
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'owner_id' => $r['owner_id'],
                    'owner_name' => $r['owner_name'] ?? '未指派',
                    'owner_workshop' => $r['owner_workshop'] ?? null,
                    'total' => 0,
                    'by_source' => [],
                    'items' => [],
                ];
            }
            $groups[$key]['total'] += 1;
            $groups[$key]['items'][] = $r;
        }

        foreach ($groups as &$g) {
            $src = [];
            foreach ($g['items'] as $r) {
                $key = $r['source'];
                $src[$key] = ['source' => $key, 'source_label' => $r['source_label'], 'count' => ($src[$key]['count'] ?? 0) + 1];
            }
            $g['by_source'] = array_values($src);
        }
        unset($g);

        return [
            'total' => count($items),
            'open_count' => count($open),
            'closed_count' => count($items) - count($open),
            'by_source' => array_values($bySource),
            'groups' => array_values($groups),
            'items' => array_values($filtered),
        ];
    }

    /**
     * 关闭红灯（写 red_light_closes + audit_logs，清缓存）
     */
    public function close(int $id, ?int $userId, string $reason, ?string $closedBy = null): array
    {
        [$source, $taskId] = $this->decodeId($id);
        if (! $source || ! $taskId) {
            throw new \InvalidArgumentException('红灯记录不存在');
        }

        $task = ProdTask::find($taskId);
        if (! $task) {
            throw new \InvalidArgumentException('关联任务不存在');
        }

        $by = $closedBy ?: optional(User::find($userId))->name ?? '系统管理员';

        RedLightClose::firstOrCreate(
            ['source' => $source, 'task_id' => $taskId],
            ['closed_by' => $by, 'user_id' => $userId, 'reason' => $reason]
        );

        \App\Models\AuditLog::record(
            user: $userId ? User::find($userId) : null,
            module: 'dashboard',
            action: 'red_light_close',
            targetType: 'task',
            targetId: $taskId,
            detail: ['source' => $source, 'reason' => $reason, 'closed_by' => $by]
        );

        Cache::forget(self::CACHE_KEY);

        return [
            'id' => $id,
            'source' => $source,
            'source_label' => self::SOURCE_LABELS[$source] ?? $source,
            'task_id' => $taskId,
            'task_no' => $task->task_no,
            'status' => 'closed',
            'closed_by' => $by,
            'close_reason' => $reason,
            'closed_at' => now()->toDateTimeString(),
        ];
    }

    // ==================== 内部工具 ====================

    private function key(string $source, int $taskId): string
    {
        return $source.':'.$taskId;
    }

    /**
     * 生成可逆红灯 id：task_id * 10 + source_code
     */
    public function encodeId(string $source, int $taskId): int
    {
        return $taskId * 10 + (self::SOURCE_CODE[$source] ?? 0);
    }

    /**
     * 解码红灯 id → [source, task_id]；非法返回 [null, null]
     *
     * @return array{0: string|null, 1: int|null}
     */
    public function decodeId(int $id): array
    {
        if ($id < 11) {
            return [null, null];
        }
        $taskId = intdiv($id, 10);
        $code = $id % 10;
        $source = array_search($code, self::SOURCE_CODE, true);

        return $source === false ? [null, null] : [$source, $taskId];
    }

    /**
     * 组装红灯条目
     */
    private function item(string $source, ProdTask $task, string $reason, string $level): array
    {
        $owner = $this->ownerOf($task, $source);

        return [
            'id' => $this->encodeId($source, (int) $task->id),
            'source' => $source,
            'source_label' => self::SOURCE_LABELS[$source] ?? $source,
            'level' => $level,
            'task_id' => (int) $task->id,
            'task_no' => $task->task_no,
            'product_code' => $task->product?->code,
            'product_name' => $task->product?->name,
            'order_no' => $task->line?->order?->order_no,
            'qty' => (int) $task->qty,
            'done_qty' => (int) $task->done_qty,
            'progress_pct' => $task->qty ? (int) round(($task->done_qty / $task->qty) * 100) : 0,
            'reason' => $reason,
            'plan_start' => $task->plan_start?->toDateTimeString(),
            'plan_end' => $task->plan_end?->toDateTimeString(),
            'delivery_date' => $task->delivery_date?->toDateString(),
            'owner_id' => $owner['id'],
            'owner_name' => $owner['name'],
            'owner_workshop' => $owner['workshop'],
            'status' => 'open',
            'closed_by' => null,
            'close_reason' => null,
            'created_at' => now()->toDateTimeString(),
            'closed_at' => null,
        ];
    }

    /**
     * 责任人解析：
     * - 未排产 → 计划员（job_type=planner，取最早建号）
     * - 其余 → 按任务首排程机台/产线所在车间，找该车间第一个班组长（job_type=leader）；无则回退计划员
     *
     * @return array{id: int|null, name: string|null, team: string|null}
     */
    private function ownerOf(ProdTask $task, string $source): array
    {
        $planner = User::where('job_type', User::JOB_PLANNER)->orderBy('id')->first();

        if ($source === self::SOURCE_UNSCHEDULED) {
            return [
                'id' => $planner?->id,
                'name' => $planner?->name,
                'workshop' => '计划科',
            ];
        }

        $schedule = $task->schedules->first();
        $workshopId = $schedule?->machine_id ? Machine::find($schedule->machine_id)?->workshop_id : null;

        if ($workshopId) {
            $leader = User::with('workshop')
                ->where('job_type', User::JOB_LEADER)
                ->where('workshop_id', $workshopId)
                ->orderBy('id')
                ->first();

            if ($leader) {
                return [
                    'id' => $leader->id,
                    'name' => $leader->name,
                    'workshop' => $leader->workshop?->name,
                ];
            }
        }

        return [
            'id' => $planner?->id,
            'name' => $planner?->name,
            'workshop' => $planner ? '计划科' : null,
        ];
    }
}
