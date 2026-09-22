<template>
  <div class="gantt-wrap">
    <!-- 左栏：行标签（垂直滚动与右栏同步） -->
    <div class="gantt-labels">
      <div class="gantt-corner">
        <span class="corner-title">资源 / 车间机台</span>
      </div>
      <div ref="labelsBodyRef" class="gantt-labels-body">
        <div
          v-for="row in rows"
          :key="row.key"
          class="gantt-label-row"
        >
          <div class="label-main">
            <span class="label-title">{{ row.label }}</span>
            <span class="label-count">{{ row.bars.length }}</span>
          </div>
          <div class="label-sub">
            <span>{{ row.sub }}</span>
            <span class="load-badge" :class="`is-${row.loadStatus}`">负荷 {{ row.loadPct }}%</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 右栏：SVG 时间轴（吸顶时间头 + 当前时间线） -->
    <div
      ref="scrollRef"
      class="gantt-scroll"
      @scroll="onScroll"
      @pointermove="onPointerMove"
      @pointerup="onDrop"
      @dragover.prevent="onNativeDragOver"
      @drop="onNativeDrop"
      @wheel="onWheel"
    >
      <!-- 吸顶时间头 -->
      <div class="gantt-header-sticky">
        <svg :width="svgWidth" :height="HEADER_H" class="gantt-header-svg">
          <rect x="0" y="0" :width="timeWidth" :height="HEADER_H" class="gantt-header-bg" />
          <g v-for="(day, di) in dayList" :key="'hdr-' + day.getTime()">
            <line :x1="xAt(day, 0)" :y1="0" :x2="xAt(day, 0)" :y2="HEADER_H" class="gantt-day-header-line" />
            <rect
              :x="xAt(day, 0)"
              :y="2"
              :width="dayWidth"
              :height="3"
              :class="isWeekend(day) ? 'gantt-day-band gantt-day-band-weekend' : 'gantt-day-band'"
            />
            <text v-if="di % dayTextEvery === 0" :x="xAt(day, 180) + (dayWidth / 2)" y="20" class="gantt-day-text">
              {{ dayLabel(day) }}
            </text>
            <g v-if="showHourTicks" v-for="h in [0, 4, 8, 12, 16, 20]" :key="'hh-' + day.getTime() + '-' + h">
              <line
                :x1="xAt(day, h * 60)"
                :y1="26"
                :x2="xAt(day, h * 60)"
                :y2="HEADER_H"
                class="gantt-hour-tick"
              />
              <text
                v-if="h % 4 === 0"
                :x="xAt(day, h * 60) + 2"
                y="32"
                class="gantt-hour-text"
              >{{ h }}</text>
            </g>
          </g>
          <!-- 当前时刻三角标 -->
          <path v-if="nowX >= 0 && nowX <= timeWidth" :d="`M ${nowX - 5} 0 L ${nowX + 5} 0 L ${nowX} 7 Z`" class="gantt-now-flag" />
        </svg>
      </div>

      <svg :width="svgWidth" :height="bodyHeight" class="gantt-svg">
        <!-- 行底色 + 周末底纹 + 班次带 -->
        <g v-for="(row, ri) in rows" :key="'bg-' + row.key">
          <rect
            :x="0"
            :y="rowY(ri)"
            :width="timeWidth"
            :height="rowHeight"
            :class="ri % 2 === 1 ? 'gantt-row-bg gantt-row-bg-alt' : 'gantt-row-bg'"
          />
          <template v-for="day in dayList" :key="'bgd-' + row.key + '-' + day.getTime()">
            <rect
              v-if="isWeekend(day)"
              :x="xAt(day, 0)"
              :y="rowY(ri)"
              :width="dayWidth"
              :height="rowHeight"
              class="gantt-weekend-bg"
            />
            <rect
              v-for="band in bandsOf(day)"
              :key="'band-' + row.key + '-' + day.getTime() + '-' + band.key"
              :x="xAt(day, band.startMin)"
              :y="rowY(ri)"
              :width="(band.endMin - band.startMin) * pxPerMin"
              :height="rowHeight"
              :class="band.key.includes('night') ? 'gantt-band-night' : 'gantt-band-day'"
              @click="onCanvasClick(row, day, band)"
            />
          </template>
        </g>

        <!-- 网格线：每日 + 放大后 30min -->
        <g v-for="day in dayList" :key="'grid-' + day.getTime()">
          <line :x1="xAt(day, 0)" :y1="0" :x2="xAt(day, 0)" :y2="bodyHeight" class="gantt-day-line" />
          <template v-if="showHalfGrid" v-for="half in [30, 60, 90, 120, 150, 180, 210]" :key="'g2-' + half">
            <line
              :x1="xAt(day, half * 60)"
              :y1="0"
              :x2="xAt(day, half * 60)"
              :y2="bodyHeight"
              class="gantt-grid-line"
            />
          </template>
        </g>

        <!-- 当前时间线 -->
        <line v-if="nowX >= 0 && nowX <= timeWidth" :x1="nowX" :y1="0" :x2="nowX" :y2="bodyHeight" class="gantt-now-line" />

        <!-- 选中任务的工序链连接线：只为当前任务绘制，避免大数据量时整图变乱。 -->
        <g v-if="selectedTaskId !== null" class="gantt-task-links">
          <path
            v-for="link in selectedTaskLinks"
            :key="link.key"
            :d="link.path"
            class="gantt-task-link"
          >
            <title>工序 {{ link.fromSeq }} → 工序 {{ link.toSeq }}</title>
          </path>
        </g>

        <!-- 排程条 -->
        <g v-for="(row, ri) in rows" :key="'bars-' + row.key">
          <g
            v-for="bar in row.bars"
            :key="bar.id"
            :class="barGroupClass(bar)"
            @pointerdown="onBarPointerDown($event, bar)"
            @click.stop="onBarClick(bar)"
            @dblclick.stop="onBarDblClick(bar)"
          >
            <rect
              :x="xIso(bar.start)"
              :y="rowY(ri) + 4"
              :width="Math.max(barWidth(bar), 4)"
              :height="30"
              rx="3"
              :class="barClass(bar)"
            >
              <title>{{ barTip(bar) }}</title>
            </rect>
            <!-- APS计划/实际双线：计划是主条，实际用细轨道逐段绘制，段间空白保留真实停工/未报工状态。 -->
            <template v-if="bar.actualSegments.length">
              <!-- 用虚线连接同一工序的不同报工段，但不填充空档，避免误认为一直在生产。 -->
              <line
                v-for="(gap, gapIndex) in actualGaps(bar)"
                :key="`${bar.id}-actual-gap-${gapIndex}`"
                :x1="xIso(gap.start)"
                :y1="rowY(ri) + 47"
                :x2="xIso(gap.end)"
                :y2="rowY(ri) + 47"
                class="gantt-actual-gap-line"
              >
                <title>同一工序，中间未报工/非连续生产：{{ gap.start }} ~ {{ gap.end }}</title>
              </line>
              <rect
                v-for="(segment, segmentIndex) in bar.actualSegments"
                :key="`${bar.id}-actual-${segmentIndex}`"
                :x="xIso(segment.start)"
                :y="rowY(ri) + 42"
                :width="actualSegmentWidth(segment)"
                :height="10"
                rx="4"
                class="gantt-actual-rect"
              >
                <title>实际报工 {{ segment.qty }} 件：{{ segment.start }} ~ {{ segment.end }}</title>
              </rect>
            </template>
            <rect
              v-else-if="bar.actualStart"
              :x="xIso(bar.actualStart)"
              :y="rowY(ri) + 42"
              :width="actualWidth(bar)"
              :height="10"
              rx="4"
              class="gantt-actual-rect"
            >
              <title>{{ barTip(bar) }}</title>
            </rect>
            <text
              v-if="bar.actualStart"
              :x="xIso(bar.actualStart) + 5"
              :y="rowY(ri) + 63"
              class="gantt-actual-label"
            >{{ actualLabel(bar) }}</text>
            <!-- APS预计延长段：报工滚动后预计晚于计划（虚线框） -->
            <rect
              v-if="bar.forecastEnd"
              :x="xIso(bar.end)"
              :y="rowY(ri) + 4"
              :width="Math.max(xIso(bar.forecastEnd) - xIso(bar.end), 3)"
              :height="30"
              rx="3"
              class="gantt-forecast-rect"
            >
              <title>{{ barTip(bar) }}</title>
            </rect>
            <!-- 打断留档斜纹 -->
            <line
              v-if="bar.isInterrupted"
              :x1="xIso(bar.start)"
              :y1="rowY(ri) + rowHeight - 6"
              :x2="xIso(bar.end)"
              :y2="rowY(ri) + 6"
              class="gantt-interrupt-line"
            />
            <!-- 只有当前起未来三天的锁定任务显示锁定标识；窗口外任务可直接调整。 -->
            <text v-if="showsLock(bar)" :x="xIso(bar.start) + 6" :y="rowY(ri) + 16" class="gantt-lock-text">🔒</text>
            <!-- APS冻结区标记 -->
            <text v-if="bar.isFrozen" :x="xIso(bar.end) - 16" :y="rowY(ri) + 16" class="gantt-lock-text">❄️</text>
            <!-- 超期标记 -->
            <text v-if="isOverdue(bar)" :x="xIso(bar.end) - 16" :y="rowY(ri) + 30" class="gantt-lock-text">⏰</text>
            <text
              v-if="barWidth(bar) > 70"
              :x="xIso(bar.start) + (showsLock(bar) ? 22 : 6)"
              :y="rowY(ri) + 20"
              class="gantt-bar-text"
            >
              {{ barTitle(bar) }}
            </text>
            <!-- 选中后的时间调整手柄：左右边界只调整当前工序；长按主体才移动整条工序链 -->
            <g v-if="selectedBarId === bar.id && isAdjustable(bar)" class="gantt-edge-controls">
              <rect
                :x="xIso(bar.start) - 10"
                :y="rowY(ri) + 1"
                width="20"
                height="36"
                class="gantt-edge-hit"
                role="button"
                aria-label="拖动左侧手柄调整排程时间"
                @pointerdown.stop="onEdgePointerDown($event, bar, 'start', ri)"
              >
                <title>拖动左侧手柄：调整当前工序开始时间</title>
              </rect>
              <rect :x="xIso(bar.start) - 3" :y="rowY(ri) + 5" width="6" height="28" rx="2" class="gantt-edge-visual" />
              <rect
                :x="xIso(bar.end) - 10"
                :y="rowY(ri) + 1"
                width="20"
                height="36"
                class="gantt-edge-hit"
                role="button"
                aria-label="拖动右侧手柄调整排程时间"
                @pointerdown.stop="onEdgePointerDown($event, bar, 'end', ri)"
              >
                <title>拖动右侧手柄：调整当前工序结束时间</title>
              </rect>
              <rect :x="xIso(bar.end) - 3" :y="rowY(ri) + 5" width="6" height="28" rx="2" class="gantt-edge-visual" />
            </g>
          </g>
        </g>

        <!-- 已选工序的时间调整预览 -->
        <g v-if="adjustPreview" class="gantt-adjust-preview">
          <rect
            :x="adjustPreview.x"
            :y="adjustPreview.y + 4"
            :width="adjustPreview.width"
            height="30"
            rx="3"
            class="gantt-adjust-preview-rect"
          />
          <line
            :x1="adjustPreview.x"
            :y1="adjustPreview.y"
            :x2="adjustPreview.x"
            :y2="adjustPreview.y + rowHeight"
            class="gantt-adjust-preview-line"
          />
        </g>

        <!-- 拖拽插入点预览 -->
        <g v-if="dropPreview" class="gantt-drop-preview" :class="{ 'is-invalid': !dropPreview.allowed }">
          <rect
            :x="dropPreview.x"
            :y="dropPreview.y"
            :width="dropPreview.width"
            :height="rowHeight - 4"
            rx="3"
            class="gantt-preview-rect"
          />
          <text v-if="!dropPreview.allowed" :x="dropPreview.x + 6" :y="dropPreview.y + 20" class="gantt-preview-invalid-text">资源类型不匹配</text>
          <line :x1="dropPreview.x" :y1="0" :x2="dropPreview.x" :y2="bodyHeight" class="gantt-preview-line" />
        </g>
      </svg>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { PoolTask } from '@/types/schedule'
