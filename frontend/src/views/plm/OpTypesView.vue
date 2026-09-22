<template>
  <el-card shadow="never" class="page-card">
    <div class="toolbar">
      <el-input v-model="keyword" placeholder="搜索名称 / 类型键" clearable style="width: 220px" @keyup.enter="load" />
      <el-button type="primary" plain @click="load">查询</el-button>
      <div style="flex: 1" />
      <el-button v-if="canManage" type="primary" @click="openCreate"><el-icon><Plus /></el-icon>新增工序类型</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" stripe>
      <el-table-column prop="op_type" label="类型键" width="170" show-overflow-tooltip />
      <el-table-column prop="op_name" label="名称" min-width="110" />
      <el-table-column label="形态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.is_molding ? 'warning' : 'info'" size="small">{{ row.is_molding ? '成型' : '人工线' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="机台类型" width="100">
        <template #default="{ row }">{{ row.machine_type ?? '—' }}</template>
      </el-table-column>
      <el-table-column label="适用车间" width="110">
        <template #default="{ row }">{{ workshopName(row.workshop) }}</template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="70" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="used_count" label="引用" width="70" />
      <el-table-column prop="remark" label="说明" min-width="140" show-overflow-tooltip />
      <el-table-column v-if="canManage" label="操作" width="200" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button v-if="row.is_active" link type="warning" @click="onToggleActive(row)">停用</el-button>
          <el-button v-else link type="success" @click="onToggleActive(row)">启用</el-button>
          <el-button link type="danger" @click="onDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <FormDialog
    v-model="dialogVisible"
    :title="editingId ? '编辑工序类型' : '新增工序类型'"
    :fields="formFields"
    :model="formModel"
    @submit="onSubmit"
  />
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { plmApi } from '@/api/plm'
import type { OpType } from '@/types'
import { WORKSHOP_LABEL, type Workshop } from '@/types'
import FormDialog, { type FormField } from '@/components/common/FormDialog.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const canManage = computed(() => auth.can('plm.route.manage'))

const loading = ref(false)
const rows = ref<OpType[]>([])
const keyword = ref('')

const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const formModel = ref<Record<string, unknown>>({})

const WORKSHOP_OPTIONS = (Object.keys(WORKSHOP_LABEL) as Workshop[]).map((w) => ({ label: WORKSHOP_LABEL[w], value: w }))

/** 车间名（兜底原值） */
function workshopName(ws: string): string {
  return WORKSHOP_LABEL[ws as Workshop] ?? ws
}

const formFields = computed<FormField[]>(() => {
  const isEditing = editingId.value !== null
  return [
    { prop: 'op_type', label: '类型键', type: 'input', required: true, placeholder: '如 molding_injection', disabled: isEditing },
    { prop: 'op_name', label: '名称', type: 'input', required: true, placeholder: '如 注塑成型' },
    {
      prop: 'is_molding', label: '形态', type: 'switch',
    },
    {
      prop: 'machine_type', label: '机台类型', type: 'select', required: true,
      show: (m) => m.is_molding === 1,
      options: [
        { label: '注塑机（injection）', value: 'injection' },
        { label: '吹塑机（blow）', value: 'blow' },
      ],
    },
    {
      prop: 'workshop', label: '适用车间', type: 'select', required: true,
      options: WORKSHOP_OPTIONS,
    },
    { prop: 'sort', label: '排序', type: 'number', min: 1 },
    { prop: 'remark', label: '说明', type: 'input' },
  ]
})

async function load(): Promise<void> {
  loading.value = true
  try {
    // all=true：真实后端返回全量数组（含停用项，启停按钮才可用）；兼容分页对象
    const result = (await plmApi.listOpTypes({ all: true, keyword: keyword.value || undefined })) as OpType[] | { data: OpType[] }
    rows.value = Array.isArray(result) ? result : result.data ?? []
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editingId.value = null
  const maxSort = Math.max(0, ...rows.value.map((r) => r.sort))
  formModel.value = { op_type: '', op_name: '', is_molding: 0, machine_type: null, workshop: 'injection', sort: maxSort + 1, remark: '' }
  dialogVisible.value = true
}

function openEdit(row: OpType): void {
  editingId.value = row.id
  formModel.value = {
    op_type: row.op_type,
    op_name: row.op_name,
    is_molding: row.is_molding ? 1 : 0,
    machine_type: row.machine_type ?? null,
    workshop: row.workshop,
    sort: row.sort,
    remark: row.remark ?? '',
  }
  dialogVisible.value = true
}

async function onSubmit(model: Record<string, unknown>): Promise<void> {
  const payload = { ...model, is_molding: model.is_molding === 1 }
  try {
    if (editingId.value) {
      await plmApi.updateOpType(editingId.value, payload as Partial<OpType>)
      ElMessage.success('工序类型已更新')
    } else {
      await plmApi.createOpType(payload as Partial<OpType>)
      ElMessage.success('工序类型已创建')
    }
    dialogVisible.value = false
    await load()
  } catch {
    // 统一提示
  }
}

async function onToggleActive(row: OpType): Promise<void> {
  const next = !row.is_active
  try {
    await plmApi.updateOpType(row.id, { is_active: next })
    ElMessage.success(next ? '已启用' : '已停用')
    await load()
  } catch {
    // 统一提示
  }
}

async function onDelete(row: OpType): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除工序类型「${row.op_name}」吗？`, '删除确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await plmApi.deleteOpType(row.id)
    ElMessage.success('工序类型已删除')
    await load()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message ?? '删除失败（可能被工艺路线引用，可停用）')
  }
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
</style>
