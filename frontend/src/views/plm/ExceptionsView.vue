<template>
  <div class="exceptions-page">
    <!-- 类型统计卡片 -->
    <el-row :gutter="12" class="type-row">
      <el-col :span="6" v-for="c in typeCards" :key="c.type">
        <el-card shadow="never" class="type-card" :class="`tc-${c.type}`" @click="filterType = c.type === filterType ? '' : c.type">
          <div class="tc-count">{{ c.count }}</div>
          <div class="tc-label">{{ c.label }}</div>
          <div class="tc-desc">{{ c.desc }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header">
          <span>异常列表（停机 / 换模 / 次品 / 缺料 · 上报人 / 时间 / 状态 / 处理）</span>
          <div>
            <el-radio-group v-model="filterStatus" size="small" @change="load">
              <el-radio-button value="">全部</el-radio-button>
              <el-radio-button value="open">待处理</el-radio-button>
              <el-radio-button value="processing">处理中</el-radio-button>
              <el-radio-button value="closed">已关闭</el-radio-button>
            </el-radio-group>
            <el-button size="small" type="primary" plain class="ml12" @click="openReport">
              <el-icon><Plus /></el-icon>上报异常
            </el-button>
          </div>
        </div>
      </template>

      <StaleDataNotice :visible="loadError && items.length > 0" @retry="load" />

      <el-table v-loading="loading" :data="items" size="small" stripe class="ex-table">
        <template #empty>
          <EmptyStatePanel
            :kind="emptyKind"
            :title="emptyTitle"
            :description="emptyDescription"
            :action-label="emptyActionLabel"
            @action="onEmptyAction"
          />
        </template>
        <el-table-column label="类型" width="90">
          <template #default="{ row }">
            <el-tag :type="typeTag(row.type)" size="small" effect="dark">{{ row.type_label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="级别" width="70">
          <template #default="{ row }">
            <el-tag :type="row.level === 'high' ? 'danger' : row.level === 'medium' ? 'warning' : 'info'" size="small">{{ levelLabel(row.level) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="machine_code" label="机台" width="80">
          <template #default="{ row }">{{ row.machine_code ?? '-' }}</template>
        </el-table-column>
        <el-table-column prop="task_no" label="任务号" width="140">
          <template #default="{ row }">{{ row.task_no ?? '-' }}</template>
        </el-table-column>
        <el-table-column prop="product_name" label="产品" min-width="110">
          <template #default="{ row }">{{ row.product_name ?? '-' }}</template>
        </el-table-column>
        <el-table-column prop="desc" label="异常描述" min-width="220" show-overflow-tooltip />
        <el-table-column prop="report_by" label="上报人" width="90" />
        <el-table-column prop="report_time" label="上报时间" width="150" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 'closed' ? 'success' : row.status === 'processing' ? 'warning' : 'danger'" size="small">
              {{ row.status_label }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="处理" min-width="180">
          <template #default="{ row }">
            <div v-if="row.handler" class="handle-info">
              <span>{{ row.handler }}</span>
              <el-text type="info" size="small" class="handle-result">{{ row.handle_result ?? '' }}</el-text>
            </div>
            <el-text v-else type="info" size="small">未指派</el-text>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.status !== 'closed'" size="small" type="primary" plain @click="openHandle(row)">处理</el-button>
            <el-tooltip v-else :content="`处理人：${row.handler ?? '-'} · ${row.handle_time ?? ''}`" placement="top">
              <el-button size="small" text type="info">查看处理</el-button>
            </el-tooltip>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 处理弹窗 -->
    <el-dialog v-model="handleVisible" title="处理异常（留审计）" width="480px">
      <el-alert type="warning" :closable="false" class="mb12">
        处理结果写入操作审计（audit_logs：module=production, action=exception_handle）。
      </el-alert>
      <el-form label-width="80px">
        <el-form-item label="异常">
          <span>{{ current?.type_label }} · {{ current?.desc }}</span>
        </el-form-item>
        <el-form-item label="处理人">
          <el-input v-model="handleBy" placeholder="如：维修组·张工" />
        </el-form-item>
        <el-form-item label="处理结果">
          <el-input v-model="handleResult" type="textarea" :rows="3" placeholder="如：已更换密封圈，重新试机通过" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="handleVisible = false">取消</el-button>
        <el-button type="primary" :disabled="!handleBy.trim()" :loading="handling" @click="confirmHandle">确认处理</el-button>
      </template>
    </el-dialog>

    <!-- 上报弹窗 -->
    <el-dialog v-model="reportVisible" title="上报异常" width="520px">
      <el-form label-width="90px">
        <el-form-item label="异常类型" required>
          <el-select v-model="reportForm.type" style="width: 100%">
            <el-option label="停机" value="stop_machine" />
            <el-option label="换模" value="mold_change" />
            <el-option label="次品" value="defect" />
            <el-option label="缺料" value="material_short" />
          </el-select>
        </el-form-item>
        <el-form-item label="机台">
          <el-input v-model="reportForm.machine_code" placeholder="如 I-01（可选）" />
        </el-form-item>
        <el-form-item label="异常描述" required>
          <el-input v-model="reportForm.desc" type="textarea" :rows="3" placeholder="描述异常现象与影响" />
        </el-form-item>
        <el-form-item label="级别">
          <el-radio-group v-model="reportForm.level">
            <el-radio-button value="high">高</el-radio-button>
            <el-radio-button value="medium">中</el-radio-button>
            <el-radio-button value="low">低</el-radio-button>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="reportVisible = false">取消</el-button>
        <el-button type="primary" :disabled="!reportForm.desc.trim()" :loading="reporting" @click="confirmReport">提交上报</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { productionApiV4 } from '@/api/production'
import EmptyStatePanel from '@/components/common/EmptyStatePanel.vue'
import StaleDataNotice from '@/components/common/StaleDataNotice.vue'
import { useAuthStore } from '@/stores/auth'
import type { ProductionException } from '@/types/production'

const auth = useAuthStore()
const items = ref<ProductionException[]>([])
const total = ref(0)
const filterType = ref('')
const filterStatus = ref('')
const loading = ref(true)
const loadError = ref(false)
const hasFilters = computed(() => Boolean(filterType.value || filterStatus.value))
const emptyKind = computed(() => loading.value ? 'loading' : loadError.value ? 'error' : hasFilters.value ? 'filtered' : 'empty')
const emptyTitle = computed(() => loading.value ? '正在读取异常记录' : loadError.value ? '异常列表加载失败' : hasFilters.value ? '没有匹配的异常记录' : '当前没有异常记录')
const emptyDescription = computed(() => loading.value ? '正在从生产系统读取异常列表。' : loadError.value
  ? '请检查服务连接后重试；接口异常不会被当作“没有异常”。'
  : hasFilters.value ? '调整异常类型或处理状态，也可以清空筛选后查看全部。' : '出现停机、换模、次品或缺料时，可从上方“上报异常”登记。')
const emptyActionLabel = computed(() => loading.value ? '' : loadError.value ? '重新加载' : hasFilters.value ? '清空筛选' : '')

const typeCards = computed(() => {
  const countOf = (t: string): number => items.value.filter((e) => e.type === t).length
  return [
    { type: 'stop_machine', label: '停机', count: countOf('stop_machine'), desc: '机台故障/停机', tone: 'danger' },
    { type: 'mold_change', label: '换模', count: countOf('mold_change'), desc: '换模/模具异常', tone: 'warning' },
    { type: 'defect', label: '次品', count: countOf('defect'), desc: '质量异常', tone: 'primary' },
    { type: 'material_short', label: '缺料', count: countOf('material_short'), desc: '物料短缺', tone: 'info' },
  ]
})

const handleVisible = ref(false)
const handling = ref(false)
const handleBy = ref('')
const handleResult = ref('')
const current = ref<ProductionException | null>(null)

const reportVisible = ref(false)
const reporting = ref(false)
const reportForm = reactive({ type: 'stop_machine', machine_code: '', desc: '', level: 'medium' })

function typeTag(type: string): 'danger' | 'warning' | 'primary' | 'info' {
  return type === 'stop_machine' ? 'danger' : type === 'mold_change' ? 'warning' : type === 'defect' ? 'primary' : 'info'
}

function levelLabel(level: string): string {
  return level === 'high' ? '高' : level === 'medium' ? '中' : '低'
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    const page = await productionApiV4.exceptions({ status: filterStatus.value || undefined, type: filterType.value || undefined })
    items.value = page.data
    total.value = page.total
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
    filterType.value = ''
    filterStatus.value = ''
    void load()
  }
}

function openHandle(row: ProductionException): void {
  current.value = row
  handleBy.value = row.handler ?? (auth.user?.name ?? '')
  handleResult.value = ''
  handleVisible.value = true
}

async function confirmHandle(): Promise<void> {
  if (!current.value || !handleBy.value.trim()) return
  handling.value = true
  try {
    await productionApiV4.handleException(current.value.id, {
      status: 'closed',
      handler: handleBy.value.trim(),
      result: handleResult.value.trim() || '现场处理完成',
    })
    ElMessage.success('异常已处理，审计已留痕')
    handleVisible.value = false
    await load()
  } catch {
    ElMessage.error('处理失败')
  } finally {
    handling.value = false
  }
}

function openReport(): void {
  reportForm.type = 'stop_machine'
  reportForm.machine_code = ''
  reportForm.desc = ''
  reportForm.level = 'medium'
  reportVisible.value = true
}

async function confirmReport(): Promise<void> {
  reporting.value = true
  try {
    await productionApiV4.reportException({
      type: reportForm.type as ProductionException['type'],
      machine_code: reportForm.machine_code.trim() || undefined,
      desc: reportForm.desc.trim(),
      level: reportForm.level as ProductionException['level'],
      report_by: auth.user?.name ?? '系统管理员',
    })
    ElMessage.success('异常已上报')
    reportVisible.value = false
    await load()
  } catch {
    ElMessage.error('上报失败')
  } finally {
    reporting.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.exceptions-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.type-row {
  margin-bottom: 0;
}
.type-card {
  border-radius: 10px;
  text-align: center;
  cursor: pointer;
  transition: transform 0.15s;
}
.type-card:hover {
  transform: translateY(-2px);
}
.tc-stop_machine {
  border-top: 3px solid #f56c6c;
}
.tc-mold_change {
  border-top: 3px solid #e6a23c;
}
.tc-defect {
  border-top: 3px solid #1677ff;
}
.tc-material_short {
  border-top: 3px solid #909399;
}
.tc-count {
  font-size: 26px;
  font-weight: 700;
  color: #1f2937;
}
.tc-label {
  font-size: 13px;
  color: #1f2937;
  font-weight: 600;
}
.tc-desc {
  font-size: 12px;
  color: #94a3b8;
}
.panel {
  border-radius: 10px;
}
.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
}
.ml12 {
  margin-left: 12px;
}
.ex-table {
  width: 100%;
}
.handle-info {
  display: flex;
  flex-direction: column;
  line-height: 1.4;
}
.handle-result {
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mb12 {
  margin-bottom: 12px;
}
</style>
