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
    constructor(
      value?: string | number | Date,
      month?: number,
      date?: number,
      hours?: number,
      minutes?: number,
      seconds?: number,
      milliseconds?: number,
    ) {
      if (arguments.length === 0) {
        super(fixedTimestamp)
      } else if (arguments.length === 1) {
        // 保留 Date/时间戳/日期字符串的原生单参数语义。
        super(value as string | number)
      } else if (arguments.length === 2) {
        super(value as number, month as number)
      } else if (arguments.length === 3) {
        super(value as number, month as number, date as number)
      } else if (arguments.length === 4) {
        super(value as number, month as number, date as number, hours as number)
      } else if (arguments.length === 5) {
        super(value as number, month as number, date as number, hours as number, minutes as number)
      } else if (arguments.length === 6) {
        super(value as number, month as number, date as number, hours as number, minutes as number, seconds as number)
      } else {
        super(value as number, month as number, date as number, hours as number, minutes as number, seconds as number, milliseconds as number)
      }
    }

    static now(): number {
      return fixedTimestamp
    }
  }

  globalThis.Date = FixedDate as unknown as DateConstructor
}
