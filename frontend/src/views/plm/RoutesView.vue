<template>
  <el-card shadow="never" class="page-card">
    <div class="toolbar">
      <el-input v-model="keyword" placeholder="搜索路线名称 / 版本" clearable style="width: 220px" @keyup.enter="load" />
      <el-select v-model="filterStatus" placeholder="状态" clearable style="width: 130px" @change="load">
        <el-option label="草稿" value="draft" />
        <el-option label="已发布" value="published" />
        <el-option label="已禁用" value="disabled" />
      </el-select>
      <el-button type="primary" plain @click="load">查询</el-button>
      <div style="flex: 1" />
      <!-- 产能换算预览（验收 A9） -->
      <el-popover placement="bottom-end" width="340" trigger="click">
        <template #reference>
          <el-button plain>
            <el-icon><Cpu /></el-icon>产能换算预览
          </el-button>
        </template>
        <div class="calc-panel">
          <el-input-number v-model="calc.qty" :min="1" style="width: 120px" />
          <span class="x">件</span>
          <el-input-number v-model="calc.capacity" :min="0" :precision="2" style="width: 140px" />
          <span class="x">件/h</span>
          <el-input-number v-model="calc.moldChange" :min="30" :max="30" disabled style="width: 120px" />
          <span class="x">min 固定换模</span>
          <el-button type="primary" size="small" style="width: 100%; margin-top: 8px" @click="doCalc">计算所需时间</el-button>
          <el-alert v-if="calcResult" :title="calcResult" type="success" :closable="false" style="margin-top: 8px" />
        </div>
      </el-popover>
      <el-button v-if="canManage" type="primary" @click="openCreate">
        <el-icon><Plus /></el-icon>新建工艺
      </el-button>
    </div>

    <StaleDataNotice :visible="loadError && routes.length > 0" @retry="load" />

    <el-table v-loading="loading" :data="routes" stripe>
      <template #empty>
        <EmptyStatePanel :kind="emptyKind" :title="emptyTitle" :description="emptyDescription" :action-label="emptyActionLabel" @action="onEmptyAction" />
      </template>
      <el-table-column prop="name" label="路线名称" min-width="180" show-overflow-tooltip />
      <el-table-column prop="version" label="版本" width="90" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }"><StatusTag :status="row.status" /></template>
      </el-table-column>
      <el-table-column label="工序数" width="90">
        <template #default="{ row }">{{ row.ops_count ?? (row.ops?.length ?? 0) }}</template>
      </el-table-column>
      <el-table-column prop="remark" label="说明" min-width="200" show-overflow-tooltip />
      <el-table-column label="操作" width="340" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="viewOps(row)">查看工序</el-button>
          <template v-if="canManage">
            <el-button v-if="row.status !== 'disabled'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-if="row.status === 'draft'" link type="success" @click="onPublish(row)">发布</el-button>
            <el-button v-if="row.status === 'published'" link type="warning" @click="onRevoke(row)">下架</el-button>
            <el-button v-if="row.status !== 'disabled'" link type="danger" @click="onDisable(row)">禁用</el-button>
            <el-button v-if="row.status === 'disabled'" link type="success" @click="onEnable(row)">启用</el-button>
            <el-button link type="info" @click="openCopy(row)">复制</el-button>
            <el-button link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <!-- 工序编辑弹窗 -->
  <el-dialog v-model="opsVisible" :title="opsTitle" width="920px">
    <el-alert
      title="工序只需确定「在哪做」（生产车间 + 默认机台/产线）；理论产能由排产选定机台后按机台产能计算，工艺路线不再维护产能"
      type="info"
      :closable="false"
      style="margin-bottom: 12px"
    />
    <!-- 路线头部：名称/版本/说明（已确认订单锁定工序，头部编辑不影响订单） -->
    <el-form label-width="90px" class="route-head-form">
      <el-row :gutter="16">
        <el-col :span="12">
          <el-form-item label="路线名称" required>
            <el-input v-model="routeName" :disabled="!headEditable" placeholder="如 标准注塑工艺" maxlength="128" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="版本" required>
            <el-input v-model="routeVersion" :disabled="!headEditable" placeholder="如 R1" maxlength="16" style="width: 110px" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="状态">
            <StatusTag :status="currentStatus" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="说明">
        <el-input v-model="routeRemark" :disabled="!headEditable" type="textarea" :rows="2" placeholder="路线说明（可选）" maxlength="255" />
      </el-form-item>
    </el-form>
    <el-table :data="ops" border size="small">
      <el-table-column label="序号" width="70">
        <template #default="{ row }">
          <el-input-number v-model="row.seq" :disabled="viewingOnly" :min="1" :max="99" size="small" controls-position="right" style="width: 64px" />
        </template>
      </el-table-column>
      <el-table-column label="工序类型" width="140">
        <template #default="{ row }">
          <el-select v-model="row.op_type" :disabled="viewingOnly" size="small" @change="onOpTypeChange(row)">
            <el-option v-for="t in templates" :key="t.op_type" :label="t.op_name" :value="t.op_type" />
          </el-select>
        </template>
      </el-table-column>
      <el-table-column label="工序名" width="120">
        <template #default="{ row }">
          <el-input v-model="row.op_name" :disabled="viewingOnly" size="small" />
        </template>
      </el-table-column>
      <el-table-column label="生产车间" width="150">
        <template #default="{ row }">
          <el-select v-model="row.workshop_id" :disabled="viewingOnly" size="small" clearable placeholder="选择车间" @change="onWorkshopChange(row)">
            <el-option v-for="w in activeWorkshops" :key="w.id" :label="w.name" :value="w.id" />
          </el-select>
        </template>
      </el-table-column>
      <el-table-column label="换模(min)" width="100">
        <template #default="{ row }">
          <el-input-number
            v-if="isMoldingOp(row.op_type)"
            v-model="row.mold_change_min"
            :disabled="viewingOnly"
            :min="0"
            size="small"
            controls-position="right"
            style="width: 90px"
          />
          <span v-else class="muted">—</span>
        </template>
      </el-table-column>
      <el-table-column label="默认机台/产线" min-width="190">
        <template #default="{ row }">
          <el-select
            v-if="isMoldingOp(row.op_type)"
            v-model="row.default_machine_id"
            :disabled="viewingOnly"
            size="small"
            clearable
            placeholder="选择机台"
          >
            <el-option
              v-for="m in machineCandidatesFor(row)"
              :key="m.id"
              :label="`${m.code} - ${m.name}`"
              :value="m.id"
            />
          </el-select>
          <el-select v-else v-model="row.default_line_id" :disabled="viewingOnly" size="small" clearable placeholder="选择产线">
            <el-option
              v-for="m in lineCandidatesFor(row)"
              :key="m.id"
              :label="`${m.code} - ${m.name}`"
              :value="m.id"
            />
          </el-select>
        </template>
      </el-table-column>
      <el-table-column label="删除" width="60">
        <template #default="{ $index }">
          <el-button v-if="!viewingOnly" link type="danger" @click="ops.splice($index, 1)">删</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div v-if="!viewingOnly" class="op-adder">
      <el-button type="primary" plain @click="addOp">+ 添加工序</el-button>
      <el-button type="success" plain @click="applyTemplate">
        <el-icon><MagicStick /></el-icon>一键生成标准 4 工序模板
      </el-button>
    </div>

    <template #footer>
      <el-button @click="opsVisible = false">取消</el-button>
      <el-button v-if="headEditable" type="primary" :loading="opsSaving" @click="saveOps">保存</el-button>
    </template>
  </el-dialog>

  <!-- 复制弹窗 -->
  <FormDialog
    v-model="copyVisible"
    title="复制工艺路线"
    :fields="copyFields"
    :model="copyModel"
    @submit="onCopy"
  />
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Cpu, MagicStick, Plus } from '@element-plus/icons-vue'
import { plmApi } from '@/api/plm'
import type { Machine, OpTemplate, PlmRoute, PlmWorkshop, RouteOp } from '@/types'
import { OP_TYPE_WORKSHOP } from '@/types'
import FormDialog, { type FormField } from '@/components/common/FormDialog.vue'
import EmptyStatePanel from '@/components/common/EmptyStatePanel.vue'
import StaleDataNotice from '@/components/common/StaleDataNotice.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { useAuthStore } from '@/stores/auth'
import { estimateHours, formatHours } from '@/utils/capacity'

