<template>
  <div class="integration-config-page">
    <!-- 连接状态 + 操作区 -->
    <el-row :gutter="12" class="top-row">
      <el-col :span="10">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>金蝶 K3Cloud 连接</span>
              <el-button size="small" type="primary" plain :loading="testing" @click="testHealth">
                <el-icon><Connection /></el-icon>测试连接
              </el-button>
            </div>
          </template>
          <div class="health-body">
            <el-tag v-if="health" :type="health.ok ? 'success' : 'danger'" size="large" effect="dark">
              {{ health.ok ? '连接可用' : '连接异常' }}
            </el-tag>
            <el-tag v-else type="info" size="large" effect="plain">未测试</el-tag>
            <span class="health-mock" v-if="health?.mock">（Mock 模拟模式）</span>
            <div class="health-msg" v-if="health?.message">{{ health.message }}</div>
            <el-divider />
            <div class="conn-detail">
              <div>账套：<code>{{ k3Base }}</code></div>
              <div>拉单间隔：<code>10 分钟（游标增量）</code></div>
              <div>游标：<code>{{ lastCursor || '-' }}</code></div>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="14">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>手动操作（四期）</span>
              <span class="sub-tip">实时调用后端集成接口</span>
            </div>
          </template>
          <div class="action-grid">
            <div class="action-item">
              <el-button type="warning" plain :loading="pulling" @click="doPull">
                <el-icon><Download /></el-icon>手动拉取生产订单
              </el-button>
              <div class="action-result" v-if="pullResult">{{ pullResult }}</div>
            </div>
            <div class="action-item">
              <el-button type="success" plain :loading="syncing" @click="doSyncMaterials">
                <el-icon><Refresh /></el-icon>同步物料主数据
              </el-button>
              <div class="action-result" v-if="syncResult">{{ syncResult }}</div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 未匹配物料 -->
    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header">
          <span>物料对齐 · 未匹配提示清单</span>
          <el-button size="small" text type="primary" @click="loadUnmatched">刷新</el-button>
        </div>
      </template>
      <el-alert v-if="unmatchedError" type="error" :title="`${unmatchedError}${unmatched.items.length ? '；下方仍显示上次成功读取的数据。' : ''}`" :closable="false" class="mb12">
        <template #default><el-button size="small" type="danger" plain @click="loadUnmatched">重试</el-button></template>
      </el-alert>
      <el-alert v-else-if="unmatchedLoading" type="info" title="正在读取物料匹配状态…" :closable="false" class="mb12" />
      <el-alert type="warning" :closable="false" class="mb12" v-else-if="unmatched.total > 0">
        以下产品编码在金蝶物料库中未匹配到（供人工核对/补建物料）：共 {{ unmatched.total }} 条
      </el-alert>
      <el-alert type="success" :closable="false" class="mb12" v-else-if="!unmatchedLoading && !unmatchedError">
        产品编码与金蝶物料库全部对齐，无未匹配项
      </el-alert>
      <el-table :data="unmatched.items" size="small" stripe v-loading="unmatchedLoading" :empty-text="unmatchedError ? '读取失败，请重试' : '暂无未匹配物料'">
        <el-table-column prop="code" label="产品编码" width="160" />
        <el-table-column prop="name" label="产品名称" min-width="160" />
        <el-table-column prop="reason" label="提示" min-width="220" />
      </el-table>
    </el-card>

    <!-- 集成日志 -->
    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header">
          <span>集成日志（留痕/对账）</span>
          <el-radio-group v-model="logStatus" size="small" @change="loadLogs">
            <el-radio-button value="">全部</el-radio-button>
            <el-radio-button value="success">成功</el-radio-button>
            <el-radio-button value="failed">失败</el-radio-button>
            <el-radio-button value="retry">重试</el-radio-button>
          </el-radio-group>
        </div>
      </template>
      <el-alert v-if="logsError" :title="`${logsError}${logs.length ? '；下方仍显示上次成功读取的日志。' : ''}`" type="error" show-icon :closable="false" class="mb12">
        <template #default><el-button size="small" type="danger" plain @click="loadLogs">重试</el-button></template>
      </el-alert>
      <el-table :data="logs" size="small" stripe v-loading="logsLoading" :empty-text="logsError ? '读取失败，请重试' : '暂无集成日志'">
        <el-table-column prop="created_at" label="时间" width="160" />
        <el-table-column label="方向" width="70">
          <template #default="{ row }">
            <el-tag :type="row.direction === 'out' ? 'warning' : 'info'" size="small">{{ row.direction === 'out' ? '推送' : '拉取' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="api_type" label="接口类型" width="150" />
        <el-table-column prop="target" label="目标" width="100" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 'success' ? 'success' : row.status === 'failed' ? 'danger' : 'warning'" size="small">
              {{ statusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="biz_id" label="业务ID" width="80" />
        <el-table-column prop="error_msg" label="错误/说明" min-width="200" show-overflow-tooltip />
        <el-table-column label="操作" width="110" fixed="right">
          <template #default="{ row }">
            <el-button size="small" text type="primary" @click="showLog(row)">报文</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 报文弹窗 -->
    <el-dialog v-model="logVisible" title="集成日志报文" width="640px">
      <el-descriptions :column="2" border size="small" class="mb12">
        <el-descriptions-item label="接口">{{ currentLog?.api_type }}</el-descriptions-item>
        <el-descriptions-item label="状态">{{ currentLog?.status }}</el-descriptions-item>
        <el-descriptions-item label="方向">{{ currentLog?.direction }}</el-descriptions-item>
        <el-descriptions-item label="业务ID">{{ currentLog?.biz_id ?? '-' }}</el-descriptions-item>
      </el-descriptions>
      <el-tabs>
        <el-tab-pane label="请求报文">
          <pre class="log-json">{{ pretty(currentLog?.request_body) }}</pre>
        </el-tab-pane>
        <el-tab-pane label="响应报文">
          <pre class="log-json">{{ pretty(currentLog?.response_body) }}</pre>
        </el-tab-pane>
      </el-tabs>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Connection, Download, Refresh } from '@element-plus/icons-vue'
import { integrationApiV4 } from '@/api/dashboard'
import { systemApiV4 } from '@/api/system'
import type { PageResult } from '@/types'
import type { IntegrationLog } from '@/types/production'

const health = ref<{ ok: boolean; mock: boolean; message: string } | null>(null)
const testing = ref(false)
const pulling = ref(false)
const syncing = ref(false)
const pullResult = ref('')
const syncResult = ref('')
const lastCursor = ref('')
const unmatchedLoading = ref(true)
const unmatchedError = ref('')
const logsLoading = ref(true)
const logsError = ref('')

const unmatched = ref<{ total: number; items: Array<{ id: number; code: string; name: string; reason: string }> }>({ total: 0, items: [] })

const logs = ref<IntegrationLog[]>([])
const logStatus = ref('')
const logVisible = ref(false)
const currentLog = ref<IntegrationLog | null>(null)

const k3Base = computed(() => 'K3Cloud WebAPI（账套连接配置）')

function statusLabel(s: string): string {
  return { success: '成功', failed: '失败', retry: '重试中' }[s] ?? s
}

function pretty(v: unknown): string {
  if (v === null || v === undefined) return '-'
  try {
    return JSON.stringify(v, null, 2)
  } catch {
    return String(v)
  }
}

async function testHealth(): Promise<void> {
  testing.value = true
  try {
    health.value = await integrationApiV4.k3Health()
    ElMessage[health.value.ok ? 'success' : 'error'](health.value.message)
  } catch {
    health.value = { ok: false, mock: false, message: '连接测试失败（后端不可用）' }
  } finally {
    testing.value = false
  }
}

async function doPull(): Promise<void> {
  pulling.value = true
  try {
    const r = await integrationApiV4.pullProdOrders()
    pullResult.value = r.message
    lastCursor.value = String(r.cursor)
    ElMessage.success(r.message)
    await loadLogs()
  } catch {
    pullResult.value = '拉取失败（详见集成日志）'
  } finally {
    pulling.value = false
  }
}

async function doSyncMaterials(): Promise<void> {
  syncing.value = true
  try {
    const r = await integrationApiV4.syncMaterials()
    syncResult.value = r.message
    ElMessage.success(r.message)
    await Promise.all([loadUnmatched(), loadLogs()])
  } catch {
    syncResult.value = '同步失败（详见集成日志）'
  } finally {
    syncing.value = false
  }
}

async function loadUnmatched(): Promise<void> {
  unmatchedLoading.value = true
  unmatchedError.value = ''
  try {
    const r = await integrationApiV4.unmatchedMaterials()
    unmatched.value = { total: r.total, items: r.items }
  } catch (error: any) {
    unmatchedError.value = error?.response?.data?.message ?? '物料匹配状态读取失败，请稍后重试。'
  } finally {
    unmatchedLoading.value = false
  }
}

async function loadLogs(): Promise<void> {
  logsLoading.value = true
  logsError.value = ''
  try {
    const r = (await systemApiV4.listIntegrationLogs({ status: logStatus.value || undefined })) as PageResult<IntegrationLog>
    logs.value = Array.isArray(r) ? r : r.data
  } catch (error: any) {
    logsError.value = error?.response?.data?.message ?? '集成日志读取失败，请检查后端连接后重试。'
  } finally {
    logsLoading.value = false
  }
}

function showLog(row: IntegrationLog): void {
  currentLog.value = row
  logVisible.value = true
}

onMounted(() => {
  testHealth()
  loadUnmatched()
  loadLogs()
})
</script>

<style scoped>
.integration-config-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.top-row {
  margin-bottom: 0;
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
.sub-tip {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 400;
}
.health-body {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.health-mock {
  font-size: 12px;
  color: #e6a23c;
}
.health-msg {
  width: 100%;
  font-size: 13px;
  color: #6b7280;
}
.conn-detail {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 13px;
  color: #4b5563;
}
.action-grid {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.action-item {
  display: flex;
  align-items: center;
  gap: 12px;
}
.action-result {
  font-size: 13px;
  color: #1677ff;
}
.mb12 {
  margin-bottom: 12px;
}
.log-json {
  background: #0f172a;
  color: #e2e8f0;
  border-radius: 8px;
  padding: 12px;
  font-size: 12px;
  max-height: 320px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-all;
}
</style>
