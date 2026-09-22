import { request } from './axios'
import type { ApiResponse, AuthUser, LoginResult } from '@/types'

/**
 * 认证 API（对应后端 app/Modules/Auth）
 */
export const authApi = {
  /** 账号密码登录（PC 回退通道） */
  login(employeeNo: string, password: string): Promise<LoginResult> {
    return request<LoginResult>({
      url: '/auth/login',
      method: 'post',
      data: { employee_no: employeeNo, password },
    })
  },

  /** 企微扫码登录（一期骨架；后端未配置时返回 501 自动回退账号密码） */
  async wecomLogin(code: string): Promise<ApiResponse<never>> {
    return request({
      url: '/auth/wecom-login',
      method: 'post',
      data: { code },
    })
  },

  /** 当前用户信息（角色 + 权限） */
  me(): Promise<AuthUser> {
    return request<AuthUser>({ url: '/auth/me', method: 'get' })
  },

  /** 登出 */
  logout(): Promise<null> {
    return request<null>({ url: '/auth/logout', method: 'post' })
  },

  /** 绑定工号（企微/微信首次扫码后） */
  bindEmployee(payload: { channel: 'wecom' | 'wx'; code?: string; openid?: string; employee_no: string }): Promise<LoginResult> {
    return request<LoginResult>({ url: '/auth/bind-employee', method: 'post', data: payload })
  },
}