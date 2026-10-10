<template>
  <el-card shadow="never" class="page-card">
    <div class="toolbar">
      <el-input v-model="keyword" placeholder="搜索工号 / 姓名 / 手机" clearable style="width: 220px" @keyup.enter="load" />
      <el-select v-model="filterRole" placeholder="角色" clearable style="width: 130px">
        <el-option v-for="r in roles" :key="r.name" :label="roleLabel(r.name)" :value="r.name" />
      </el-select>
      <el-button type="primary" plain @click="load">查询</el-button>
      <div style="flex: 1" />
      <el-button v-if="canManage" type="primary" @click="openCreate"><el-icon><Plus /></el-icon>新建用户</el-button>
    </div>

    <StaleDataNotice :visible="loadError && rows.length > 0" @retry="load" />

    <el-table v-loading="loading" :data="rows" stripe>
      <template #empty>
        <EmptyStatePanel :kind="emptyKind" :title="emptyTitle" :description="emptyDescription" :action-label="emptyActionLabel" @action="onEmptyAction" />
      </template>
      <el-table-column prop="employee_no" label="工号" width="100" />
      <el-table-column prop="name" label="姓名" width="110" />
      <el-table-column prop="phone" label="手机号" width="130" />
      <el-table-column label="岗位" width="100">
        <template #default="{ row }">{{ roleLabel(row.job_type) }}</template>
      </el-table-column>
      <el-table-column label="角色" min-width="150">
        <template #default="{ row }">
          <el-tag v-for="r in row.roles" :key="r" size="small" style="margin-right: 4px">{{ roleLabel(r) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="workshop_name" label="车间" width="130">
        <template #default="{ row }">{{ row.workshop_name ?? '—' }}</template>
      </el-table-column>
      <el-table-column label="绑定" width="130">
        <template #default="{ row }">
          <el-tag v-if="row.wecom_bound" size="small" type="success">企微</el-tag>
          <el-tag v-if="row.wx_bound" size="small" type="primary">微信</el-tag>
          <span v-if="!row.wecom_bound && !row.wx_bound" class="none">未绑定</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column v-if="canManage" label="操作" width="220" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="warning" @click="openReset(row)">重置密码</el-button>
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

  <!-- 新建/编辑 -->
  <FormDialog
    v-model="dialogVisible"
    :title="editingId ? '编辑用户' : '新建用户'"
    :fields="userFields"
    :model="formModel"
    @submit="onSubmit"
  />

  <!-- 重置密码 -->
  <el-dialog v-model="resetVisible" title="重置密码" width="400px">
    <el-form label-width="80px">
      <el-form-item label="新密码">
        <el-input v-model="newPassword" type="password" show-password placeholder="至少 6 位" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="resetVisible = false">取消</el-button>
      <el-button type="primary" @click="onReset">确认重置</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { systemApi } from '@/api/system'
import type { PlmWorkshop, SysRole, SysUser } from '@/types'
import FormDialog, { type FormField } from '@/components/common/FormDialog.vue'
import EmptyStatePanel from '@/components/common/EmptyStatePanel.vue'
import StaleDataNotice from '@/components/common/StaleDataNotice.vue'
import { useAuthStore } from '@/stores/auth'
import { STATUS_LABELS } from '@/utils/capacity'
import { plmApi } from '@/api/plm'

const auth = useAuthStore()
const canManage = computed(() => auth.can('system.user.manage'))

const loading = ref(true)
const rows = ref<SysUser[]>([])
const roles = ref<SysRole[]>([])
const workshops = ref<PlmWorkshop[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const keyword = ref('')
const filterRole = ref('')
const loadError = ref(false)
const hasFilters = computed(() => Boolean(keyword.value.trim() || filterRole.value))
const emptyKind = computed(() => loading.value ? 'loading' : loadError.value ? 'error' : hasFilters.value ? 'filtered' : 'empty')
const emptyTitle = computed(() => loading.value ? '正在读取用户档案' : loadError.value ? '用户列表加载失败' : hasFilters.value ? '没有匹配的用户' : '还没有用户档案')
const emptyDescription = computed(() => loading.value ? '正在读取用户与权限信息。' : loadError.value
  ? '请检查服务连接后重试；接口异常不会被当作“没有用户”。'
  : hasFilters.value ? '调整工号、姓名或角色条件，也可以清空筛选。' : '新增用户后，可配置岗位、角色、车间与移动端绑定信息。')
const emptyActionLabel = computed(() => loading.value ? '' : loadError.value ? '重新加载' : hasFilters.value ? '清空筛选' : '')

const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const formModel = ref<Record<string, unknown>>({})

const resetVisible = ref(false)
const resetUserId = ref<number | null>(null)
const newPassword = ref('')

function roleLabel(name: string): string {
  return STATUS_LABELS[name] ?? name
}

const userFields = computed<FormField[]>(() => [
  { prop: 'employee_no', label: '工号', type: 'input', required: true, placeholder: '与企微绑定时一致' },
  { prop: 'name', label: '姓名', type: 'input', required: true },
  { prop: 'phone', label: '手机号', type: 'input' },
  { prop: 'password', label: '初始密码', type: 'input', placeholder: '至少 6 位，可空=仅免密', show: () => !editingId.value },
  {
    prop: 'job_type', label: '岗位类型', type: 'select', required: true,
    options: ['admin', 'engineer', 'planner', 'leader', 'statistician', 'worker', 'sales', 'boss'].map((v) => ({ label: roleLabel(v), value: v })),
  },
  {
    prop: 'roles', label: '角色', type: 'select', multiple: true,
    options: roles.value.map((r) => ({ label: roleLabel(r.name), value: r.name })),
  },
  {
    prop: 'workshop_id', label: '所属车间', type: 'select', clearable: true,
    options: workshops.value.map((w) => ({ label: w.name, value: w.id })),
  },
  { prop: 'status', label: '启用', type: 'switch' },
  { prop: 'wecom_userid', label: '企微 userid', type: 'input', placeholder: '绑定字段' },
  { prop: 'wx_openid', label: '微信 openid', type: 'input', placeholder: '绑定字段' },
])

async function load(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    const result = await systemApi.listUsers({
      page: page.value,
      per_page: perPage.value,
      keyword: keyword.value || undefined,
    })
    if (Array.isArray(result)) {
      rows.value = result
      total.value = result.length
    } else {
      rows.value = result.data
      total.value = result.total
    }
    // 角色过滤在前端做（简单场景）
    if (filterRole.value) {
      rows.value = rows.value.filter((u) => u.roles.includes(filterRole.value))
      total.value = rows.value.length
    }
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
    filterRole.value = ''
    page.value = 1
    void load()
  }
}

async function loadMeta(): Promise<void> {
  roles.value = await systemApi.listRoles()
  const wsResult = await plmApi.listWorkshops({ all: true })
  workshops.value = Array.isArray(wsResult) ? wsResult : wsResult.data
}

function openCreate(): void {
  editingId.value = null
  formModel.value = {
    employee_no: '', name: '', phone: '', password: '123456',
    job_type: 'worker', roles: [], workshop_id: undefined, status: 1,
    wecom_userid: '', wx_openid: '',
  }
  dialogVisible.value = true
}

function openEdit(row: SysUser): void {
  editingId.value = row.id
  formModel.value = {
    ...row,
    password: '',
    workshop_id: row.workshop_id ?? undefined,
    roles: [...row.roles],
    wecom_userid: row.wecom_userid ?? '',
    wx_openid: row.wx_openid ?? '',
  }
  dialogVisible.value = true
}

async function onSubmit(model: Record<string, unknown>): Promise<void> {
  const payload = { ...model }
  if (!payload.password) delete payload.password
  try {
    if (editingId.value) {
      await systemApi.updateUser(editingId.value, payload as Partial<SysUser>)
      ElMessage.success('用户更新成功')
    } else {
      await systemApi.createUser(payload as Partial<SysUser> & { password?: string })
      ElMessage.success('用户创建成功')
    }
    dialogVisible.value = false
    await load()
  } catch {
    // 统一提示
  }
}

function openReset(row: SysUser): void {
  resetUserId.value = row.id
  newPassword.value = ''
  resetVisible.value = true
}

async function onReset(): Promise<void> {
  if (newPassword.value.length < 6) {
    ElMessage.warning('密码至少 6 位')
    return
  }
  await systemApi.resetPassword(resetUserId.value!, newPassword.value)
  ElMessage.success('密码重置成功')
  resetVisible.value = false
}

async function onDelete(row: SysUser): Promise<void> {
  await ElMessageBox.confirm(`确定删除用户「${row.name}（${row.employee_no}）」吗？`, '删除确认', { type: 'warning' })
  await systemApi.deleteUser(row.id)
  ElMessage.success('用户已删除')
  await load()
}

onMounted(async () => {
  await loadMeta()
  await load()
})
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

.none {
  font-size: 13px;
  color: #9ca3af;
}
</style>
