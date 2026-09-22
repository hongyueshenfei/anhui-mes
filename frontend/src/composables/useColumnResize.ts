import { nextTick, onBeforeUnmount, watch, type Ref } from 'vue'

interface ResizeTarget {
  el: HTMLElement
  key: string
}

/**
 * el-table 列宽拖拽（Element Plus 未内置）
 *
 * 用法：给表格 ref + 响应式列宽 + 列 key 顺序，每列 :width="widths.key" 绑定；
 * 表头单元格右侧会出现拖拽手柄，拖动更新对应列宽。
 */
export function useColumnResize(
  tableRef: Ref<{ $el: HTMLElement } | HTMLElement | null>,
  widths: Record<string, number>,
  keys: string[],
  minWidth = 50,
): { bindHandles: () => void } {
  const targets: ResizeTarget[] = []
  let dragging: { key: string; startX: number; startWidth: number } | null = null
  let observer: MutationObserver | null = null

  function onMouseMove(e: MouseEvent): void {
    if (!dragging) return
    const next = Math.max(minWidth, dragging.startWidth + e.clientX - dragging.startX)
    console.debug('[col-resize] move', dragging.key, next)
    widths[dragging.key] = next
  }

  function onMouseUp(): void {
    dragging = null
    document.body.style.cursor = ''
  }

  function bindHandles(): void {
    const el = tableRef.value
    if (!el) return
    const root = '$el' in el ? el.$el : el
    if (!root) return

    const ths = root.querySelectorAll<HTMLElement>('.el-table__header-wrapper th')
    if (ths.length === 0) return

    ths.forEach((th, i) => {
      const key = keys[i]
      if (!key || th.querySelector('.col-resize-handle')) return

      const handle = document.createElement('div')
      handle.className = 'col-resize-handle'
      handle.title = '拖动调整列宽'
      handle.addEventListener('mousedown', (e: MouseEvent) => {
        e.preventDefault()
        console.debug('[col-resize] mousedown', key, e.clientX)
        dragging = { key, startX: e.clientX, startWidth: widths[key] ?? th.getBoundingClientRect().width }
        document.body.style.cursor = 'col-resize'
      })
      th.appendChild(handle)
      targets.push({ el: th, key })
    })
  }

  // 表格可能在组件挂载后（v-if/异步数据）才渲染：ref 赋值后再绑定表头
  watch(tableRef, (el) => {
    if (!el) return
    const root = '$el' in el ? el.$el : el
    if (!root) return
    nextTick(() => {
      bindHandles()
      // 表头可能晚于渲染：监听子树变化，出现表头后自动绑定（幂等）
      observer?.disconnect()
      observer = new MutationObserver(() => bindHandles())
      observer.observe(root, { childList: true, subtree: true })
    })
  })

  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup', onMouseUp)
  onBeforeUnmount(() => {
    observer?.disconnect()
    observer = null
    document.removeEventListener('mousemove', onMouseMove)
    document.removeEventListener('mouseup', onMouseUp)
  })

  return { bindHandles }
}
