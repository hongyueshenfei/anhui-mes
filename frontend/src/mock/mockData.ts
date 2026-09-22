/**
 * 模拟数据（后端不可用时兜底展示）
 * 与后端 Seeder 数据同构，保证页面在纯前端模式下依然可演示。
 */
import type {
  Bom,
  Machine,
  Mold,
  OpType,
  PlmRoute,
  PlmWorkshop,
  Product,
  SysRole,
  SysUser,
  RouteOp,
} from '@/types'

export const mockMachines: Machine[] = [
  { id: 1, code: 'I-01', name: '注塑机 1 号', type: 'injection', workshop: 'injection', workshop_id: 1, capacity_per_hour: 400, mold_change_min: 30, shift_mode: 'two_shift', status: 'running', is_active: 1 },
  { id: 2, code: 'I-02', name: '注塑机 2 号', type: 'injection', workshop: 'injection', workshop_id: 1, capacity_per_hour: 400, mold_change_min: 30, shift_mode: 'two_shift', status: 'running', is_active: 1 },
  { id: 3, code: 'I-03', name: '注塑机 3 号', type: 'injection', workshop: 'injection', workshop_id: 1, capacity_per_hour: 350, mold_change_min: 35, shift_mode: 'two_shift', status: 'running', is_active: 1 },
  { id: 4, code: 'I-04', name: '注塑机 4 号', type: 'injection', workshop: 'injection', workshop_id: 1, capacity_per_hour: 350, mold_change_min: 35, shift_mode: 'two_shift', status: 'maintenance', is_active: 1 },
  { id: 13, code: 'B-01', name: '吹塑机 1 号', type: 'blow', workshop: 'blow', workshop_id: 2, capacity_per_hour: 300, mold_change_min: 20, shift_mode: 'two_shift', status: 'running', is_active: 1 },
  { id: 14, code: 'B-02', name: '吹塑机 2 号', type: 'blow', workshop: 'blow', workshop_id: 2, capacity_per_hour: 280, mold_change_min: 25, shift_mode: 'two_shift', status: 'running', is_active: 1 },
  { id: 17, code: 'P-01', name: '喷漆线', type: 'paint_line', workshop: 'paint', workshop_id: 3, capacity_per_hour: 1500, mold_change_min: 0, shift_mode: 'day', status: 'running', is_active: 1 },
  { id: 18, code: 'P-02', name: '效果线', type: 'effect_line', workshop: 'effect', workshop_id: 4, capacity_per_hour: 1500, mold_change_min: 0, shift_mode: 'day', status: 'running', is_active: 1 },
  { id: 19, code: 'P-03', name: '包装线', type: 'pack_line', workshop: 'pack', workshop_id: 5, capacity_per_hour: 3000, mold_change_min: 0, shift_mode: 'day', status: 'running', is_active: 1 },
]

export const mockWorkshops: PlmWorkshop[] = [
  { id: 1, code: 'injection', name: '注塑车间', sort: 1, is_active: 1, remark: '注塑机台车间', machines_count: 4 },
  { id: 2, code: 'blow', name: '吹塑车间', sort: 2, is_active: 1, remark: '吹塑机台车间', machines_count: 2 },
  { id: 3, code: 'paint', name: '喷漆车间', sort: 3, is_active: 1, remark: '喷漆人工线', machines_count: 1 },
  { id: 4, code: 'effect', name: '效果车间', sort: 4, is_active: 1, remark: '效果人工线', machines_count: 1 },
  { id: 5, code: 'pack', name: '包装车间', sort: 5, is_active: 1, remark: '包装人工线', machines_count: 1 },
]

