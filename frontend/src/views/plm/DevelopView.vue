<template>
  <div class="develop-page">
    <!-- 统计条 -->
    <el-row :gutter="12" class="stat-row">
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-value">{{ total }}</div>
          <div class="stat-label">开发中项目</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-value">{{ doingCount }}</div>
          <div class="stat-label">进行中阶段</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="stat-card overdue">
          <div class="stat-value">{{ overdueCount }}</div>
          <div class="stat-label">逾期项目</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-value">{{ massCount }}</div>
          <div class="stat-label">已量产</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header">
          <span>新品开发流程 · 阶段看板（询价 → 立项 → 设计 → 开模 → 试模 → 小批 → 量产）</span>
          <el-button size="small" type="primary" plain @click="reportVisible = true">
            <el-icon><Plus /></el-icon>新建开发
          </el-button>
        </div>
      </template>

      <!-- Kanban 列 -->
      <div class="kanban">
        <div v-for="stage in stageDefs" :key="stage.key" class="kb-col" :style="{ borderTopColor: stage.color }">
          <div class="kb-head">
            <span class="kb-dot" :style="{ background: stage.color }"></span>
            <span class="kb-title">{{ stage.label }}</span>
            <span class="kb-count">{{ byStage[stage.key]?.length ?? 0 }}</span>
          </div>
          <div class="kb-body">
            <div v-for="card in byStage[stage.key] ?? []" :key="card.id" class="kb-card" :class="{ overdue: card.overdue }">
              <div class="kb-card-top">
                <span class="kb-dev">{{ card.dev_no }}</span>
                <el-tag v-if="card.overdue" type="danger" size="small">逾期</el-tag>
                <el-tag v-else :type="prioTag(card.priority)" :effect="card.priority === 0 ? 'dark' : 'light'" size="small">P{{ card.priority }}</el-tag>
              </div>
              <div class="kb-name">{{ card.product_name }}</div>
              <div class="kb-spec">{{ card.product_code }} · {{ card.spec }}</div>
              <div class="kb-meta">
                <span>客户：{{ card.customer_name }}</span>
                <span>负责人：{{ card.owner_name }}</span>
                <span>计划：{{ card.plan_finish }}</span>
              </div>
              <div class="kb-remark" v-if="card.remark">{{ card.remark }}</div>
              <div class="kb-actions">
                <el-button v-if="!isLast(stage.key)" size="small" type="primary" text @click="advance(card)">推进下一阶段 →</el-button>
                <el-button v-else size="small" type="success" text disabled>已量产</el-button>
              </div>
            </div>
            <el-empty v-if="(byStage[stage.key]?.length ?? 0) === 0" description="暂无" :image-size="48" />
          </div>
        </div>
      </div>
    </el-card>

    <!-- 新建开发弹窗 -->
    <el-dialog v-model="reportVisible" title="新建开发任务" width="520px">
      <el-form label-width="90px">
        <el-form-item label="产品名称" required>
          <el-input v-model="form.product_name" placeholder="如：折叠收纳箱 18L" />
        </el-form-item>
        <el-form-item label="产品编码">
          <el-input v-model="form.product_code" placeholder="如 HY-20XX（可选）" />
        </el-form-item>
        <el-form-item label="客户">
          <el-input v-model="form.customer_name" placeholder="客户名称" />
        </el-form-item>
        <el-form-item label="规格">
          <el-input v-model="form.spec" placeholder="材质/容量/工艺要点" />
        </el-form-item>
        <el-form-item label="起始阶段">
          <el-select v-model="form.stage" style="width: 100%">
            <el-option v-for="s in stageDefs" :key="s.key" :label="s.label" :value="s.key" />
          </el-select>
        </el-form-item>
        <el-form-item label="优先级">
          <el-input-number v-model="form.priority" :min="0" :max="5" controls-position="right" />
          <span class="form-tip">P0 最紧急</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="reportVisible = false">取消</el-button>
        <el-button type="primary" :disabled="!form.product_name.trim()" :loading="creating" @click="confirmCreate">创建</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { plmApiV4 } from '@/api/plm'
import { developStages, type DevelopStageKey, type DevelopTask } from '@/types/develop'