const auth = useAuthStore()
const canManage = computed(() => auth.can('plm.route.manage'))

const routes = ref<PlmRoute[]>([])
const loading = ref(true)
const keyword = ref('')
const filterStatus = ref('')
const loadError = ref(false)
const hasFilters = computed(() => Boolean(keyword.value.trim() || filterStatus.value))
const emptyKind = computed(() => loading.value ? 'loading' : loadError.value ? 'error' : hasFilters.value ? 'filtered' : 'empty')
const emptyTitle = computed(() => loading.value ? '正在读取工艺路线' : loadError.value ? '工艺路线加载失败' : hasFilters.value ? '没有匹配的工艺路线' : '还没有工艺路线')
const emptyDescription = computed(() => loading.value ? '正在从生产系统读取最新记录。' : loadError.value
  ? '请检查服务连接后重试；接口异常不会被当作“没有路线”。'
  : hasFilters.value ? '调整路线名称、版本或状态条件，也可以清空筛选。' : '先建立工艺路线，再配置工序顺序与适用车间。')
const emptyActionLabel = computed(() => loading.value ? '' : loadError.value ? '重新加载' : hasFilters.value ? '清空筛选' : '')

const opsVisible = ref(false)
const opsTitle = ref('')
const ops = ref<RouteOp[]>([])
const opsSaving = ref(false)
const editingRouteId = ref<number | null>(null)
/** 工序只读（仅「查看工序」模式；编辑任意状态均可改工序） */
const viewingOnly = ref(false)
/** 头部（名称/版本/说明）可编辑：编辑与新建为 true，纯查看为 false */
const headEditable = ref(false)
const routeName = ref('')
const routeVersion = ref('')
const routeRemark = ref('')
const currentStatus = ref<PlmRoute['status']>('draft')
const templates = ref<OpTemplate[]>([])
/** 全部机台/产线资源（下拉数据源；按车间+类型在前端过滤，等价于 listMachines({workshop,type})） */
const machines = ref<Machine[]>([])
/** 车间列表（code→id 映射，默认机台/产线候选按 workshop_id 过滤） */
const workshops = ref<PlmWorkshop[]>([])

