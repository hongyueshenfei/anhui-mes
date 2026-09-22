<template>
  <div class="heatmap-page">
    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header">
          <span>产能负荷热力（{{ data?.start ?? '-' }} ~ {{ data?.end ?? '-' }}）</span>
          <div class="legend">
            <span class="legend-item"><i class="cell-demo" style="background:#e8f5e9"></i> &le;30%</span>
            <span class="legend-item"><i class="cell-demo" style="background:#c8e6c9"></i> 31-50%</span>
            <span class="legend-item"><i class="cell-demo" style="background:#ffecb3"></i> 51-70%</span>
            <span class="legend-item"><i class="cell-demo" style="background:#ffb74d"></i> 71-85%</span>
            <span class="legend-item"><i class="cell-demo" style="background:#e57373"></i> &gt;85% 预警</span>
            <el-tag v-if="data" size="small" type="warning" effect="plain">高负荷机台 {{ data.summary.alert_count }} 台 · 平均负荷 {{ data.summary.avg_load }}% · 峰值 {{ data.summary.peak_load }}%</el-tag>
          </div>
        </div>
      </template>
      <div class="table-wrap">
        <table class="heat-table">
          <thead>
            <tr>
              <th class="col-machine">机台</th>
              <th v-for="d in data?.days ?? []" :key="d" class="col-day">
                <div class="day-text">{{ dayLabel(d) }}</div>
                <div class="day-week">{{ weekLabel(d) }}</div>
              </th>
              <th class="col-avg">平均</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in data?.rows ?? []" :key="row.machine_id">
              <td class="col-machine">
                <div class="mc-code">{{ row.machine_code }}</div>
                <div class="mc-name">{{ row.machine_name }}</div>
              </td>
              <td v-for="cell in row.cells" :key="cell.date" class="col-day">
                <div class="heat-cell" :class="{ alert: cell.alert, 'no-data': cell.load_pct === null }" :style="cellStyle(cell.load_pct)" :title="row.machine_code + ' ' + cell.date + ' 负荷 ' + (cell.load_pct === null ? '暂无数据' : cell.load_pct + '%')">
                  {{ cell.load_pct === null ? '—' : cell.load_pct + '%' }}
                </div>
              </td>
              <td class="col-avg">
                <span class="avg-val" :class="{ 'avg-alert': row.avg_load > 85 }">{{ row.avg_load }}%</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import dayjs from 'dayjs'
import { dashboardApi } from '@/api/dashboard'
import type { LoadHeatmapData } from '@/types/dashboard'

const data = ref<LoadHeatmapData | null>(null)

function cellStyle(load: number | null): Record<string, string> {
  if (load === null) {
    return { background: '#f1f5f9', color: '#94a3b8' }
  }
  let bg = '#e8f5e9'
  if (load > 85) bg = '#e57373'
  else if (load > 70) bg = '#ffb74d'
  else if (load > 50) bg = '#ffecb3'
  else if (load > 30) bg = '#c8e6c9'
  const color = load > 70 ? '#fff' : '#37474f'
  return { background: bg, color }
}
function dayLabel(date: string): string {
  return dayjs(date).format('M/D')
}
function weekLabel(date: string): string {
  const map = ['日', '一', '二', '三', '四', '五', '六']
  return `周${map[dayjs(date).day()]}`
}

onMounted(async () => {
  try {
    data.value = await dashboardApi.loadHeatmap()
  } catch {
    data.value = null
  }
})
</script>

<style scoped>
.heatmap-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
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
.legend {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 12px;
  color: #6b7280;
  font-weight: 400;
}
.legend-item {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.cell-demo {
  width: 12px;
  height: 12px;
  border-radius: 2px;
  display: inline-block;
}
.table-wrap {
  overflow-x: auto;
}
.heat-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.heat-table th,
.heat-table td {
  border: 1px solid #f1f5f9;
  text-align: center;
  padding: 2px;
}
.col-machine {
  width: 150px;
  text-align: left !important;
  padding: 4px 10px !important;
}
.col-day {
  width: 64px;
}
.col-avg {
  width: 70px;
}
.day-text {
  font-weight: 600;
  color: #475569;
}
.day-week {
  color: #94a3b8;
  font-size: 11px;
}
.mc-code {
  font-weight: 700;
  color: #1f2937;
}
.mc-name {
  color: #94a3b8;
  font-size: 11px;
}
.heat-cell {
  height: 38px;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  cursor: default;
}
.heat-cell.alert {
  box-shadow: inset 0 0 0 2px #d32f2f;
}
.avg-val {
  font-weight: 700;
  color: #166534;
}
.avg-alert {
  color: #b91c1c;
}
</style>
