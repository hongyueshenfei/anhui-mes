import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { AuthUser } from '@/types'
import { authApi } from '@/api/auth'

/**
 * 认证状态：Token + 用户信息（角色/权限）
 * 权限驱动侧边菜单与按钮显隐。
 */
export const useAuthStore = defineStore('auth', () => {
  const token = ref<string>(localStorage.getItem('hy_token') || '')
  const user = ref<AuthUser | null>(JSON.parse(localStorage.getItem('hy_user') || 'null'))

  const isLoggedIn = computed(() => !!token.value)
  const permissions = computed<string[]>(() => user.value?.permissions ?? [])
  const roles = computed<string[]>(() => user.value?.roles ?? [])

  /** 当前阶段报工操作人：班组长/统计员；管理员保留维护与更正能力。 */
  const canOperateReports = computed(() => {
    const allowed = ['admin', 'leader', 'statistician']
    return (allowed.includes(user.value?.job_type ?? '') || roles.value.some((role) => allowed.includes(role)))
      && can('report.manage')
  })

  /** 是否拥有权限（超级管理员 admin 角色全通） */
  function can(permission: string): boolean {
    if (roles.value.includes('admin')) return true
    return permissions.value.includes(permission)
  }

  /** 登录后落盘 */
  function setSession(newToken: string, newUser: AuthUser): void {
    token.value = newToken
    user.value = newUser
    localStorage.setItem('hy_token', newToken)
    localStorage.setItem('hy_user', JSON.stringify(newUser))
  }

  /** 从后端拉取最新用户信息；返回是否成功，失败时保留会话供页面提示重试 */
  async function refreshMe(): Promise<boolean> {
    if (!token.value) return false
    try {
      user.value = await authApi.me()
      localStorage.setItem('hy_user', JSON.stringify(user.value))
      return true
    } catch {
      // 后端暂不可用：保留本地会话，但不切换数据源
      return false
    }
  }

  /** 登出 */
  async function logout(): Promise<void> {
    try {
      await authApi.logout()
    } catch {
      // 后端不可用也照常登出
    }
    token.value = ''
    user.value = null
    localStorage.removeItem('hy_token')
    localStorage.removeItem('hy_user')
  }

  return { token, user, isLoggedIn, permissions, roles, can, canOperateReports, setSession, refreshMe, logout }
})