import type { GanttBar, GanttRow } from '@/utils/ScheduleAdapter'
import { toDate, toIso } from '@/utils/ScheduleAdapter'

const props = defineProps<{
  rows: GanttRow[]
  startDate: Date
  endDate: Date
  shift: {
    two_shift: { shift1: { name: string; start: string; end: string }; shift2: { name: string; end: string; start: string } }
    day: { start: string; end: string }
  }
  draggingTask?: PoolTask | null
  /** 像素/天（缩放比例尺）：默认 140=天级总览；2160=小时级细看 */
  pxPerDay?: number
  /** 是否在打开时自动滚到当前时刻；历史+计划窗口默认从窗口起点展示 */
  focusNow?: boolean
}>()

const emit = defineEmits<{
  (e: 'drop-on', payload: { row: GanttRow; startIso: string }): void
  (e: 'click-empty', payload: { row: GanttRow; startIso: string }): void
  (e: 'click-bar', bar: GanttBar): void
  (e: 'dbl-click-bar', bar: GanttBar): void
  (e: 'move-bar', payload: { bar: GanttBar; row: GanttRow; startIso: string }): void
  (e: 'resize-bar', payload: { bar: GanttBar; row: GanttRow; edge: 'start' | 'end'; startIso: string; endIso: string }): void
  (e: 'native-drop', payload: { taskId: number; row: GanttRow; startIso: string }): void
  (e: 'drop-rejected', payload: { task: PoolTask; row: GanttRow }): void
  (e: 'update:pxPerDay', value: number): void
}>()

