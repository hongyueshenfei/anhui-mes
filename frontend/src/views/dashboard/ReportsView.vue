<template>
  <div class="reports-page">
    <!-- 指标卡 -->
    <el-row :gutter="12" class="metric-row">
      <el-col :span="4" v-for="m in metrics" :key="m.key">
        <el-card shadow="never" class="metric-card">
          <div class="metric-value">{{ m.value }}{{ m.unit }}</div>
          <div class="metric-label">{{ m.label }}</div>
          <div class="metric-trend" :class="`trend-${m.trend}`">
            {{ m.trend === 'up' ? '▲' : m.trend === 'down' ? '▼' : '■' }} {{ m.compare_text }}
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="12" class="charts-row">
      <!-- 交期达成率 -->
      <el-col :span="12">
        <el-card shadow="never" class="panel">
          <template #header><span class="panel-title">{{ deliverySeries?.label ?? '交期达成率趋势' }}</span></template>
          <div class="bar-chart">
            <div class="bar-y">
              <span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0%</span>
            </div>
            <div class="bar-area">
              <div v-for="(p, i) in deliverySeries?.data ?? []" :key="i" class="bar-col">
                <div class="bar-bar" :class="{ 'no-data': p.value === null }" :style="{ height: seriesValue(p.value) + '%', background: p.value === null ? '#e5e7eb' : p.value >= 85 ? '#67c23a' : p.value >= 70 ? '#e6a23c' : '#f56c6c' }" :title="p.date + ' ' + seriesLabel(p.value)">
                  <span class="bar-val">{{ seriesLabel(p.value) }}</span>
                </div>
                <div class="bar-date">{{ shortLabel(p.date) }}</div>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
      <!-- 机台利用率 -->
      <el-col :span="12">
        <el-card shadow="never" class="panel">
          <template #header><span class="panel-title">{{ utilSeries?.label ?? '机台利用率' }}</span></template>
          <div class="bar-chart">
            <div class="bar-y">
              <span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0%</span>
            </div>
            <div class="bar-area">
              <div v-for="(p, i) in utilSeries?.data ?? []" :key="i" class="bar-col">
                <div class="bar-bar" :class="{ 'no-data': p.value === null }" :style="{ height: seriesValue(p.value) + '%', background: p.value === null ? '#e5e7eb' : p.value > 85 ? '#f56c6c' : '#1677ff' }" :title="p.date + ' ' + seriesLabel(p.value)">
                  <span class="bar-val">{{ seriesLabel(p.value) }}</span>
                </div>
                <div class="bar-date">{{ shortLabel(p.date) }}</div>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="12" class="charts-row">
      <!-- 报工及时率 -->
      <el-col :span="8">
        <el-card shadow="never" class="panel small">
          <template #header><span class="panel-title">{{ timelySeries?.label ?? '报工及时率' }}</span></template>
          <div v-for="(p, i) in timelySeries?.data ?? []" :key="i" class="h-bar">
            <span class="h-label">{{ shortLabel(p.date) }}</span>
            <div class="h-track"><div class="h-fill" :style="{ width: seriesValue(p.value) + '%', background: p.value === null ? '#e5e7eb' : p.value >= 90 ? '#67c23a' : '#e6a23c' }"></div></div>
            <span class="h-val">{{ seriesLabel(p.value) }}</span>
          </div>
        </el-card>
      </el-col>
      <!-- 次品率 -->
      <el-col :span="8">
        <el-card shadow="never" class="panel small">
          <template #header><span class="panel-title">{{ defectSeries?.label ?? '次品率' }}</span></template>
          <div v-for="(p, i) in defectSeries?.data ?? []" :key="i" class="h-bar">
            <span class="h-label">{{ shortLabel(p.date) }}</span>
            <div class="h-track"><div class="h-fill" :style="{ width: Math.min(100, seriesValue(p.value) * 20) + '%', background: p.value === null ? '#e5e7eb' : p.value >= 3 ? '#f56c6c' : '#e6a23c' }"></div></div>
            <span class="h-val">{{ seriesLabel(p.value) }}</span>
          </div>
        </el-card>
      </el-col>
      <!-- 模具寿命 TOP -->
      <el-col :span="8">
        <el-card shadow="never" class="panel small">
          <template #header><span class="panel-title">模具寿命预警 TOP</span></template>
          <div v-for="m in moldLife" :key="m.mold_id" class="mold-item">
            <div class="mold-info">
              <span class="mold-code">{{ m.mold_code }}</span>
              <span class="mold-name">{{ m.mold_name }}</span>
              <el-tag v-if="m.maintain_due || m.life_used_pct > 90" type="danger" size="small">到期</el-tag>
            </div>
            <el-progress :percentage="Math.min(100, m.life_used_pct)" :stroke-width="8" :color="m.life_used_pct > 90 ? '#f56c6c' : m.life_used_pct > 70 ? '#e6a23c' : '#67c23a'" />
          </div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { dashboardApi } from '@/api/dashboard'
