import axios, { type AxiosInstance, type AxiosRequestConfig } from 'axios'
import { ElMessage } from 'element-plus'
import type { ApiResponse } from '@/types'
import { isDemoMode } from '@/config/demo'
import { mockRequest } from '@/mock/router'
import { MockApiError } from '@/mock/store'

type ErrorBody = {
  message?: unknown
  data?: { errors?: unknown } | null
  errors?: unknown
}

/**
 * 提取后端可读错误原因，兼容统一响应、Laravel 校验错误和本地 mock 错误。
 * 页面可以用它保留业务错误，而不是把所有失败都显示成同一句话。
 */
export function getApiErrorMessage(error: unknown, fallback = '请求失败'): string {
  const candidate = error as {
    message?: unknown
    response?: { data?: ErrorBody }
  }
  const body = candidate.response?.data
  const message = typeof body?.message === 'string' && body.message.trim()
    ? body.message.trim()
    : typeof candidate.message === 'string' && candidate.message.trim()
      ? candidate.message.trim()
      : ''
  const details = formatErrorDetails(body?.data?.errors ?? body?.errors)

  if (message && details.length > 0 && !details.some((detail) => message.includes(detail))) {
    return `${message}：${details.join('；')}`
  }
  return message || details.join('；') || fallback
}

function formatErrorDetails(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value.flatMap((item) => formatErrorDetails(item))
  }
  if (typeof value === 'string' && value.trim()) {
    return [value.trim()]
  }
  if (value && typeof value === 'object') {
    const item = value as { message?: unknown }
    if (typeof item.message === 'string' && item.message.trim()) {
      return [item.message.trim()]
    }
    return Object.entries(value).flatMap(([key, itemValue]) => {
      const details = formatErrorDetails(itemValue)
      return details.map((detail) => `${key}：${detail}`)
    })
  }
  return []
}

/**
 * axios 实例：
 * - baseURL /api（vite dev 代理 → Laravel 8010）
 * - 请求拦截：注入 Bearer Token
 * - 响应拦截：统一解包 {code, message, data}；401 自动登出跳登录
 */
const http: AxiosInstance = axios.create({
  baseURL: '/api',
  timeout: 15000,
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem('hy_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

http.interceptors.response.use(
  (response) => {
    const body = response.data as ApiResponse
    // 登录等通道返回 need_bind 时 code=0 正常放行
    if (body && typeof body.code === 'number' && body.code !== 0) {
      ElMessage.error(body.message || '请求失败')
      return Promise.reject(new Error(body.message || '业务错误'))
    }
    return response
  },
  (error) => {
    const status = error.response?.status
    const message = error.response?.data?.message || error.message || '网络请求失败'

    if (status === 401) {
      localStorage.removeItem('hy_token')
      localStorage.removeItem('hy_user')
      if (!location.pathname.startsWith('/login')) {
        ElMessage.warning('登录已过期，请重新登录')
        location.href = '/login'
      }
    } else if (status === 403) {
      ElMessage.error(message || '无权访问该资源')
    } else if (status >= 500 || !error.response) {
      console.warn('[HY] 后端请求失败：', message)
    } else {
      ElMessage.error(message)
    }
    return Promise.reject(error)
  },
)

/**
 * 通用请求：解包真实后端 data；fallback 仅供显式演示模式使用
 *
 * 演示模式（isDemoMode()）：
 * 不发起真实网络请求，直接由本地 mock 路由（src/mock/router.ts）响应，
 * 写操作在内存库生效；仅在显式设置 VITE_DEMO_MODE=1 时使用此通道。
 */
export async function request<T>(config: AxiosRequestConfig, fallback?: T): Promise<T> {
  if (isDemoMode()) {
    try {
      return await mockRequest<T>(config)
    } catch (e) {
      if (e instanceof MockApiError) {
        // 显式演示模式下，未补齐 mock 端点时允许使用 fallback 数据
        if (fallback !== undefined) {
          return fallback
        }
        // 包装为与真实 axios 错误一致的结构，页面 409/404 等分支可复用
        const err = new Error(e.message) as Error & {
          response?: { status: number; data: { code: number; message: string; data: unknown } }
        }
        err.response = { status: e.status, data: { code: e.code, message: e.message, data: e.bizData } }
        throw err
      }
      if (fallback !== undefined) {
        return fallback
      }
      throw e
    }
  }
  const response = await http.request<ApiResponse<T>>(config)
  return response.data.data
}

export default http