/** 时间头高度（吸顶） */
const HEADER_H = 34
/** 像素/分钟（由比例尺 pxPerDay 派生；默认天级总览） */
const pxPerMin = computed(() => (props.pxPerDay ?? 140) / 1440)
const rowHeight = 68
const scrollRef = ref<HTMLDivElement | null>(null)
const labelsBodyRef = ref<HTMLDivElement | null>(null)
const bodyHeight = computed(() => props.rows.length * rowHeight + 2)

/** 小时刻度可见（放大到 ≥480px/天） */
const showHourTicks = computed(() => pxPerMin.value * 60 >= 8)
/** 30 分钟网格可见（放大到 ≥1200px/天） */
const showHalfGrid = computed(() => pxPerMin.value * 60 >= 20)
/** 日期文字间隔（天级过密时隔 N 天显示） */
const dayTextEvery = computed(() => {
  const d = props.pxPerDay ?? 140
  return d >= 90 ? 1 : d >= 45 ? 2 : 4
})
/** 拖拽/点击吸附粒度（分钟）：随缩放自适应 */
const snapMinutes = computed(() => (showHalfGrid.value ? 30 : showHourTicks.value ? 60 : 240))

/** 当前时刻 X 坐标（当前时间线） */
const nowX = computed(() => {
  const now = Date.now()
  if (now < props.startDate.getTime() || now > props.endDate.getTime() + 24 * 3600 * 1000) return -1
  return ((now - props.startDate.getTime()) / 60000) * pxPerMin.value
})

const dayList = computed<Date[]>(() => {
  const days: Date[] = []
  const cursor = new Date(props.startDate)
  cursor.setHours(0, 0, 0, 0)
  while (cursor.getTime() <= props.endDate.getTime()) {
    days.push(new Date(cursor))
    cursor.setDate(cursor.getDate() + 1)
  }
  return days
})

const spanMinutes = computed(() => {
  const startMs = props.startDate.getTime()
  const endMs = props.endDate.getTime() + 24 * 3600 * 1000
  return Math.max(1, Math.round((endMs - startMs) / 60000))
})

const dayWidth = computed(() => 24 * 60 * pxPerMin.value)
const timeWidth = computed(() => spanMinutes.value * pxPerMin.value)
const svgWidth = computed(() => timeWidth.value + 2)

function isWeekend(day: Date): boolean {
  const wd = day.getDay()
  return wd === 0 || wd === 6
}

interface Band {
  key: string
  startMin: number
  endMin: number
}

/** 某天的班制背景带（白班亮/夜班深） */
function bandsOf(day: Date): Band[] {
  const bands: Band[] = []
  const s = props.shift?.two_shift
  if (s?.shift1 && s?.shift2) {
    bands.push({ key: `day-${s.shift1.start}`, startMin: toMin(s.shift1.start), endMin: toMin(s.shift1.end) })
    bands.push({ key: `night-${s.shift2.start}`, startMin: toMin(s.shift2.start), endMin: 1440 })
    bands.push({ key: `night-a-${s.shift2.end}`, startMin: 0, endMin: toMin(s.shift2.end) })
  } else {
    const d = props.shift?.day
    bands.push({ key: 'day-0800', startMin: toMin(d?.start ?? '08:00'), endMin: toMin(d?.end ?? '17:00') })
  }
  return bands
}

function toMin(hhmm: string): number {
  const [h, m] = hhmm.split(':').map(Number)
  return h * 60 + m
}

function xAt(day: Date, minuteOfDay: number): number {
  const base = (day.getTime() - props.startDate.getTime()) / 60000
  return base * pxPerMin.value + minuteOfDay * pxPerMin.value
}

function xIso(iso: string): number {
  const d = toDate(iso)
  const clamped = d.getTime() < props.startDate.getTime() ? props.startDate.getTime() : d.getTime()
  return ((clamped - props.startDate.getTime()) / 60000) * pxPerMin.value
}

function rowY(index: number): number {
  return index * rowHeight
}

function barWidth(bar: GanttBar): number {
  const s = toDate(bar.start).getTime()
  const e = toDate(bar.end).getTime()
  const startClamped = Math.max(s, props.startDate.getTime())
  const endClamped = Math.min(e, props.endDate.getTime() + 24 * 3600 * 1000)
  return ((endClamped - startClamped) / 60000) * pxPerMin.value
}

/**
 * 实际条宽度（actual_start~actual_end，窗口裁剪）。
 * 未完工时只有 actual_start，显示最小绿色标记，避免有报工却完全不可见。
 */
function actualWidth(bar: GanttBar): number {
  if (!bar.actualStart) return 0
  const s = Math.max(toDate(bar.actualStart).getTime(), props.startDate.getTime())
  const e = Math.min(toDate(bar.actualEnd ?? bar.actualStart).getTime(), props.endDate.getTime() + 24 * 3600 * 1000)
  return Math.max(((e - s) / 60000) * pxPerMin.value, 4)
}

function actualSegmentWidth(segment: { start: string; end: string }): number {
  const s = Math.max(toDate(segment.start).getTime(), props.startDate.getTime())
  const e = Math.min(toDate(segment.end).getTime(), props.endDate.getTime() + 24 * 3600 * 1000)
  return Math.max(((e - s) / 60000) * pxPerMin.value, 4)
}