export const mockProducts: Product[] = [
  { id: 1, code: 'P-0001', name: '直口杯 500ml', spec: 'PP 材质 · 口径 8cm', unit: '只', status: 'mass', weight: 22.0, default_mold_id: 1, default_route_id: 1, default_mold: { id: 1, code: 'M-001', name: '直口杯 500ml 注塑模' }, default_route: { id: 1, name: '直口杯注塑工艺', version: 'R1' }, produced_qty: 128000 },
  { id: 2, code: 'P-0002', name: '圆瓶 1L', spec: 'PET 材质 · 扁圆瓶', unit: '只', status: 'mass', weight: 38.5, default_mold_id: 2, default_route_id: 2, default_mold: { id: 2, code: 'M-002', name: '圆瓶 1L 吹塑模' }, default_route: { id: 2, name: '圆瓶吹塑工艺', version: 'R1' }, produced_qty: 86500 },
  { id: 3, code: 'P-0003', name: '方盒 800ml', spec: 'PP 材质 · 带盖', unit: '只', status: 'trial', weight: 45.0, default_mold_id: 3, default_route_id: 3, default_mold: { id: 3, code: 'M-003', name: '方盒 800ml 注塑模' }, default_route: { id: 3, name: '方盒注塑工艺', version: 'R1' }, produced_qty: 5200 },
  { id: 4, code: 'P-0004', name: '提手桶 5L', spec: 'PE 材质 · 带提手', unit: '只', status: 'developing', weight: 120.0, default_mold_id: 4, default_route_id: 4, default_mold: { id: 4, code: 'M-004', name: '提手桶 5L 吹塑模' }, default_route: { id: 4, name: '提手桶吹塑工艺', version: 'R1' }, produced_qty: 0 },
  { id: 5, code: 'P-0005', name: '喷雾瓶 300ml', spec: 'PP 材质 · 泵头款', unit: '只', status: 'developing', weight: 18.0, default_mold_id: 5, default_route_id: 5, default_mold: { id: 5, code: 'M-005', name: '喷雾瓶 300ml 注塑模' }, default_route: { id: 5, name: '喷雾瓶注塑工艺', version: 'R1' }, produced_qty: 0 },
]

/** 工序类型字典（demo 兜底：5 个默认类型，sort 1..5 连续） */
export const mockOpTypes: OpType[] = [
  { id: 1, op_type: 'molding_injection', op_name: '注塑成型', is_molding: true, machine_type: 'injection', workshop: 'injection', sort: 1, is_active: true, remark: '注塑机台成型' },
  { id: 2, op_type: 'molding_blow', op_name: '吹塑成型', is_molding: true, machine_type: 'blow', workshop: 'blow', sort: 2, is_active: true, remark: '吹塑机台成型' },
  { id: 3, op_type: 'paint', op_name: '喷漆', is_molding: false, machine_type: null, workshop: 'paint', sort: 3, is_active: true, remark: '喷漆人工线' },
  { id: 4, op_type: 'effect', op_name: '效果处理', is_molding: false, machine_type: null, workshop: 'effect', sort: 4, is_active: true, remark: '效果人工线' },
  { id: 5, op_type: 'pack', op_name: '包装', is_molding: false, machine_type: null, workshop: 'pack', sort: 5, is_active: true, remark: '包装人工线' },
]

export const mockBoms: Bom[] = [
  { id: 1, product_id: 1, version: 'V1.0', status: 'draft', k3_push_status: 'none', published_at: null, remark: '草稿（种子数据）', lines_count: 2 },
  { id: 2, product_id: 1, version: 'V2.0', status: 'published', k3_push_status: 'none', published_at: '2025-01-06 09:00:00', remark: '发布（种子数据）', lines_count: 2 },
  { id: 3, product_id: 2, version: 'V2.0', status: 'published', k3_push_status: 'none', published_at: '2025-01-06 09:00:00', remark: '发布（种子数据）', lines_count: 2 },
]

export const mockBomLines = [
  { seq: 1, item_code: 'RAW-PP-001', item_name: 'PP 原料 聚丙烯', qty: 1.02, unit: 'kg', loss_rate: 2.0, is_key: 1 as const },
  { seq: 2, item_code: 'PKG-BOX-001', item_name: '中号彩盒', qty: 1.0, unit: '个', loss_rate: 0.5, is_key: 0 as const },
]

