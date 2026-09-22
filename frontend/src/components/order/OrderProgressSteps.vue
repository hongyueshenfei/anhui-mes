<template>
  <div v-if="stages.length" class="order-progress-steps" :class="{ 'is-compact': compact }">
    <!-- 完整模式（详情页）：标题 + 总进度 -->
    <div v-if="!compact" class="ops-head">
      <span class="ops-icon">
        <el-icon><Operation /></el-icon>
      </span>
      <span class="ops-title">工单进度条</span>
      <span class="ops-overall">总进度 <b>{{ overallPercent }}%</b></span>
    </div>

    <!-- 节点横排（强制一行；溢出滚动）；连接线由节点之间的 .ops-connector 渲染 -->
    <div class="ops-grid">
      <template v-for="(s, i) in stages" :key="s.op_type">
        <div class="ops-node" :class="nodeClass(s)">
          <div class="ops-dot">
            <el-icon v-if="s.percent === 100" :size="compact ? 9 : 12"><Check /></el-icon>
            <span v-else-if="s.percent > 0" class="ops-dot-fill"></span>
          </div>
          <div class="ops-info">
            <span class="ops-percent" :class="{ 'is-muted': s.percent === 0 }">{{ s.percent }}%</span>
            <span class="ops-label" :class="{ 'is-muted': s.percent === 0 }" :title="s.op_label">{{ s.op_label }}</span>
            <span v-if="!compact" class="ops-qty">{{ s.done_qty }}/{{ s.plan_qty }}</span>
          </div>
        </div>
        <!-- 节点间连接线 -->
        <div
          v-if="i < stages.length - 1"
          class="ops-connector"
          :class="{ 'is-done': s.percent === 100, 'is-active': s.percent > 0 && s.percent < 100 }"
        ></div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Check, Operation } from '@element-plus/icons-vue'
import type { ProgressStage } from '@/types/schedule'

const props = withDefaults(
  defineProps<{
    stages: ProgressStage[]
    /** 总进度（后端直接返回；未传时按 stage 数量平均估算，仅兜底） */
    percent?: number
    /**
     * 紧凑模式（工单中心列表单元格用）：
     * - 无标题区、无数量列、字号缩、节点紧凑
     * - 仍保留节点圆形 + 工序名 + 百分比 + 连接线
     */
    compact?: boolean
  }>(),
  { compact: false },
)

/** 总进度百分比（兜底：未传时按 stage 简单平均估算） */
const overallPercent = computed(() => {
  if (typeof props.percent === 'number') return Math.round(props.percent)
  if (!props.stages.length) return 0
  const total = props.stages.reduce((s, x) => s + x.percent, 0)
  return Math.round(total / props.stages.length)
})

/** 节点视觉类：done / active / idle（统一三态区分） */
function nodeClass(s: ProgressStage): string {
  if (s.percent === 100) return 'is-done'
  if (s.percent > 0) return 'is-active'
  return 'is-idle'
}
</script>

<style scoped>
.order-progress-steps {
  margin: 4px 0 14px;
  padding: 12px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
  /* 关键：父级若是 flex item（如 el-table cell），默认 min-width:auto 会阻止收缩并触发换行；显式置 0 */
  min-width: 0;
  max-width: 100%;
  /* 任何情况下都不允许内部节点被父级高度限制触发换行 */
  box-sizing: border-box;
}
/* 紧凑模式：去掉外边距/边框/标题；用于列表单元格 */
.order-progress-steps.is-compact {
  margin: 0;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
}

.ops-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  font-size: 13px;
  color: #334155;
}
.ops-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 4px;
  background: #e0f2fe;
  color: #0284c7;
}
.ops-title {
  font-weight: 600;
}
.ops-overall {
  margin-left: auto;
  font-size: 12px;
  color: #64748b;
}
.ops-overall b {
  color: #16a34a;
  font-size: 14px;
  margin-left: 2px;
}

/* 节点横排（强制一行；overflow 滚动）；节点宽度由内容决定（不再均分） */
/* 关键：显式声明 flex-direction + flex-wrap + flex-flow 三重钉死，
   防止父级 (el-table cell 的 .cell 是 flex 容器) 把子容器识别为 flex item
   并在 height 受限时触发降级 wrap（典型表现：4 节点变 2x2） */
.ops-grid {
  display: flex;
  flex-direction: row;
  flex-wrap: nowrap;
  flex-flow: row nowrap;
  align-items: center;
  column-gap: 6px;
  overflow-x: auto;
  overflow-y: hidden;
  scrollbar-width: thin;
  min-width: 0;
  width: 100%;
  /* 不允许被父级 flex 容器压缩到换行 */
  flex-shrink: 0;
  white-space: nowrap;
}
/* 紧凑模式：列间距缩到 4px，让 4 节点能塞进 ~360px 列表 cell */
.is-compact.ops-grid {
  column-gap: 4px;
}
.ops-node {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto; /* 节点按内容收缩，不再均分父容器 */
  min-width: 0;
  white-space: nowrap;
}
.ops-dot {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 2px solid #cbd5e1;
  background: #ffffff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  flex-shrink: 0;
  transition: all 0.18s;
}
/* 紧凑模式：圆点缩到 12px，更紧凑（确保 4 节点能塞进 ~360px 列表 cell） */
.is-compact .ops-dot {
  width: 12px;
  height: 12px;
  border-width: 1.5px;
}
.ops-dot-fill {
  display: block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #f59e0b;
}
.is-compact .ops-dot-fill {
  width: 6px;
  height: 6px;
}
.ops-node.is-done .ops-dot {
  background: #16a34a;
  border-color: #16a34a;
}
.ops-node.is-active .ops-dot {
  border-color: #f59e0b;
}
.ops-info {
  display: flex;
  flex-direction: column;
  line-height: 1.3;
  min-width: 0;
  white-space: nowrap;
}
.ops-percent {
  font-size: 13px;
  font-weight: 600;
  color: #16a34a;
}
.ops-percent.is-muted {
  color: #94a3b8;
  font-weight: 500;
}
/* 紧凑模式：节点 gap 缩到 6px，更紧凑 */
.is-compact .ops-node {
  gap: 6px;
}
.is-compact .ops-percent {
  font-size: 11px;
}
.ops-label {
  font-size: 12px;
  color: #334155;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 80px;
}
.ops-label.is-muted {
  color: #94a3b8;
}
.is-compact .ops-label {
  font-size: 11px;
  max-width: 48px; /* 紧凑模式 48px，可容纳"注塑/成型"等 4 字符工序名不截断 */
}
.ops-qty {
  font-size: 11px;
  color: #94a3b8;
}

/* 节点间连接线：虚线默认灰色，已完成段实色高亮，进行中段橙色 */
/* flex: 1 1 auto 让连接线自动填满节点之间的剩余空间，工序多时整体均匀分布 */
.ops-connector {
  flex: 1 1 auto;
  min-width: 16px;
  max-width: 60px;
  height: 0;
  border-top: 2px dashed #cbd5e1;
  align-self: center;
  margin: 0 4px;
  /* 与节点同水平线对齐（dot 中心） */
  position: relative;
  top: -1px;
}
.is-compact .ops-connector {
  min-width: 6px;
  max-width: 16px;
  border-top-width: 1.5px;
  margin: 0 2px;
}
.ops-connector.is-done {
  border-top-style: solid;
  border-top-color: #16a34a;
}
.ops-connector.is-active {
  border-top-style: solid;
  border-top-color: #f59e0b;
  opacity: 0.7;
}
</style>