/**
 * QA_REJECT 回归验证：request 捕获 MockApiError 时回退到 fallback 参数
 *
 * 由 esbuild bundle 后在 node 下运行（避免引入 vitest 依赖）：
 *   node_modules/.bin/esbuild scripts/verify-mock-fallback.ts \
 *     --bundle --format=esm --platform=node --define:import.meta.env={} \
 *     --alias:@=./src --alias:element-plus=./scripts/stubs/element-plus.mjs \
 *     --outfile=/tmp/verify-mock-fallback.mjs
 *   node /tmp/verify-mock-fallback.mjs
 *
 * 覆盖场景：
 *   1. 忘补 mock 端点（抛 MockApiError 404）+ fallback → 返回 fallback 数据
 *   2. 忘补 mock 端点 + 无 fallback → 抛 404 包装错误（页面分支可复用）
 *   3. 正常 mock 端点（已实现）→ 返回真实 mock 数据（不破坏既有端点）
 */
import { request } from '../src/api/axios'

// node 环境无 localStorage，注入最小 stub（axios 层仅在调用时读取）
globalThis.localStorage ??= {
  getItem: () => null,
  setItem: () => {},
  removeItem: () => {},
} as Storage

const FALLBACK = { from: 'fallback', ok: true } as never

type Check = { name: string; pass: boolean; detail?: string }
const checks: Check[] = []
const done = (name: string, pass: boolean, detail?: string): void => {
  checks.push({ name, pass, detail })
}

async function run(): Promise<void> {
  // 1. 忘补 mock 端点 + fallback → 回退
  try {
    const r = (await request({ url: '/api/plm/boms/nonexistent-endpoint', method: 'get' }, FALLBACK)) as { from: string }
    done('MockApiError + fallback 回退到 fallback 数据', r?.from === 'fallback', `got=${JSON.stringify(r)}`)
  } catch (e) {
    done('MockApiError + fallback 回退到 fallback 数据', false, `意外抛错：${String(e)}`)
  }

  // 2. 忘补 mock 端点 + 无 fallback → 抛 404 包装错误
  try {
    await request({ url: '/api/plm/boms/nonexistent-endpoint', method: 'get' })
    done('MockApiError 无 fallback 抛 404 包装错误', false, '未抛错（应 reject）')
  } catch (e) {
    const err = e as { response?: { status: number }; message?: string }
    done('MockApiError 无 fallback 抛 404 包装错误', err?.response?.status === 404, `status=${err?.response?.status} message=${err?.message}`)
  }

  // 3. 正常 mock 端点（已实现）不受影响
  try {
    const r = await request<{ data?: unknown[] }>({ url: '/api/plm/machines', method: 'get', params: { page: 1, page_size: 5 } })
    done('正常 mock 端点返回真实数据', Array.isArray(r?.data), `total=${(r as { total?: number })?.total}`)
  } catch (e) {
    done('正常 mock 端点返回真实数据', false, `意外抛错：${String(e)}`)
  }

  let failed = 0
  for (const c of checks) {
    // eslint-disable-next-line no-console
    console.log(`${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${c.detail ? `  — ${c.detail}` : ''}`)
    if (!c.pass) failed += 1
  }
  // eslint-disable-next-line no-console
  console.log(`\n${checks.length - failed}/${checks.length} 通过`)
  process.exit(failed === 0 ? 0 : 1)
}

run().catch((e) => {
  // eslint-disable-next-line no-console
  console.error('脚本异常：', e)
  process.exit(2)
})