export const mockRoutes: PlmRoute[] = [
  { id: 1, name: '直口杯注塑工艺', version: 'R1', status: 'published', remark: '初始工艺（种子数据）', ops_count: 4 },
  { id: 2, name: '圆瓶吹塑工艺', version: 'R1', status: 'published', remark: '初始工艺（种子数据）', ops_count: 4 },
  { id: 3, name: '方盒注塑工艺', version: 'R1', status: 'published', remark: '初始工艺（种子数据）', ops_count: 4 },
  { id: 4, name: '收纳箱注塑工艺', version: 'D1', status: 'draft', remark: '草稿（种子数据）', ops_count: 4 },
]

export const mockRouteOps: RouteOp[] = [
  { seq: 1, op_type: 'molding_injection', op_name: '注塑成型', workshop_id: 1, mold_change_min: 30, shift_mode: 'two_shift', machine_type: 'injection', rate_key: 'molding' },
  { seq: 2, op_type: 'paint', op_name: '喷漆', workshop_id: 3, mold_change_min: null, shift_mode: 'day', machine_type: null, rate_key: 'paint' },
  { seq: 3, op_type: 'effect', op_name: '效果处理', workshop_id: 4, mold_change_min: null, shift_mode: 'day', machine_type: null, rate_key: 'effect' },
  { seq: 4, op_type: 'pack', op_name: '包装', workshop_id: 5, mold_change_min: null, shift_mode: 'day', machine_type: null, rate_key: 'pack' },
]

export const mockMolds: Mold[] = [
  { id: 1, code: 'M-001', name: '直口杯 500ml 注塑模', type: 'injection', status: 'in_use', location: 'A区-01', cavity_count: 4, lifetime_openings: 500000, opened_count: 35600, purchase_cost: 88000, purchase_date: '2023-06-15', vendor: '浙江精工模具', maintain_cycle_days: 30, last_maintain_at: '2025-01-10 10:00:00', next_maintain_at: '2025-02-09 10:00:00', remaining_openings: 464400, maintain_due: false, life_used_pct: 7.1, machines: [{ id: 1, code: 'I-01', name: '注塑机 1 号' }, { id: 2, code: 'I-02', name: '注塑机 2 号' }] },
  { id: 2, code: 'M-002', name: '圆瓶 1L 吹塑模', type: 'blow', status: 'in_use', location: 'A区-02', cavity_count: 2, lifetime_openings: 300000, opened_count: 120000, purchase_cost: 65000, purchase_date: '2023-09-01', vendor: '宁波吹塑模具', maintain_cycle_days: 45, last_maintain_at: '2025-01-01 10:00:00', next_maintain_at: '2025-02-15 10:00:00', remaining_openings: 180000, maintain_due: false, life_used_pct: 40.0, machines: [{ id: 5, code: 'B-01', name: '吹塑机 1 号' }] },
  { id: 3, code: 'M-003', name: '方盒 800ml 注塑模', type: 'injection', status: 'idle', location: 'B区-03', cavity_count: 4, lifetime_openings: 400000, opened_count: 8000, purchase_cost: 72000, purchase_date: '2024-02-20', vendor: '浙江精工模具', maintain_cycle_days: 30, last_maintain_at: null, next_maintain_at: null, remaining_openings: 392000, maintain_due: false, life_used_pct: 2.0, machines: [] },
  { id: 4, code: 'M-004', name: '提手桶 5L 吹塑模', type: 'blow', status: 'repair', location: '维修区', cavity_count: 1, lifetime_openings: 200000, opened_count: 198000, purchase_cost: 58000, purchase_date: '2022-11-10', vendor: '宁波吹塑模具', maintain_cycle_days: 30, last_maintain_at: null, next_maintain_at: null, remaining_openings: 2000, maintain_due: false, life_used_pct: 99.0, machines: [] },
  { id: 5, code: 'M-005', name: '喷雾瓶 300ml 注塑模', type: 'injection', status: 'in_use', location: 'A区-05', cavity_count: 6, lifetime_openings: 600000, opened_count: 45000, purchase_cost: 96000, purchase_date: '2024-07-01', vendor: '广东塑模科技', maintain_cycle_days: 25, last_maintain_at: '2025-01-20 10:00:00', next_maintain_at: '2025-02-14 10:00:00', remaining_openings: 555000, maintain_due: false, life_used_pct: 7.5, machines: [] },
]