import type { ReportsData } from '@/types/dashboard'

const data = ref<ReportsData | null>(null)

const metrics = computed(() => data.value?.metrics ?? [])
const deliverySeries = computed(() => data.value?.delivery_series ?? null)
const utilSeries = computed(() => data.value?.utilization_series ?? null)
const timelySeries = computed(() => data.value?.report_timely_series ?? null)
const defectSeries = computed(() => data.value?.defect_series ?? null)
const moldLife = computed(() => data.value?.mold_life ?? [])

function shortLabel(date: string): string {
  if (date.includes('-')) {
    const parts = date.split('-')
    return `${Number(parts[1])}/${Number(parts[2])}`
  }
  return date
}

function seriesValue(value: number | null): number {
  return value ?? 0
}

function seriesLabel(value: number | null): string {
  return value === null ? '暂无数据' : String(value) + '%'
}

onMounted(async () => {
  try {
    data.value = await dashboardApi.reports()
  } catch {
    data.value = null
  }
})
</script>

<style scoped>
.reports-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.metric-row {
  margin-bottom: 0;
}
.metric-card {
  border-radius: 10px;
  text-align: center;
}
.metric-value {
  font-size: 24px;
  font-weight: 700;
  color: #1f2937;
}
.metric-label {
  font-size: 13px;
  color: #6b7280;
}
.metric-trend {
  font-size: 12px;
  margin-top: 4px;
}
.trend-up {
  color: #67c23a;
}
.trend-down {
  color: #f56c6c;
}
.trend-flat {
  color: #94a3b8;
}
.charts-row {
  margin-bottom: 0;
}
.panel {
  border-radius: 10px;
  margin-bottom: 0;
}
.panel.small {
  height: 100%;
}
.panel-title {
  font-weight: 600;
}
.bar-chart {
  display: flex;
  height: 240px;
}
.bar-y {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  font-size: 11px;
  color: #94a3b8;
  padding-right: 6px;
  text-align: right;
  height: calc(100% - 22px);
}
.bar-area {
  flex: 1;
  display: flex;
  align-items: flex-end;
  justify-content: space-around;
  border-bottom: 1px solid #e5e7eb;
  position: relative;
}
.bar-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 8%;
}
.bar-bar {
  width: 100%;
  max-width: 34px;
  border-radius: 4px 4px 0 0;
  position: relative;
  min-height: 4px;
  transition: height 0.3s;
}
.bar-val {
  position: absolute;
  top: -18px;
  left: 50%;
  transform: translateX(-50%);
  font-size: 10px;
  color: #64748b;
  white-space: nowrap;
}
.bar-date {
  font-size: 11px;
  color: #94a3b8;
  margin-top: 4px;
}
.h-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}
.h-label {
  width: 52px;
  font-size: 12px;
  color: #475569;
  white-space: nowrap;
}
.h-track {
  flex: 1;
  height: 12px;
  background: #f1f5f9;
  border-radius: 6px;
  overflow: hidden;
}
.h-fill {
  height: 100%;
  border-radius: 6px;
}
.h-val {
  width: 40px;
  text-align: right;
  font-size: 12px;
  font-weight: 600;
  color: #1f2937;
}
.mold-item {
  margin-bottom: 10px;
}
.mold-info {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
  font-size: 12px;
}
.mold-code {
  font-weight: 700;
  color: #1f2937;
}
.mold-name {
  color: #6b7280;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
