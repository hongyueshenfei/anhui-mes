<template>
  <div class="red-light-page">
    <!-- 来源统计 -->
    <el-row :gutter="12" class="src-row">
      <el-col :span="6" v-for="s in sourceCards" :key="s.source">
        <el-card shadow="never" class="src-card" :class="`src-${s.source}`" @click="filterSource = s.source === filterSource ? '' : s.source">
          <div class="src-count">{{ s.count }}</div>
          <div class="src-label">{{ s.label }}</div>
          <div class="src-desc">{{ s.desc }}</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 工具栏 -->
    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header">
          <span>红灯明细（按责任人聚合 · 关闭留审计）</span>
          <div>
            <el-radio-group v-model="filterSource" size="small" @change="load">
              <el-radio-button value="">全部</el-radio-button>
              <el-radio-button value="unscheduled">未排产</el-radio-button>
              <el-radio-button value="no_report">未报工</el-radio-button>
              <el-radio-button value="overdue">逾期</el-radio-button>
              <el-radio-button value="behind">落后</el-radio-button>
            </el-radio-group>
            <el-button size="small" type="warning" plain class="ml12" :loading="refreshing" @click="refresh">
              <el-icon><Refresh /></el-icon>重算红灯
            </el-button>
          </div>
        </div>
      </template>

      <!-- 责任人聚合 -->
      <div class="group-wrap">
        <el-card v-for="g in groups" :key="g.owner_id ?? 'none'" shadow="never" class="owner-group" :class="{ 'has-red': g.total > 0 }">
          <div class="owner-head">
            <el-avatar :size="26" class="owner-avatar">{{ (g.owner_name ?? '?').charAt(0) }}</el-avatar>
            <div class="owner-info">
              <div class="owner-name">{{ g.owner_name }}<span class="owner-workshop">{{ g.owner_workshop ?? '' }}</span></div>
            </div>
            <div class="owner-total">{{ g.total }} 项</div>
          </div>
          <div class="owner-src">
            <el-tag v-for="b in g.by_source" :key="b.source" :type="srcType(b.source)" size="small" class="ml4">{{ b.source_label }}×{{ b.count }}</el-tag>
          </div>
        </el-card>
      </div>

      <!-- 红灯表格 -->
      <el-table :data="items" size="small" stripe class="rl-table">
        <el-table-column label="来源" width="90">
          <template #default="{ row }">
            <el-tag :type="srcType(row.source)" size="small" effect="dark">{{ row.source_label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="task_no" label="任务号" width="150" />
        <el-table-column prop="product_code" label="产品编码" width="100" />
        <el-table-column prop="product_name" label="产品名称" min-width="120" />
        <el-table-column label="进度" width="140">
          <template #default="{ row }">
            <el-progress :percentage="row.progress_pct" :stroke-width="8" :color="row.progress_pct > 60 ? '#67c23a' : '#e6a23c'" />
          </template>
        </el-table-column>
        <el-table-column prop="reason" label="红灯原因" min-width="220" show-overflow-tooltip />
        <el-table-column prop="owner_name" label="责任人" width="90" />
        <el-table-column label="状态" width="130">
          <template #default="{ row }">
            <el-tag v-if="row.status === 'open'" type="danger" size="small">未处理</el-tag>
            <el-tag v-else type="success" size="small">
              已关闭：{{ row.closed_by }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.status === 'open'" size="small" type="primary" plain @click="openClose(row)">关闭（留审计）</el-button>
            <el-tooltip v-else :content="`原因：${row.close_reason ?? '-'} · ${row.closed_at ?? ''}`" placement="top">
              <el-button size="small" text type="info">查看审计</el-button>
            </el-tooltip>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 关闭弹窗 -->
    <el-dialog v-model="closeVisible" title="关闭红灯（留审计）" width="480px">
      <el-alert type="warning" :closable="false" class="mb12">
        关闭后记录写入操作审计（audit_logs：module=dashboard, action=red_light_close），后续红灯重算不会重复产生。
      </el-alert>
      <el-form label-width="80px">
        <el-form-item label="任务">
          <span>{{ current?.task_no }} · {{ current?.product_name }}</span>
        </el-form-item>
        <el-form-item label="处理结果">
          <el-input v-model="closeReason" type="textarea" :rows="3" placeholder="如：已完成排产确认 / 已补报工 / 已与客户确认顺延" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="closeVisible = false">取消</el-button>
        <el-button type="primary" :disabled="!closeReason.trim()" :loading="closing" @click="confirmClose">确认关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import { dashboardApi } from '@/api/dashboard'
import { useAuthStore } from '@/stores/auth'
import type { RedLight, RedLightGroup, RedLightListData } from '@/types/dashboard'

const auth = useAuthStore()
const data = ref<RedLightListData | null>(null)
const filterSource = ref('')
const refreshing = ref(false)

const items = computed(() => data.value?.items ?? [])
const groups = computed<RedLightGroup[]>(() => data.value?.groups ?? [])
const sourceCards = computed(() => {
  const by = (data.value?.by_source ?? [])
  const countOf = (s: string): number => by.find((b) => b.source === s)?.count ?? 0
  return [
    { source: 'unscheduled', label: '未排产', count: countOf('unscheduled'), desc: '任务未排入机台', tone: 'warning' },
    { source: 'no_report', label: '未报工', count: countOf('no_report'), desc: '计划开始 3 小时无报工', tone: 'info' },
    { source: 'overdue', label: '逾期', count: countOf('overdue'), desc: '交期/计划结束已过', tone: 'danger' },
    { source: 'behind', label: '落后 >10%', count: countOf('behind'), desc: '实际进度落后计划 10%+', tone: 'primary' },
  ]
})

const closeVisible = ref(false)
const closing = ref(false)
const closeReason = ref('')
const current = ref<RedLight | null>(null)

function srcType(source: string): 'danger' | 'warning' | 'info' | 'primary' {
  return source === 'overdue' ? 'danger' : source === 'unscheduled' ? 'warning' : source === 'no_report' ? 'info' : 'primary'
}

async function load(): Promise<void> {
  try {
    data.value = await dashboardApi.redLights({ source: filterSource.value || undefined })
  } catch {
    data.value = null
  }
}

async function refresh(): Promise<void> {
  refreshing.value = true
  try {
    const r = await dashboardApi.refreshRedLights()
    ElMessage.success(`红灯重算完成：当前未处理 ${r.open_count} 项`)
    await load()
  } catch {
    ElMessage.error('重算失败')
  } finally {
    refreshing.value = false
  }
}

function openClose(row: RedLight): void {
  current.value = row
  closeReason.value = ''
  closeVisible.value = true
}

async function confirmClose(): Promise<void> {
  if (!current.value || !closeReason.value.trim()) return
  closing.value = true
  try {
    const by = auth.user?.name ?? '系统管理员'
    await dashboardApi.closeRedLight(current.value.id, { reason: closeReason.value.trim(), closed_by: by })
    ElMessage.success('红灯已关闭，审计已留痕')
    closeVisible.value = false
    await load()
  } catch {
    ElMessage.error('关闭失败')
  } finally {
    closing.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.red-light-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.src-row {
  margin-bottom: 0;
}
.src-card {
  border-radius: 10px;
  text-align: center;
  cursor: pointer;
  transition: transform 0.15s;
}
.src-card:hover {
  transform: translateY(-2px);
}
.src-unscheduled {
  border-top: 3px solid #e6a23c;
}
.src-no_report {
  border-top: 3px solid #909399;
}
.src-overdue {
  border-top: 3px solid #f56c6c;
}
.src-behind {
  border-top: 3px solid #1677ff;
}
.src-count {
  font-size: 26px;
  font-weight: 700;
  color: #b91c1c;
}
.src-label {
  font-size: 13px;
  color: #1f2937;
  font-weight: 600;
}
.src-desc {
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
.ml4 {
  margin-left: 4px;
}
.group-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}
.owner-group {
  flex: 1 1 220px;
  max-width: 320px;
  border-radius: 10px;
  border-left: 4px solid #e5e7eb;
}
.owner-group.has-red {
  border-left-color: #f56c6c;
}
.owner-head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.owner-avatar {
  background: #1e293b;
  color: #fff;
  font-size: 13px;
}
.owner-info {
  flex: 1;
}
.owner-name {
  font-weight: 600;
  color: #1f2937;
  font-size: 14px;
}
.owner-workshop {
  margin-left: 6px;
  font-size: 12px;
  color: #94a3b8;
  font-weight: 400;
}
.owner-total {
  font-size: 16px;
  font-weight: 700;
  color: #b91c1c;
}
.owner-src {
  margin-top: 6px;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.rl-table {
  width: 100%;
}
.mb12 {
  margin-bottom: 12px;
}
</style>