<template>
  <el-card shadow="never" class="page-card">
    <div class="toolbar">
      <div class="hint">六个内置角色（一期 RBAC）：管理员 / 工程师 / 计划员 / 班组长 / 工人 / 业务员，均可微调权限</div>
      <el-button v-if="canManage" type="primary" @click="openCreate"><el-icon><Plus /></el-icon>新建角色</el-button>
    </div>

    <StaleDataNotice :visible="loadError && rows.length > 0" @retry="load" />

    <el-table v-loading="loading" :data="rows" stripe>
      <template #empty>
        <EmptyStatePanel
          :kind="loading ? 'loading' : loadError ? 'error' : 'empty'"
          :title="loading ? '正在读取角色权限' : loadError ? '角色列表加载失败' : '未读取到预置角色'"
          :description="loading ? '正在读取角色与权限配置。' : loadError ? '请检查服务连接后重试。' : '系统通常预置六个基础角色；若列表为空，请检查角色初始化与访问权限。'"
          :action-label="loading ? '' : loadError ? '重新加载' : ''"
          @action="load"
        />
      </template>
      <el-table-column prop="name" label="角色名" width="120" />
      <el-table-column label="角色中文" width="110">
        <template #default="{ row }">{{ roleLabel(row.name) }}</template>
      </el-table-column>
      <el-table-column label="权限数" width="90">
        <template #default="{ row }">{{ row.permissions.length }}</template>
      </el-table-column>
      <el-table-column label="权限明细" min-width="300">
        <template #default="{ row }">
          <el-tag v-for="p in row.permissions.slice(0, 4)" :key="p" size="small" style="margin: 2px 4px 2px 0" type="info">
            {{ p }}
          </el-tag>
          <el-tag v-if="row.permissions.length > 4" size="small" type="info" effect="plain">+{{ row.permissions.length - 4 }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column v-if="canManage" label="操作" width="150" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑权限</el-button>
          <el-button v-if="!builtinRoles.includes(row.name)" link type="danger" @click="onDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <!-- 角色编辑 -->
  <el-dialog v-model="dialogVisible" :title="editingRole ? `编辑角色 ${editingRole.name}` : '新建角色'" width="560px">
    <el-form label-width="90px">
      <el-form-item label="角色名" required>
        <el-input v-model="roleName" placeholder="英文标识，如 planner" :disabled="!!editingRole" />
      </el-form-item>
      <el-form-item label="权限">
        <el-checkbox-group v-model="checkedPermissions" class="perm-group">
          <el-checkbox v-for="p in allPermissions" :key="p" :value="p" border style="margin: 4px">
            {{ p }}
          </el-checkbox>
        </el-checkbox-group>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" @click="onSave">保存</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { systemApi } from '@/api/system'
import EmptyStatePanel from '@/components/common/EmptyStatePanel.vue'
import StaleDataNotice from '@/components/common/StaleDataNotice.vue'
import type { SysRole } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { STATUS_LABELS } from '@/utils/capacity'

const auth = useAuthStore()
const canManage = computed(() => auth.can('system.role.manage'))

const loading = ref(true)
const loadError = ref(false)
const rows = ref<SysRole[]>([])
const dialogVisible = ref(false)
const editingRole = ref<SysRole | null>(null)
const roleName = ref('')
const checkedPermissions = ref<string[]>([])

/** 一期全部权限清单（与后端 RoleSeeder 对齐） */
const allPermissions = ref([
  'system.user.manage', 'system.role.manage', 'system.config.manage', 'audit.view',
  'plm.machine.manage', 'plm.machine.view',
  'plm.product.manage', 'plm.product.view',
  'plm.bom.manage', 'plm.bom.view', 'plm.bom.publish',
  'plm.route.manage', 'plm.route.view',
  'plm.mold.manage', 'plm.mold.view',
])

const builtinRoles = ['admin', 'engineer', 'planner', 'leader', 'worker', 'sales']

function roleLabel(name: string): string {
  return STATUS_LABELS[name] ?? name
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = false
  loadError.value = false
  try {
    rows.value = await systemApi.listRoles()
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editingRole.value = null
  roleName.value = ''
  checkedPermissions.value = []
  dialogVisible.value = true
}

function openEdit(row: SysRole): void {
  editingRole.value = row
  roleName.value = row.name
  checkedPermissions.value = [...row.permissions]
  dialogVisible.value = true
}

async function onSave(): Promise<void> {
  if (!roleName.value) {
    ElMessage.warning('请输入角色名')
    return
  }
  try {
    if (editingRole.value) {
      await systemApi.updateRole(editingRole.value.id, { permissions: checkedPermissions.value })
      ElMessage.success('角色权限已更新')
    } else {
      await systemApi.createRole({ name: roleName.value, permissions: checkedPermissions.value })
      ElMessage.success('角色创建成功')
    }
    dialogVisible.value = false
    await load()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function onDelete(row: SysRole): Promise<void> {
  await ElMessageBox.confirm(`确定删除角色「${row.name}」吗？`, '删除确认', { type: 'warning' })
  await systemApi.deleteRole(row.id)
  ElMessage.success('角色已删除')
  await load()
}

onMounted(load)
</script>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.hint {
  font-size: 13px;
  color: #6b7280;
}

.perm-group {
  max-height: 320px;
  overflow-y: auto;
  padding: 4px;
}
</style>