function actualLabelWidth(bar: GanttBar): number {
  return bar.actualSegments.length ? actualSegmentWidth(bar.actualSegments[0]) : actualWidth(bar)
}

function actualLabel(bar: GanttBar): string {
  const width = actualLabelWidth(bar)
  // 实际段很短时也保留可读信息，标签允许超出细轨道但不再直接隐藏。
  if (width < 24) return `${bar.progressPct}%`
  if (width < 70) return `实际 ${bar.progressPct}%`
  if (width < 150) return fitText('实际 · ' + bar.progressPct + '%', width - 8, 6)
  return fitText('实际 ' + bar.reportedQty + '/' + bar.qty + ' · ' + bar.progressPct + '%', width - 10, 7)
}

function fitText(value: string, width: number, charWidth: number): string {
  const maxChars = Math.floor(Math.max(0, width) / charWidth)
  if (maxChars < 4) return ''
  return value.length > maxChars ? value.slice(0, maxChars - 1) + '…' : value
}

function barTitle(bar: GanttBar): string {
  return fitText(bar.taskNo + ' · ' + bar.opName, barWidth(bar) - 12, 7)
}

function actualGaps(bar: GanttBar): Array<{ start: string; end: string }> {
  const gaps: Array<{ start: string; end: string }> = []
  for (let index = 1; index < bar.actualSegments.length; index += 1) {
    const previous = bar.actualSegments[index - 1]
    const current = bar.actualSegments[index]
    if (toDate(current.start).getTime() > toDate(previous.end).getTime()) {
      gaps.push({ start: previous.end, end: current.start })
    }
  }
  return gaps
}

function dayLabel(day: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0')
  const wd = ['日', '一', '二', '三', '四', '五', '六'][day.getDay()]
  return `${pad(day.getMonth() + 1)}-${pad(day.getDate())} 周${wd}`
}

/** 超期：计划结束已过且未完工 */
function isOverdue(bar: GanttBar): boolean {
  if (bar.status === 'done') return false
  // 已有实际完工时间时，以实际完工是否晚于计划结束判断，避免历史正常完成任务被误报超期。
  if (bar.actualEnd) return toDate(bar.actualEnd).getTime() > toDate(bar.end).getTime()
  return toDate(bar.end).getTime() < Date.now()
}

function barClass(bar: GanttBar): string {
  const cls = ['gantt-bar']
  if (bar.isConflict || bar.isOverlap) cls.push('is-conflict')
  else if (bar.isInterrupted) cls.push('is-interrupted')
  else if (isOverdue(bar)) cls.push('is-overdue')
  else if (bar.status === 'done') cls.push('is-done')
  else if (bar.status === 'running') cls.push('is-running')
  else if (bar.source === 'ai') cls.push('is-ai')
  else if (bar.source === 'insert') cls.push('is-insert')
  else cls.push('is-manual')
  return cls.join(' ')
}

function barGroupClass(bar: GanttBar): string {
  return `gantt-bar-group ${selectedTaskId.value === bar.taskId ? 'is-selected' : ''} ${selectedBarId.value === bar.id ? 'is-bar-selected' : ''}`
}

function showsLock(bar: GanttBar): boolean {
  return bar.isLocked && bar.isFrozen
}

function isAdjustable(bar: GanttBar): boolean {
  // 三日锁定任务必须先通过工具栏解锁；窗口外任务不受锁定标识影响，可直接移动。
  return !showsLock(bar) && bar.status !== 'running' && bar.status !== 'done' && !bar.actualStart && !bar.actualEnd
}

function barTip(bar: GanttBar): string {
  const lines = [
    `${bar.taskNo} · ${bar.productName}(${bar.productCode})`,
    `工序：${bar.opName} · 数量 ${bar.qty}件`,
    `进度：已报工 ${bar.reportedQty}/${bar.qty}件（${bar.progressPct}%）`,
    `计划：${bar.start} ~ ${bar.end}`,
    `状态：${statusLabel(bar.status)}${showsLock(bar) ? ' · 三日锁定（请先点击上方解锁）' : ''}${bar.isInterrupted ? ' · 打断留档' : ''}${isOverdue(bar) ? ' · ⚠已超期' : ''}`,
  ]
  if (bar.actualStart && bar.actualEnd) lines.push(`实际：${bar.actualStart} ~ ${bar.actualEnd}`)
  else if (bar.actualStart) lines.push(`实际已开工：${bar.actualStart}（尚未完工）`)
  if (bar.actualSegments.length) {
    lines.push(`报工段：${bar.actualSegments.map((segment) => `${segment.start} ~ ${segment.end}（${segment.qty}件）`).join('；')}`)
  }
  if (bar.forecastEnd) lines.push(`预计完成：${bar.forecastEnd}（报工滚动，晚于计划）`)
  if (bar.moldCode) lines.push(`模具：${bar.moldCode}`)
  if (bar.note) lines.push(`备注：${bar.note}`)
  return lines.join('\n')
}

function statusLabel(s: string): string {
  return ({ pending: '待执行', running: '进行中', paused: '已暂停', done: '已完成' } as Record<string, string>)[s] ?? s
}

