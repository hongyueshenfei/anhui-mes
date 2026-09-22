<template>
  <el-card shadow="never">
    <template #header>
      <div class="detail-header">
        <span>
          工单中心 · 订单详情 <el-tag size="small" class="ml8">{{ detail?.order_no ?? '' }}</el-tag>
        </span>
        <div class="op-bar">
          <el-button size="small" @click="router.back()">返回</el-button>
          <el-button size="small" type="warning" :disabled="!canConfirm" @click="onConfirmRoute">确认工艺</el-button>
          <el-button size="small" type="primary" :disabled="!canEdit" @click="openEdit">编辑</el-button>
          <el-button size="small" type="success" :disabled="!canStart" @click="onStart">开始</el-button>
          <el-button size="small" type="warning" :disabled="!canReport" @click="openReport">报工</el-button>
          <el-button size="small" type="success" :disabled="!canComplete" @click="onComplete">完结</el-button>
          <el-button size="small" type="danger" :disabled="!canRevoke" @click="onRevoke">撤销</el-button>
          <el-button size="small" @click="openLogs">日志</el-button>
        </div>
      </div>
    </template>

    <el-empty v-if="!detail && !loading" description="订单不存在" :image-size="70" />

    <template v-if="detail">
      <el-descriptions :column="4" border size="small" class="mb-12">
        <el-descriptions-item label="订单号">{{ detail.order_no }}</el-descriptions-item>
        <el-descriptions-item label="客户">{{ detail.customer_name }}</el-descriptions-item>
        <el-descriptions-item label="来源">{{ srcLabel(detail.src) }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag size="small" :type="statusType(detail.status)">{{ statusLabel(detail.status) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="优先级">
          <el-tag size="small" :type="priorityType(detail.priority)">P{{ detail.priority }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="期望交期">{{ fmtDate(detail.delivery_date) }}</el-descriptions-item>
        <el-descriptions-item label="创建人">{{ detail.creator?.name ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="备注">{{ detail.remark ?? '-' }}</el-descriptions-item>
      </el-descriptions>

      <!-- 完工进度已下移至产品行「完工进度」列；工序级进度见下方「工艺路线及排产」工序表 -->
      <el-table ref="linesTableRef" :data="detail.lines ?? []" size="small" border stripe class="lines-table">
        <el-table-column prop="line_no" label="行" :width="lineWidths.line_no" align="center" />
        <el-table-column label="产品" :width="lineWidths.product" min-width="150">
          <template #default="{ row }">
            {{ row.product?.name }}（{{ row.product?.code }}）
          </template>
        </el-table-column>
        <el-table-column prop="qty" label="数量" :width="lineWidths.qty" align="center" />
        <el-table-column prop="delivery_date" label="交期" :width="lineWidths.delivery_date">
          <template #default="{ row }">{{ fmtDate(row.delivery_date) }}</template>
        </el-table-column>
        <el-table-column label="状态" :width="lineWidths.status" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="lineStatusType(row.status)">{{ lineStatusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <!-- 完工进度（分工序：与工单中心「总进度」同款节点样式；数据=行内各任务 stages 合并） -->
        <el-table-column label="完工进度" :width="lineWidths.progress" min-width="360" align="center">
          <template #default="{ row }">
            <OrderProgressSteps
              v-if="lineProgress(row)?.stages?.length"
              :stages="lineProgress(row)!.stages"
              :percent="lineProgress(row)?.percent"
              compact
            />
            <span v-else class="percent-text">{{ linePercent(row) }}%</span>
          </template>
        </el-table-column>
        <el-table-column label="完工数" :width="lineWidths.done_qty" align="center">
          <template #default="{ row }">{{ lineDoneQty(row) }}</template>
        </el-table-column>
        <el-table-column label="剩余数" :width="lineWidths.remain_qty" align="center">
          <template #default="{ row }">{{ lineRemainQty(row) }}</template>
        </el-table-column>
        <!-- 工期状态：逾期（排产晚于交期 / 超期未完成）/ 正常 / 未排产 -->
        <el-table-column label="工期状态" :width="lineWidths.overdue" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="overdueType(lineOverdue(row))">{{ overdueLabel(lineOverdue(row)) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="排产开始" :width="lineWidths.plan_start">
          <template #default="{ row }">
            <span class="readonly-cell" :class="{ 'cell-muted': !lineExtreme(row, 'plan_start', 'min') }">{{ fmtDateTime(lineExtreme(row, 'plan_start', 'min')) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="排产结束" :width="lineWidths.plan_end">
          <template #default="{ row }">
            <span class="readonly-cell" :class="{ 'cell-muted': !lineExtreme(row, 'plan_end', 'max') }">{{ fmtDateTime(lineExtreme(row, 'plan_end', 'max')) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="实际开工" :width="lineWidths.actual_start">
          <template #default="{ row }">
            <span class="readonly-cell" :class="{ 'cell-muted': !lineExtreme(row, 'actual_start', 'min') }">{{ fmtDateTime(lineExtreme(row, 'actual_start', 'min')) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="实际完工" :width="lineWidths.actual_end">
          <template #default="{ row }">
            <span class="readonly-cell" :class="{ 'cell-muted': !lineExtreme(row, 'actual_end', 'max') }">{{ fmtDateTime(lineExtreme(row, 'actual_end', 'max')) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="累计工时(h)" :width="lineWidths.total_hours" align="center">
          <template #default="{ row }">
            <span class="readonly-cell" title="按排程计划时长累计（实际工时待报工接入时长后替换）">{{ lineTotalHours(row) }}</span>
          </template>
        </el-table-column>
      </el-table>

      <!-- 工艺路线及排产（确认工艺阶段可编辑；排产/完工列来自排程与报工，全状态只读展示） -->
      <div v-if="cfgTasksExist" class="route-confirm-box" :class="{ 'ro-mode': !canConfirm }">
        <div class="route-confirm-head">
          <span class="route-confirm-title">工艺路线及排产</span>
          <span class="route-confirm-hint">选择工艺路线后下方展示该订单的工序明细；排产/完工列由排产与报工自动带出（不可修改）</span>
        </div>
        <div v-for="l in detail.lines ?? []" :key="l.id">
          <div v-for="t in l.tasks ?? []" :key="t.id" class="route-task">
            <div class="route-row">
              <span class="task-no">{{ t.task_no }}</span>
              <span class="task-qty">{{ t.qty }}件</span>
              <el-select
                :model-value="cfgDraft(t)?.route_id ?? null"
                size="small"
                style="width: 220px"
                placeholder="请选择工艺路线"
                :disabled="!canConfirm"
                @update:model-value="(v: number | undefined) => onCfgRouteChange(t, v)"
              >
                <el-option v-for="r in routeOptions" :key="r.id" :value="r.id" :label="r.label" />
              </el-select>
              <el-tag v-if="!cfgDraft(t)?.route_id" size="small" type="danger">未配置</el-tag>
              <el-tag v-else-if="cfgDraft(t)?.overridden" size="small" type="warning">已定制</el-tag>
            </div>

            <!-- 内嵌工序明细：选择路线后直接展示在当前订单下方（可编辑，保存后仅作用于当前订单） -->
            <div v-if="cfgOps(t).length" class="inline-route-editor">
              <div class="inline-route-title">
                <span class="inline-route-label">工序明细 · {{ taskRouteLabel(t) }}</span>
                <el-button v-if="canConfirm && cfgDraft(t)?.overridden" link type="danger" size="small" @click="cfgRestoreStandard(t)">还原标准路线</el-button>
              </div>
              <el-table :data="cfgOps(t)" size="small" border max-height="360">
                <el-table-column label="序号" width="54" align="center">
                  <template #default="{ $index }">{{ $index + 1 }}</template>
                </el-table-column>
                <el-table-column label="工序名称" min-width="150">
                  <template #default="{ row }">
                    <div :class="{ 'cell-invalid': !(row.op_name ?? '').trim() }">
                      <el-select
                        v-model="row.op_type"
                        size="small"
                        style="width: 100%"
                        placeholder="选择工序"
                        :disabled="!canConfirm"
                        @update:model-value="(v: string) => onOpTypeChange(row, v)"
                      >
                        <el-option v-for="o in opTypeOptions" :key="o.op_type" :value="o.op_type" :label="o.op_name" />
                      </el-select>
                    </div>
                  </template>
                </el-table-column>
                <el-table-column label="数量" width="110">
                  <template #default="{ row }">
                    <el-input-number v-model="row.qty" :min="1" :precision="0" :controls="false" size="small" :disabled="!canConfirm" style="width: 100%" />
                  </template>
                </el-table-column>
                <!-- 排产/完工列：只读，来自排程与报工（确认工艺阶段不可修改） -->
                <el-table-column label="排产开始" width="150">
                  <template #default="{ row }">
                    <span class="readonly-cell" :class="{ 'cell-muted': !row.plan_start }">{{ row.plan_start ? schedTime(row.plan_start) : '待排产' }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="排产结束" width="150">
                  <template #default="{ row }">
                    <span class="readonly-cell" :class="{ 'cell-muted': !row.plan_end }">{{ row.plan_end ? schedTime(row.plan_end) : '-' }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="排产机台/产线" min-width="160">
                  <template #default="{ row }">
                    <span v-if="row.machines?.length" class="readonly-cell" :title="row.machines.join('、')">{{ row.machines.join('、') }}</span>
                    <span v-else class="readonly-cell cell-muted">-</span>
                  </template>
                </el-table-column>
                <el-table-column label="完工数量" width="90" align="center">
                  <template #default="{ row }">
                    <span class="readonly-cell">{{ row.done_qty ?? 0 }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="完工进度" width="120">
                  <template #default="{ row }">
                    <div v-if="row.percent != null" class="percent-cell">
                      <div class="percent-bar"><div class="percent-fill" :style="{ width: Math.min(100, row.percent) + '%' }"></div></div>
                      <span class="percent-text">{{ row.percent }}%</span>
                    </div>
                    <span v-else class="readonly-cell cell-muted">-</span>
                  </template>
                </el-table-column>
                <el-table-column label="标准工时(h)" min-width="100">
                  <template #default="{ row }">
                    <el-input-number v-model="row.std_hours" :min="0" :precision="2" :controls="false" size="small" :disabled="!canConfirm" style="width: 100%" />
                  </template>
                </el-table-column>
                <el-table-column label="负责人" min-width="140">
                  <template #default="{ row }">
                    <el-select v-model="row.owner_id" size="small" filterable clearable placeholder="选负责人" :disabled="!canConfirm" style="width: 100%">
                      <el-option v-for="u in userOptions" :key="u.id" :value="u.id" :label="`${u.name}（${u.employee_no}）`" />
                    </el-select>
                  </template>
                </el-table-column>
                <el-table-column label="备注" min-width="120">
                  <template #default="{ row }">
                    <el-input v-model="row.remark" size="small" placeholder="备注" :disabled="!canConfirm" />
                  </template>
                </el-table-column>
                <el-table-column label="操作" width="150" align="center" class-name="op-col">
                  <template #default="{ $index }">
                    <el-button class="op-icon-btn" link size="small" :disabled="!canConfirm || $index === 0" @click="cfgMoveOp(t, $index, -1)">
                      <el-icon><Top /></el-icon><span class="op-text">上移</span>
                    </el-button>
                    <el-button class="op-icon-btn" link size="small" :disabled="!canConfirm || $index === cfgOps(t).length - 1" @click="cfgMoveOp(t, $index, 1)">
                      <el-icon><Bottom /></el-icon><span class="op-text">下移</span>
                    </el-button>
                    <el-button class="op-icon-btn" link type="danger" size="small" :disabled="!canConfirm" @click="cfgRemoveOp(t, $index)">
                      <el-icon><Delete /></el-icon><span class="op-text">删除</span>
                    </el-button>
                  </template>
                </el-table-column>
              </el-table>
              <div class="inline-route-actions">
                <el-button v-if="canConfirm" size="small" type="primary" plain @click="cfgAddOp(t)">+ 添加工序</el-button>
                <span class="rte-hint">修改仅保存到当前订单，不影响工艺路线库</span>
              </div>
            </div>
          </div>
        </div>
        <div class="route-confirm-actions">
          <el-button v-if="canConfirm" size="small" type="primary" :disabled="!hasCfgDrafts" :loading="routeSaving" @click="onSaveRoutes">保存工艺</el-button>
          <el-button v-if="canConfirm" size="small" type="warning" :disabled="!allRoutesConfigured" @click="onConfirmRoute">确认工艺完成</el-button>
        </div>
      </div>

      <!-- 领料清单（详情页展示框：按已发布 BOM 自动计算需求量） -->
      <div class="picklist-box" v-loading="detailPickLoading">
        <div class="picklist-head">
          <span class="picklist-title">领料清单</span>
          <span class="picklist-hint">按订单各产品行已发布 BOM 自动计算需求量</span>
        </div>
        <template v-if="detailPick">
          <div v-for="l in detailPick.lines" :key="l.line_no" class="pick-card">
            <div class="pick-card-head">
              <span class="pick-line-no">行{{ l.line_no }}</span>
              <span class="pick-product">{{ l.product_name ?? '-' }}（{{ l.product_code ?? '-' }}）</span>
              <span class="pick-qty">订单数量 {{ l.qty }}</span>
              <el-tag v-if="l.bom_id" size="small" type="success">BOM {{ l.bom_version }}</el-tag>
              <el-tag v-else size="small" type="danger">无已发布 BOM</el-tag>
            </div>
            <!-- 列宽自适应：全部用 min-width，由 el-table 按 比例 flex 分配剩余空间 -->
            <el-table v-if="l.items.length" :data="l.items" size="small" border max-height="420">
              <el-table-column label="物料编码" min-width="120">
                <template #default="{ row }"><span class="readonly-cell">{{ row.item_code }}</span></template>
              </el-table-column>
              <el-table-column label="物料名称" min-width="160">
                <template #default="{ row }"><span class="readonly-cell">{{ row.item_name }}</span></template>
              </el-table-column>
              <el-table-column label="规格" min-width="110">
                <template #default="{ row }"><span class="readonly-cell">{{ row.spec ?? '-' }}</span></template>
              </el-table-column>
              <el-table-column label="单位" min-width="70" align="center">
                <template #default="{ row }"><span class="readonly-cell">{{ row.unit }}</span></template>
              </el-table-column>
              <el-table-column label="单件用量" min-width="100" align="right">
                <template #default="{ row }"><span class="readonly-cell">{{ row.unit_qty }}</span></template>
              </el-table-column>
              <el-table-column label="损耗率%" min-width="90" align="right">
                <template #default="{ row }"><span class="readonly-cell">{{ row.loss_rate }}</span></template>
              </el-table-column>
              <el-table-column label="订单需求量" min-width="120" align="right">
                <template #default="{ row }"><span class="readonly-cell">{{ row.required_qty }}</span></template>
              </el-table-column>
            </el-table>
            <el-empty v-else description="无物料明细" :image-size="48" />
          </div>
          <div class="pick-summary">
            <span>物料种类：<b>{{ detailPick.summary.item_count }}</b></span>
          </div>
        </template>
        <el-empty v-else description="暂无领料数据（无产品明细或均无已发布 BOM）" :image-size="60" />
      </div>
    </template>

    <!-- 编辑工单（基础信息 / 工艺路线明细 / 领料清单） -->
    <el-dialog v-model="editVisible" title="编辑工单" :width="isMobile.matches ? '96%' : '960px'" top="5vh" append-to-body>
      <el-tabs v-model="editTab">
        <!-- Tab 1 · 基础信息 -->
        <el-tab-pane label="基础信息" name="base">
          <el-form label-width="90px">
            <el-form-item label="客户名称">
              <el-input v-model="editForm.customer_name" placeholder="客户名称" />
            </el-form-item>
            <el-form-item label="优先级">
              <el-input-number v-model="editForm.priority" :min="1" :max="5" />
            </el-form-item>
            <el-form-item label="期望交期">
              <el-date-picker v-model="editForm.delivery_date" type="date" value-format="YYYY-MM-DD" style="width: 180px" />
            </el-form-item>
            <el-form-item label="备注">
              <el-input v-model="editForm.remark" type="textarea" :rows="2" placeholder="备注" />
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- Tab 2 · 工艺路线明细 -->
        <el-tab-pane label="工艺路线明细" name="route">
          <div v-if="editRouteLoading" class="tab-loading">工艺明细加载中…</div>
          <template v-else>
            <el-empty v-if="!routeTasks.length" description="该订单暂无生产任务" :image-size="60" />
            <div v-for="task in routeTasks" :key="task.task_id" class="task-card">
              <div class="task-card-head">
                <div class="task-card-info">
                  <span class="task-no">{{ task.task_no }}</span>
                  <span class="task-product">{{ task.product_name ?? '-' }}</span>
                  <span class="task-qty">{{ task.qty }}件</span>
                </div>
                <div class="task-card-route">
                  <el-select
                    :model-value="task.route_id"
                    size="small"
                    style="width: 220px"
                    placeholder="请选择工艺路线"
                    :disabled="!canConfirm"
                    @update:model-value="(v: number | undefined) => onTaskRouteChange(task, v)"
                  >
                    <el-option v-for="r in routeOptions" :key="r.id" :value="r.id" :label="r.label" />
                  </el-select>
                  <el-tag size="small" :type="isTaskOverridden(task) ? 'warning' : 'info'">
                    {{ isTaskOverridden(task) ? '已定制' : '沿用标准' }}
                  </el-tag>
                  <el-button v-if="isTaskOverridden(task)" link type="primary" size="small" :disabled="!canConfirm" @click="onRestoreStandard(task)">
                    恢复标准路线
                  </el-button>
                </div>
              </div>
              <el-table :data="task.ops" size="small" border max-height="420">
                <el-table-column label="序号" width="54" align="center">
                  <template #default="{ $index }">{{ $index + 1 }}</template>
                </el-table-column>
                <el-table-column label="工序名称" min-width="150">
                  <template #default="{ row }">
                    <div :class="{ 'cell-invalid': !(row.op_name ?? '').trim() }">
                      <el-select
                        v-model="row.op_type"
                        size="small"
                        style="width: 100%"
                        placeholder="选择工序"
                        :disabled="!canConfirm"
                        @update:model-value="(v: string) => onOpTypeChange(row, v)"
                      >
                        <el-option v-for="o in opTypeOptions" :key="o.op_type" :value="o.op_type" :label="o.op_name" />
                      </el-select>
                    </div>
                  </template>
                </el-table-column>
                <el-table-column label="数量" width="110">
                  <template #default="{ row }">
                    <el-input-number v-model="row.qty" :min="1" :precision="0" :controls="false" size="small" :disabled="!canConfirm" style="width: 100%" />
                  </template>
                </el-table-column>
                <el-table-column label="标准工时(h)" min-width="110">
                  <template #default="{ row }">
                    <el-input-number v-model="row.std_hours" :min="0" :precision="2" :controls="false" size="small" :disabled="!canConfirm" style="width: 100%" />
                  </template>
                </el-table-column>
                <el-table-column label="负责人" min-width="150">
                  <template #default="{ row }">
                    <el-select v-model="row.owner_id" size="small" filterable clearable placeholder="选负责人" :disabled="!canConfirm" style="width: 100%">
                      <el-option v-for="u in userOptions" :key="u.id" :value="u.id" :label="`${u.name}（${u.employee_no}）`" />
                    </el-select>
                  </template>
                </el-table-column>
                <el-table-column label="备注" min-width="140">
                  <template #default="{ row }">
                    <el-input v-model="row.remark" size="small" placeholder="备注" :disabled="!canConfirm" />
                  </template>
                </el-table-column>
                <el-table-column label="操作" width="160" align="center" class-name="op-col">
                  <template #default="{ $index }">
                    <el-button class="op-icon-btn" link size="small" :disabled="!canConfirm || $index === 0" @click="moveOp(task, $index, -1)">
                      <el-icon><Top /></el-icon><span class="op-text">上移</span>
                    </el-button>
                    <el-button class="op-icon-btn" link size="small" :disabled="!canConfirm || $index === task.ops.length - 1" @click="moveOp(task, $index, 1)">
                      <el-icon><Bottom /></el-icon><span class="op-text">下移</span>
                    </el-button>
                    <el-button class="op-icon-btn" link type="danger" size="small" :disabled="!canConfirm" @click="removeOp(task, $index)">
                      <el-icon><Delete /></el-icon><span class="op-text">删除</span>
                    </el-button>
                  </template>
                </el-table-column>
              </el-table>
              <div class="task-card-foot">
                <el-button size="small" type="primary" plain :disabled="!canConfirm" @click="addOp(task)">+ 添加工序</el-button>
              </div>
            </div>
          </template>
        </el-tab-pane>
      </el-tabs>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="editLoading" @click="onEditSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 生产报工（公共组件：与报工台账手动报工同款界面；从订单进入自动带入本订单任务） -->
    <ReportDialog
      v-model="reportVisible"
      :preset-tasks="reportTasks"
      :preset-task-id="reportTasks[0]?.id ?? null"
      @success="load"
    />

    <!-- 操作日志 -->
    <el-dialog v-model="logVisible" title="工单操作日志" width="660px">
      <el-table :data="logs" size="small" border max-height="420">
        <el-table-column label="动作" width="90">
          <template #default="{ row }">
            <el-tag size="small">{{ logActionLabel(row.action) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态流转" width="140">
          <template #default="{ row }">{{ statusLabel(row.from_status) }} → {{ statusLabel(row.to_status) }}</template>
        </el-table-column>
        <el-table-column prop="operator_name" label="操作人" width="100" />
        <el-table-column prop="remark" label="备注" />
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ fmtDateTime(row.created_at) }}</template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Top, Bottom, Delete } from '@element-plus/icons-vue'
import { orderApi } from '@/api/order'
import { plmApi } from '@/api/plm'
import { systemApi } from '@/api/system'
import { useColumnResize } from '@/composables/useColumnResize'
import { formatDate, formatDateTime } from '@/utils/datetime'
import { useAuthStore } from '@/stores/auth'
import type { Machine, RouteOp } from '@/types'
import type { Order, OrderLine, ProdTask, ProgressStage, WorkOrderLog } from '@/types/schedule'
import OrderProgressSteps from '@/components/order/OrderProgressSteps.vue'
import ReportDialog from '@/components/production/ReportDialog.vue'
import type {
  OrderPickListPayload,
  OrderRouteOpsSubmitTask,
  OrderRouteOpView,
  OrderTaskRouteOps,
  UserOption,
} from '@/types/orderEdit'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const canManage = computed(() => auth.can('order.manage'))

/** 订单明细表格：列宽可拖拽（列少时由 el-table fit 拉伸铺满界面） */
const linesTableRef = ref<{ $el: HTMLElement } | null>(null)
const lineWidths = reactive<Record<string, number>>({
  line_no: 50,
  product: 200,
  qty: 80,
  delivery_date: 100,
  status: 80,
  progress: 360, // 2026-09-01: 完工进度列改用分工序节点（与工单中心「总进度」同款），130 → 360
  done_qty: 80,
  remain_qty: 80,
  overdue: 90,
  plan_start: 130,
  plan_end: 130,
  actual_start: 130,
  actual_end: 130,
  total_hours: 96,
})
useColumnResize(linesTableRef, lineWidths, [
  'line_no', 'product', 'qty', 'delivery_date', 'status', 'progress',
  'done_qty', 'remain_qty', 'overdue', 'plan_start', 'plan_end', 'actual_start', 'actual_end', 'total_hours',
])

const fmtDateTime = formatDateTime
const fmtDate = formatDate

const detail = ref<Order | null>(null)
const loading = ref(false)

const logs = ref<WorkOrderLog[]>([])
const editVisible = ref(false)
const editLoading = ref(false)
const editForm = ref<{
  customer_name: string
  priority: number
  delivery_date: string | null
  remark: string
}>({ customer_name: '', priority: 3, delivery_date: null, remark: '' })

// ==================== 订单编辑 · 工艺路线明细（Tab2） ====================

/** 窄屏判定（桌面 960px / 移动端 96%） */
const isMobile = window.matchMedia('(max-width: 768px)')
/** 编辑弹窗当前 Tab */
const editTab = ref('base')
/** 工艺明细加载中（打开弹窗时异步拉取） */
const editRouteLoading = ref(false)

/** 工序类型 → 工序中文名（添加工序默认行自动带出） */
const OP_NAMES: Record<string, string> = {
  molding_injection: '注塑成型', molding_blow: '吹塑成型', paint: '喷漆', effect: '效果处理', pack: '包装',
}
/** 工序类型 → 车间 id（按 op_type 推导默认车间） */
const OP_WORKSHOP_IDS: Record<string, number> = { molding_injection: 1, molding_blow: 2, paint: 3, effect: 4, pack: 5 }
/** 车间 id → 名称（展示辅助；与后端 Seeder 口径一致） */
const WORKSHOP_NAMES: Record<number, string> = { 1: '注塑车间', 2: '吹塑车间', 3: '喷漆车间', 4: '效果车间', 5: '包装车间' }
/** 常见工序类型顺序（添加工序取最后一道的下一个常见类型） */
const OP_TYPE_ORDER = ['molding_injection', 'molding_blow', 'paint', 'effect', 'pack']

/** 工序类型候选（工序名称下拉；来自平台工序模板/字典，含默认车间） */
const opTypeOptions = ref<Array<{ op_type: string; op_name: string; workshop_id: number }>>([])
async function loadOpTypeOptions(): Promise<void> {
  try {
    const tpls = await plmApi.opTemplates()
    opTypeOptions.value = (tpls ?? []).map((t) => ({
      op_type: t.op_type,
      op_name: t.op_name,
      workshop_id: OP_WORKSHOP_IDS[t.op_type] ?? null,
    }))
  } catch {
    opTypeOptions.value = OP_TYPE_ORDER.map((opType) => ({
      op_type: opType,
      op_name: OP_NAMES[opType] ?? opType,
      workshop_id: OP_WORKSHOP_IDS[opType] ?? null,
    }))
  }
}

/** 工序类型变更联动：名称 + 默认车间（数量保持原值） */
function onOpTypeChange(op: OrderRouteOpView, opType: string): void {
  const opt = opTypeOptions.value.find((o) => o.op_type === opType)
  op.op_type = opType
  op.op_name = opt?.op_name ?? OP_NAMES[opType] ?? opType
  op.workshop_id = opt?.workshop_id ?? OP_WORKSHOP_IDS[opType] ?? null
}

/** 任务工艺编辑态（含「恢复标准路线」跟踪标记） */
interface EditableTask extends OrderTaskRouteOps {
  /** 已点「恢复标准路线」：保存时按 ops 与标准快照是否一致提交空数组（清空 override）或当前明细 */
  restore_standard?: boolean
  /** 恢复标准时的 ops 快照（JSON 字符串），用于判断是否再次定制 */
  standard_snapshot?: string
}

/** 工艺明细草稿（仅本地，取消/关闭不落库） */
const routeTasks = ref<EditableTask[]>([])
/** 打开弹窗时的工艺明细快照（保存时对比变更） */
const routeTasksOriginal = ref<EditableTask[]>([])
/** 详情页「领料清单」展示框数据（只读，按已发布 BOM 计算需求量） */
const detailPick = ref<OrderPickListPayload | null>(null)
const detailPickLoading = ref(false)
/** 负责人候选 */
const userOptions = ref<UserOption[]>([])

/** 排产时间本地格式化（后端返回 Y-m-d H:i，无需引入日期库） */
function schedTime(v: string | null | undefined): string {
  return v ? String(v).slice(0, 16).replace('T', ' ') : ''
}

/** 工艺确认阶段不选择设备：设备/产线在排产阶段由排产模块指定 */

/** 路线库工序 → 工序行视图（机器/产线恒为 null，排产阶段由排产模块选择；工时/负责人带路线库默认值；数量默认 = 任务数量） */
function routeOpToView(op: RouteOp, taskQty: number): OrderRouteOpView {
  return {
    seq: op.seq,
    op_type: op.op_type,
    op_name: op.op_name,
    workshop_id: op.workshop_id ?? OP_WORKSHOP_IDS[op.op_type] ?? null,
    machine_id: null,
    line_id: null,
    std_hours: op.std_hours ?? null,
    owner_id: op.owner_id ?? null,
    remark: op.remark ?? null,
    qty: taskQty,
    workshop_name: null,
    machine_name: null,
    line_name: null,
    owner_name: null,
  }
}

/** 展示层「是否已定制」：恢复标准后若再次改动工序，视为重新定制 */
function isTaskOverridden(task: EditableTask): boolean {
  if (task.restore_standard) {
    return JSON.stringify(task.ops) !== task.standard_snapshot
  }
  return task.overridden
}

/** 任务工艺明细重载：从路线库拉取标准工序（切换路线 / 恢复标准共用） */
async function reloadTaskRouteOps(task: EditableTask): Promise<void> {
  if (!task.route_id) {
    task.ops = []
    return
  }
  try {
    const route = await plmApi.getRoute(task.route_id)
    task.route_name = route.name
    task.route_version = route.version
    task.ops = (route.ops ?? []).map((op) => routeOpToView(op, Number(task.qty ?? 1)))
    renumberOps(task)
  } catch {
    task.ops = []
  }
}

/** 切换工艺路线：已定制时先确认，确认后覆盖为路线库明细 */
async function onTaskRouteChange(task: EditableTask, value: number | undefined): Promise<void> {
  const routeId = value ?? null
  if (routeId === null || routeId === task.route_id) return
  if (isTaskOverridden(task)) {
    const ok = await ElMessageBox.confirm('该任务已定制过工序明细，切换路线将覆盖定制内容，是否继续？', '切换工艺路线', { type: 'warning' }).catch(() => false)
    if (!ok) return // 受控组件：task.route_id 未变，select 显示自动回到旧值
  }
  const prev = {
    route_id: task.route_id,
    overridden: task.overridden,
    restore_standard: task.restore_standard,
    standard_snapshot: task.standard_snapshot,
  }
  task.route_id = routeId
  task.overridden = true // 切换路线视为按新路线定制基准
  task.restore_standard = false
  task.standard_snapshot = undefined
  try {
    const route = await plmApi.getRoute(routeId)
    task.route_name = route.name
    task.route_version = route.version
    task.ops = (route.ops ?? []).map((op) => routeOpToView(op, Number(task.qty ?? 1)))
    renumberOps(task)
  } catch {
    // 路线库明细拉取失败：回滚切换，避免状态与显示不一致
    task.route_id = prev.route_id
    task.overridden = prev.overridden
    task.restore_standard = prev.restore_standard
    task.standard_snapshot = prev.standard_snapshot
    task.ops = []
  }
}

/** 恢复标准路线：清空 override（保存时提交空数组），重新拉取路线库明细 */
async function onRestoreStandard(task: EditableTask): Promise<void> {
  task.overridden = false
  task.restore_standard = true
  task.standard_snapshot = undefined
  await reloadTaskRouteOps(task)
  task.standard_snapshot = JSON.stringify(task.ops)
}

/** + 添加工序：op_type 默认成型，已有行则取最后一道的下一个常见类型 */
function addOp(task: EditableTask): void {
  const last = task.ops[task.ops.length - 1]
  let opType = 'molding_injection'
  if (last) {
    const idx = OP_TYPE_ORDER.indexOf(last.op_type)
    opType = idx >= 0 && idx < OP_TYPE_ORDER.length - 1 ? OP_TYPE_ORDER[idx + 1] : last.op_type
  }
  const workshopId = OP_WORKSHOP_IDS[opType] ?? null
  task.ops.push({
    seq: task.ops.length + 1,
    op_type: opType,
    op_name: OP_NAMES[opType] ?? opType,
    workshop_id: workshopId,
    machine_id: null,
    line_id: null,
    std_hours: null,
    owner_id: null,
    remark: null,
    qty: Number(task.qty ?? 1),
    workshop_name: workshopId ? WORKSHOP_NAMES[workshopId] ?? null : null,
    machine_name: null,
    line_name: null,
    owner_name: null,
  })
  renumberOps(task)
}

/** 上移 / 下移（首行禁上移、末行禁下移，移动后自动重排序号） */
function moveOp(task: EditableTask, index: number, dir: number): void {
  const target = index + dir
  if (target < 0 || target >= task.ops.length) return
  const arr = task.ops
  const tmp = arr[index]
  arr[index] = arr[target]
  arr[target] = tmp
  renumberOps(task)
}

/** 删除工序（删除后自动重排序号） */
function removeOp(task: EditableTask, index: number): void {
  task.ops.splice(index, 1)
  renumberOps(task)
}

/** 重排工序序号（1..n） */
function renumberOps(task: EditableTask): void {
  task.ops.forEach((op, i) => {
    op.seq = i + 1
  })
}

/** 保存前校验：工序名称必填（逐行提示；设备在排产阶段选择，此处不校验） */
function validateRouteOps(): boolean {
  for (const task of routeTasks.value) {
    for (let i = 0; i < task.ops.length; i += 1) {
      const op = task.ops[i]
      if (!(op.op_name ?? '').trim()) {
        ElMessage.warning(`第 ${task.task_no} 第 ${i + 1} 道工序：请填写「工序名称」`)
        return false
      }
    }
  }
  return true
}

/** 组装待提交的任务工艺（未改动任务跳过；恢复标准显式标记 restore_standard 由后端清除定制快照） */
function buildRouteOpsSubmit(): OrderRouteOpsSubmitTask[] {
  const result: OrderRouteOpsSubmitTask[] = []
  for (const t of routeTasks.value) {
    const orig = routeTasksOriginal.value.find((o) => o.task_id === t.task_id)
    const opsChanged = JSON.stringify(t.ops) !== JSON.stringify(orig?.ops ?? [])
    const routeChanged = t.route_id !== orig?.route_id
    if (t.restore_standard) {
      const changedAfterRestore = JSON.stringify(t.ops) !== t.standard_snapshot
      // 恢复标准后又改过工序 → 按普通定制提交；否则显式告知后端清除定制快照
      result.push(
        changedAfterRestore
          ? { task_id: t.task_id, route_id: t.route_id ?? undefined, ops: t.ops }
          : { task_id: t.task_id, route_id: t.route_id ?? undefined, restore_standard: true, ops: [] },
      )
    } else if (t.overridden || opsChanged || routeChanged) {
      result.push({ task_id: t.task_id, route_id: t.route_id ?? undefined, ops: t.ops })
    }
  }
  return result
}

/** 复制工艺任务列表（深拷贝一层 ops） */
function cloneRouteTasks(tasks: EditableTask[]): EditableTask[] {
  return tasks.map((t) => ({ ...t, ops: t.ops.map((o) => ({ ...o })) }))
}

/** 加载编辑弹窗下拉数据源（负责人候选；设备在排产阶段选择，此处不加载机台） */
async function loadEditOptions(): Promise<void> {
  try {
    userOptions.value = (await systemApi.listUserOptions()) ?? []
  } catch {
    userOptions.value = []
  }
}

const reportVisible = ref(false)

const logVisible = ref(false)

/** 当前工单所有生产任务（传给 ReportDialog 预选/限定本订单任务） */
const reportTasks = computed(() => (detail.value?.lines ?? []).flatMap((l) => l.tasks ?? []))

/** 按钮可用性（按状态禁用） */
const canEdit = computed(() => detail.value?.status !== 'produced')
const canStart = computed(() => ['pending', 'scheduled', 'paused'].includes(detail.value?.status ?? ''))
const canComplete = computed(() => ['scheduled', 'in_progress', 'paused'].includes(detail.value?.status ?? ''))
const canRevoke = computed(() => ['scheduled', 'in_progress', 'paused'].includes(detail.value?.status ?? ''))
const canReport = computed(() => auth.canOperateReports && ['scheduled', 'in_progress', 'paused'].includes(detail.value?.status ?? ''))
/** 工艺确认：仅「工艺确认」阶段可配置与确认；确认进入待排产后工艺锁定（2026-09-01 用户要求） */
const canConfirm = computed(() => canManage.value && detail.value?.status === 'route_confirm')
/** 工艺路线及排产区是否展示：有生产任务即展示（排程/完工列只读；编辑仅限可确认状态） */
const cfgTasksExist = computed(() => (detail.value?.lines ?? []).some((l) => (l.tasks ?? []).length > 0))

/** 工艺确认：已发布工艺路线选项 */
const routeOptions = ref<Array<{ id: number; label: string }>>([])
/** 工艺确认：任务工序内嵌编辑草稿（task_id → 该订单的工序明细） */
interface CfgRouteDraft {
  route_id: number | null
  ops: OrderRouteOpView[]
  overridden: boolean
}
const cfgDrafts = ref<Record<number, CfgRouteDraft>>({})
const routeSaving = ref(false)
const allTasks = computed(() => (detail.value?.lines ?? []).flatMap((l) => l.tasks ?? []))
/** 任务草稿（不存在返回 undefined） */
function cfgDraft(t: { id: number }): CfgRouteDraft | undefined {
  return cfgDrafts.value[t.id]
}
/** 任务当前工序列表（未配路线/无草稿时为空 → 不展开） */
function cfgOps(t: { id: number }): OrderRouteOpView[] {
  return cfgDraft(t)?.ops ?? []
}
/** 任务当前工艺路线显示名（「名称 · 版本」） */
function taskRouteLabel(t: { id: number }): string {
  const d = cfgDraft(t)
  return d?.route_id ? (routeOptions.value.find((r) => r.id === d.route_id)?.label ?? '-') : '-'
}
const hasCfgDrafts = computed(() => Object.values(cfgDrafts.value).some((d) => d.route_id != null))
const allRoutesConfigured = computed(() =>
  allTasks.value.length > 0 && allTasks.value.every((t) => cfgDraft(t)?.route_id ?? t.route_id),
)

/** 加载订单当前工序明细到草稿（后端返回：已定制=订单快照，未定制=路线库标准；均含工序数量） */
async function cfgLoad(): Promise<void> {
  if (!detail.value) return
  try {
    const res = await orderApi.getOrderRouteOps(detail.value.id)
    const map: Record<number, CfgRouteDraft> = {}
    for (const tk of res.tasks ?? []) {
      map[tk.task_id] = {
        route_id: tk.route_id,
        ops: (JSON.parse(JSON.stringify(tk.ops ?? [])) as OrderRouteOpView[]).map((o) => ({
          ...o,
          qty: o.qty ?? tk.qty ?? 1,
        })),
        overridden: tk.overridden,
      }
    }
    cfgDrafts.value = map
  } catch {
    // 拦截器已提示
  }
}

/** 切换工艺路线：就地加载该路线标准工序到当前订单草稿（已定制/已编辑先确认） */
async function onCfgRouteChange(t: { id: number; qty?: number }, value: number | undefined): Promise<void> {
  const next = value ?? null
  if (!cfgDrafts.value[t.id]) {
    cfgDrafts.value[t.id] = { route_id: next, ops: [], overridden: false }
  }
  const d = cfgDrafts.value[t.id]
  if (next === d.route_id) return
  if (d.ops.length > 0 || d.overridden) {
    const ok = await ElMessageBox.confirm('当前任务已有工序明细，切换路线将重置为该路线标准工序，是否继续？', '切换工艺路线', { type: 'warning' }).catch(() => false)
    if (!ok) return // 受控组件：select 显示自动回退旧值
  }
  d.route_id = next
  d.overridden = false
  d.ops = []
  if (next) {
    try {
      const route = await plmApi.getRoute(next)
      d.ops = (route.ops ?? []).map((o) => routeOpToView(o, Number(t.qty ?? 1)))
      renumberOpList(d.ops)
    } catch {
      // 拦截器已提示
    }
  }
}

/** 工序数组公共操作（配置区内嵌表格用；Tab2 的 EditableTask 操作走各自版本） */
function renumberOpList(ops: OrderRouteOpView[]): void {
  ops.forEach((o, i) => {
    o.seq = i + 1
  })
}
function cfgAddOp(t: { id: number; qty?: number }): void {
  const d = cfgDraft(t)
  if (!d) return
  const last = d.ops[d.ops.length - 1]
  let opType = 'molding_injection'
  if (last) {
    const idx = OP_TYPE_ORDER.indexOf(last.op_type)
    opType = idx >= 0 && idx < OP_TYPE_ORDER.length - 1 ? OP_TYPE_ORDER[idx + 1] : last.op_type
  }
  d.ops.push({
    seq: d.ops.length + 1,
    op_type: opType,
    op_name: OP_NAMES[opType] ?? opType,
    workshop_id: OP_WORKSHOP_IDS[opType] ?? null,
    machine_id: null,
    line_id: null,
    std_hours: null,
    owner_id: null,
    remark: null,
    qty: Number(t.qty ?? 1),
  })
}
function cfgMoveOp(t: { id: number }, index: number, dir: number): void {
  const d = cfgDraft(t)
  if (!d) return
  const arr = d.ops
  const target = index + dir
  if (target < 0 || target >= arr.length) return
  ;[arr[index], arr[target]] = [arr[target], arr[index]]
  renumberOpList(arr)
}
function cfgRemoveOp(t: { id: number }, index: number): void {
  const d = cfgDraft(t)
  if (!d) return
  d.ops.splice(index, 1)
  renumberOpList(d.ops)
}

/** 校验全部任务草稿（仅工序名称必填；设备在排产阶段选择，此处不校验） */
function cfgValidate(): boolean {
  for (const [tid, d] of Object.entries(cfgDrafts.value)) {
    if (!d.route_id) continue
    for (let i = 0; i < d.ops.length; i++) {
      const op = d.ops[i]
      if (!(op.op_name ?? '').trim()) {
        ElMessage.warning(`任务 ${tid} 第 ${i + 1} 道工序：请填写「工序名称」`)
        return false
      }
    }
  }
  return true
}

/** 还原标准路线：清除该任务在当前订单的定制工序（重新拉路线库工序） */
async function cfgRestoreStandard(t: { id: number }): Promise<void> {
  if (!detail.value) return
  const ok = await ElMessageBox.confirm('确认恢复为标准工艺路线？将清除该任务在「当前订单」中的定制工序，可随时再次编辑。', '还原标准路线', { type: 'warning' }).catch(() => false)
  if (!ok) return
  const d = cfgDraft(t)
  try {
    await orderApi.updateOrderRouteOps(detail.value.id, {
      tasks: [{ task_id: t.id, route_id: d?.route_id ?? undefined, restore_standard: true, ops: [] }],
    })
    ElMessage.success('已恢复标准工艺路线')
    await cfgLoad()
  } catch {
    // 拦截器已提示
  }
}

/** 加载已发布工艺路线（工艺确认下拉数据源） */
async function loadRouteOptions(): Promise<void> {
  try {
    const routes = await plmApi.listPublishedRoutesAll()
    routeOptions.value = (routes ?? []).map((r) => ({
      id: r.id,
      label: `${r.name ?? r.version} · ${r.version}`,
    }))
  } catch {
    routeOptions.value = []
  }
}

/** 保存任务工艺（路线 + 订单级工序明细，批量；空工序任务仅保存路线） */
async function onSaveRoutes(): Promise<void> {
  if (!detail.value) return
  if (!cfgValidate()) return
  const entries = Object.entries(cfgDrafts.value).filter(([, d]) => d.route_id != null)
  if (entries.length === 0) {
    ElMessage.warning('请先为任务选择工艺路线')
    return
  }
  routeSaving.value = true
  const routeTasks = entries.map(([tid, d]) => ({ task_id: Number(tid), route_id: d.route_id as number }))
  const opsTasks = entries
    .filter(([, d]) => d.ops.length > 0)
    .map(([tid, d]) => ({ task_id: Number(tid), route_id: d.route_id ?? undefined, ops: d.ops }))
  try {
    await Promise.all([
      orderApi.updateOrderRoutes(detail.value.id, { tasks: routeTasks }),
      opsTasks.length > 0 ? orderApi.updateOrderRouteOps(detail.value.id, { tasks: opsTasks }) : Promise.resolve(),
    ])
    ElMessage.success('任务工艺与工序明细已保存')
    await load()
  } catch {
    // 拦截器已提示
  } finally {
    routeSaving.value = false
  }
}

/** 确认工艺（route_confirm → pending） */
async function onConfirmRoute(): Promise<void> {
  if (!detail.value) return
  const ok = await ElMessageBox.confirm('确认工艺路线无误并完成确认？确认后工单进入「待排产」，可开始排产。', '工艺确认', { type: 'warning' }).catch(() => false)
  if (!ok) return
  try {
    await orderApi.confirmOrderRoute(detail.value.id)
    ElMessage.success('工艺确认完成，工单已进入待排产')
    await load()
  } catch {
    // 拦截器已提示
  }
}

onMounted(() => {
  load()
  loadRouteOptions()
  loadEditOptions()
  void loadOpTypeOptions()
})

async function load(): Promise<void> {
  const id = Number(route.params.id)
  if (!id) return
  loading.value = true
  try {
    detail.value = await orderApi.getOrder(id)
    // 工艺路线及排产区在所有状态展示（排程/完工列只读），因此始终加载
    void cfgLoad()
    // 详情页「领料清单」展示框：与编辑弹窗 Tab3 同源（按已发布 BOM 计算需求量）
    void loadDetailPick(id)
  } catch {
    detail.value = null
  } finally {
    loading.value = false
  }
}

/** 详情页领料清单展示框（只读） */
async function loadDetailPick(id: number): Promise<void> {
  detailPickLoading.value = true
  try {
    const pick = await orderApi.getOrderPickList(id)
    detailPick.value = pick
  } catch {
    detailPick.value = null
  } finally {
    detailPickLoading.value = false
  }
}

async function openEdit(): Promise<void> {
  if (!detail.value) return
  editForm.value = {
    customer_name: detail.value.customer_name,
    priority: detail.value.priority,
    delivery_date: detail.value.delivery_date,
    remark: detail.value.remark ?? '',
  }
  editTab.value = 'base'
  editVisible.value = true
  // 草稿仅存本地 reactive：取消/关闭不调用写接口，再次打开重新拉取（原数据不受影响）
  routeTasks.value = []
  routeTasksOriginal.value = []
  editRouteLoading.value = true
  const id = detail.value.id
  try {
    const ops = await orderApi.getOrderRouteOps(id)
    const tasks = ops.tasks.map(
      (t) => ({ ...t, ops: t.ops.map((o) => ({ ...o, qty: o.qty ?? t.qty ?? 1 })) }) as EditableTask,
    )
    routeTasks.value = tasks
    routeTasksOriginal.value = cloneRouteTasks(tasks)
  } catch {
    // 拦截器已提示（后端未就绪时保持空态）
    routeTasks.value = []
  } finally {
    editRouteLoading.value = false
  }
}

async function onEditSave(): Promise<void> {
  if (!detail.value) return
  if (editRouteLoading.value) {
    ElMessage.warning('工艺明细加载中，请稍候')
    return
  }
  if (!validateRouteOps()) return
  editLoading.value = true
  try {
    await orderApi.updateOrder(detail.value.id, {
      customer_name: editForm.value.customer_name,
      priority: editForm.value.priority,
      delivery_date: editForm.value.delivery_date,
      remark: editForm.value.remark,
    })
    const routeTasksToSave = buildRouteOpsSubmit()
    if (routeTasksToSave.length > 0) {
      await orderApi.updateOrderRouteOps(detail.value.id, { tasks: routeTasksToSave })
    }
    ElMessage.success('工单信息已更新')
    editVisible.value = false
    await load()
  } catch {
    // 拦截器已提示
  } finally {
    editLoading.value = false
  }
}

async function onStart(): Promise<void> {
  if (!detail.value) return
  const ok = await ElMessageBox.confirm('确认开始生产？工单状态将变为「生产中」。', '开始生产', { type: 'warning' }).catch(() => false)
  if (!ok) return
  try {
    await orderApi.startOrder(detail.value.id)
    ElMessage.success('工单已开始生产')
    await load()
  } catch {
    // 拦截器已提示
  }
}

async function onComplete(): Promise<void> {
  if (!detail.value) return
  const ok = await ElMessageBox.confirm('确认完结入库？所有任务与排程将置为「已完成」。', '完结入库', { type: 'warning' }).catch(() => false)
  if (!ok) return
  try {
    await orderApi.completeOrder(detail.value.id)
    ElMessage.success('工单已完结入库')
    await load()
  } catch {
    // 拦截器已提示
  }
}

async function onRevoke(): Promise<void> {
  if (!detail.value) return
  const ok = await ElMessageBox.confirm('确认撤销？工单将回到「待排产」。', '撤销工单', { type: 'warning' }).catch(() => false)
  if (!ok) return
  try {
    await orderApi.revokeOrder(detail.value.id)
    ElMessage.success('工单已撤销（回到待排产）')
    await load()
  } catch {
    // 拦截器已提示
  }
}

function openReport(): void {
  // 报工界面与台账手动报工一致（ReportDialog 组件），从订单进入自动带入本订单任务
  reportVisible.value = true
}

async function openLogs(): Promise<void> {
  if (!detail.value) return
  logVisible.value = true
  try {
    const res = await orderApi.listOrderLogs(detail.value.id)
    logs.value = res.data
  } catch {
    logs.value = []
  }
}

function srcLabel(s: string): string {
  return { manual: '手工', excel: 'Excel', mini_program: '小程序', k3: '金蝶' }[s] ?? s
}
function statusLabel(s: string | null): string {
  if (!s) return '-'
  return { route_confirm: '工艺确认', pending: '待排产', scheduled: '已排产', in_progress: '生产中', paused: '已停工', produced: '已入库', aborted: '已中止', closed: '已关闭' }[s] ?? s
}
function statusType(s: string): 'primary' | 'success' | 'info' | 'warning' {
  return ({ route_confirm: 'warning', pending: 'info', scheduled: 'success', in_progress: 'primary', paused: 'warning', produced: 'success', aborted: 'danger', closed: 'info' }[s] ?? 'info') as 'primary' | 'success' | 'info' | 'warning'
}
function lineStatusLabel(s: string): string {
  return { pending: '待排产', scheduled: '已排产', in_progress: '生产中', done: '已完成' }[s] ?? s
}
function lineStatusType(s: string): 'primary' | 'success' | 'info' {
  return ({ pending: 'info', scheduled: 'success', in_progress: 'primary', done: 'success' }[s] ?? 'info') as 'primary' | 'success' | 'info'
}

// ==================== 产品行聚合（完工/剩余/工期/时间/工时） ====================

type OverdueState = 'overdue' | 'ok' | 'unscheduled'

function lineTasks(line: OrderLine): ProdTask[] {
  return line.tasks ?? []
}

/** 行完工数：多任务取 Σ 任务完工（成品口径），单行兜底行冗余字段 */
function lineDoneQty(line: OrderLine): number {
  const tasks = lineTasks(line)
  if (tasks.length) return tasks.reduce((s, t) => s + (t.done_qty ?? 0), 0)
  return line.done_qty ?? 0
}

function lineRemainQty(line: OrderLine): number {
  return Math.max(0, line.qty - lineDoneQty(line))
}

function linePercent(line: OrderLine): number {
  const qty = line.qty || 1
  return Math.min(100, Math.round((lineDoneQty(line) / qty) * 100))
}

/**
 * 行级工序进度（完工进度列·分工序展示，与工单中心「总进度」同款节点样式）：
 * - 合并行内所有任务的 progress.stages（按 op_type 累加 plan/done，重算 percent）
 * - 单任务订单即为该任务自身进度；多任务（如大批量拆分）合并为产品行整体工序进度
 * - 无排程/无任务返回 null（前端回退显示成品口径百分比）
 */
function lineProgress(line: OrderLine): { stages: ProgressStage[]; percent: number } | null {
  const tasks = lineTasks(line)
  if (!tasks.length) return null
  const stageMap = new Map<string, ProgressStage>()
  let overallPlan = 0
  let overallDone = 0
  for (const t of tasks) {
    for (const s of t.progress?.stages ?? []) {
      const ex = stageMap.get(s.op_type)
      if (ex) {
        ex.plan_qty += s.plan_qty
        ex.done_qty += s.done_qty
        ex.percent = ex.plan_qty > 0 ? Math.min(100, Math.round((ex.done_qty / ex.plan_qty) * 100)) : 0
      } else {
        stageMap.set(s.op_type, { ...s })
      }
      overallPlan += s.plan_qty
      overallDone += s.done_qty
    }
  }
  if (!stageMap.size) return null
  const stages = Array.from(stageMap.values())
  const percent = overallPlan > 0 ? Math.min(100, Math.round((overallDone / overallPlan) * 100)) : 0
  return { stages, percent }
}

/**
 * 行级时间极值：任务级字段取最早(min) / 最晚(max)（排产起止、实际开工/完工）。
 * 空值过滤后无数据返回 null（前端显示 '-'）。
 */
function lineExtreme(line: OrderLine, key: 'plan_start' | 'plan_end' | 'actual_start' | 'actual_end', mode: 'min' | 'max'): string | null {
  const vals = lineTasks(line)
    .map((t) => t[key])
    .filter((v): v is string => !!v)
  if (!vals.length) return null
  vals.sort()
  return mode === 'min' ? vals[0] : vals[vals.length - 1]
}

/** 行累计工时：Σ 任务排程计划时长（保留 2 位） */
function lineTotalHours(line: OrderLine): number {
  const total = lineTasks(line).reduce((s, t) => s + (t.total_hours ?? 0), 0)
  return Math.round(total * 100) / 100
}

/** 当前本地时间（YYYY-MM-DD HH:mm，字符串比较避免时区偏移） */
function nowLocal(): string {
  const d = new Date()
  const p = (n: number): string => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

/**
 * 工期状态（是否逾期）：
 * - 已完成 → 正常
 * - 未排产（无排程且无交期）→ 未排产
 * - 逾期：① 排产结束晚于交期（排产即逾期）；② 当前日期晚于交期仍未完成；③ 当前时间晚于最晚排产结束
 */
function lineOverdue(line: OrderLine): OverdueState {
  const tasks = lineTasks(line)
  if (lineDoneQty(line) >= line.qty) return 'ok'

  const planStarts = tasks.some((t) => t.plan_start)
  const planEnds = tasks.map((t) => t.plan_end).filter((v): v is string => !!v)
  if (!planStarts && !planEnds.length && !line.delivery_date) return 'unscheduled'

  const now = nowLocal()
  const nowDate = now.slice(0, 10)
  const delivery = line.delivery_date ? String(line.delivery_date) : null

  if (delivery && planEnds.some((pe) => pe.slice(0, 10) > delivery)) return 'overdue'
  if (delivery && nowDate > delivery) return 'overdue'
  if (planEnds.some((pe) => now > pe.slice(0, 16).replace('T', ' '))) return 'overdue'
  return 'ok'
}

function overdueType(s: OverdueState): 'danger' | 'success' | 'info' {
  return ({ overdue: 'danger', ok: 'success', unscheduled: 'info' } as const)[s]
}
function overdueLabel(s: OverdueState): string {
  return ({ overdue: '逾期', ok: '正常', unscheduled: '未排产' } as const)[s]
}
function priorityType(p: number): 'danger' | 'warning' | 'info' {
  return p >= 5 ? 'danger' : p >= 4 ? 'warning' : 'info'
}
function logActionLabel(a: string): string {
  return { edit: '编辑', start: '开始', complete: '完结', revoke: '撤销', route_confirm: '工艺确认' }[a] ?? a
}
</script>

<style scoped>
.detail-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
}
.op-bar {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.mb-12 {
  margin-bottom: 12px;
}
.ml8 {
  margin-left: 8px;
}
/* 工艺确认配置区 */
.route-confirm-box {
  margin-top: 14px;
  padding: 12px 14px;
  border: 1px solid #fde68a;
  background: #fffbeb;
  border-radius: 8px;
}
/* 排产/完工等只读状态：灰底（不可编辑） */
.route-confirm-box.ro-mode {
  border-color: #e2e8f0;
  background: #f8fafc;
}
.route-confirm-box.ro-mode .route-confirm-title {
  color: #334155;
}
.route-confirm-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}
.route-confirm-title {
  font-weight: 600;
  color: #92400e;
}
.route-confirm-hint {
  font-size: 12px;
  color: #a16207;
}
.route-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 5px 0;
}
.route-row .task-no {
  font-size: 12px;
  font-weight: 600;
  min-width: 150px;
}
.route-row .task-qty {
  font-size: 12px;
  color: #64748b;
  min-width: 60px;
}
/* 内嵌工序明细（选择工艺路线后在下方就地展开） */
.route-task {
  padding: 4px 0;
}
.inline-route-editor {
  margin: 6px 0 10px 4px;
  padding: 10px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #ffffff;
}
.inline-route-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}
.inline-route-label {
  font-size: 12px;
  font-weight: 600;
  color: #334155;
}
.inline-route-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
  flex-wrap: wrap;
}
.rte-hint {
  font-size: 12px;
  color: #94a3b8;
}
.route-confirm-actions {
  margin-top: 10px;
  display: flex;
  gap: 8px;
}
/* ==================== 编辑弹窗 · 工艺路线明细 ==================== */
.tab-loading {
  padding: 28px 0;
  text-align: center;
  color: #94a3b8;
  font-size: 13px;
}
.task-card {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 12px;
}
.task-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.task-card-info {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.task-card-route {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.task-no {
  font-weight: 600;
  font-size: 13px;
}
.task-product {
  font-size: 13px;
  color: #334155;
}
.task-qty {
  font-size: 12px;
  color: #64748b;
}
.task-card-foot {
  margin-top: 8px;
}
/* 必填单元格校验标红（工序名称 / 工作中心设备） */
.cell-invalid :deep(.el-input__wrapper) {
  box-shadow: 0 0 0 1px #f56c6c inset;
}
.cell-invalid :deep(.el-select__wrapper) {
  box-shadow: 0 0 0 1px #f56c6c inset;
}
/* ==================== 编辑弹窗 · 领料清单 ==================== */
.pick-card {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 12px;
}
.pick-card-head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 8px;
  font-size: 13px;
}
.pick-line-no {
  color: #64748b;
  font-size: 12px;
}
.pick-product {
  font-weight: 600;
}
.pick-qty {
  color: #64748b;
  font-size: 12px;
}
.readonly-cell {
  display: inline-block;
  padding: 3px 6px;
  border-radius: 4px;
  background: #f8fafc;
  color: #475569;
  font-size: 12px;
  line-height: 1.5;
}
/* 排产/完工列：占位（未排产）灰字 */
.cell-muted {
  color: #94a3b8;
}
/* 完工进度条 */
.percent-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.percent-bar {
  flex: 1;
  min-width: 50px;
  height: 8px;
  background: #eef2f7;
  border-radius: 4px;
  overflow: hidden;
}
.percent-fill {
  height: 100%;
  background: #22c55e;
  border-radius: 4px;
}
.percent-text {
  font-size: 12px;
  color: #334155;
  min-width: 38px;
  text-align: right;
}
.pick-summary {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding: 4px 6px;
  font-size: 13px;
  color: #334155;
}

/* ==================== 详情页 · 领料清单展示框 ==================== */
.picklist-box {
  margin-top: 14px;
  padding: 12px 14px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  border-radius: 8px;
}
.picklist-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.picklist-title {
  font-weight: 600;
  color: #334155;
}
.picklist-hint {
  font-size: 12px;
  color: #64748b;
}

/* 订单明细表格：列宽可拖拽 */
.lines-table :deep(th.el-table__cell) {
  position: relative;
}
.lines-table :deep(.col-resize-handle) {
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
  width: 8px;
  cursor: col-resize;
  z-index: 6;
}
/* 窄屏：操作列按钮改为纯图标（隐藏文字，不引入新依赖） */
@media (max-width: 768px) {
  .op-icon-btn {
    margin-left: 0 !important;
    padding: 4px 6px !important;
  }
  .op-icon-btn .op-text {
    display: none !important;
  }
}
</style>
