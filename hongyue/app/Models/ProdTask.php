<?php

namespace App\Models;

use App\Models\WorkReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 生产任务（排产对象，产品级）—— 对应架构 §3.3 prod_tasks 表
 *
 * - status: unscheduled(待排)/scheduled(已排)/in_progress/paused/done/split(拆单父任务)
 * - is_locked: 排产保存即锁定；解锁需 reason 并留审计
 */
class ProdTask extends Model
{
    use SoftDeletes;

    /** 状态 */
    public const STATUS_UNSCHEDULED = 'unscheduled';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE = 'done';
    public const STATUS_SPLIT = 'split';

    public const STATUS_LABELS = [
        self::STATUS_UNSCHEDULED => '待排产',
        self::STATUS_SCHEDULED => '已排产',
        self::STATUS_IN_PROGRESS => '生产中',
        self::STATUS_PAUSED => '已暂停',
        self::STATUS_DONE => '已完成',
        self::STATUS_SPLIT => '已拆单',
    ];

    protected $fillable = [
        'parent_task_id',
        'task_no',
        'order_line_id',
        'product_id',
        'route_id',
        'route_ops_override',
        'mold_id',
        'qty',
        'done_qty',
        'stocked_qty',
        'status',
        'priority',
        'delivery_date',
        'deviation_pct',
        'plan_start',
        'plan_end',
        'is_locked',
        'source',
        'split_index',
        'split_total',
        'is_split_parent',
    ];

    protected $casts = [
        'parent_task_id' => 'integer',
        'order_line_id' => 'integer',
        'product_id' => 'integer',
        'route_id' => 'integer',
        'route_ops_override' => 'array',
        'mold_id' => 'integer',
        'qty' => 'integer',
        'done_qty' => 'integer',
        'stocked_qty' => 'integer',
        'priority' => 'integer',
        'deviation_pct' => 'float',
        'plan_start' => 'datetime',
        'plan_end' => 'datetime',
        'delivery_date' => 'date',
        'is_locked' => 'integer',
        'split_index' => 'integer',
        'split_total' => 'integer',
        'is_split_parent' => 'integer',
    ];

    /**
     * 拆单父任务只是关系容器，不是可排产任务。
     * 统一使用全局作用域避免任务池、甘特、报工、入库和看板重复统计父任务。
     */
    protected static function booted(): void
    {
        static::addGlobalScope('leaf_task', function (Builder $builder): void {
            $builder->where($builder->getModel()->getTable().'.is_split_parent', 0);
        });
    }

    /** 只返回可实际生产的叶子任务。 */
    public function scopeLeaf(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.is_split_parent', 0);
    }

    /**
     * 所属订单明细
     */
    public function line()
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    /** 拆单父任务（查询父任务时会跳过 leaf_task 全局作用域）。 */
    public function parentTask()
    {
        return $this->belongsTo(self::class, 'parent_task_id')->withoutGlobalScopes();
    }

    /** 拆单产生的子任务。 */
    public function childTasks()
    {
        return $this->hasMany(self::class, 'parent_task_id')->withoutGlobalScopes()->orderBy('split_index');
    }

    /**
     * 产品
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 排产锁定的工艺路线
     */
    public function route()
    {
        return $this->belongsTo(PlmRoute::class, 'route_id');
    }

    /**
     * 选定模具
     */
    public function mold()
    {
        return $this->belongsTo(Mold::class, 'mold_id');
    }

    /**
     * 工序排程明细
     */
    public function schedules()
    {
        return $this->hasMany(TaskSchedule::class, 'task_id')->orderBy('seq');
    }

    /**
     * 报工流水（只增不改）
     */
    public function reports()
    {
        return $this->hasMany(WorkReport::class, 'task_id')->orderByDesc('report_time');
    }

    /**
     * 入库单
     */
    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'task_id')->orderByDesc('id');
    }

    /**
     * 净完成合格数（Σ正 − Σ冲减）
     */
    public function netDoneQty(): int
    {
        return (int) $this->reports()->sum(\Illuminate\Support\Facades\DB::raw('qty'));
    }

    /**
     * 状态中文名
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * 是否已排产
     */
    public function isScheduled(): bool
    {
        return in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS, self::STATUS_PAUSED], true);
    }

    /**
     * 生效工序：优先订单级快照，否则取工艺路线库（按 seq 排序）
     *
     * @return array<int, array{seq:int, op_type:string, op_name:string, workshop_id:int|null,
     *                          machine_id:int|null, line_id:int|null,
     *                          std_hours:float|null, owner_id:int|null, remark:string|null}>
     */
    public function effectiveOps(): array
    {
        if ($this->route_ops_override) {
            $ops = $this->route_ops_override;
            usort($ops, fn ($a, $b) => (int) ($a['seq'] ?? 0) <=> (int) ($b['seq'] ?? 0));

            return array_values($ops);
        }

        $route = $this->route;
        if (! $route) {
            return [];
        }

        return $route->ops->map(fn (RouteOp $op) => [
            'seq' => (int) $op->seq,
            'op_type' => $op->op_type,
            'op_name' => $op->op_name,
            'workshop_id' => $op->workshop_id,
            'machine_id' => $op->default_machine_id,
            'line_id' => $op->default_line_id,
            'std_hours' => $op->std_hours,
            'owner_id' => $op->owner_id,
            'remark' => $op->remark,
            'qty' => null, // 未定制时无工序数量，排产/展示按任务数量兜底
        ])->all();
    }

    /**
     * 是否已被订单定制过（route_ops_override 非空）
     */
    public function hasRouteOpsOverride(): bool
    {
        return ! empty($this->route_ops_override);
    }
}
