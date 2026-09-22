import { request } from './axios'
import type { AuditLog, PageResult, SysRole, SysUser } from '@/types'
import type { IntegrationLog } from '@/types/production'
import type { UserOption } from '@/types/orderEdit'
import { mockAuditLogs, mockRoles, mockUsers } from '@/mock/mockData'

/**
 * 系统管理 API（对应后端 app/Modules/System）
 */
export const systemApi = {
  // ============ 用户 ============
  listUsers(params?: Record<string, unknown>): Promise<PageResult<SysUser> | SysUser[]> {
    return request({ url: '/system/users', method: 'get', params }, mockUsers as unknown as PageResult<SysUser>)
  },
  /** 负责人候选（订单编辑弹窗「负责人」下拉；可选按车间过滤） */
  listUserOptions(params?: { workshop_id?: number }): Promise<UserOption[]> {
    return request({ url: '/system/users/options', method: 'get', params }, mockUsers
      .filter((u) => u.id > 1)
      .slice(0, 5)
      .map((u) => ({ id: u.id, name: u.name, employee_no: u.employee_no, workshop_id: u.workshop_id ?? null, workshop_name: u.workshop_name ?? null })))
  },
  createUser(data: Partial<SysUser> & { password?: string }): Promise<SysUser> {
    return request({ url: '/system/users', method: 'post', data })
  },
  updateUser(id: number, data: Partial<SysUser> & { password?: string }): Promise<SysUser> {
    return request({ url: `/system/users/${id}`, method: 'put', data })
  },
  deleteUser(id: number): Promise<null> {
    return request({ url: `/system/users/${id}`, method: 'delete' })
  },
  assignRoles(id: number, roles: string[]): Promise<{ roles: string[] }> {
    return request({ url: `/system/users/${id}/roles`, method: 'post', data: { roles } })
  },
  resetPassword(id: number, password: string): Promise<null> {
    return request({ url: `/system/users/${id}/reset-password`, method: 'post', data: { password } })
  },

  // ============ 角色 ============
  listRoles(): Promise<SysRole[]> {
    return request({ url: '/system/roles', method: 'get' }, mockRoles)
  },
  createRole(data: { name: string; permissions: string[] }): Promise<{ id: number; name: string }> {
    return request({ url: '/system/roles', method: 'post', data })
  },
  updateRole(id: number, data: { name?: string; permissions: string[] }): Promise<null> {
    return request({ url: `/system/roles/${id}`, method: 'put', data })
  },
  deleteRole(id: number): Promise<null> {
    return request({ url: `/system/roles/${id}`, method: 'delete' })
  },

  // ============ 审计日志 ============
  listAuditLogs(params?: Record<string, unknown>): Promise<PageResult<AuditLog> | AuditLog[]> {
    return request({ url: '/system/audit-logs', method: 'get', params }, mockAuditLogs as unknown as PageResult<AuditLog>)
  },
}
/**
 * 四期：集成日志（推送留痕/对账）
 */
export const systemApiV4 = {
  listIntegrationLogs(params?: Record<string, unknown>): Promise<PageResult<IntegrationLog> | IntegrationLog[]> {
    return request({ url: '/system/integration-logs', method: 'get', params }, [])
  },
}
