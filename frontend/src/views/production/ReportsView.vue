<template>
  <el-card shadow="never" class="page-card">
    <div class="page-head">
      <div>
        <div class="page-title">生产报工</div>
        <div class="page-subtitle">按已接受排程录入当日汇总，当前由班组长或统计员统一填报，不区分个人作业人</div>
      </div>
      <el-tag type="info" effect="plain">计划与实际分开记录</el-tag>
    </div>

    <el-tabs v-model="activeTab" class="report-tabs">
      <el-tab-pane label="当日报工" name="daily">
        <DailyReportGrid @saved="load" />
      </el-tab-pane>
      <el-tab-pane label="报工流水" name="ledger">
    <div class="toolbar">
      <div class="toolbar-left">
        <el-input v-model="keyword" placeholder="任务号/产品编码" clearable style="width: 180px" @keyup.enter="load" />
        <el-select v-model="filterSource" placeholder="来源" clearable style="width: 130px">
          <el-option label="扫码" value="scan" />
          <el-option label="点选" value="click" />
          <el-option label="班组长代报" value="leader_report" />
          <el-option label="班组长/统计员日结" value="leader_batch" />
          <el-option label="补报" value="makeup" />
          <el-option label="离线补传" value="offline" />
        </el-select>
        <el-select v-model="filterSign" placeholder="类型" clearable style="width: 110px">
          <el-option label="正常报工" value="1" />
          <el-option label="冲减记录" value="-1" />
        </el-select>
        <el-date-picker
          v-model="dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          range-separator="~"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 250px"
        />
        <el-button type="primary" plain @click="load">查询</el-button>
      </div>
      <div class="toolbar-right">
        <el-button v-if="canReport" type="primary" @click="reportVisible = true">
          <el-icon><Plus /></el-icon>手动报工
        </el-button>
        <el-tag type="info" effect="plain">台账只增不改 · 冲减为负记录</el-tag>
      </div>
    </div>

    <el-table v-loading="loading" :data="rows" stripe>
      <el-table-column prop="report_time" label="报工时间" width="160">
        <template #default="{ row }">{{ formatDateTime(row.report_time) }}</template>
      </el-table-column>
      <el-table-column label="实际作业时间" width="300">
        <template #default="{ row }">
          {{ formatDateTime(row.start_time ?? row.report_time) }}
          <span class="sub">至</span>
          {{ formatDateTime(row.end_time ?? row.report_time) }}
        </template>
      </el-table-column>
      <el-table-column label="报工人" width="100">
        <template #default="{ row }">{{ row.operator?.name ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="个人归属（历史）" width="130">
        <template #default="{ row }">
          {{ row.worker?.name ?? '未区分个人' }}
          <el-tag v-if="row.worker?.workshop" size="small" class="workshop-tag">{{ row.worker.workshop.name }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="任务" min-width="150">
        <template #default="{ row }">
          {{ row.task?.task_no ?? row.task_no ?? '-' }}
          <div class="sub">{{ row.task?.product?.name }}</div>
        </template>
      </el-table-column>
      <el-table-column label="工序" width="110">
        <template #default="{ row }">
          <el-tag v-if="row.schedule?.op_name" size="small" type="primary" effect="plain">#{{ row.schedule.seq }} {{ row.schedule.op_name }}</el-tag>
          <span v-else class="sub">任务级</span>
        </template>
      </el-table-column>
      <el-table-column label="机台/产线" width="110">
        <template #default="{ row }">{{ row.machine?.name ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="合格数" width="90" align="center">
        <template #default="{ row }">
          <el-tag :type="row.qty >= 0 ? 'success' : 'danger'" size="small">{{ row.qty >= 0 ? '+' + row.qty : row.qty }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="defect_qty" label="次品" width="70" align="center" />
      <el-table-column label="来源" width="100">
        <template #default="{ row }">
          <el-tag size="small" :type="sourceTag(row.source)">{{ row.source_label ?? row.source }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="备注/冲减原因" min-width="140">
        <template #default="{ row }">
          <span :class="{ 'correction': row.sign === -1 }">{{ row.remark ?? '-' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="冲减入口" width="90" align="center">
        <template #default="{ row }">
          <el-button v-if="row.qty > 0" size="small" text type="danger" @click="openCorrection(row)">冲减</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="page"
        :page-size="perPage"
        :total="total"
        layout="total, prev, pager, next"
        background
        @current-change="load"
      />
    </div>
      </el-tab-pane>
      <el-tab-pane label="报工统计" name="summary">
        <ReportSummaryTable />
      </el-tab-pane>
    </el-tabs>

    <!-- 冲减弹窗 -->
    <el-dialog v-model="correctionVisible" title="冲减（更正报工）" width="440px">
      <el-alert type="warning" :closable="false" class="mb-12">
        冲减为负数量记录，不做删除（台账只增不改）。原记录保留，另行追加负记录。
      </el-alert>
      <el-form label-width="90px">
        <el-form-item label="任务号">
          <span>{{ selected?.task?.task_no ?? selected?.task_no }}</span>
        </el-form-item>
        <el-form-item label="冲减数量" required>
          <el-input-number v-model="correctionQty" :min="1" :max="selected?.qty ?? 0" />
        </el-form-item>
        <el-form-item label="冲减原因" required>
          <el-input v-model="correctionReason" placeholder="必填（如：误报更正/返工）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="correctionVisible = false">取消</el-button>
        <el-button type="danger" :loading="submitting" @click="onCorrection">确认冲减</el-button>
      </template>
    </el-dialog>

    <!-- 手动报工（关联工单，工序链校验）—— 公共组件（订单详情同款） -->
    <ReportDialog v-model="reportVisible" @success="load" />
  </el-card>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { productionApi } from '@/api/production'
import type { WorkReport } from '@/types/production'
import ReportDialog from '@/components/production/ReportDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/utils/datetime'
import DailyReportGrid from '@/components/production/DailyReportGrid.vue'
import ReportSummaryTable from '@/components/production/ReportSummaryTable.vue'

const auth = useAuthStore()
const canReport = computed(() => auth.canOperateReports)

const rows = ref<WorkReport[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = 15
const keyword = ref('')
const filterSource = ref('')
const filterSign = ref('')
const dateRange = ref<[string, string] | null>(null)
const activeTab = ref<'daily' | 'ledger' | 'summary'>('daily')

const correctionVisible = ref(false)
const selected = ref<WorkReport | null>(null)
const correctionQty = ref(1)
const correctionReason = ref('')
const submitting = ref(false)

/** 手动报工（弹窗本体在 ReportDialog 组件内） */
const reportVisible = ref(false)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await productionApi.listReports({
      page: page.value,
      per_page: perPage,
      keyword: keyword.value || undefined,
      source: filterSource.value || undefined,
      sign: filterSign.value || undefined,
      date_from: dateRange.value?.[0],
      date_to: dateRange.value?.[1],
    })
    rows.value = result.data
    total.value = result.total
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

function sourceTag(s: string): 'primary' | 'success' | 'warning' | 'info' | 'danger' {
  const tags: Record<string, 'primary' | 'success' | 'warning' | 'info' | 'danger'> = {
    scan: 'primary',
    click: 'success',
    leader_report: 'warning',
    leader_batch: 'warning',
    makeup: 'info',
    offline: 'danger',
  }
  return tags[s] ?? 'info'
}

function openCorrection(row: WorkReport): void {
  selected.value = row
  correctionQty.value = Math.min(1, row.qty)
  correctionReason.value = ''
  correctionVisible.value = true
}

async function onCorrection(): Promise<void> {
  if (!selected.value) return
  if (!correctionReason.value.trim()) {
    ElMessage.warning('冲减必须填写原因')
    return
  }
  submitting.value = true
  try {
    const result = await productionApi.submitReport({
      task_id: selected.value.task_id,
      qty: -correctionQty.value,
      remark: correctionReason.value.trim(),
      source: 'click',
    })
    ElMessage.success(`冲减已记录：任务 ${result.task.task_no} 完成量 ${result.task.done_qty}`)
    correctionVisible.value = false
    await load()
  } catch {
    // 拦截器提示（原因必填/超量等）
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page-card {
  border-radius: 8px;
}
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 4px;
}
.page-title {
  color: #1e293b;
  font-size: 18px;
  font-weight: 650;
}
.page-subtitle {
  margin-top: 4px;
  color: #94a3b8;
  font-size: 12px;
}
.report-tabs :deep(.el-tabs__content) {
  overflow: visible;
}
.toolbar {
  display: flex;
  justify-content: space-between;
  margin-bottom: 14px;
  gap: 10px;
  flex-wrap: wrap;
}
.toolbar-left,
.toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
}
.sub {
  font-size: 12px;
  color: #94a3b8;
}
.workshop-tag {
  margin-left: 4px;
}
.correction {
  color: #b91c1c;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.mb-12 {
  margin-bottom: 12px;
}
</style>
