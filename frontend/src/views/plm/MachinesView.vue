<template>
  <el-card shadow="never" class="page-card">
    <!-- 工具栏 -->
    <div class="toolbar">
      <div class="toolbar-left">
        <el-input
          v-model="keyword"
          placeholder="搜索机台/产线编码或名称"
          clearable
          style="width: 220px"
          @keyup.enter="load"
        />
        <el-select v-model="filterType" placeholder="资源类型" clearable style="width: 130px">
          <el-option label="注塑机" value="injection" />
          <el-option label="吹塑机" value="blow" />
          <el-option label="喷漆产线" value="paint_line" />
          <el-option label="效果产线" value="effect_line" />
          <el-option label="包装产线" value="pack_line" />
        </el-select>
        <el-select v-model="filterStatus" placeholder="状态" clearable style="width: 130px">
          <el-option label="运行中" value="running" />
          <el-option label="停机" value="stopped" />
          <el-option label="维修中" value="maintenance" />
        </el-select>
        <el-button type="primary" plain @click="load">查询</el-button>
      </div>
      <div class="toolbar-right">
        <el-button v-if="canManage" type="success" plain @click="openCreateWorkshop">
          <el-icon><OfficeBuilding /></el-icon>新增车间
        </el-button>
        <el-button v-if="canManage" type="primary" @click="openCreate">
          <el-icon><Plus /></el-icon>新增机台
        </el-button>
      </div>
    </div>

    <div class="resource-summary" aria-label="机台状态概览" :aria-busy="loading">
      <div class="summary-heading">
        <span class="summary-marker" aria-hidden="true"></span>
        <strong>设备状态</strong>
        <span class="summary-total">{{ loading ? '读取中…' : machineCounts.total + ' 台资源' }}</span>
      </div>
      <div class="summary-filters" role="group" aria-label="按设备状态快速筛选">
        <button
          type="button"
          class="summary-filter"
          :class="{ 'is-active': !filterStatus }"
          :aria-pressed="!filterStatus"
          @click="setStatusFilter('')"
        >
          全部 <b>{{ loading ? '—' : machineCounts.total }}</b>
        </button>
        <button
          type="button"
          class="summary-filter summary-filter--running"
          :class="{ 'is-active': filterStatus === 'running' }"
          :aria-pressed="filterStatus === 'running'"
          @click="setStatusFilter('running')"
        >
          <i aria-hidden="true"></i>运行中 <b>{{ loading ? '—' : machineCounts.running }}</b>
        </button>
        <button
          type="button"
          class="summary-filter summary-filter--maintenance"
          :class="{ 'is-active': filterStatus === 'maintenance' }"
          :aria-pressed="filterStatus === 'maintenance'"
          @click="setStatusFilter('maintenance')"
        >
          <i aria-hidden="true"></i>维修中 <b>{{ loading ? '—' : machineCounts.maintenance }}</b>
        </button>
        <button
          type="button"
          class="summary-filter summary-filter--stopped"
          :class="{ 'is-active': filterStatus === 'stopped' }"
          :aria-pressed="filterStatus === 'stopped'"
          @click="setStatusFilter('stopped')"
        >
          <i aria-hidden="true"></i>停机 <b>{{ loading ? '—' : machineCounts.stopped }}</b>
        </button>
        <span v-if="!loading && machineCounts.inactive" class="inactive-note">已停用 {{ machineCounts.inactive }} 台</span>
      </div>
    </div>

    <!-- 车间 → 机台/产线 树形表 -->
    <el-table
      class="resource-table"
      v-loading="loading"
      :data="treeRows"
      :row-class-name="resourceRowClassName"
      row-key="_key"
      :tree-props="{ children: 'children', hasChildren: 'hasChildren' }"
      default-expand-all
      stripe
    >
      <el-table-column label="名称" min-width="180">
        <template #default="{ row }">
          <span v-if="row._rowType === 'workshop'" class="ws-name">
            <el-icon class="ws-icon"><OfficeBuilding /></el-icon>{{ row.name }}
          </span>
          <span v-else>{{ row.name }}</span>
        </template>
      </el-table-column>
      <el-table-column label="编码" width="110">
        <template #default="{ row }">{{ row.code }}</template>
      </el-table-column>
      <el-table-column label="所属车间" width="130">
        <template #default="{ row }">
          <span v-if="row._rowType === 'workshop'" class="muted">—</span>
          <span v-else>{{ row.workshop_name ?? row.workshop ?? '—' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="标准产能(件/h)" width="130" sortable>
        <template #default="{ row }">
          <span v-if="row._rowType === 'workshop'">{{ row.machines_count ?? row.children.length }} 台</span>
          <span v-else>{{ row.capacity_per_hour }}</span>
        </template>
      </el-table-column>
      <el-table-column label="换模(min)" width="90">
        <template #default="{ row }">{{ row._rowType === 'workshop' ? '—' : row.mold_change_min }}</template>
      </el-table-column>
      <el-table-column label="班制" width="90">
        <template #default="{ row }">{{ row._rowType === 'workshop' ? '—' : (row.shift_mode === 'two_shift' ? '两班' : '白班') }}</template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <StatusTag v-if="row._rowType !== 'workshop'" :status="row.status" />
          <el-tag v-else :type="row.is_active === 1 ? 'success' : 'info'" size="small">
            {{ row.is_active === 1 ? '启用' : '停用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="排序" width="70">
        <template #default="{ row }">{{ row._rowType === 'workshop' ? row.sort : '—' }}</template>
      </el-table-column>
      <el-table-column v-if="canManage" label="操作" width="270" :fixed="isCompact ? false : 'right'">
        <template #default="{ row }">
          <template v-if="row._rowType === 'workshop'">
            <el-button link type="primary" @click="openEditWorkshop(row)">编辑</el-button>
            <el-button link type="primary" @click="openCreateInWorkshop(row)">新增机台</el-button>
            <el-button link type="danger" @click="onDeleteWorkshop(row)">删除</el-button>
          </template>
          <template v-else>
            <el-button link type="success" @click="openQr(row)">机台码</el-button>
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <!-- 机台/产线 新增/编辑弹窗 -->
  <FormDialog
    v-model="dialogVisible"
    :title="editingId ? '编辑机台' : '新增机台'"
    :fields="machineFields"
    :model="formModel"
    @submit="onSubmit"
  />
  <el-dialog v-model="qrVisible" title="机台扫码报工码" width="360px" destroy-on-close>
    <div class="qr-content" v-if="qrMachine">
      <img v-if="qrImage" :src="qrImage" :alt="qrMachine.code + '扫码报工二维码'" class="qr-image" />
      <div class="qr-machine-name">{{ qrMachine.code }} · {{ qrMachine.name }}</div>
      <div class="qr-hint">现场扫码后进入该机台的当日固定任务，不可跨机台报工。</div>
      <el-input :model-value="qrUrl" readonly size="small" class="qr-url" />
      <el-button type="primary" plain :disabled="!qrImage" @click="downloadQr">下载二维码</el-button>
    </div>
  </el-dialog>
  <!-- 车间 新增/编辑弹窗 -->
  <FormDialog
    v-model="wsDialogVisible"
    :title="editingWsId ? '编辑车间' : '新增车间'"
    :fields="workshopFields"
    :model="wsFormModel"
    @submit="onWsSubmit"
  />
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { OfficeBuilding, Plus } from '@element-plus/icons-vue'
import QRCode from 'qrcode'
import { plmApi } from '@/api/plm'
import type { Machine, PlmWorkshop } from '@/types'
import FormDialog, { type FormField } from '@/components/common/FormDialog.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { useAuthStore } from '@/stores/auth'

interface WorkshopRow extends PlmWorkshop {
  _key: string
  _rowType: 'workshop'
  hasChildren?: boolean
  children: MachineRow[]
}
interface MachineRow extends Machine {
  _key: string
  _rowType: 'machine'
}
type TreeRow = WorkshopRow | MachineRow

const auth = useAuthStore()
const canManage = computed(() => auth.can('plm.machine.manage'))

const loading = ref(false)
const treeRows = ref<TreeRow[]>([])
const allMachines = ref<Machine[]>([])
const keyword = ref('')
const filterType = ref('')
const filterStatus = ref('')
const isCompact = ref(false)
let compactMediaQuery: MediaQueryList | undefined

// 机台弹窗
const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const formModel = ref<Record<string, unknown>>({})
const editingMachineSnapshot = ref<Machine | null>(null)

// 车间弹窗
const wsDialogVisible = ref(false)
const editingWsId = ref<number | null>(null)
const wsFormModel = ref<Record<string, unknown>>({})

const qrVisible = ref(false)
const qrImage = ref('')
const qrUrl = ref('')
const qrMachine = ref<Machine | null>(null)

/** 全部车间（用于树形父行） */
const workshops = ref<PlmWorkshop[]>([])
const machineCounts = computed(() => ({
  total: allMachines.value.length,
  running: allMachines.value.filter((machine) => machine.status === 'running').length,
  maintenance: allMachines.value.filter((machine) => machine.status === 'maintenance').length,
  stopped: allMachines.value.filter((machine) => machine.status === 'stopped').length,
  inactive: allMachines.value.filter((machine) => machine.is_active !== 1).length,
}))
/** 启用车间（用于机台表单「所属车间」下拉） */
const workshopOptions = ref<Array<{ label: string; value: number }>>([])

const machineFields = computed<FormField[]>(() => [
  { prop: 'code', label: '机台/产线编码', type: 'input', required: true, placeholder: '如 I-01 / P-01' },
  { prop: 'name', label: '名称', type: 'input', required: true },
  {
    prop: 'type', label: '资源类型', type: 'select', required: true,
    options: [
      { label: '注塑机', value: 'injection' },
      { label: '吹塑机', value: 'blow' },
      { label: '喷漆产线', value: 'paint_line' },
      { label: '效果产线', value: 'effect_line' },
      { label: '包装产线', value: 'pack_line' },
    ],
  },
  {
    prop: 'workshop_id', label: '所属车间', type: 'select', required: true, clearable: true, filterable: true,
    options: workshopOptions.value,
  },
  { prop: 'capacity_per_hour', label: '标准产能(件/h)', type: 'number', required: true, min: 0, precision: 2 },
  { prop: 'mold_change_min', label: '换模分钟', type: 'number', required: true, min: 0 },
  {
    prop: 'shift_mode', label: '班制', type: 'select', required: true,
    options: [{ label: '两班制', value: 'two_shift' }, { label: '白班制', value: 'day' }],
  },
  {
    prop: 'status', label: '状态', type: 'select', required: true,
    options: [
      { label: '运行中', value: 'running' },
      { label: '停机', value: 'stopped' },
      { label: '维修中', value: 'maintenance' },
    ],
  },
  { prop: 'is_active', label: '启用', type: 'switch' },
])

const workshopFields = computed<FormField[]>(() => [
  { prop: 'code', label: '车间编码', type: 'input', required: true, placeholder: '如 WS-01' },
  { prop: 'name', label: '车间名称', type: 'input', required: true },
  { prop: 'sort', label: '排序', type: 'number', required: true, min: 0 },
  { prop: 'is_active', label: '启用', type: 'switch' },
  { prop: 'remark', label: '备注', type: 'textarea' },
])

function toArray<T>(result: unknown, fallback: T[]): T[] {
  return Array.isArray(result) ? result : (result as { data: T[] }).data ?? fallback
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const [wsResult, mResult] = await Promise.all([
      plmApi.listWorkshops({ active_only: 0, per_page: 100 }),
      plmApi.listMachines({ all: true }),
    ])
    const allWorkshops = toArray<PlmWorkshop>(wsResult, [])
    const machines = toArray<Machine>(mResult, [])
    workshops.value = allWorkshops
    allMachines.value = machines
    // 机台表单「所属车间」下拉只取启用车间
    workshopOptions.value = allWorkshops
      .filter((w) => w.is_active === 1)
      .sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0))
      .map((w) => ({ label: w.name, value: w.id }))

    const kw = keyword.value.trim().toLowerCase()
    const filtered = machines.filter((m) => {
      if (kw && !`${m.code} ${m.name}`.toLowerCase().includes(kw)) return false
      if (filterType.value && m.type !== filterType.value) return false
      if (filterStatus.value && m.status !== filterStatus.value) return false
      return true
    })

    const hasResourceFilter = Boolean(kw || filterType.value || filterStatus.value)
    treeRows.value = allWorkshops
      .slice()
      .sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0))
      .map((ws) => ({
        _key: `ws-${ws.id}`,
        _rowType: 'workshop' as const,
        ...ws,
        hasChildren: true,
        children: filtered
          .filter((m) => m.workshop_id === ws.id)
          .map((m) => ({ _key: `m-${m.id}`, _rowType: 'machine' as const, ...m })),
      }))
      .filter((ws) => !hasResourceFilter || ws.children.length > 0)
  } finally {
    loading.value = false
  }
}