const copyVisible = ref(false)
const copyRouteId = ref<number | null>(null)
const copyModel = ref<Record<string, unknown>>({})
const copyFields: FormField[] = [
  { prop: 'name', label: '路线名称', type: 'input', required: true, placeholder: '如 标准注塑工艺' },
  { prop: 'version', label: '新版本号', type: 'input', required: true, placeholder: '如 R1' },
  { prop: 'remark', label: '说明', type: 'textarea' },
]

// 产能换算
const calc = ref({ qty: 1000, capacity: 400, moldChange: 30 })
const calcResult = ref('')

function doCalc(): void {
  try {
    const hours = estimateHours(calc.value.qty, calc.value.capacity, calc.value.moldChange)
    calcResult.value = `${calc.value.qty} 件 约需 ${formatHours(hours)}（固定换模 30 分钟，含 5% 预留）`
  } catch (e) {
    calcResult.value = (e as Error).message
  }
}

/** 启用中的车间（生产车间下拉数据源） */
const activeWorkshops = computed(() => workshops.value.filter((w) => w.is_active === 1))

/** 从工序类型字典取元数据（opTemplates 启用项；自定义类型跟随字典） */
function metaFor(opType: string): OpTemplate | undefined {
  return templates.value.find((t) => t.op_type === opType)
}

/** 是否成型工序（需选机台）：字典优先，存量类型回退硬编码 */
function isMoldingOp(opType: string): boolean {
  const meta = metaFor(opType)
  if (meta) return meta.is_molding
  return opType === 'molding_injection' || opType === 'molding_blow'
}