export const mockUsers: SysUser[] = [
  { id: 1, employee_no: 'admin', name: '系统管理员', phone: '13800000000', job_type: 'admin', status: 1, workshop_id: null, wecom_userid: null, wx_openid: null, wecom_bound: false, wx_bound: false, roles: ['admin'] },
  { id: 2, employee_no: 'E001', name: '张工', phone: '13900000001', job_type: 'engineer', status: 1, workshop_id: null, workshop_name: null, wecom_userid: 'wecom_zhang', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['engineer'] },
  { id: 3, employee_no: 'P001', name: '李计划', phone: '13900000002', job_type: 'planner', status: 1, workshop_id: null, wecom_userid: 'wecom_li', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['planner'] },
  { id: 4, employee_no: 'L001', name: '王班长', phone: '13900000003', job_type: 'leader', status: 1, workshop_id: 1, workshop_name: '注塑车间', wecom_userid: 'wecom_wang', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['leader'] },
  { id: 13, employee_no: 'T001', name: '陈统计', phone: '13900000013', job_type: 'statistician', status: 1, workshop_id: null, workshop_name: null, wecom_userid: 'wecom_statistician', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['statistician'] },
  { id: 5, employee_no: 'W001', name: '赵师傅', phone: '13900000004', job_type: 'worker', status: 1, workshop_id: 1, workshop_name: '注塑车间', wecom_userid: 'wecom_zhao', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['worker'] },
  { id: 6, employee_no: 'S001', name: '孙业务', phone: '13900000006', job_type: 'sales', status: 1, workshop_id: null, wecom_userid: 'wecom_sun', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['sales'] },
]

export const mockRoles: SysRole[] = [
  { id: 1, name: 'admin', guard_name: 'web', permissions: ['system.user.manage', 'system.role.manage', 'system.config.manage', 'audit.view', 'plm.machine.manage', 'plm.machine.view', 'plm.product.manage', 'plm.product.view', 'plm.bom.manage', 'plm.bom.view', 'plm.bom.publish', 'plm.route.manage', 'plm.route.view', 'plm.mold.manage', 'plm.mold.view'] },
  { id: 2, name: 'engineer', guard_name: 'web', permissions: ['plm.machine.manage', 'plm.machine.view', 'plm.product.manage', 'plm.product.view', 'plm.bom.manage', 'plm.bom.view', 'plm.bom.publish', 'plm.route.manage', 'plm.route.view', 'plm.mold.manage', 'plm.mold.view'] },
  { id: 3, name: 'planner', guard_name: 'web', permissions: ['plm.machine.view', 'plm.product.view', 'plm.bom.view', 'plm.route.view', 'plm.mold.view'] },
  { id: 4, name: 'leader', guard_name: 'web', permissions: ['plm.machine.view', 'plm.product.view', 'plm.bom.view', 'plm.route.view', 'plm.mold.view'] },
  { id: 5, name: 'worker', guard_name: 'web', permissions: ['plm.machine.view', 'plm.product.view'] },
  { id: 6, name: 'sales', guard_name: 'web', permissions: ['plm.product.view', 'plm.mold.view'] },
]

export const mockAuditLogs = [
  { id: 1, user_id: 2, user: { id: 2, employee_no: 'E001', name: '张工' }, module: 'plm', action: 'publish_bom', target_type: 'bom', target_id: 2, detail: { product_id: 1, version: 'V2.0' }, ip: '127.0.0.1', created_at: '2025-01-06 09:00:00' },
  { id: 2, user_id: 1, user: { id: 1, employee_no: 'admin', name: '系统管理员' }, module: 'system', action: 'user_create', target_type: 'system', target_id: 7, detail: { method: 'POST' }, ip: '127.0.0.1', created_at: '2025-01-05 14:30:00' },
]