function setStatusFilter(status: Machine['status'] | ''): void {
  filterStatus.value = filterStatus.value === status && status ? '' : status
  load()
}

function resourceRowClassName({ row }: { row: TreeRow }): string {
  if (row._rowType === 'workshop') return ''
  if (row.status === 'maintenance') return 'resource-row--maintenance'
  if (row.status === 'stopped') return 'resource-row--stopped'
  if (row.is_active !== 1) return 'resource-row--inactive'
  return ''
}

// ============ 机台 新增/编辑 ============
function openCreate(): void {
  editingId.value = null
  editingMachineSnapshot.value = null
  formModel.value = {
    code: '',
    name: '',
    type: 'injection',
    workshop_id: workshopOptions.value[0]?.value ?? null,
    capacity_per_hour: 400,
    mold_change_min: 30,
    shift_mode: 'two_shift',
    status: 'running',
    is_active: 1,
  }
  dialogVisible.value = true
}

function openCreateInWorkshop(ws: PlmWorkshop): void {
  editingId.value = null
  editingMachineSnapshot.value = null
  formModel.value = {
    code: '',
    name: '',
    type: 'injection',
    workshop_id: ws.id,
    capacity_per_hour: 400,
    mold_change_min: 30,
    shift_mode: 'two_shift',
    status: 'running',
    is_active: 1,
  }
  dialogVisible.value = true
}