/**
 * 「默认机台」候选：仅成型工序，且限定为「本工序生产车间下的对应机台类型」。
 * 车间按工序显式 workshop_id 过滤；未指定车间时按字典 workshop code 推导（兼容存量数据）。
 */
function machineCandidatesFor(op: RouteOp): Machine[] {
  const meta = metaFor(op.op_type)
  if (!(meta?.is_molding ?? isMoldingOp(op.op_type))) return []
  const type = meta?.machine_type ?? (op.op_type === 'molding_injection' ? 'injection' : 'blow')
  if (op.workshop_id) return machines.value.filter((m) => m.type === type && m.workshop_id === op.workshop_id)
  const ws = OP_TYPE_WORKSHOP[op.op_type] ?? meta?.workshop
  const wid = ws ? workshopIdForCode(ws) : undefined
  return machines.value.filter((m) => m.type === type && (wid ? m.workshop_id === wid : m.workshop === ws))
}

/**
 * 「默认产线」候选：仅人工线工序，且限定为「本工序生产车间下的对应产线类型」。
 * 车间按工序显式 workshop_id 过滤；未指定车间时按字典 workshop code 推导（兼容存量数据）。
 */
function lineCandidatesFor(op: RouteOp): Machine[] {
  const meta = metaFor(op.op_type)
  if (meta?.is_molding ?? isMoldingOp(op.op_type)) return []
  const type = op.op_type === 'pack' ? 'pack_line' : op.op_type === 'effect' ? 'effect_line' : 'paint_line'
  if (op.workshop_id) return machines.value.filter((m) => m.type === type && m.workshop_id === op.workshop_id)
  const ws = OP_TYPE_WORKSHOP[op.op_type] ?? meta?.workshop
  const wid = ws ? workshopIdForCode(ws) : undefined
  return machines.value.filter((m) => m.type === type && (wid ? m.workshop_id === wid : m.workshop === ws))
}

/** 车间 code → workshop_id（真实后端按 id 匹配；未加载到映射时返回 undefined 走 code 兜底） */
function workshopIdForCode(code: string): number | undefined {
  return workshops.value.find((w) => w.code === code)?.id
}

/** 生产车间变化：重置默认机台/产线，并按车间同步机台类型（兼容排产按 machine_type 兜底） */
function onWorkshopChange(op: RouteOp): void {
  op.default_machine_id = undefined
  op.default_line_id = undefined
  const ws = workshops.value.find((w) => w.id === op.workshop_id)
  if (ws && (ws.code === 'injection' || ws.code === 'blow')) {
    op.machine_type = ws.code
  } else {
    op.machine_type = null
  }
}

/** 工序类型变化时：未显式选择车间则带出字典车间，并重置默认机台/产线选择 */
function onOpTypeChange(op: RouteOp): void {
  op.default_machine_id = undefined
  op.default_line_id = undefined
  const meta = metaFor(op.op_type)
  const ws = OP_TYPE_WORKSHOP[op.op_type] ?? meta?.workshop
  if (ws && !op.workshop_id) {
    const wid = workshopIdForCode(ws)
    if (wid) op.workshop_id = wid
  }
  const curWs = workshops.value.find((w) => w.id === op.workshop_id)
  if (curWs && (curWs.code === 'injection' || curWs.code === 'blow')) {
    op.machine_type = curWs.code
  } else {
    op.machine_type = meta?.machine_type ?? null
  }
}

