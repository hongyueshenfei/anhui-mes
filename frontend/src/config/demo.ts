/**
 * 演示模式（Demo Mode）统一开关
 *
 * 默认使用真实后端；演示模式仅在显式设置 VITE_DEMO_MODE=1/true 时启用。
 * 这样页面数据不会在真实接口不可用时静默切换为本地 mock。
 *
 * 控制方式（优先级从高到低）：
 * 1. import.meta.env.VITE_DEMO_MODE === '1' / 'true' → 开启
 * 2. 当前浏览器 sessionStorage['hy_demo_mode'] = '1' → 开启
 * 3. 默认关闭（走真实后端）
 */

const DEMO_KEY = 'hy_demo_mode'

/** 是否演示模式（同步读取，供 axios 层短路使用） */
export function isDemoMode(): boolean {
  const env = (import.meta.env.VITE_DEMO_MODE as string | undefined) ?? ''
  if (env !== '') {
    return env === '1' || env === 'true'
  }
  try {
    if (sessionStorage.getItem(DEMO_KEY) === '1') return true
  } catch {
    // sessionStorage 不可用时按环境变量/默认值
  }
  return false
}

/** 显式开启演示模式（可在控制台调试） */
export function enableDemoMode(): void {
  sessionStorage.setItem(DEMO_KEY, '1')
}

/** 显式关闭演示模式（连接真实后端） */
export function disableDemoMode(): void {
  sessionStorage.removeItem(DEMO_KEY)
}

/** 演示模式展示文案 */
export const DEMO_MODE_LABEL = '演示模式 · 模拟数据（未连接后端）'
