/**
 * 本地测试时钟：配置 VITE_TEST_NOW 后固定浏览器 Date，留空则使用真实时间。
 * 只影响当前前端进程，不修改操作系统时间。
 */
const configuredNow = String(import.meta.env.VITE_TEST_NOW ?? '').trim()

export function installTestClock(): void {
  if (!configuredNow) return

  const nativeDate = globalThis.Date
  const fixedTimestamp = nativeDate.parse(configuredNow)
  if (!Number.isFinite(fixedTimestamp)) {
    console.warn(`[test-clock] 无法解析 VITE_TEST_NOW: ${configuredNow}`)
    return
  }

  class FixedDate extends nativeDate {
    constructor(value?: string | number) {
      if (arguments.length === 0) {
        super(fixedTimestamp)
      } else {
        super(value as string | number)
      }
    }

    static now(): number {
      return fixedTimestamp
    }
  }

  globalThis.Date = FixedDate as unknown as DateConstructor
}