/** 加载全部机台/产线资源（供默认机台/产线下拉使用） */
async function loadMachines(): Promise<void> {
  const result = await plmApi.listMachines({ all: true })
  machines.value = Array.isArray(result) ? result : result.data
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    routes.value = await plmApi.listRoutes({
      keyword: keyword.value || undefined,
      status: filterStatus.value || undefined,
    })
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

function onEmptyAction(): void {
  if (loadError.value) {
    void load()
  } else if (hasFilters.value) {
    keyword.value = ''
    filterStatus.value = ''
    void load()
  } else if (canManage.value) {
    openCreate()
  }
}

async function loadMeta(): Promise<void> {
  templates.value = await plmApi.opTemplates()
  // 车间列表（code→id 映射，默认机台/产线候选按 workshop_id 过滤）
  const wsResult = await plmApi.listWorkshops({ active_only: 1 })
  workshops.value = Array.isArray(wsResult) ? wsResult : wsResult.data ?? []
}

async function openCreate(): Promise<void> {
  editingRouteId.value = null
  viewingOnly.value = false
  headEditable.value = true
  currentStatus.value = 'draft'
  routeName.value = ''
  routeVersion.value = 'R1'
  routeRemark.value = ''
  ops.value = []
  opsTitle.value = '新建工艺路线'
  opsVisible.value = true
}

async function openEdit(row: PlmRoute): Promise<void> {
  editingRouteId.value = row.id
  // 工艺路线与已生成排程脱离关联：任意状态均可编辑名称/版本/说明/工序
  viewingOnly.value = false
  headEditable.value = true
  currentStatus.value = row.status
  const detail = await plmApi.getRoute(row.id)
  routeName.value = detail.name
  routeVersion.value = detail.version
  routeRemark.value = detail.remark ?? ''
  // 加载即序号连续化（1/2/3/...）：防御历史数据/外部导入的跳号（10/20/30），展示与保存口径一致
  ops.value = (detail.ops ?? []).map((o, i) => ({ ...o, seq: i + 1 }))
  opsTitle.value = `编辑工艺「${detail.name} ${detail.version}」`
  opsVisible.value = true
}

async function viewOps(row: PlmRoute): Promise<void> {
  editingRouteId.value = null
  viewingOnly.value = true
  headEditable.value = false
  currentStatus.value = row.status
  const detail = await plmApi.getRoute(row.id)
  routeName.value = detail.name
  routeVersion.value = detail.version
  routeRemark.value = detail.remark ?? ''
  ops.value = (detail.ops ?? []).map((o, i) => ({ ...o, seq: i + 1 }))
  opsTitle.value = `工艺「${row.name} ${row.version}」· 查看`
  opsVisible.value = true
}

function applyTemplate(): void {
  // 标准 4 工序：成型（注塑/吹塑取一个）→ 喷漆 → 效果 → 包装；
  // 工序序号为路线内连续序号 1/2/3/4，车间按工序类型字典推导
  const molding = templates.value.find((t) => t.is_molding) ?? templates.value[0]
  const manual = templates.value.filter((t) => !t.is_molding)
  const selected = [molding, ...manual].filter((t): t is OpTemplate => !!t)
  ops.value = selected.map((t, i) => ({
    seq: i + 1,
    op_type: t.op_type,
    op_name: t.op_name,
    workshop_id: (OP_TYPE_WORKSHOP[t.op_type] ?? t.workshop) ? workshopIdForCode(OP_TYPE_WORKSHOP[t.op_type] ?? t.workshop!) : undefined,
    mold_change_min: t.is_molding ? 30 : null,
    shift_mode: t.is_molding ? ('two_shift' as const) : ('day' as const),
    machine_type: t.machine_type,
    rate_key: null,
    default_machine_id: undefined,
    default_line_id: undefined,
  }))
}

function addOp(): void {
  const meta = metaFor('paint')
  ops.value.push({
    seq: ops.value.length + 1,
    op_type: 'paint',
    op_name: '喷漆',
    workshop_id: meta?.workshop ? workshopIdForCode(meta.workshop) : undefined,
    mold_change_min: null,
    shift_mode: 'day',
    machine_type: null,
    rate_key: null,
    default_machine_id: undefined,
    default_line_id: undefined,
  })
}

async function saveOps(): Promise<void> {
  const name = routeName.value.trim()
  const version = routeVersion.value.trim()
  if (!name) {
    ElMessage.warning('请输入工艺路线名称')
    return
  }
  if (!version) {
    ElMessage.warning('请输入工艺版本号')
    return
  }
  if (ops.value.length === 0) {
    ElMessage.warning('请至少添加一道工序')
    return
  }
  // 序号强制连续化（1/2/3/...），避免删除中间工序后产生跳号
  ops.value.forEach((op, i) => {
    op.seq = i + 1
  })
  opsSaving.value = true
  try {
    if (editingRouteId.value) {
      const payload: { name: string; version: string; remark: string; ops?: RouteOp[] } = {
        name,
        version,
        remark: routeRemark.value,
        ops: ops.value,
      }
      await plmApi.updateRoute(editingRouteId.value, payload)
      ElMessage.success('工艺路线更新成功')
    } else {
      await plmApi.createRoute({
        name,
        version,
        remark: routeRemark.value,
        ops: ops.value,
      })
      ElMessage.success('工艺路线创建成功')
    }
    opsVisible.value = false
    await load()
  } catch (e) {
    // 保存失败：展示后端 422 中的首个业务错误（如车间不匹配），便于用户定位
    const detail = (e as { response?: { data?: { data?: Record<string, string[]> } } })?.response?.data?.data
    const firstMsg = detail ? Object.values(detail)[0]?.[0] : undefined
    ElMessage.error(firstMsg ?? '工艺路线保存失败，请检查工序配置')
  } finally {
    opsSaving.value = false
  }
}

async function onPublish(row: PlmRoute): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定发布工艺路线「${row.name} ${row.version}」吗？`, '发布确认', { type: 'warning' })
  } catch {
    return // 用户取消
  }
  await plmApi.publishRoute(row.id)
  ElMessage.success('工艺路线发布成功')
  await load()
}

/** 下架：已发布 → 草稿（退回后可编辑并重新发布） */
async function onRevoke(row: PlmRoute): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定下架工艺「${row.name} ${row.version}」吗？下架后可编辑并重新发布`, '下架确认', { type: 'warning' })
  } catch {
    return // 用户取消
  }
  await plmApi.revokeRoute(row.id)
  ElMessage.success('工艺路线已下架（退回草稿）')
  await load()
}

