<template>
  <el-card shadow="never" class="page-card">
    <div class="toolbar">
      <el-input v-model="keyword" placeholder="搜索编码 / 名称" clearable style="width: 200px" @keyup.enter="load" />
      <el-select v-model="filterType" placeholder="模具类型" clearable style="width: 120px">
        <el-option label="注塑模" value="injection" />
        <el-option label="吹塑模" value="blow" />
      </el-select>
      <el-select v-model="filterStatus" placeholder="状态" clearable style="width: 120px">
        <el-option label="使用中" value="in_use" />
        <el-option label="闲置" value="idle" />
        <el-option label="维修中" value="repair" />
        <el-option label="已报废" value="scrapped" />
      </el-select>
      <el-checkbox v-model="onlyDue" label="仅看到期保养" />
      <el-button type="primary" plain @click="load">查询</el-button>
      <div style="flex: 1" />
      <el-button v-if="canManage" type="primary" @click="openCreate"><el-icon><Plus /></el-icon>新增模具</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" stripe>
      <el-table-column prop="code" label="模具编码" width="100" />
      <el-table-column prop="name" label="模具名称" min-width="160" show-overflow-tooltip />
      <el-table-column label="类型" width="90">
        <template #default="{ row }">{{ row.type === 'injection' ? '注塑' : '吹塑' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <StatusTag :status="row.status" />
        </template>
      </el-table-column>
      <el-table-column prop="cavity_count" label="模穴数" width="80" />
      <el-table-column label="寿命使用" width="130">
        <template #default="{ row }">
          <el-progress
            :percentage="Math.min(100, row.life_used_pct ?? Math.round((row.opened_count / (row.lifetime_openings || 1)) * 1000) / 10)"
            :status="(row.opened_count / (row.lifetime_openings || 1)) > 0.85 ? 'exception' : undefined"
            :stroke-width="8"
          />
        </template>
      </el-table-column>
      <el-table-column label="到期保养" width="110">
        <template #default="{ row }">
          <el-tag v-if="row.maintain_due || (row.next_maintain_at && new Date(row.next_maintain_at) <= new Date())" type="danger" size="small">已到期</el-tag>
          <span v-else-if="row.next_maintain_at" class="next-date">{{ row.next_maintain_at.slice(0, 10) }}</span>
          <span v-else class="next-date">—</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" v-if="canManage" width="220" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="warning" @click="openMaintain(row)">保养</el-button>
          <el-button link type="info" @click="viewLife(row)">寿命</el-button>
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

  <!-- 新增/编辑 -->
  <FormDialog
    v-model="dialogVisible"
    :title="editingId ? '编辑模具' : '新增模具'"
    :fields="moldFields"
    :model="formModel"
    @submit="onSubmit"
  />

  <!-- 保养登记 -->
  <FormDialog
    v-model="maintainVisible"
    title="登记保养 / 维修"
    :fields="maintainFields"
    :model="maintainModel"
    @submit="onMaintain"
  />

  <!-- 寿命流水 -->
  <el-dialog v-model="lifeVisible" :title="`寿命流水 · ${lifeMoldCode}`" width="820px">
    <el-descriptions :column="3" border size="small" style="margin-bottom: 12px">
      <el-descriptions-item label="累计开模">{{ lifeMold?.opened_count ?? 0 }} 次</el-descriptions-item>
      <el-descriptions-item label="设计寿命">{{ lifeMold?.lifetime_openings ?? 0 }} 次</el-descriptions-item>
      <el-descriptions-item label="剩余">{{ (lifeMold?.lifetime_openings ?? 0) - (lifeMold?.opened_count ?? 0) }} 次</el-descriptions-item>
    </el-descriptions>
    <el-table :data="lifeLogs" size="small" border max-height="460">
      <el-table-column label="时间" width="180">
        <template #default="{ row }">{{ formatDateTime(row.created_at) }}</template>
      </el-table-column>
      <el-table-column label="订单号" min-width="220">
        <template #default="{ row }">
          <el-link
            v-if="row.order_id ?? row.task?.line?.order?.id"
            type="primary"
            :underline="false"
            @click="goOrderDetail(row.order_id ?? row.task?.line?.order?.id)"
          >
            {{ row.order_no ?? row.task?.line?.order?.order_no }}
          </el-link>
          <span v-else>{{ row.order_no ?? row.task?.line?.order?.order_no ?? '—' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="开模次数" prop="opening_count" width="100" />
      <el-table-column label="说明" min-width="160">
        <template #default>报工联动（三期）</template>
      </el-table-column>
    </el-table>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { plmApi } from '@/api/plm'
import type { Machine, Mold, Product } from '@/types'
import { formatDateTime } from '@/utils/datetime'
import FormDialog, { type FormField } from '@/components/common/FormDialog.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const canManage = computed(() => auth.can('plm.mold.manage'))

const loading = ref(false)
const rows = ref<Mold[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const keyword = ref('')
const filterType = ref('')
const filterStatus = ref('')
const onlyDue = ref(false)

const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const formModel = ref<Record<string, unknown>>({})

const maintainVisible = ref(false)
const maintainMoldId = ref<number | null>(null)
const maintainModel = ref<Record<string, unknown>>({})

const lifeVisible = ref(false)
const lifeMold = ref<Mold | null>(null)
const lifeMoldCode = ref('')
const lifeLogs = ref<Array<{ opening_count: number; created_at: string; order_no?: string; order_id?: number; task?: { line?: { order?: { id: number; order_no: string } } } }>>([])

/** 跳转订单详情 */
function goOrderDetail(orderId: number | undefined): void {
  if (!orderId) return
  router.push({ path: `/order/detail/${orderId}` })
}

const allMachines = ref<Machine[]>([])
const allProducts = ref<Product[]>([])

const moldFields = computed<FormField[]>(() => [
  { prop: 'code', label: '模具编码', type: 'input', required: true, placeholder: '如 M-101' },
  { prop: 'name', label: '模具名称', type: 'input', required: true },
  {
    prop: 'type', label: '模具类型', type: 'select', required: true,
    options: [{ label: '注塑模', value: 'injection' }, { label: '吹塑模', value: 'blow' }],
  },
  {
    prop: 'status', label: '状态', type: 'select', required: true,
    options: [
      { label: '使用中', value: 'in_use' },
      { label: '闲置', value: 'idle' },
      { label: '维修中', value: 'repair' },
      { label: '已报废', value: 'scrapped' },
    ],
  },
  { prop: 'location', label: '存放位置', type: 'input' },
  { prop: 'cavity_count', label: '模穴数', type: 'number', required: true, min: 1 },
  { prop: 'lifetime_openings', label: '设计寿命(次)', type: 'number', required: true, min: 0 },
  { prop: 'purchase_cost', label: '模具造价(元)', type: 'number', min: 0, precision: 2 },
  { prop: 'purchase_date', label: '购置日期', type: 'date' },
  { prop: 'vendor', label: '供应商', type: 'input' },
  { prop: 'maintain_cycle_days', label: '保养周期(天)', type: 'number', min: 1 },
  {
    prop: 'product_ids', label: '关联产品', type: 'select', multiple: true,
    options: allProducts.value.map((p) => ({ label: `${p.code} ${p.name}`, value: p.id })),
  },
  {
    prop: 'machine_ids', label: '适用机台', type: 'select', multiple: true,
    options: allMachines.value.map((m) => ({ label: `${m.code} ${m.name}`, value: m.id })),
  },
  { prop: 'notes', label: '备注', type: 'textarea' },
])

const maintainFields: FormField[] = [
  {
    prop: 'type', label: '类型', type: 'select', required: true,
    options: [{ label: '保养', value: 'maintain' }, { label: '维修', value: 'repair' }],
  },
  { prop: 'cost', label: '费用(元)', type: 'number', min: 0, precision: 2 },
  { prop: 'vendor', label: '供应商/责任人', type: 'input' },
  { prop: 'remark', label: '备注', type: 'textarea' },
]

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await plmApi.listMolds({
      page: page.value,
      per_page: perPage.value,
      keyword: keyword.value || undefined,
      type: filterType.value || undefined,
      status: filterStatus.value || undefined,
      maintain_due: onlyDue.value ? 1 : undefined,
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

async function loadOptions(): Promise<void> {
  const [machineResult, productResult] = await Promise.all([
    plmApi.listMachines({ all: true }),
    plmApi.listProducts({ all: true }),
  ])
  allMachines.value = Array.isArray(machineResult) ? machineResult : machineResult.data
  allProducts.value = Array.isArray(productResult) ? productResult : productResult.data
}

function openCreate(): void {
  editingId.value = null
  formModel.value = {
    code: '', name: '', type: 'injection', status: 'in_use', location: '',
    cavity_count: 1, lifetime_openings: 500000, purchase_cost: undefined,
    purchase_date: undefined, vendor: '', maintain_cycle_days: 30,
    product_ids: [], machine_ids: [], notes: '',
  }
  dialogVisible.value = true
}

function openEdit(row: Mold): void {
  editingId.value = row.id
  formModel.value = {
    ...row,
    product_ids: row.products?.map((p) => p.id) ?? [],
    machine_ids: row.machines?.map((m) => m.id) ?? [],
  }
  dialogVisible.value = true
}

async function onSubmit(model: Record<string, unknown>): Promise<void> {
  try {
    if (editingId.value) {
      await plmApi.updateMold(editingId.value, model as Partial<Mold> & { product_ids?: number[]; machine_ids?: number[] })
      ElMessage.success('模具更新成功')
    } else {
      await plmApi.createMold(model as Partial<Mold> & { product_ids?: number[]; machine_ids?: number[] })
      ElMessage.success('模具创建成功')
    }
    dialogVisible.value = false
    await load()
  } catch {
    // 统一提示
  }
}

function openMaintain(row: Mold): void {
  maintainMoldId.value = row.id
  maintainModel.value = { type: 'maintain', cost: 0, vendor: '', remark: '' }
  maintainVisible.value = true
}

async function onMaintain(model: Record<string, unknown>): Promise<void> {
  try {
    await plmApi.addMaintain(maintainMoldId.value!, model as { type: 'maintain' | 'repair'; cost?: number; vendor?: string; remark?: string })
    ElMessage.success('保养/维修记录已登记')
    maintainVisible.value = false
    await load()
  } catch {
    // 统一提示
  }
}

async function viewLife(row: Mold): Promise<void> {
  lifeMold.value = row
  lifeMoldCode.value = row.code
  try {
    const data = await plmApi.moldLife(row.id)
    lifeLogs.value = data.logs
  } catch {
    lifeLogs.value = []
  }
  lifeVisible.value = true
}

onMounted(async () => {
  await loadOptions()
  await load()
})
</script>

<style scoped>
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
  flex-wrap: wrap;
  align-items: center;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.next-date {
  font-size: 13px;
  color: #6b7280;
}
</style>