function openEdit(row: Machine): void {
  editingId.value = row.id
  editingMachineSnapshot.value = { ...row }
  formModel.value = { ...row }
  dialogVisible.value = true
}

async function openQr(row: Machine): Promise<void> {
  qrMachine.value = row
  qrUrl.value = `${import.meta.env.VITE_MOBILE_APP_URL ?? 'http://localhost:5174'}/report/qr?machine_code=${encodeURIComponent(row.code)}`
  qrImage.value = await QRCode.toDataURL(qrUrl.value, { width: 240, margin: 2 })
  qrVisible.value = true
}

function downloadQr(): void {
  if (!qrImage.value || !qrMachine.value) return
  const link = document.createElement('a')
  link.href = qrImage.value
  link.download = `${qrMachine.value.code}-扫码报工.png`
  link.click()
}

async function onSubmit(model: Record<string, unknown>): Promise<void> {
  try {
    if (editingId.value) {
      const machineId = editingId.value
      const basisChanged = (['capacity_per_hour', 'mold_change_min', 'shift_mode'] as const)
        .some((field) => editingMachineSnapshot.value && model[field] !== undefined
          && String(editingMachineSnapshot.value[field]) !== String(model[field]))
      await plmApi.updateMachine(machineId, model as Partial<Machine>)
      ElMessage.success('机台更新成功')
      dialogVisible.value = false
      await load()

      if (basisChanged) {
        const shouldRecalculate = await ElMessageBox.confirm(
          '机台产能参数已保存。是否从修改当天起，按新产能重算未开工排程？已开工、已完工和已有报工的工序不会被重算。',
          '重算排程',
          { type: 'warning', confirmButtonText: '重算排程', cancelButtonText: '暂不重算' },
        ).then(() => true).catch(() => false)
        if (shouldRecalculate) {
          const result = await plmApi.recalculateMachineSchedules(machineId)
          ElMessage.success(`排程重算完成：涉及 ${result.task_count} 个工单，调整 ${result.changed_count} 条工序`)
          await load()
        }
      }
    } else {
      await plmApi.createMachine(model as Partial<Machine>)
      ElMessage.success('机台创建成功')
      dialogVisible.value = false
      await load()
    }
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function onDelete(row: Machine): Promise<void> {
  await ElMessageBox.confirm(`确定删除机台「${row.code}」吗？`, '删除确认', { type: 'warning' })
  await plmApi.deleteMachine(row.id)
  ElMessage.success('机台已删除')
  await load()
}

// ============ 车间 新增/编辑/删除 ============
function openCreateWorkshop(): void {
  editingWsId.value = null
  wsFormModel.value = {
    code: '',
    name: '',
    sort: workshops.value.length + 1,
    is_active: 1,
    remark: '',
  }
  wsDialogVisible.value = true
}

function openEditWorkshop(row: PlmWorkshop): void {
  editingWsId.value = row.id
  wsFormModel.value = { ...row }
  wsDialogVisible.value = true
}

async function onWsSubmit(model: Record<string, unknown>): Promise<void> {
  try {
    if (editingWsId.value) {
      await plmApi.updateWorkshop(editingWsId.value, model as Partial<PlmWorkshop>)
      ElMessage.success('车间更新成功')
    } else {
      await plmApi.createWorkshop(model as Partial<PlmWorkshop>)
      ElMessage.success('车间创建成功')
    }
    wsDialogVisible.value = false
    await load()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function onDeleteWorkshop(row: PlmWorkshop): Promise<void> {
  await ElMessageBox.confirm(`确定删除车间「${row.name}」吗？`, '删除确认', { type: 'warning' })
  await plmApi.deleteWorkshop(row.id)
  ElMessage.success('车间已删除')
  await load()
}

onMounted(() => {
  compactMediaQuery = window.matchMedia('(max-width: 700px)')
  isCompact.value = compactMediaQuery.matches
  compactMediaQuery.addEventListener('change', syncCompactLayout)
  load()
})

onUnmounted(() => compactMediaQuery?.removeEventListener('change', syncCompactLayout))

function syncCompactLayout(event: MediaQueryListEvent): void {
  isCompact.value = event.matches
}
</script>

<style scoped>
.page-card {
  min-height: 100%;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  gap: 8px;
  flex-wrap: wrap;
}

.toolbar-left,
.toolbar-right {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.ws-name {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-weight: 600;
}

.ws-icon {
  color: var(--el-color-primary);
}

.muted {
  color: #94a3b8;
}

.qr-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.qr-image {
  width: 240px;
  height: 240px;
}

.qr-machine-name {
  font-weight: 600;
}

.qr-hint {
  color: #64748b;
  font-size: 12px;
  text-align: center;
}

.qr-url {
  width: 100%;
}

.resource-table {
  width: 100%;
}

.resource-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px 20px;
  min-height: 48px;
  margin: -2px 0 14px;
  padding: 7px 12px;
  border: 1px solid #e1e7ec;
  border-radius: 6px;
  background: #f7f9fb;
}

.summary-heading,
.summary-filters {
  display: flex;
  align-items: center;
}

.summary-heading {
  flex: 0 0 auto;
  gap: 8px;
  color: #263746;
  font-size: 13px;
}

.summary-marker {
  width: 3px;
  height: 18px;
  border-radius: 2px;
  background: #176db3;
}

.summary-total {
  color: #8190a0;
  font-size: 12px;
  font-weight: 400;
}

.summary-filters {
  flex-wrap: wrap;
  gap: 4px;
}

.summary-filter {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 30px;
  padding: 0 9px;
  border: 1px solid transparent;
  border-radius: 4px;
  background: transparent;
  color: #536575;
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease;
}

.summary-filter:hover,
.summary-filter:focus-visible {
  border-color: #b8d5e9;
  background: #fff;
  outline: none;
}

.summary-filter.is-active {
  border-color: #c9dff0;
  background: #eaf3fa;
  color: #155f99;
}

.summary-filter b {
  color: #31485b;
  font-variant-numeric: tabular-nums;
  font-weight: 650;
}

.summary-filter i {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: currentColor;
}

.summary-filter--running i { color: #22826a; }
.summary-filter--maintenance i { color: #ad7419; }
.summary-filter--stopped i { color: #82909d; }

.inactive-note {
  padding-left: 7px;
  border-left: 1px solid #d9e0e6;
  color: #7b8792;
  font-size: 11px;
}

.resource-table :deep(.resource-row--maintenance > td) {
  background: #fffaf1;
}

.resource-table :deep(.resource-row--maintenance > td:first-child .cell) {
  box-shadow: inset 3px 0 0 #c58a2a;
}

.resource-table :deep(.resource-row--stopped > td) {
  background: #f5f7f9;
}

.resource-table :deep(.resource-row--stopped > td:first-child .cell),
.resource-table :deep(.resource-row--inactive > td:first-child .cell) {
  box-shadow: inset 3px 0 0 #8d9aa5;
}

@media (max-width: 700px) {
  .resource-summary {
    align-items: flex-start;
    flex-direction: column;
  }

  .summary-filters {
    width: 100%;
  }

  .page-card :deep(.el-card__body) {
    overflow-x: auto;
  }

  .resource-table {
    min-width: 860px;
  }
}
</style>