/** 禁用：任意状态 → 已禁用（不可被引用） */
async function onDisable(row: PlmRoute): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定禁用工艺「${row.name} ${row.version}」吗？禁用后不可被引用`, '禁用确认', { type: 'warning' })
  } catch {
    return // 用户取消
  }
  await plmApi.disableRoute(row.id)
  ElMessage.success('工艺路线已禁用')
  await load()
}

/** 启用：已禁用 → 已发布（恢复可用） */
async function onEnable(row: PlmRoute): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定启用工艺「${row.name} ${row.version}」吗？启用后恢复为已发布`, '启用确认', { type: 'warning' })
  } catch {
    return // 用户取消
  }
  await plmApi.enableRoute(row.id)
  ElMessage.success('工艺路线已启用')
  await load()
}

/** 删除：任意状态软删（存在引用时后端 409 保护） */
async function onDelete(row: PlmRoute): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除工艺「${row.name} ${row.version}」吗？删除后不可恢复`, '删除确认', { type: 'error' })
  } catch {
    return // 用户取消
  }
  await plmApi.deleteRoute(row.id)
  ElMessage.success('工艺路线已删除')
  await load()
}

function openCopy(row: PlmRoute): void {
  copyRouteId.value = row.id
  copyModel.value = { name: `${row.name} 副本`, version: 'R1', remark: `复制自「${row.name}」${row.version}` }
  copyVisible.value = true
}

async function onCopy(model: Record<string, unknown>): Promise<void> {
  try {
    await plmApi.copyRoute(copyRouteId.value!, {
      name: model.name as string,
      version: model.version as string,
      remark: (model.remark as string) || undefined,
    })
    ElMessage.success('工艺复制成功（新路线为草稿）')
    copyVisible.value = false
    await load()
  } catch {
    // 统一提示
  }
}

onMounted(async () => {
  await Promise.all([loadMeta(), loadMachines(), load()])
})
</script>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
}

.calc-panel {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.x {
  font-size: 12px;
  color: #6b7280;
}

.op-adder {
  display: flex;
  gap: 10px;
  margin-top: 14px;
}

.muted {
  color: #c0c4cc;
}
</style>
