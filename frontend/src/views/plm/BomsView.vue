<template>
  <el-card shadow="never" class="page-card">
    <!-- 筛选栏（product_code 以 URL query 为单一真相） -->
    <div class="filter-bar">
      <el-input
        v-model="productCode"
        placeholder="产品编码（如 P-0001）"
        clearable
        style="width: 190px"
        @keyup.enter="applyProductCode"
        @clear="applyProductCode"
      >
        <template #prefix><el-icon><Search /></el-icon></template>
      </el-input>
      <el-select v-model="status" placeholder="状态" clearable style="width: 130px" @change="load">
        <el-option label="全部状态" value="" />
        <el-option label="草稿" value="draft" />
        <el-option label="已发布" value="published" />
      </el-select>
      <el-input
        v-model="search"
        placeholder="搜索 BOM 简称 / 产品名称"
        clearable
        style="width: 230px"
        @keyup.enter="load"
        @clear="load"
      >
        <template #prefix><el-icon><Search /></el-icon></template>
      </el-input>
      <el-button @click="reset">重置</el-button>
      <div class="spacer" />
      <el-button v-if="canManage" type="primary" @click="openCreate">
        <el-icon><Plus /></el-icon>新建版本
      </el-button>
    </div>
    <div class="hint">金蝶物料清单样式：BOM 头 + 明细行；草稿可编辑，发布后供生产引用（四期直发金蝶）</div>

    <!-- 混合行列表：BOM 头行 + 紧跟的明细行 -->
    <el-table v-loading="loading" :data="visibleRows" :row-class-name="rowClassName" :cell-class-name="cellClassName" @row-click="toggleExpand">
      <el-table-column label="" width="50" align="center">
        <template #default="{ row }">
          <span v-if="row.row_type === 'bom'" class="bom-indicator">
            <el-icon class="expand-arrow"><CaretRight v-if="!isExpanded(row.bom_id)" /><CaretBottom v-else /></el-icon>
            📋
          </span>
        </template>
      </el-table-column>
      <el-table-column label="版本" width="180">
        <template #default="{ row }">
          <el-link v-if="row.row_type === 'bom'" type="primary" :underline="false" @click.stop="openDetail(row)">
            {{ row.version }}
          </el-link>
          <span v-else class="line-version">{{ bomVersionOf(row.bom_id) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="BOM 简称" width="140">
        <template #default="{ row }">{{ row.row_type === 'bom' ? (row.bom_short_name || '—') : '' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <StatusTag v-if="row.row_type === 'bom'" :status="row.status ?? ''" />
        </template>
      </el-table-column>
      <el-table-column label="产品编码" width="130">
        <template #default="{ row }">{{ row.row_type === 'bom' ? row.product_code : '' }}</template>
      </el-table-column>
      <el-table-column label="产品名称" width="160" show-overflow-tooltip>
        <template #default="{ row }">{{ row.row_type === 'bom' ? row.product_name : '' }}</template>
      </el-table-column>
      <el-table-column label="序" width="60">
        <template #default="{ row }">{{ row.row_type === 'line' ? row.seq : '' }}</template>
      </el-table-column>
      <el-table-column label="材料编码" width="160">
        <template #default="{ row }">
          <el-link
            v-if="row.row_type === 'line' && row.is_semi_finished"
            type="primary"
            :underline="false"
            @click="jumpToSemi(row.item_code!)"
          >
            {{ row.item_code }}
          </el-link>
          <span v-else-if="row.row_type === 'line'">{{ row.item_code }}</span>
        </template>
      </el-table-column>
      <el-table-column label="材料名称" width="140" show-overflow-tooltip>
        <template #default="{ row }">{{ row.row_type === 'line' ? row.item_name : '' }}</template>
      </el-table-column>
      <el-table-column label="规格" width="100">
        <template #default="{ row }">{{ row.row_type === 'line' ? (row.spec || '—') : '' }}</template>
      </el-table-column>
      <el-table-column label="用量" width="90">
        <template #default="{ row }">{{ row.row_type === 'line' ? row.qty : '' }}</template>
      </el-table-column>
      <el-table-column label="单位" width="70">
        <template #default="{ row }">{{ row.row_type === 'line' ? row.unit : '' }}</template>
      </el-table-column>
      <el-table-column label="损耗率" width="80">
        <template #default="{ row }">{{ row.row_type === 'line' ? `${row.loss_rate ?? '0.00'}%` : '' }}</template>
      </el-table-column>
      <el-table-column label="主材" width="60" align="center">
        <template #default="{ row }">
          <el-tag v-if="row.row_type === 'line' && row.is_key === 1" type="warning" size="small">主材</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="100" align="center" fixed="right">
        <template #default="{ row }">
          <el-dropdown v-if="row.row_type === 'bom'" trigger="click" @command="(cmd: string) => onBomAction(row, cmd)">
            <el-button size="small" text type="primary">
              操作<el-icon><ArrowDown /></el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item v-for="a in bomActions(row)" :key="a.key" :command="a.key" :disabled="a.disabled">
                  {{ a.label }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <!-- 新建版本（版本创建入口） -->
  <FormDialog
    v-model="createVisible"
    title="新建 BOM 版本"
    :fields="versionFields"
    :model="versionModel"
    @submit="onCreateVersion"
  />

  <!-- BOM 详情 / 版本管理 -->
  <el-dialog v-model="detailVisible" :title="detailTitle" width="880px" destroy-on-close @closed="onDetailClosed">
    <el-tabs v-model="activeTab">
      <el-tab-pane label="明细" name="lines">
        <el-alert
          v-if="!editing"
          title="只读查看（金蝶同步数据，工单场景仅查看）"
          type="info"
          :closable="false"
          style="margin-bottom: 12px"
        />
        <div v-else class="line-editor-toolbar">
          <span class="version-hint">正在编辑「{{ currentBomRow?.version ?? '' }}」明细，保存后该版本需重新发布</span>
          <div>
            <el-button v-if="canManage" type="primary" size="small" @click="onSaveLines">保存</el-button>
            <el-button size="small" @click="onCancelEdit">取消</el-button>
          </div>
        </div>
        <el-table :data="detailLines" border size="small">
          <el-table-column label="序" width="90">
            <template #default="{ row }">
              <el-input-number v-if="editing" v-model="row.seq" :disabled="!editing" :min="1" size="small" controls-position="right" style="width: 90px" />
              <span v-else>{{ row.seq }}</span>
            </template>
          </el-table-column>
          <el-table-column label="材料编码" width="170">
            <template #default="{ row }">
              <el-input v-model="row.item_code" :disabled="!editing" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="材料名称" min-width="140">
            <template #default="{ row }">
              <el-input v-model="row.item_name" :disabled="!editing" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="用量" width="110">
            <template #default="{ row }">
              <el-input-number v-model="row.qty" :disabled="!editing" :precision="4" :min="0" size="small" controls-position="right" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column label="单位" width="80">
            <template #default="{ row }">
              <el-input v-model="row.unit" :disabled="!editing" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="损耗率%" width="110">
            <template #default="{ row }">
              <el-input-number v-model="row.loss_rate" :disabled="!editing" :precision="2" :min="0" :max="100" size="small" controls-position="right" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column label="主材" width="70">
            <template #default="{ row }">
              <el-switch
                v-if="editing"
                v-model="row.is_key"
                :active-value="1"
                :inactive-value="0"
                :disabled="!editing"
              />
              <el-tag v-else-if="row.is_key === 1" type="warning" size="small">主材</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
      <el-tab-pane label="版本管理" name="versions">
        <div class="version-toolbar">
          <span class="version-hint">产品 {{ currentBomRow?.product_code ?? '' }} 的全部 BOM 版本</span>
          <el-button v-if="canManage && currentBomRow" type="primary" size="small" @click="openCreate">新建版本</el-button>
        </div>
        <el-table v-loading="versionsLoading" :data="versions" border size="small">
          <el-table-column prop="version" label="版本" width="100" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }"><StatusTag :status="row.status" /></template>
          </el-table-column>
          <el-table-column label="明细行数" width="90">
            <template #default="{ row }">{{ row.lines_count ?? 0 }}</template>
          </el-table-column>
          <el-table-column label="K3 推送" width="110">
            <template #default="{ row }"><el-tag size="small" type="info">{{ k3Label(row.k3_push_status) }}</el-tag></template>
          </el-table-column>
          <el-table-column label="发布时间" width="170">
            <template #default="{ row }">{{ row.published_at ?? '—' }}</template>
          </el-table-column>
          <el-table-column prop="remark" label="变更说明" min-width="140" show-overflow-tooltip />
          <el-table-column label="操作" width="220" fixed="right">
            <template #default="{ row }">
              <!-- draft: 编辑 / 发布 / 删除 -->
              <template v-if="row.status === 'draft'">
                <el-button v-if="canManage" link type="primary" @click="onEditBom(row)">编辑</el-button>
                <el-button v-if="canPublish" link type="success" @click="onPublish(row)">发布</el-button>
                <el-button v-if="canManage" link type="danger" @click="onDelete(row)">删除</el-button>
              </template>
              <!-- published: 退回 / 禁用 / 删除 -->
              <template v-else-if="row.status === 'published'">
                <el-button v-if="canManage" link type="warning" @click="onRevoke(row)">退回</el-button>
                <el-button v-if="canManage" link type="info" @click="onDisable(row)">禁用</el-button>
                <el-button v-if="canManage" link type="danger" @click="onDelete(row)">删除</el-button>
              </template>
              <!-- disabled: 启用 / 删除 -->
              <template v-else-if="row.status === 'disabled'">
                <el-button v-if="canManage" link type="success" @click="onEnable(row)">启用</el-button>
                <el-button v-if="canManage" link type="danger" @click="onDelete(row)">删除</el-button>
              </template>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>
    <template #footer>
      <el-button @click="detailVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown, CaretBottom, CaretRight, Plus, Search } from '@element-plus/icons-vue'
import { plmApi } from '@/api/plm'
import type { Bom, BomLine, BomListRow } from '@/types'
import FormDialog, { type FormField } from '@/components/common/FormDialog.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const canManage = computed(() => auth.can('plm.bom.manage'))
const canPublish = computed(() => auth.can('plm.bom.publish'))

const route = useRoute()
const router = useRouter()

// 筛选（product_code 与 URL query 双向绑定，为单一真相；status/search 为本地过滤）
const productCode = ref((route.query.product_code as string) ?? '')
const status = ref('')
const search = ref('')

const rows = ref<BomListRow[]>([])
const loading = ref(false)

/** 展开的 BOM id 集合（默认不展开，点击 BOM 头行展开/收起明细行） */
const expandedBoms = ref<Set<number>>(new Set())

/** 可见行：默认仅 BOM 头行；展开的 BOM 追加其明细行 */
const visibleRows = computed<BomListRow[]>(() => {
  const out: BomListRow[] = []
  let currentBomId: number | null = null
  for (const r of rows.value) {
    if (r.row_type === 'bom') {
      out.push(r)
      currentBomId = r.bom_id ?? null
    } else if (currentBomId !== null && expandedBoms.value.has(currentBomId)) {
      out.push(r)
    }
  }
  return out
})

function isExpanded(bomId: number | undefined): boolean {
  return bomId !== undefined && expandedBoms.value.has(bomId)
}

function toggleExpand(row: BomListRow, _column: unknown, event: Event): void {
  if (row.row_type !== 'bom') return
  // 点击操作按钮/版本链接等交互元素时不触发行展开（避免误展开）
  const target = event.target as HTMLElement
  if (target.closest('.el-dropdown, .el-button, a')) return
  if (row.bom_id === undefined) return
  const set = new Set(expandedBoms.value)
  if (set.has(row.bom_id)) {
    set.delete(row.bom_id)
  } else {
    set.add(row.bom_id)
  }
  expandedBoms.value = set
}

const createVisible = ref(false)
const versionModel = ref<Record<string, unknown>>({})
const versionFields: FormField[] = [
  { prop: 'version', label: '版本号', type: 'input', required: true, placeholder: '如 V3.0' },
  { prop: 'remark', label: '变更说明', type: 'textarea' },
]

const detailVisible = ref(false)
const detailTitle = ref('')
const activeTab = ref('lines')
const currentBomRow = ref<BomListRow | null>(null)
const detailLines = ref<BomLine[]>([])
const versions = ref<Bom[]>([])
const versionsLoading = ref(false)
/** 当前明细编辑状态：仅草稿版本可编辑（退回后回到草稿） */
const editing = ref(false)
/** 当前编辑/查看中的 BOM id（用于保存明细） */
const detailCurrentBomId = ref<number | null>(null)

/** 当前产品上下文：详情弹窗打开的 BOM 优先（弹窗内「新建版本」归属该产品）；
 *  未打开弹窗时回退取筛选结果首个 BOM 头行（顶部工具栏按钮的上下文） */
const currentProductId = computed<number | null>(() => {
  if (currentBomRow.value?.product_id) {
    return currentBomRow.value.product_id
  }
  return rows.value.find((r) => r.row_type === 'bom')?.product_id ?? null
})

/** line 行展示其所属 BOM 的版本（灰色辅助文字） */
function bomVersionOf(bomId?: number): string {
  const meta = rows.value.find((r) => r.row_type === 'bom' && r.bom_id === bomId)
  return meta?.version ?? ''
}

function k3Label(value: string | null | undefined): string {
  return ({ none: '未推送', queued: '排队中', pushed: '已推送', failed: '失败' } as Record<string, string>)[value ?? ''] ?? value ?? '—'
}

function rowClassName({ row }: { row: BomListRow }): string {
  return row.row_type === 'bom' ? 'bom-row bom-row-clickable' : 'line-row'
}

function cellClassName({ row, columnIndex }: { row: BomListRow; columnIndex: number }): string {
  return row.row_type === 'line' && columnIndex === 0 ? 'line-indent-cell' : ''
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const params: { product_code?: string; status?: string; search?: string } = {}
    if (productCode.value.trim()) params.product_code = productCode.value.trim()
    if (status.value) params.status = status.value
    if (search.value.trim()) params.search = search.value.trim()
    rows.value = await plmApi.listAllBoms(params)
    // 新查询默认全部收起
    expandedBoms.value = new Set()
  } finally {
    loading.value = false
  }
}

/** 产品编码输入 → 写入 URL query（单一真相），watch(route.query.product_code) 触发重载 */
function applyProductCode(): void {
  const query = { ...route.query }
  if (productCode.value.trim()) {
    query.product_code = productCode.value.trim()
  } else {
    delete query.product_code
  }
  router.replace({ query })
}

function reset(): void {
  productCode.value = ''
  status.value = ''
  search.value = ''
  router.replace({ query: {} })
  void load()
}

function openCreate(): void {
  if (!currentProductId.value) {
    ElMessage.warning('请先输入产品编码筛选，再新建版本')
    return
  }
  versionModel.value = { version: '', remark: '' }
  createVisible.value = true
}

async function onCreateVersion(model: Record<string, unknown>): Promise<void> {
  const productId = currentProductId.value
  if (!productId) return
  try {
    await plmApi.createBom(productId, {
      version: model.version as string,
      remark: (model.remark as string) || undefined,
      lines: [],
    })
    ElMessage.success('BOM 版本创建成功（可编辑明细）')
    createVisible.value = false
    await load()
    await refreshVersions()
  } catch {
    // 统一提示
  }
}

async function refreshVersions(): Promise<void> {
  const code = currentBomRow.value?.product_code
  if (!code) {
    versions.value = []
    return
  }
  versionsLoading.value = true
  try {
    versions.value = await plmApi.listBomsByProductCode(code)
  } catch {
    versions.value = []
  } finally {
    versionsLoading.value = false
  }
}

async function openDetail(row: BomListRow, enterEdit = false): Promise<void> {
  currentBomRow.value = row
  activeTab.value = 'lines'
  detailLines.value = []
  detailCurrentBomId.value = row.bom_id ?? null
  // 列表「编辑明细」直接进入编辑态（仅草稿；非草稿回退为只读查看）
  editing.value = enterEdit && row.status === 'draft'
  detailTitle.value = `BOM ${row.version ?? ''} · ${row.product_name ?? row.product_code ?? ''}`
  detailVisible.value = true

  await loadDetail(row.bom_id!)
  await refreshVersions()
}

/** BomListRow → Bom（操作函数共用） */
function toBom(row: BomListRow): Bom {
  return { id: row.bom_id ?? 0, version: row.version ?? '', status: row.status ?? 'draft' } as Bom
}

/** 主列表操作菜单（按状态 + 权限生成） */
function bomActions(row: BomListRow): Array<{ key: string; label: string; disabled: boolean }> {
  const s = row.status ?? ''
  return [
    { key: 'view', label: '查看明细', disabled: false },
    { key: 'edit', label: '编辑明细', disabled: s !== 'draft' || !canManage.value },
    { key: 'publish', label: '发布', disabled: s !== 'draft' || !canPublish.value },
    { key: 'revoke', label: '退回草稿', disabled: s !== 'published' || !canManage.value },
    { key: 'disable', label: '禁用', disabled: s === 'disabled' || !canManage.value },
    { key: 'enable', label: '启用', disabled: s !== 'disabled' || !canManage.value },
    { key: 'delete', label: '删除', disabled: !canManage.value },
  ]
}

async function onBomAction(row: BomListRow, cmd: string): Promise<void> {
  switch (cmd) {
    case 'view':
      await openDetail(row)
      break
    case 'edit':
      if (row.status !== 'draft') {
        ElMessage.warning('仅草稿状态的 BOM 可编辑')
        break
      }
      await openDetail(row, true)
      break
    case 'publish':
      await onPublish(toBom(row))
      break
    case 'revoke':
      await onRevoke(toBom(row))
      break
    case 'disable':
      await onDisable(toBom(row))
      break
    case 'enable':
      await onEnable(toBom(row))
      break
    case 'delete':
      await onDelete(toBom(row))
      break
  }
}

/** 加载 BOM 明细到详情表格（详情弹窗/行内编辑共用） */
async function loadDetail(id: number): Promise<void> {
  try {
    const detail = await plmApi.getBom(id)
    detailLines.value = detail.lines ?? []
  } catch {
    detailLines.value = []
  }
}

/** 编辑入口：仅草稿版本可编辑 → 切到「明细」Tab 并进入行内编辑态 */
function onEditBom(row: Bom): void {
  if (row.status !== 'draft') {
    ElMessage.warning('仅草稿状态的 BOM 可编辑')
    return
  }
  detailCurrentBomId.value = row.id
  activeTab.value = 'lines'
  editing.value = true
  void loadDetail(row.id)
}

/** 保存明细：调 updateBom（先清后插），成功后退回只读态并刷新版本列表 */
async function onSaveLines(): Promise<void> {
  const id = detailCurrentBomId.value
  if (!id) return
  try {
    await plmApi.updateBom(id, { lines: detailLines.value })
    ElMessage.success('BOM 明细保存成功')
    editing.value = false
    await refreshVersions()
    await load()
  } catch {
    // request 层已统一提示
  }
}

/** 取消编辑：退出编辑态并重新拉取该 BOM 明细还原 */
function onCancelEdit(): void {
  editing.value = false
  if (detailCurrentBomId.value) {
    void loadDetail(detailCurrentBomId.value)
  }
}

/** 退回：已发布 → 草稿（可编辑） */
async function onRevoke(row: Bom): Promise<void> {
  try {
    await ElMessageBox.confirm(`确认退回 BOM「${row.version}」？退回后将变为草稿，可编辑`, '退回确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await plmApi.revokeBom(row.id)
    ElMessage.success('BOM 已退回为草稿')
    await load()
    await refreshVersions()
  } catch {
    // request 层已统一提示
  }
}

/** 禁用：已发布 → 已禁用（不可被排产/订单引用） */
async function onDisable(row: Bom): Promise<void> {
  try {
    await ElMessageBox.confirm(`确认禁用 BOM「${row.version}」？禁用后不可被排产/订单引用`, '禁用确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await plmApi.disableBom(row.id)
    ElMessage.success('BOM 已禁用')
    await load()
    await refreshVersions()
  } catch {
    // request 层已统一提示
  }
}

/** 启用：已禁用 → 已发布 */
async function onEnable(row: Bom): Promise<void> {
  try {
    await ElMessageBox.confirm(`确认启用 BOM「${row.version}」？`, '启用确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await plmApi.enableBom(row.id)
    ElMessage.success('BOM 已启用')
    await load()
    await refreshVersions()
  } catch {
    // request 层已统一提示
  }
}

/** 删除：任意状态软删除（不可恢复） */
async function onDelete(row: Bom): Promise<void> {
  try {
    await ElMessageBox.confirm(`确认删除 BOM「${row.version}」？删除后不可恢复`, '删除确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await plmApi.deleteBom(row.id)
    ElMessage.success('BOM 已删除')
    await load()
    await refreshVersions()
  } catch {
    // request 层已统一提示
  }
}

/** 弹窗关闭：清空当前 BOM 上下文，避免顶部「新建版本」误用上一次弹窗的产品 */
function onDetailClosed(): void {
  currentBomRow.value = null
  versions.value = []
}

/** 半成品跳转：过滤为该半成品的 BOM 列表（URL 同步变化） */
function jumpToSemi(code: string): void {
  router.replace({ query: { ...route.query, product_code: code } })
}

async function onPublish(row: Bom): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定发布 BOM「${row.version}」吗？发布后版本锁定（四期直发金蝶）`, '发布确认', { type: 'warning' })
  } catch {
    return // 用户取消
  }
  try {
    await plmApi.publishBom(row.id)
    ElMessage.success('BOM 发布成功')
    await load()
    await refreshVersions()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message ?? 'BOM 发布失败')
  }
}

onMounted(() => {
  void load()
})

watch(
  () => route.query.product_code,
  () => {
    productCode.value = (route.query.product_code as string) ?? ''
    void load()
  },
)
</script>

<style scoped>
.filter-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.spacer {
  flex: 1;
}

.hint {
  font-size: 13px;
  color: #6b7280;
  margin-bottom: 14px;
}

.bom-indicator {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  font-size: 16px;
}

.expand-arrow {
  font-size: 12px;
  color: #94a3b8;
}

.line-version {
  color: #909399;
  font-size: 12px;
}

.version-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.version-hint {
  font-size: 13px;
  color: #6b7280;
}

.line-editor-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  margin-bottom: 12px;
  background: #f0f9eb;
  border: 1px solid #e1f3d8;
  border-radius: 4px;
}

/* 混合行样式：BOM 头行浅色底 + 加粗；明细行缩进 */
:deep(.bom-row) {
  background: #f5f7fa;
  font-weight: 500;
  cursor: pointer;
}

:deep(.bom-row:hover > td) {
  background: #eef1f6 !important;
}

:deep(.line-indent-cell .cell) {
  padding-left: 28px;
}
</style>