const items = ref<DevelopTask[]>([])
const stageDefs = developStages

const byStage = computed<Record<string, DevelopTask[]>>(() => {
  const map: Record<string, DevelopTask[]> = {}
  for (const s of stageDefs) map[s.key] = []
  for (const it of items.value) {
    if (map[it.stage]) map[it.stage].push(it)
  }
  return map
})

const total = computed(() => items.value.length)
const doingCount = computed(() => items.value.filter((d) => d.stage_status === 'doing').length)
const overdueCount = computed(() => items.value.filter((d) => d.overdue).length)
const massCount = computed(() => items.value.filter((d) => d.stage === 'mass').length)

const reportVisible = ref(false)
const creating = ref(false)
const form = reactive({ product_name: '', product_code: '', customer_name: '', spec: '', stage: 'inquiry' as DevelopStageKey, priority: 3 })

function isLast(stage: DevelopStageKey): boolean {
  return stage === 'mass'
}

function prioTag(p: number): 'danger' | 'warning' | 'primary' | 'info' {
  return p === 0 ? 'danger' : p === 1 ? 'warning' : p === 2 ? 'primary' : 'info'
}

async function load(): Promise<void> {
  try {
    const data = await plmApiV4.develop()
    items.value = data.items
  } catch {
    items.value = []
  }
}

async function advance(card: DevelopTask): Promise<void> {
  try {
    await ElMessageBox.confirm(`确认将「${card.product_name}」推进到下一阶段？`, '推进阶段', { type: 'info' })
  } catch {
    return
  }
  try {
    await plmApiV4.advanceDevelop(card.id)
    ElMessage.success('阶段已推进，审计已留痕')
    await load()
  } catch {
    ElMessage.error('推进失败')
  }
}

async function confirmCreate(): Promise<void> {
  creating.value = true
  try {
    await plmApiV4.createDevelop({
      product_name: form.product_name.trim(),
      product_code: form.product_code.trim() || undefined,
      customer_name: form.customer_name.trim() || '待定客户',
      spec: form.spec.trim(),
      stage: form.stage,
      priority: form.priority,
    })
    ElMessage.success('开发任务已创建')
    reportVisible.value = false
    await load()
  } catch {
    ElMessage.error('创建失败')
  } finally {
    creating.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.form-tip {
  margin-left: 8px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.develop-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.stat-row {
  margin-bottom: 0;
}
.stat-card {
  border-radius: 10px;
  text-align: center;
}
.stat-card.overdue {
  border-top: 3px solid #f56c6c;
}
.stat-value {
  font-size: 26px;
  font-weight: 700;
  color: #1f2937;
}
.stat-label {
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
.kanban {
  display: flex;
  gap: 10px;
  overflow-x: auto;
  padding-bottom: 6px;
}
.kb-col {
  flex: 1 1 150px;
  min-width: 150px;
  max-width: 190px;
  background: #f7f8fa;
  border-radius: 10px;
  border-top: 3px solid #e5e7eb;
  padding: 8px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.kb-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  color: #1f2937;
  font-size: 13px;
}
.kb-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}
.kb-count {
  margin-left: auto;
  background: #e5e7eb;
  border-radius: 10px;
  padding: 0 8px;
  font-size: 12px;
  color: #4b5563;
}
.kb-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 80px;
}
.kb-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 8px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  transition: transform 0.12s;
}
.kb-card:hover {
  transform: translateY(-2px);
}
.kb-card.overdue {
  border-left: 3px solid #f56c6c;
}
.kb-card-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
}
.kb-dev {
  font-size: 11px;
  color: #94a3b8;
  font-family: monospace;
}
.kb-name {
  font-weight: 600;
  color: #1f2937;
  font-size: 13px;
  line-height: 1.3;
}
.kb-spec {
  font-size: 11px;
  color: #94a3b8;
  margin-top: 2px;
}
.kb-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 11px;
  color: #6b7280;
  margin-top: 6px;
}
.kb-remark {
  font-size: 11px;
  color: #b45309;
  background: #fef3c7;
  border-radius: 4px;
  padding: 3px 6px;
  margin-top: 6px;
}
.kb-actions {
  margin-top: 6px;
  text-align: right;
}
</style>
