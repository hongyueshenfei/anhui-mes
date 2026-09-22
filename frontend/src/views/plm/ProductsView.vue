<template>
  <el-card shadow="never" class="page-card">
    <div class="toolbar">
      <el-input v-model="keyword" placeholder="搜索编码 / 名称" clearable style="width: 220px" @keyup.enter="load" />
      <el-select v-model="filterStatus" placeholder="状态" clearable style="width: 130px">
        <el-option label="开发中" value="developing" />
        <el-option label="试产" value="trial" />
        <el-option label="量产" value="mass" />
        <el-option label="停产" value="obsolete" />
      </el-select>
      <el-button type="primary" plain @click="load">查询</el-button>
      <div style="flex: 1" />
      <el-button v-if="canManage" type="primary" @click="openCreate"><el-icon><Plus /></el-icon>新建产品</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" stripe>
      <el-table-column prop="code" label="产品编码" width="120" />
      <el-table-column prop="name" label="产品名称" min-width="150" />
      <el-table-column prop="spec" label="规格" min-width="150" show-overflow-tooltip />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <StatusTag :status="row.status" />
          <el-dropdown v-if="canManage" trigger="click" style="margin-left: 4px" @command="(s: string) => onStatusChange(row, s)">
            <el-button link type="primary" size="small">流转</el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="trial" :disabled="row.status !== 'developing'">→ 试产</el-dropdown-item>
                <el-dropdown-item command="mass" :disabled="row.status !== 'trial'">→ 量产</el-dropdown-item>
                <el-dropdown-item command="obsolete">→ 停产</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </template>
      </el-table-column>
      <el-table-column label="默认模具" width="130">
        <template #default="{ row }">{{ row.default_mold?.code ?? '—' }}</template>
      </el-table-column>
      <el-table-column label="默认工艺" width="150">
        <template #default="{ row }">
          <template v-if="row.default_route">
            <span>{{ row.default_route.name ? `${row.default_route.name} · ${row.default_route.version}` : row.default_route.version }}</span>
          </template>
          <span v-else>—</span>
        </template>
      </el-table-column>
      <el-table-column label="已生成数量" width="120" sortable :sort-by="(row: Product) => row.produced_qty ?? 0" align="right">
        <template #default="{ row }">
          <span :class="{ 'qty-zero': !row.produced_qty }">{{ formatQty(row.produced_qty ?? 0) }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="weight" label="重量(g)" width="90" />
      <el-table-column v-if="canManage" label="操作" width="150" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="onDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="perPage"
      class="pager"
      layout="total, prev, pager, next, sizes"
      :total="total"
      :page-sizes="[10, 15, 50]"
      @change="load"
    />
  </el-card>

  <FormDialog
    v-model="dialogVisible"
    :title="editingId ? '编辑产品（基础信息由金蝶同步，可编辑默认工艺）' : '新建产品'"
    :fields="productFields"
    :model="formModel"
    @submit="onSubmit"
  />
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { plmApi } from '@/api/plm'
import type { Mold, PlmRoute, Product } from '@/types'
import FormDialog, { type FormField } from '@/components/common/FormDialog.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const canManage = computed(() => auth.can('plm.product.manage'))

const loading = ref(false)
const rows = ref<Product[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const keyword = ref('')
const filterStatus = ref('')

const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const formModel = ref<Record<string, unknown>>({})

/** 默认工艺下拉选项：独立工艺路线库（名称 · 版本 · 状态 · 工序数） */
const routeOptions = ref<Array<{ label: string; value: number }>>([])
/** 默认模具下拉选项 */
const moldOptions = ref<Array<{ label: string; value: number }>>([])

const ROUTE_STATUS_LABEL: Record<string, string> = { draft: '草稿', published: '已发布' }

/** 工艺路线选项文案：与「工艺路线列表」同口径（名称 · 版本 · 状态 · 工序数） */
function routeOptionLabel(r: PlmRoute): string {
  const status = ROUTE_STATUS_LABEL[r.status] ?? r.status
  const ops = r.ops_count ?? r.ops?.length ?? 0
  return `${r.name} · ${r.version} · ${status} · ${ops} 工序`
}

/** 千分位格式化（已生成数量展示） */
function formatQty(n: number): string {
  return new Intl.NumberFormat('en-US').format(n)
}

/** 表单字段：编辑场景基础信息（金蝶同步）全部禁用；默认工艺/模具可编辑 */
const productFields = computed<FormField[]>(() => {
  const isEditing = editingId.value !== null
  return [
    { prop: 'code', label: '产品编码', type: 'input', required: true, placeholder: '与金蝶物料编码一致', disabled: isEditing },
    { prop: 'name', label: '产品名称', type: 'input', required: true, disabled: isEditing },
    { prop: 'spec', label: '规格', type: 'input', disabled: isEditing },
    { prop: 'unit', label: '单位', type: 'input', placeholder: '只 / 个', disabled: isEditing },
    {
      prop: 'status', label: '状态', type: 'select', required: true,
      options: [
        { label: '开发中', value: 'developing' },
        { label: '试产', value: 'trial' },
        { label: '量产', value: 'mass' },
        { label: '停产', value: 'obsolete' },
      ],
    },
    {
      prop: 'default_route_id', label: '默认工艺', type: 'select', clearable: true,
      placeholder: routeOptions.value.length ? '请选择默认工艺' : '请先在工艺路线发布',
      filterable: true,
      options: routeOptions.value,
    },
    {
      prop: 'default_mold_id', label: '默认模具', type: 'select', clearable: true,
      placeholder: '请选择默认模具',
      options: moldOptions.value,
    },
    { prop: 'weight', label: '单件重量(g)', type: 'number', min: 0, precision: 3 },
  ]
})

/**
 * 默认工艺下拉：工艺路线库全部已发布版本（独立实体，产品仅引用）。
 */
async function loadRouteOptions(): Promise<void> {
  try {
    const routes = await plmApi.listPublishedRoutesAll()
    routeOptions.value = [...(routes ?? [])]
      .sort((a, b) => (a.name ?? '').localeCompare(b.name ?? '') || a.version.localeCompare(b.version))
      .map((r) => ({ label: routeOptionLabel(r), value: r.id }))
  } catch {
    routeOptions.value = []
  }
}

/** 加载全部模具（默认模具候选） */
async function loadMoldOptions(): Promise<void> {
  try {
    const molds = (await plmApi.listMolds()) as Mold[] | { data: Mold[] }
    const list = Array.isArray(molds) ? molds : (molds.data ?? [])
    moldOptions.value = list.map((m) => ({ label: `${m.code} - ${m.name}`, value: m.id }))
  } catch {
    moldOptions.value = []
  }
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await plmApi.listProducts({
      page: page.value,
      per_page: perPage.value,
      keyword: keyword.value || undefined,
      status: filterStatus.value || undefined,
    })
    if (Array.isArray(result)) {
      rows.value = result
      total.value = result.length
    } else {
      rows.value = result.data
      total.value = result.total
    }
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editingId.value = null
  formModel.value = { code: '', name: '', spec: '', unit: '只', status: 'developing', weight: undefined, default_route_id: undefined, default_mold_id: undefined }
  dialogVisible.value = true
  loadRouteOptions()
  loadMoldOptions()
}

function openEdit(row: Product): void {
  editingId.value = row.id
  formModel.value = { ...row, weight: row.weight ?? undefined }
  dialogVisible.value = true
  loadRouteOptions()
  loadMoldOptions()
}

async function onSubmit(model: Record<string, unknown>): Promise<void> {
  // 编辑时不允许直接改 code（金蝶主映射）；produced_qty 由生产累计，不允许前端覆盖
  const payload = { ...model }
  if (editingId.value) {
    delete payload.code
    delete payload.produced_qty
  }
  try {
    if (editingId.value) {
      await plmApi.updateProduct(editingId.value, payload as Partial<Product>)
      ElMessage.success('产品更新成功')
    } else {
      await plmApi.createProduct(payload as Partial<Product>)
      ElMessage.success('产品创建成功')
    }
    dialogVisible.value = false
    await load()
  } catch {
    // 统一提示
  }
}

async function onStatusChange(row: Product, status: string): Promise<void> {
  try {
    await plmApi.transitionStatus(row.id, status)
    ElMessage.success(`状态已流转为「${status}」`)
    await load()
  } catch {
    // 统一提示
  }
}

async function onDelete(row: Product): Promise<void> {
  await ElMessageBox.confirm(`确定删除产品「${row.code}」吗？`, '删除确认', { type: 'warning' })
  await plmApi.deleteProduct(row.id)
  ElMessage.success('产品已删除')
  await load()
}

onMounted(load)
</script>

<style scoped>
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.qty-zero {
  color: #c0c4cc;
}
</style>