function timeRange(bar: GanttBar): string {
  const f = (iso: string): string => {
    const d = toDate(iso)
    const pad = (n: number): string => String(n).padStart(2, '0')
    return `${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
  }
  return `${f(bar.start)} ~ ${f(bar.end)}`
}

function actualTimeRange(bar: GanttBar): string {
  if (!bar.actualStart || !bar.actualEnd) return '-'
  const f = (iso: string): string => {
    const d = toDate(iso)
    const pad = (n: number): string => String(n).padStart(2, '0')
    return `${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
  }
  return `${f(bar.actualStart)} ~ ${f(bar.actualEnd)}`
}

/** 当前滚动下悬停/插入点（拖拽预览） */
const dropPreview = ref<{ x: number; y: number; width: number; allowed: boolean } | null>(null)
const dropRowIndex = ref(-1)
const dropDate = ref<Date | null>(null)
/** 原生拖拽事件中同步读取的资源类型，作为任务池选中状态的兜底。 */
const nativeDragResourceType = ref<string | null>(null)

/** 已排工序拖动状态：默认移动整条工序链，落点按被抓取位置吸附 */
const draggingBar = ref<GanttBar | null>(null)
const dragOffsetMinutes = ref(0)
const dragMoved = ref(false)
const justDragged = ref(false)
const pendingBarPress = ref<{ bar: GanttBar; x: number; y: number } | null>(null)
let barPressTimer: number | null = null

interface EdgeDragState {
  bar: GanttBar
  edge: 'start' | 'end'
  rowIndex: number
  initialStartMinutes: number
  initialEndMinutes: number
  targetStartMinutes: number
  targetEndMinutes: number
}

/** 选中的具体工序段：同工单其他工序仍联动高亮，但只有当前段显示时间手柄 */
const selectedBarId = ref<number | null>(null)
const edgeDragging = ref<EdgeDragState | null>(null)
const edgeMoved = ref(false)
const adjustPreview = ref<{ x: number; y: number; width: number } | null>(null)

/** 点击一个工序后，同一任务的所有工序段联动高亮，便于查看跨机台/产线的接续关系 */
const selectedTaskId = ref<number | null>(null)

interface TaskLink {
  key: string
  path: string
  fromSeq: number
  toSeq: number
}

const selectedTaskLinks = computed<TaskLink[]>(() => {
  if (selectedTaskId.value === null) return []
  const segments = props.rows.flatMap((row, rowIndex) => row.bars
    .filter((bar) => bar.taskId === selectedTaskId.value)
    .map((bar) => ({ bar, rowIndex })))
    .sort((a, b) => a.bar.seq - b.bar.seq || toDate(a.bar.start).getTime() - toDate(b.bar.start).getTime())

  return segments.slice(0, -1).flatMap((current, index) => {
    const next = segments[index + 1]
    if (!next || current.rowIndex === next.rowIndex) return []
    const x1 = xIso(current.bar.end)
    const x2 = xIso(next.bar.start)
    const y1 = rowY(current.rowIndex) + 39
    const y2 = rowY(next.rowIndex) + 39
    const bend = Math.max(16, Math.min(72, Math.abs(x2 - x1) / 2))
    return [{
      key: `${current.bar.id}-${next.bar.id}`,
      path: `M ${x1} ${y1} C ${x1 + bend} ${y1}, ${x2 - bend} ${y2}, ${x2} ${y2}`,
      fromSeq: current.bar.seq,
      toSeq: next.bar.seq,
    }]
  })
})

/** 垂直滚动：左栏标签同步平移 */
function onScroll(): void {
  if (labelsBodyRef.value && scrollRef.value) {
    labelsBodyRef.value.style.transform = `translateY(-${scrollRef.value.scrollTop}px)`
  }
}

/** Ctrl/⌘ + 滚轮 = 缩放比例尺（交互优化） */
function onWheel(e: WheelEvent): void {
  if (!e.ctrlKey && !e.metaKey) return
  e.preventDefault()
  const current = props.pxPerDay ?? 140
  const next = e.deltaY < 0 ? current + 160 : current - 160
  emit('update:pxPerDay', Math.min(2160, Math.max(40, next)))
}

/** 滚动到当前时刻（打开页面/点按钮时） */
function scrollToNow(): void {
  if (!scrollRef.value) return
  const x = ((Date.now() - props.startDate.getTime()) / 60000) * pxPerMin.value
  scrollRef.value.scrollLeft = Math.max(0, x - scrollRef.value.clientWidth / 3)
}

onMounted(() => {
  if (props.focusNow !== false) scrollToNow()
  else if (scrollRef.value) scrollRef.value.scrollLeft = 0
})

watch(() => props.focusNow, (focusNow) => {
  if (!focusNow && scrollRef.value) scrollRef.value.scrollLeft = 0
})

function onBarClick(bar: GanttBar): void {
  if (justDragged.value) return
  selectedTaskId.value = bar.taskId
  selectedBarId.value = bar.id
  emit('click-bar', bar)
}

function onBarDblClick(bar: GanttBar): void {
  selectedTaskId.value = bar.taskId
  selectedBarId.value = bar.id
  emit('dbl-click-bar', bar)
}

function onCanvasClick(row: GanttRow, day: Date, band: Band): void {
  // 点击空白处：非拖拽时视为放置意图（打开插单确认）
  if (props.draggingTask || draggingBar.value) return
  const minute = band.startMin + 60 // 班段内 1 小时处作为默认插入点
  const d = new Date(day)
  d.setHours(0, minute, 0, 0)
  emit('click-empty', { row, startIso: toIso(d) })
}

function requiredResourceType(): string | null {
  return props.draggingTask?.required_resource_type ?? nativeDragResourceType.value
}

function canDropOn(row: GanttRow): boolean {
  const required = requiredResourceType()
  return Boolean(required && row.resourceType === required)
}

/** 鼠标移动显示插入点预览（绑定在 scroll div 上） */
function onPointerMove(event: MouseEvent): void {
  if (draggingBar.value) {
    dragMoved.value = true
    updateDropPreview(event, dragWidthMinutes(draggingBar.value))
    return
  }
  if (!props.draggingTask || !scrollRef.value) {
    dropPreview.value = null
    return
  }
  updateDropPreview(event)
}

function updateDropPreview(event: MouseEvent, widthMinutes = 8 * 60): void {
  if (!scrollRef.value) return
  const rect = scrollRef.value.getBoundingClientRect()
  const x = event.clientX - rect.left + scrollRef.value.scrollLeft
  const y = event.clientY - rect.top + scrollRef.value.scrollTop - HEADER_H
  const index = Math.floor(y / rowHeight)
  if (index < 0 || index >= props.rows.length) {
    dropPreview.value = null
    return
  }
  // 吸附粒度随缩放自适应（天级=4小时，小时级=30分钟）
  const minutes = x / pxPerMin.value - (draggingBar.value ? dragOffsetMinutes.value : 0)
  const snapped = Math.round(minutes / snapMinutes.value) * snapMinutes.value
  const absMs = props.startDate.getTime() + snapped * 60000
  const row = props.rows[index]
  dropRowIndex.value = index
  dropDate.value = new Date(absMs)
  dropPreview.value = {
    x: snapped * pxPerMin.value,
    y: rowY(index),
    width: Math.min(widthMinutes * pxPerMin.value, timeWidth.value - snapped * pxPerMin.value),
    allowed: !props.draggingTask || canDropOn(row),
  }
}

function dragWidthMinutes(bar: GanttBar): number {
  return Math.max(60, (toDate(bar.end).getTime() - toDate(bar.start).getTime()) / 60000)
}

function onBarPointerDown(event: PointerEvent, bar: GanttBar): void {
  if (event.button !== 0 || props.draggingTask || !scrollRef.value) return
  if (!isAdjustable(bar)) return

  const rect = scrollRef.value.getBoundingClientRect()
  const x = event.clientX - rect.left + scrollRef.value.scrollLeft
  dragOffsetMinutes.value = Math.max(0, (x - xIso(bar.start)) / pxPerMin.value)
  // 单击仍然负责选中；长按 240ms 后才进入整条工序链拖动，避免误触把点击变成移动。
  pendingBarPress.value = { bar, x: event.clientX, y: event.clientY }
  dragMoved.value = false
  dropRowIndex.value = -1
  dropDate.value = null
  if (barPressTimer !== null) window.clearTimeout(barPressTimer)
  barPressTimer = window.setTimeout(() => {
    const press = pendingBarPress.value
    if (!press) return
    draggingBar.value = press.bar
    pendingBarPress.value = null
    barPressTimer = null
  }, 240)
  window.addEventListener('pointermove', onBarGlobalMove)
  window.addEventListener('pointerup', onBarGlobalUp)
}

function onBarGlobalMove(event: PointerEvent): void {
  if (!draggingBar.value) {
    const press = pendingBarPress.value
    if (!press) return
    const movedX = event.clientX - press.x
    const movedY = event.clientY - press.y
    if (Math.hypot(movedX, movedY) > 6) cancelBarPress()
    return
  }
  dragMoved.value = true
  updateDropPreview(event, dragWidthMinutes(draggingBar.value))
}

function onBarGlobalUp(): void {
  if (!draggingBar.value) {
    cancelBarPress()
    return
  }
  const bar = draggingBar.value
  const row = dropRowIndex.value >= 0 ? props.rows[dropRowIndex.value] : null
  const date = dropDate.value
  const moved = dragMoved.value

  draggingBar.value = null
  dropPreview.value = null
  dragOffsetMinutes.value = 0
  dragMoved.value = false
  window.removeEventListener('pointermove', onBarGlobalMove)
  window.removeEventListener('pointerup', onBarGlobalUp)

  if (!bar || !row || !date || !moved) return
  justDragged.value = true
  window.setTimeout(() => { justDragged.value = false }, 0)
  emit('move-bar', { bar, row, startIso: toIso(date) })
}

function cancelBarPress(): void {
  if (barPressTimer !== null) {
    window.clearTimeout(barPressTimer)
    barPressTimer = null
  }
  pendingBarPress.value = null
  if (!draggingBar.value) {
    window.removeEventListener('pointermove', onBarGlobalMove)
    window.removeEventListener('pointerup', onBarGlobalUp)
  }
}

function timelineMinutesAt(event: PointerEvent): number {
  if (!scrollRef.value) return 0
  const rect = scrollRef.value.getBoundingClientRect()
  const x = event.clientX - rect.left + scrollRef.value.scrollLeft
  return x / pxPerMin.value
}

function snappedTimelineMinutes(minutes: number): number {
  return Math.round(minutes / snapMinutes.value) * snapMinutes.value
}

function dateAtTimelineMinutes(minutes: number): Date {
  return new Date(props.startDate.getTime() + minutes * 60000)
}

/** 端点手柄只调整当前工序的一侧边界；整条链移动仍由任务主体长按触发。 */
function onEdgePointerDown(event: PointerEvent, bar: GanttBar, edge: 'start' | 'end', rowIndex: number): void {
  if (event.button !== 0 || props.draggingTask || !scrollRef.value || !isAdjustable(bar)) return
  const initialStartMinutes = (toDate(bar.start).getTime() - props.startDate.getTime()) / 60000
  const initialEndMinutes = (toDate(bar.end).getTime() - props.startDate.getTime()) / 60000
  selectedTaskId.value = bar.taskId
  selectedBarId.value = bar.id
  edgeDragging.value = {
    bar,
    edge,
    rowIndex,
    initialStartMinutes,
    initialEndMinutes,
    targetStartMinutes: initialStartMinutes,
    targetEndMinutes: initialEndMinutes,
  }
  edgeMoved.value = false
  window.addEventListener('pointermove', onEdgeGlobalMove)
  window.addEventListener('pointerup', onEdgeGlobalUp)
}

function onEdgeGlobalMove(event: PointerEvent): void {
  const state = edgeDragging.value
  if (!state) return
  const snapped = snappedTimelineMinutes(timelineMinutesAt(event))
  const targetStartMinutes = state.edge === 'start'
    ? Math.min(snapped, state.initialEndMinutes - 60)
    : state.initialStartMinutes
  const targetEndMinutes = state.edge === 'end'
    ? Math.max(snapped, state.initialStartMinutes + 60)
    : state.initialEndMinutes
  state.targetStartMinutes = targetStartMinutes
  state.targetEndMinutes = targetEndMinutes
  edgeMoved.value = Math.abs(
    (state.edge === 'start' ? targetStartMinutes : targetEndMinutes)
      - (state.edge === 'start' ? state.initialStartMinutes : state.initialEndMinutes),
  ) >= 1
  const durationMinutes = Math.max(60, targetEndMinutes - targetStartMinutes)
  adjustPreview.value = {
    x: targetStartMinutes * pxPerMin.value,
    y: rowY(state.rowIndex),
    width: durationMinutes * pxPerMin.value,
  }
}

function onEdgeGlobalUp(): void {
  const state = edgeDragging.value
  const moved = edgeMoved.value
  const targetStartMinutes = state?.targetStartMinutes ?? 0
  const targetEndMinutes = state?.targetEndMinutes ?? 0
  const row = state ? props.rows[state.rowIndex] : null
  edgeDragging.value = null
  edgeMoved.value = false
  adjustPreview.value = null
  window.removeEventListener('pointermove', onEdgeGlobalMove)
  window.removeEventListener('pointerup', onEdgeGlobalUp)
  if (!state || !row || !moved) return
  justDragged.value = true
  window.setTimeout(() => { justDragged.value = false }, 0)
  emit('resize-bar', {
    bar: state.bar,
    row,
    edge: state.edge,
    startIso: toIso(dateAtTimelineMinutes(targetStartMinutes)),
    endIso: toIso(dateAtTimelineMinutes(targetEndMinutes)),
  })
}

function onDrop(): void {
  if (draggingBar.value) return
  if (!props.draggingTask || dropRowIndex.value < 0 || !dropDate.value) {
    dropPreview.value = null
    return
  }
  const row = props.rows[dropRowIndex.value]
  const d = new Date(dropDate.value)
  if (!dropPreview.value?.allowed) {
    const task = props.draggingTask
    if (task) emit('drop-rejected', { task, row })
    dropPreview.value = null
    return
  }
  emit('drop-on', { row, startIso: toIso(d) })
  dropPreview.value = null
}

/** HTML5 原生拖拽兜底：浏览器接管拖拽时，自定义 pointer 不会触发；监听 dragover/drop 走原生生拖拽 */
function onNativeDragOver(event: DragEvent): void {
  nativeDragResourceType.value = props.draggingTask?.required_resource_type
    ?? event.dataTransfer?.getData('application/x-resource-type')
    ?? null
  const rect = scrollRef.value?.getBoundingClientRect()
  if (!rect) return
  const x = event.clientX - rect.left + (scrollRef.value?.scrollLeft ?? 0)
  const y = event.clientY - rect.top + (scrollRef.value?.scrollTop ?? 0) - HEADER_H
  const index = Math.floor(y / rowHeight)
  if (index < 0 || index >= props.rows.length) {
    dropPreview.value = null
    return
  }
  const minutes = x / pxPerMin.value
  const snapped = Math.round(minutes / snapMinutes.value) * snapMinutes.value
  const absMs = props.startDate.getTime() + snapped * 60000
  const row = props.rows[index]
  if (event.dataTransfer) event.dataTransfer.dropEffect = canDropOn(row) ? 'copy' : 'none'
  dropRowIndex.value = index
  dropDate.value = new Date(absMs)
  dropPreview.value = {
    x: snapped * pxPerMin.value,
    y: rowY(index),
    width: Math.min(8 * 60 * pxPerMin.value, timeWidth.value - snapped * pxPerMin.value),
    allowed: canDropOn(row),
  }
}

function onNativeDrop(event: DragEvent): void {
  event.preventDefault()
  const rect = scrollRef.value?.getBoundingClientRect()
  const tid = Number(event.dataTransfer?.getData('text/plain') || event.dataTransfer?.getData('application/x-pool-task') || 0)
  if (!tid || !rect || dropRowIndex.value < 0 || !dropDate.value) {
    dropPreview.value = null
    return
  }
  const row = props.rows[dropRowIndex.value]
  const d = new Date(dropDate.value)
  if (!dropPreview.value?.allowed) {
    const task = props.draggingTask
    if (task) emit('drop-rejected', { task, row })
    dropPreview.value = null
    nativeDragResourceType.value = null
    return
  }
  emit('native-drop', { taskId: tid, row, startIso: toIso(d) })
  dropPreview.value = null
  nativeDragResourceType.value = null
}

onBeforeUnmount(() => {
  if (barPressTimer !== null) window.clearTimeout(barPressTimer)
  window.removeEventListener('pointermove', onBarGlobalMove)
  window.removeEventListener('pointerup', onBarGlobalUp)
  window.removeEventListener('pointermove', onEdgeGlobalMove)
  window.removeEventListener('pointerup', onEdgeGlobalUp)
})

/** 暴露坐标工具与滚动控制 */
defineExpose({ xIso, rowY, bodyHeight, scrollToNow })
</script>

<style scoped>
.gantt-wrap {
  display: flex;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
  background: #fff;
  /* 固定视口：内部滚动，时间头吸顶、左栏同步 */
  height: calc(100vh - 250px);
  min-height: 360px;
}
.gantt-labels {
  flex: 0 0 200px;
  border-right: 1px solid #e5e7eb;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.gantt-corner {
  height: 34px;
  line-height: 34px;
  padding: 0 12px;
  background: #f8fafc;
  border-bottom: 1px solid #e5e7eb;
  font-size: 12px;
  color: #64748b;
  flex: 0 0 34px;
}
.gantt-labels-body {
  will-change: transform;
}
.gantt-label-row {
  height: 68px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 0 12px;
  border-bottom: 1px solid #f1f5f9;
  box-sizing: border-box;
}
.label-main {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}
.label-title {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.label-count {
  flex: 0 0 auto;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: 9px;
  background: #eff6ff;
  color: #2563eb;
  font-size: 10px;
  font-weight: 700;
  line-height: 18px;
  text-align: center;
}
.label-sub {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  font-size: 11px;
  color: #94a3b8;
  margin-top: 2px;
  white-space: nowrap;
}
.load-badge {
  flex: 0 0 auto;
  padding: 1px 4px;
  border-radius: 3px;
  font-size: 10px;
  line-height: 14px;
}
.load-badge.is-low {
  background: #f1f5f9;
  color: #64748b;
}
.load-badge.is-normal {
  background: #ecfdf5;
  color: #047857;
}
.load-badge.is-high {
  background: #fff7ed;
  color: #c2410c;
}
.gantt-scroll {
  flex: 1;
  overflow: auto;
  position: relative;
  cursor: crosshair;
}
.gantt-header-sticky {
  position: sticky;
  top: 0;
  z-index: 5;
  line-height: 0;
}
.gantt-header-svg {
  display: block;
}
.gantt-svg {
  display: block;
}
.gantt-row-bg {
  fill: #ffffff;
}
.gantt-row-bg-alt {
  fill: #f8fafc;
}
.gantt-weekend-bg {
  fill: #f1f5f9;
  opacity: 0.6;
}
.gantt-band-day {
  fill: #eef5ff;
  opacity: 0.55;
}
.gantt-band-day:hover {
  opacity: 0.85;
}
.gantt-band-night {
  fill: #1e293b;
  opacity: 0.08;
}
.gantt-band-night:hover {
  opacity: 0.16;
}
.gantt-day-line {
  stroke: #dbe4ee;
  stroke-width: 1.2;
}
.gantt-grid-line {
  stroke: #eef2f6;
  stroke-width: 0.5;
}
.gantt-header-bg {
  fill: #e2e8f0;
}
.gantt-day-header-line {
  stroke: #cbd5e1;
  stroke-width: 1;
}
.gantt-day-band {
  fill: #cbd5e1;
  opacity: 0.6;
}
.gantt-day-band-weekend {
  fill: #94a3b8;
  opacity: 0.8;
}
.gantt-day-text {
  font-size: 12.5px;
  fill: #0f172a;
  text-anchor: middle;
  font-weight: 700;
  letter-spacing: 0.2px;
}
.gantt-hour-tick {
  stroke: #94a3b8;
  stroke-width: 1;
}
.gantt-hour-text {
  font-size: 9px;
  fill: #475569;
  text-anchor: start;
}
/* 当前时间线 */
.gantt-now-line {
  stroke: #ef4444;
  stroke-width: 1.5;
  stroke-dasharray: 5 3;
  pointer-events: none;
}
.gantt-now-flag {
  fill: #ef4444;
}
.gantt-task-link {
  fill: none;
  stroke: #60a5fa;
  stroke-width: 1.5;
  stroke-dasharray: 4 3;
  opacity: 0.9;
  pointer-events: none;
}
.gantt-bar {
  fill: #3b82f6;
  opacity: 0.92;
  stroke: #2563eb;
  stroke-width: 1.5;
}
/* APS预计延长段：报工滚动后预计晚于计划（虚线框，警示色） */
.gantt-forecast-rect {
  fill: #f59e0b;
  fill-opacity: 0.15;
  stroke: #f59e0b;
  stroke-width: 1.5;
  stroke-dasharray: 4 3;
}
/* APS实际条：计划下方的绿色细轨道，避免与计划主条争夺视觉层级。 */
.gantt-actual-rect {
  fill: #059669;
  opacity: 0.9;
  stroke: #047857;
  stroke-width: 1.5;
}
.gantt-actual-label {
  font-size: 10px;
  fill: #047857;
  font-weight: 600;
  pointer-events: none;
}
.gantt-actual-sub {
  font-size: 10.5px;
  fill: #ffffff;
  font-weight: 600;
  paint-order: stroke fill;
  stroke: #065f46;
  stroke-width: 1.5px;
  stroke-linejoin: round;
  pointer-events: none;
}
.gantt-actual-gap-line {
  stroke: #94a3b8;
  stroke-width: 2;
  stroke-dasharray: 4 4;
  opacity: 0.85;
  pointer-events: none;
}
.gantt-bar.is-manual {
  fill: #3b82f6;
  stroke: #2563eb;
}
.gantt-bar.is-ai {
  /* 算法排产与手工排产统一使用计划蓝色，颜色只表达业务状态。 */
  fill: #3b82f6;
  stroke: #2563eb;
}
.gantt-bar.is-insert {
  /* 插单仍是当前计划，不用额外颜色制造来源层级。 */
  fill: #3b82f6;
  stroke: #2563eb;
}
/* 计划条统一使用计划色；绿色仅用于下方实际报工条 */
.gantt-bar.is-running {
  fill: #3b82f6;
  stroke: #2563eb;
}
.gantt-bar.is-done {
  fill: #94a3b8;
  fill-opacity: 0.62;
  stroke: #64748b;
  stroke-dasharray: 3 2;
}
.gantt-bar.is-overdue {
  fill: #fee2e2;
  stroke: #dc2626;
  stroke-width: 2;
}
.gantt-bar.is-conflict,
.gantt-bar.is-overlap {
  fill: #fee2e2;
  stroke: #dc2626;
  stroke-width: 2;
}
.gantt-bar.is-interrupted {
  fill: #f59e0b;
  stroke: #b45309;
  stroke-dasharray: 4 2;
}
.gantt-interrupt-line {
  stroke: #92400e;
  stroke-width: 1.2;
}
.gantt-lock-text {
  font-size: 10px;
}
.gantt-bar-text {
  font-size: 12px;
  fill: #ffffff;
  font-weight: 600;
  paint-order: stroke fill;
  stroke: rgba(15, 23, 42, 0.45);
  stroke-width: 2px;
  stroke-linejoin: round;
  pointer-events: none;
}
.gantt-bar-sub {
  font-size: 10px;
  fill: #ffffff;
  font-weight: 500;
  paint-order: stroke fill;
  stroke: rgba(15, 23, 42, 0.45);
  stroke-width: 1.5px;
  stroke-linejoin: round;
  pointer-events: none;
}
.gantt-bar-group:hover rect {
  filter: brightness(1.08);
}
.gantt-bar-group {
  cursor: grab;
}
.gantt-bar-group:active {
  cursor: grabbing;
}
.gantt-bar-group.is-bar-selected {
  cursor: grab;
}
.gantt-bar-group.is-selected .gantt-bar:not(.is-conflict):not(.is-overlap):not(.is-overdue) {
  /* 选中态使用深蓝粗边框 + 细白色外轮廓，和普通计划蓝块明确区分。 */
  stroke-width: 4;
  stroke: #0f3d91;
  filter: drop-shadow(0 0 0.75px rgba(255, 255, 255, 0.95));
}
.gantt-bar-group.is-selected .gantt-actual-rect {
  stroke: #047857;
  stroke-width: 1.5;
}
.gantt-edge-controls {
  pointer-events: none;
}
.gantt-edge-hit {
  fill: transparent;
  stroke: transparent;
  pointer-events: all;
  cursor: ew-resize;
}
.gantt-edge-hit:hover {
  fill: rgba(255, 255, 255, 0.18);
}
.gantt-edge-visual {
  fill: #fff;
  stroke: #dc2626;
  stroke-width: 1.5;
  pointer-events: none;
  opacity: 0.95;
}
.gantt-bar-group.is-selected .gantt-edge-hit {
  stroke: transparent;
  stroke-width: 1;
}
.gantt-bar-group.is-selected .gantt-edge-visual {
  stroke: #2563eb;
  stroke-width: 1.5;
}
.gantt-adjust-preview .gantt-adjust-preview-rect {
  fill: #2563eb;
  fill-opacity: 0.18;
  stroke: #2563eb;
  stroke-width: 2;
  stroke-dasharray: 5 3;
  pointer-events: none;
}
.gantt-adjust-preview .gantt-adjust-preview-line {
  stroke: #2563eb;
  stroke-width: 1.5;
  stroke-dasharray: 4 2;
  pointer-events: none;
}
.gantt-drop-preview .gantt-preview-rect {
  fill: #22c55e;
  opacity: 0.35;
  stroke: #16a34a;
  stroke-width: 1.5;
  stroke-dasharray: 5 3;
}
.gantt-drop-preview .gantt-preview-line {
  stroke: #16a34a;
  stroke-width: 1.5;
  stroke-dasharray: 4 2;
}
.gantt-drop-preview.is-invalid .gantt-preview-rect {
  fill: #fee2e2;
  opacity: 0.78;
  stroke: #dc2626;
  stroke-width: 2;
  stroke-dasharray: 5 3;
}
.gantt-drop-preview.is-invalid .gantt-preview-line {
  stroke: #dc2626;
}
.gantt-preview-invalid-text {
  fill: #b91c1c;
  font-size: 12px;
  font-weight: 600;
  pointer-events: none;
}
</style>
