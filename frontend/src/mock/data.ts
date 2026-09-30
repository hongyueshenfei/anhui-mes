/**
 * 演示模式 · 模拟数据工厂（与后端 Seeder/API 契约同构）
 *
 * 所有日期均以「今天」为基准动态生成：逾期、临期、热力、报工台账
 * 在任何一天打开演示都呈现真实节奏（未来 7 天排产窗口、昨日/今日报工）。
 *
 * 覆盖：一期（机台/班组/产品/BOM/工艺/模具/用户）+ 二期（订单/任务池/排程/版本）
 *      + 三期（报工台账/入库单）+ 四期（红灯四源/产能热力/报表/工作台）。
 */
import dayjs, { type Dayjs } from 'dayjs'
import type { AuditLog, Bom, BomLine, Machine, Mold, OpType, PlmRoute, PlmWorkshop, Product, RouteOp, SysRole, SysUser } from '@/types'
import type { Order, OrderLine, PoolTask, ProdTask, ScheduleSuggestion, ScheduleVersion, TaskSchedule } from '@/types/schedule'
import type { IntegrationLog, ProductionException, StockIn, WorkReport } from '@/types/production'
import type { DevelopTask } from '@/types/develop'
import type { MaterialPrice, ProcessRate, Quote, QuoteLine, QuoteRuleItem } from '@/types/quote'
import type { HeatmapRow, LoadHeatmapData, RedLight, ReportsData, WorkbenchData, OrderProgress, OrderOverviewData, WorkbenchStats } from '@/types/dashboard'

// ==================== 时间工具 ====================

/** 相对今天的日期工具（演示数据统一口径） */
export const day = (offset: number): string => dayjs().add(offset, 'day').format('YYYY-MM-DD')
/** 相对今天的 ISO 时间（保留小时） */
export const dayTime = (offset: number, hour: number, minute = 0): string => {
  const d = dayjs().add(offset, 'day').hour(hour).minute(minute).second(0)
  return d.format('YYYY-MM-DD HH:mm:ss')
}
/** 相对今天的 ISO 日期时间（保留小时） */
export const dayHour = (offset: number, hour: number): string => dayjs().add(offset, 'day').hour(hour).minute(0).second(0).format('YYYY-MM-DD HH:mm:ss')

export const ORDER_NO = (i: number): string => `SO${dayjs().format('YYYYMMDD')}-${String(i).padStart(3, '0')}`
export const TASK_NO = (i: number): string => `T-${dayjs().format('YYYYMMDD')}-${String(i).padStart(3, '0')}`
export const INS_NO = (i: number): string => `RK-${dayjs().format('YYYYMMDD')}-${String(i).padStart(2, '0')}`

const RESOURCE_TYPE_BY_OP: Record<string, NonNullable<PoolTask['required_resource_type']>> = {
  molding_injection: 'injection',
  molding_blow: 'blow',
  paint: 'paint_line',
  effect: 'effect_line',
  pack: 'pack_line',
}

const RESOURCE_LABEL_BY_TYPE: Record<NonNullable<PoolTask['required_resource_type']>, string> = {
  injection: '注塑机',
  blow: '吹塑机',
  paint_line: '喷漆产线',
  effect_line: '效果产线',
  pack_line: '包装产线',
}

/** 任务池首道工序资源标识，与后端 RouteOp::resourceType() 同构。 */
export function poolResourceForOp(op?: RouteOp): Pick<PoolTask, 'required_op_type' | 'required_op_name' | 'required_resource_type' | 'required_resource_label'> {
  const resourceType = op ? RESOURCE_TYPE_BY_OP[op.op_type] ?? op.machine_type ?? null : null
  return {
    required_op_type: op?.op_type ?? null,
    required_op_name: op?.op_name ?? null,
    required_resource_type: resourceType,
    required_resource_label: resourceType ? RESOURCE_LABEL_BY_TYPE[resourceType] : '未配置首道工序',
  }
}

// ==================== 机台/产线（9 台/线） ====================

function buildMachines(): Machine[] {
  const machines: Machine[] = []
  // 注塑 I-01 ~ I-04（I-04 维修中，其余运行）
  const injCaps = [400, 420, 380, 420]
  for (let i = 1; i <= 4; i += 1) {
    machines.push({
      id: i,
      code: `I-${String(i).padStart(2, '0')}`,
      name: `注塑机 ${i} 号`,
      type: 'injection',
      workshop: 'injection',
      workshop_name: '注塑车间',
      workshop_id: 1,
      capacity_per_hour: injCaps[i - 1],
      mold_change_min: 25 + ((i * 7) % 25),
      shift_mode: 'two_shift',
      status: i === 4 ? 'maintenance' : 'running',
      is_active: 1,
      created_at: '2023-01-01 09:00:00',
    })
  }
  // 吹塑 B-01 ~ B-02
  const blowCaps = [300, 280]
  for (let i = 1; i <= 2; i += 1) {
    machines.push({
      id: 12 + i,
      code: `B-${String(i).padStart(2, '0')}`,
      name: `吹塑机 ${i} 号`,
      type: 'blow',
      workshop: 'blow',
      workshop_name: '吹塑车间',
      workshop_id: 2,
      capacity_per_hour: blowCaps[i - 1],
      mold_change_min: 15 + i * 5,
      shift_mode: 'two_shift',
      status: 'running',
      is_active: 1,
      created_at: '2023-02-01 09:00:00',
    })
  }
  // 产线（客户确认「资源强对应」）：喷漆、效果、包装各保留 1 条
  const lines = [
    { id: 17, code: 'P-01', name: '喷漆线', workshop: 'paint', workshopName: '喷漆车间', workshopId: 3, cap: 1500 },
    { id: 18, code: 'P-02', name: '效果线', workshop: 'effect', workshopName: '效果车间', workshopId: 4, cap: 1500 },
    { id: 19, code: 'P-03', name: '包装线', workshop: 'pack', workshopName: '包装车间', workshopId: 5, cap: 3000 },
  ] as const
  for (const m of lines) {
    machines.push({
      id: m.id,
      code: m.code,
      name: m.name,
      type: m.code === 'P-03' ? 'pack_line' : m.code === 'P-02' ? 'effect_line' : 'paint_line',
      workshop: m.workshop,
      workshop_name: m.workshopName,
      workshop_id: m.workshopId,
      capacity_per_hour: m.cap,
      mold_change_min: 0,
      shift_mode: 'day',
      status: 'running',
      is_active: 1,
      created_at: '2023-03-01 09:00:00',
    })
  }
  return machines
}

// ==================== 车间与工人（12 名示例 / 60 名总数） ====================

function buildWorkshops(): PlmWorkshop[] {
  return [
    { id: 1, code: 'injection', name: '注塑车间', sort: 1, is_active: 1, remark: '注塑机台车间', machines_count: 4 },
    { id: 2, code: 'blow', name: '吹塑车间', sort: 2, is_active: 1, remark: '吹塑机台车间', machines_count: 2 },
    { id: 3, code: 'paint', name: '喷漆车间', sort: 3, is_active: 1, remark: '喷漆人工线', machines_count: 1 },
    { id: 4, code: 'effect', name: '效果车间', sort: 4, is_active: 1, remark: '效果人工线', machines_count: 1 },
    { id: 5, code: 'pack', name: '包装车间', sort: 5, is_active: 1, remark: '包装人工线', machines_count: 1 },
  ]
}

/** 示例工人 12 名（各车间 2 名；全厂 60 名 = 总数口径） */
function buildWorkers(): SysUser[] {
  const workers: SysUser[] = []
  const names = ['赵师傅', '钱师傅', '孙师傅', '李师傅', '周师傅', '吴师傅', '郑师傅', '冯师傅', '褚师傅', '卫师傅', '蒋师傅', '沈师傅']
  const workshopIds: Array<number | null> = [1, 1, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5]
  const workshopNames = ['注塑车间', '注塑车间', '注塑车间', '注塑车间', '吹塑车间', '吹塑车间', '喷漆车间', '喷漆车间', '效果车间', '效果车间', '包装车间', '包装车间']
  names.forEach((name, idx) => {
    workers.push({
      id: 20 + idx,
      employee_no: `W${String(idx + 1).padStart(3, '0')}`,
      name,
      phone: `1390000${String(1000 + idx).slice(1)}`,
      job_type: 'worker',
      status: 1,
      workshop_id: workshopIds[idx],
      workshop_name: workshopIds[idx] ? workshopNames[idx] : null,
      wecom_userid: `wecom_worker_${idx + 1}`,
      wx_openid: null,
      wecom_bound: true,
      wx_bound: false,
      roles: ['worker'],
    })
  })
  return workers
}

function buildUsers(): SysUser[] {
  return [
    { id: 1, employee_no: 'admin', name: '系统管理员', phone: '13800000000', job_type: 'admin', status: 1, workshop_id: null, wecom_userid: null, wx_openid: null, wecom_bound: false, wx_bound: false, roles: ['admin'] },
    { id: 2, employee_no: 'E001', name: '张工', phone: '13900000001', job_type: 'engineer', status: 1, workshop_id: null, workshop_name: null, wecom_userid: 'wecom_zhang', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['engineer'] },
    { id: 3, employee_no: 'P001', name: '李计划', phone: '13900000002', job_type: 'planner', status: 1, workshop_id: null, workshop_name: null, wecom_userid: 'wecom_li', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['planner'] },
    { id: 4, employee_no: 'L001', name: '王班长', phone: '13900000003', job_type: 'leader', status: 1, workshop_id: 1, workshop_name: '注塑车间', wecom_userid: 'wecom_wang', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['leader'] },
    { id: 13, employee_no: 'T001', name: '陈统计', phone: '13900000013', job_type: 'statistician', status: 1, workshop_id: null, workshop_name: null, wecom_userid: 'wecom_statistician', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['statistician'] },
    { id: 5, employee_no: 'W001', name: '赵师傅', phone: '13900000004', job_type: 'worker', status: 1, workshop_id: 1, workshop_name: '注塑车间', wecom_userid: 'wecom_zhao', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['worker'] },
    { id: 6, employee_no: 'S001', name: '孙业务', phone: '13900000006', job_type: 'sales', status: 1, workshop_id: null, workshop_name: null, wecom_userid: 'wecom_sun', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['sales'] },
    { id: 7, employee_no: 'L002', name: '陈班长', phone: '13900000007', job_type: 'leader', status: 1, workshop_id: 1, workshop_name: '注塑车间', wecom_userid: 'wecom_chen', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['leader'] },
    { id: 8, employee_no: 'L003', name: '刘班长', phone: '13900000008', job_type: 'leader', status: 1, workshop_id: 2, workshop_name: '吹塑车间', wecom_userid: 'wecom_liu', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['leader'] },
    { id: 9, employee_no: 'L004', name: '周组长', phone: '13900000009', job_type: 'leader', status: 1, workshop_id: 3, workshop_name: '喷漆车间', wecom_userid: 'wecom_zhou', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['leader'] },
    { id: 10, employee_no: 'L005', name: '吴组长', phone: '13900000010', job_type: 'leader', status: 1, workshop_id: 4, workshop_name: '效果车间', wecom_userid: 'wecom_wu', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['leader'] },
    { id: 11, employee_no: 'L006', name: '郑组长', phone: '13900000011', job_type: 'leader', status: 1, workshop_id: 5, workshop_name: '包装车间', wecom_userid: 'wecom_zheng', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['leader'] },
    { id: 12, employee_no: 'P002', name: '高计划', phone: '13900000012', job_type: 'planner', status: 1, workshop_id: null, workshop_name: null, wecom_userid: 'wecom_gao', wx_openid: null, wecom_bound: true, wx_bound: false, roles: ['planner'] },
  ]
}

function buildRoles(): SysRole[] {
  const allPerms = [
    'system.user.manage', 'system.role.manage', 'system.config.manage', 'audit.view',
    'plm.machine.manage', 'plm.machine.view',
    'plm.product.manage', 'plm.product.view', 'plm.bom.manage', 'plm.bom.view', 'plm.bom.publish',
    'plm.route.manage', 'plm.route.view', 'plm.mold.manage', 'plm.mold.view',
    'order.view', 'order.manage', 'schedule.view', 'schedule.manage',
    'report.view', 'report.manage', 'warehouse.view', 'warehouse.manage',
    'dashboard.view', 'dashboard.manage', 'integration.view', 'integration.manage',
  ]
  return [
    { id: 1, name: 'admin', guard_name: 'web', permissions: allPerms },
    { id: 2, name: 'engineer', guard_name: 'web', permissions: ['plm.machine.manage', 'plm.machine.view', 'plm.product.manage', 'plm.product.view', 'plm.bom.manage', 'plm.bom.view', 'plm.bom.publish', 'plm.route.manage', 'plm.route.view', 'plm.mold.manage', 'plm.mold.view'] },
    { id: 3, name: 'planner', guard_name: 'web', permissions: ['plm.machine.view', 'plm.product.view', 'plm.bom.view', 'plm.route.view', 'plm.mold.view', 'order.view', 'order.manage', 'schedule.view', 'schedule.manage', 'dashboard.view', 'integration.view'] },
    { id: 4, name: 'leader', guard_name: 'web', permissions: ['plm.machine.view', 'plm.product.view', 'plm.bom.view', 'plm.route.view', 'plm.mold.view', 'schedule.view', 'report.view', 'report.manage', 'warehouse.view', 'dashboard.view'] },
    { id: 5, name: 'statistician', guard_name: 'web', permissions: ['plm.machine.view', 'plm.product.view', 'order.view', 'schedule.view', 'report.view', 'report.manage', 'warehouse.view', 'dashboard.view'] },
    { id: 6, name: 'worker', guard_name: 'web', permissions: ['plm.machine.view', 'plm.product.view'] },
    { id: 7, name: 'sales', guard_name: 'web', permissions: ['plm.product.view', 'plm.mold.view', 'order.view', 'dashboard.view'] },
  ]
}

// ==================== 产品 / BOM / 工艺 / 模具 ====================

function buildProducts(): Product[] {
  return [
    { id: 1, code: 'HY-1001', name: '直口杯 500ml', spec: 'PP 材质 · 口径 8cm', unit: '只', status: 'mass', weight: 22, default_mold_id: 1, default_route_id: 1, default_mold: { id: 1, code: 'M-001', name: '直口杯 500ml 注塑模' }, default_route: { id: 1, name: '直口杯注塑工艺', version: 'R1' }, produced_qty: 128000 },
    { id: 2, code: 'HY-1002', name: '圆瓶 1L', spec: 'PET 材质 · 扁圆瓶', unit: '只', status: 'mass', weight: 38.5, default_mold_id: 2, default_route_id: 2, default_mold: { id: 2, code: 'M-002', name: '圆瓶 1L 吹塑模' }, default_route: { id: 2, name: '圆瓶吹塑工艺', version: 'R1' }, produced_qty: 86500 },
    { id: 3, code: 'HY-1003', name: '方盒 800ml', spec: 'PP 材质 · 带盖', unit: '只', status: 'mass', weight: 45, default_mold_id: 3, default_route_id: 3, default_mold: { id: 3, code: 'M-003', name: '方盒 800ml 注塑模' }, default_route: { id: 3, name: '方盒注塑工艺', version: 'R1' }, produced_qty: 45200 },
    { id: 4, code: 'HY-1004', name: '提手桶 5L', spec: 'PE 材质 · 带提手', unit: '只', status: 'mass', weight: 120, default_mold_id: 4, default_route_id: 4, default_mold: { id: 4, code: 'M-004', name: '提手桶 5L 吹塑模' }, default_route: { id: 4, name: '提手桶吹塑工艺', version: 'R1' }, produced_qty: 38900 },
    { id: 5, code: 'HY-1005', name: '喷雾瓶 300ml', spec: 'PP 材质 · 泵头款', unit: '只', status: 'trial', weight: 18, default_mold_id: 5, default_route_id: 5, default_mold: { id: 5, code: 'M-005', name: '喷雾瓶 300ml 注塑模' }, default_route: { id: 5, name: '喷雾瓶注塑工艺', version: 'R1' }, produced_qty: 6200 },
    { id: 6, code: 'HY-1006', name: '餐盒 900ml', spec: 'PP 材质 · 三格', unit: '只', status: 'mass', weight: 52, default_mold_id: 6, default_route_id: 6, default_mold: { id: 6, code: 'M-006', name: '餐盒 900ml 注塑模' }, default_route: { id: 6, name: '餐盒注塑工艺', version: 'R1' }, produced_qty: 21300 },
    { id: 7, code: 'HY-1007', name: '洗衣液桶 2L', spec: 'HDPE 材质 · 提手', unit: '只', status: 'trial', weight: 85, default_mold_id: 7, default_route_id: 7, default_mold: { id: 7, code: 'M-007', name: '洗衣液桶 2L 吹塑模' }, default_route: { id: 7, name: '洗衣液桶吹塑工艺', version: 'R1' }, produced_qty: 3100 },
    { id: 8, code: 'HY-1008', name: '收纳箱 12L', spec: 'PP 材质 · 带盖', unit: '只', status: 'developing', weight: 210, default_mold_id: 8, default_route_id: 8, default_mold: { id: 8, code: 'M-008', name: '收纳箱 12L 注塑模' }, default_route: { id: 8, name: '收纳箱注塑工艺', version: 'R1' }, produced_qty: 0 },
  ]
}

function buildOpTypes(): OpType[] {
  return [
    { id: 1, op_type: 'molding_injection', op_name: '注塑成型', is_molding: true, machine_type: 'injection', workshop: 'injection', sort: 1, is_active: true, remark: '注塑机台成型' },
    { id: 2, op_type: 'molding_blow', op_name: '吹塑成型', is_molding: true, machine_type: 'blow', workshop: 'blow', sort: 2, is_active: true, remark: '吹塑机台成型' },
    { id: 3, op_type: 'paint', op_name: '喷漆', is_molding: false, machine_type: null, workshop: 'paint', sort: 3, is_active: true, remark: '喷漆人工线' },
    { id: 4, op_type: 'effect', op_name: '效果处理', is_molding: false, machine_type: null, workshop: 'effect', sort: 4, is_active: true, remark: '效果人工线' },
    { id: 5, op_type: 'pack', op_name: '包装', is_molding: false, machine_type: null, workshop: 'pack', sort: 5, is_active: true, remark: '包装人工线' },
  ]
}

function buildBoms(): { boms: Bom[]; lines: Record<number, BomLine[]> } {
  const lines: Record<number, BomLine[]> = {}
  const boms: Bom[] = [
    { id: 1, product_id: 1, version: 'V1.0', status: 'draft', k3_push_status: 'pending', published_at: null, remark: '草稿：材料损耗待复核', lines_count: 3 },
    { id: 2, product_id: 1, version: 'V2.0', status: 'published', k3_push_status: 'success', published_at: dayTime(-10, 9, 0), remark: '已推送金蝶（含 K3 回执）', lines_count: 3 },
    { id: 3, product_id: 2, version: 'V2.0', status: 'published', k3_push_status: 'success', published_at: dayTime(-9, 9, 0), remark: '已推送金蝶', lines_count: 2 },
    { id: 4, product_id: 3, version: 'V1.0', status: 'published', k3_push_status: 'success', published_at: dayTime(-8, 10, 0), remark: '已推送金蝶', lines_count: 2 },
    { id: 5, product_id: 5, version: 'V1.0', status: 'draft', k3_push_status: 'failed', published_at: null, remark: '推送失败：物料编码 RAW-PP-999 未在金蝶备案', lines_count: 2 },
    { id: 6, product_id: 6, version: 'V1.0', status: 'published', k3_push_status: 'success', published_at: dayTime(-6, 14, 0), remark: '已推送金蝶', lines_count: 3 },
  ]
  lines[1] = [
    { seq: 1, item_code: 'RAW-PP-001', item_name: 'PP 树脂（燕山石化）', qty: 0.024, unit: 'kg', loss_rate: 2.0, is_key: 1 },
    { seq: 2, item_code: 'CLR-WHITE-01', item_name: '白色母粒', qty: 0.002, unit: 'kg', loss_rate: 1.0, is_key: 0 },
    { seq: 3, item_code: 'PKG-BOX-001', item_name: '中号彩盒（直口杯）', qty: 1, unit: '个', loss_rate: 0.5, is_key: 0 },
  ]
  lines[2] = [
    { seq: 1, item_code: 'RAW-PET-001', item_name: 'PET 瓶坯', qty: 1.02, unit: '个', loss_rate: 2.0, is_key: 1 },
    { seq: 2, item_code: 'PKG-BOX-002', item_name: '圆瓶彩盒', qty: 1, unit: '个', loss_rate: 0.5, is_key: 0 },
  ]
  lines[3] = [
    { seq: 1, item_code: 'RAW-PP-001', item_name: 'PP 树脂（燕山石化）', qty: 0.05, unit: 'kg', loss_rate: 2.0, is_key: 1 },
    { seq: 2, item_code: 'PKG-BOX-003', item_name: '方盒彩盒', qty: 1, unit: '个', loss_rate: 0.5, is_key: 0 },
  ]
  lines[4] = [
    { seq: 1, item_code: 'RAW-PP-001', item_name: 'PP 树脂（燕山石化）', qty: 0.02, unit: 'kg', loss_rate: 2.0, is_key: 1 },
    { seq: 2, item_code: 'PUMP-300-01', item_name: '300ml 泵头总成', qty: 1, unit: '个', loss_rate: 1.5, is_key: 1 },
  ]
  lines[5] = [
    { seq: 1, item_code: 'RAW-PP-001', item_name: 'PP 树脂（燕山石化）', qty: 0.055, unit: 'kg', loss_rate: 2.0, is_key: 1 },
    { seq: 2, item_code: 'PKG-BOX-004', item_name: '餐盒彩盒', qty: 1, unit: '个', loss_rate: 0.5, is_key: 0 },
    { seq: 3, item_code: 'FILM-12-01', item_name: '封口膜', qty: 1, unit: '张', loss_rate: 1.0, is_key: 0 },
  ]
  return { boms, lines }
}

function buildRoutes(): { routes: PlmRoute[]; ops: Record<number, RouteOp[]> } {
  const ops: Record<number, RouteOp[]> = {}
  const routeNames = ['直口杯注塑工艺', '圆瓶吹塑工艺', '方盒注塑工艺', '提手桶吹塑工艺', '喷雾瓶注塑工艺', '餐盒注塑工艺', '洗衣液桶吹塑工艺', '收纳箱注塑工艺']
  const baseOps: RouteOp[] = [
    { seq: 1, op_type: 'molding_injection', op_name: '注塑成型', workshop_id: 1, mold_change_min: 30, shift_mode: 'two_shift', machine_type: 'injection', rate_key: 'molding' },
    { seq: 2, op_type: 'paint', op_name: '喷漆', workshop_id: 3, mold_change_min: null, shift_mode: 'day', machine_type: null, rate_key: 'paint' },
    { seq: 3, op_type: 'effect', op_name: '效果处理', workshop_id: 4, mold_change_min: null, shift_mode: 'day', machine_type: null, rate_key: 'effect' },
    { seq: 4, op_type: 'pack', op_name: '包装', workshop_id: 5, mold_change_min: null, shift_mode: 'day', machine_type: null, rate_key: 'pack' },
  ]
  const blowOps: RouteOp[] = [
    { seq: 1, op_type: 'molding_blow', op_name: '吹塑成型', workshop_id: 2, mold_change_min: 20, shift_mode: 'two_shift', machine_type: 'blow', rate_key: 'molding' },
    { seq: 2, op_type: 'paint', op_name: '喷漆', workshop_id: 3, mold_change_min: null, shift_mode: 'day', machine_type: null, rate_key: 'paint' },
    { seq: 3, op_type: 'pack', op_name: '包装', workshop_id: 5, mold_change_min: null, shift_mode: 'day', machine_type: null, rate_key: 'pack' },
  ]
  const routes: PlmRoute[] = [1, 2, 3, 4, 5, 6, 7, 8].map((id) => ({
    id,
    name: routeNames[id - 1],
    version: 'R1',
    status: id === 8 ? 'draft' : 'published',
    remark: id === 8 ? '草稿：待发布' : `产品 ${id} 标准工艺`,
    ops_count: id === 2 || id === 4 || id === 7 ? 3 : 4,
    ops: id === 2 || id === 4 || id === 7 ? blowOps.map((o) => ({ ...o })) : baseOps.map((o) => ({ ...o })),
  }))
  routes.forEach((r) => {
    // route_op_id 是排程与工艺路线的外键镜像，不能用 productId/seq 代替。
    ops[r.id] = (r.ops ?? []).map((op) => ({ ...op, id: r.id * 10 + op.seq }))
    r.ops = ops[r.id]
  })
  return { routes, ops }
}

function buildMolds(): Mold[] {
  return [
    { id: 1, code: 'M-001', name: '直口杯 500ml 注塑模', type: 'injection', status: 'in_use', location: 'A区-01', cavity_count: 4, lifetime_openings: 500000, opened_count: 35600, purchase_cost: 88000, purchase_date: '2023-06-15', vendor: '浙江精工模具', maintain_cycle_days: 30, last_maintain_at: dayTime(-12, 10, 0), next_maintain_at: dayTime(18, 10, 0), remaining_openings: 464400, maintain_due: false, life_used_pct: 7.1, products: [{ id: 1, code: 'HY-1001', name: '直口杯 500ml' }], machines: [{ id: 1, code: 'I-01', name: '注塑机 1 号' }, { id: 2, code: 'I-02', name: '注塑机 2 号' }, { id: 3, code: 'I-03', name: '注塑机 3 号' }] },
    { id: 2, code: 'M-002', name: '圆瓶 1L 吹塑模', type: 'blow', status: 'in_use', location: 'A区-02', cavity_count: 2, lifetime_openings: 300000, opened_count: 120000, purchase_cost: 65000, purchase_date: '2023-09-01', vendor: '宁波吹塑模具', maintain_cycle_days: 45, last_maintain_at: dayTime(-16, 9, 0), next_maintain_at: dayTime(29, 9, 0), remaining_openings: 180000, maintain_due: false, life_used_pct: 40, products: [{ id: 2, code: 'HY-1002', name: '圆瓶 1L' }], machines: [{ id: 13, code: 'B-01', name: '吹塑机 1 号' }, { id: 14, code: 'B-02', name: '吹塑机 2 号' }] },
    { id: 3, code: 'M-003', name: '方盒 800ml 注塑模', type: 'injection', status: 'in_use', location: 'B区-03', cavity_count: 4, lifetime_openings: 400000, opened_count: 220000, purchase_cost: 72000, purchase_date: '2024-02-20', vendor: '浙江精工模具', maintain_cycle_days: 30, last_maintain_at: dayTime(-5, 10, 0), next_maintain_at: dayTime(25, 10, 0), remaining_openings: 180000, maintain_due: false, life_used_pct: 55, products: [{ id: 3, code: 'HY-1003', name: '方盒 800ml' }], machines: [{ id: 1, code: 'I-01', name: '注塑机 1 号' }, { id: 2, code: 'I-02', name: '注塑机 2 号' }, { id: 3, code: 'I-03', name: '注塑机 3 号' }] },
    { id: 4, code: 'M-004', name: '提手桶 5L 吹塑模', type: 'blow', status: 'repair', location: '维修区', cavity_count: 1, lifetime_openings: 200000, opened_count: 198000, purchase_cost: 58000, purchase_date: '2022-11-10', vendor: '宁波吹塑模具', maintain_cycle_days: 30, last_maintain_at: null, next_maintain_at: null, remaining_openings: 2000, maintain_due: false, life_used_pct: 99, products: [{ id: 4, code: 'HY-1004', name: '提手桶 5L' }], machines: [] },
    { id: 5, code: 'M-005', name: '喷雾瓶 300ml 注塑模', type: 'injection', status: 'in_use', location: 'A区-05', cavity_count: 6, lifetime_openings: 600000, opened_count: 45000, purchase_cost: 96000, purchase_date: '2024-07-01', vendor: '广东塑模科技', maintain_cycle_days: 25, last_maintain_at: dayTime(-7, 10, 0), next_maintain_at: dayTime(18, 10, 0), remaining_openings: 555000, maintain_due: false, life_used_pct: 7.5, products: [{ id: 5, code: 'HY-1005', name: '喷雾瓶 300ml' }], machines: [{ id: 2, code: 'I-02', name: '注塑机 2 号' }, { id: 3, code: 'I-03', name: '注塑机 3 号' }] },
    { id: 6, code: 'M-006', name: '餐盒 900ml 注塑模', type: 'injection', status: 'in_use', location: 'B区-04', cavity_count: 4, lifetime_openings: 500000, opened_count: 312000, purchase_cost: 105000, purchase_date: '2024-10-01', vendor: '浙江精工模具', maintain_cycle_days: 30, last_maintain_at: dayTime(-10, 9, 0), next_maintain_at: dayTime(20, 9, 0), remaining_openings: 188000, maintain_due: false, life_used_pct: 62.4, products: [{ id: 6, code: 'HY-1006', name: '餐盒 900ml' }], machines: [{ id: 2, code: 'I-02', name: '注塑机 2 号' }, { id: 3, code: 'I-03', name: '注塑机 3 号' }] },
    { id: 7, code: 'M-007', name: '洗衣液桶 2L 吹塑模', type: 'blow', status: 'in_use', location: 'A区-07', cavity_count: 1, lifetime_openings: 250000, opened_count: 89000, purchase_cost: 70000, purchase_date: '2024-05-15', vendor: '宁波吹塑模具', maintain_cycle_days: 40, last_maintain_at: dayTime(-14, 9, 0), next_maintain_at: dayTime(26, 9, 0), remaining_openings: 161000, maintain_due: false, life_used_pct: 35.6, products: [{ id: 7, code: 'HY-1007', name: '洗衣液桶 2L' }], machines: [{ id: 13, code: 'B-01', name: '吹塑机 1 号' }, { id: 14, code: 'B-02', name: '吹塑机 2 号' }] },
    { id: 8, code: 'M-008', name: '收纳箱 12L 注塑模', type: 'injection', status: 'idle', location: 'C区-01', cavity_count: 2, lifetime_openings: 300000, opened_count: 12000, purchase_cost: 130000, purchase_date: '2025-01-10', vendor: '广东塑模科技', maintain_cycle_days: 30, last_maintain_at: null, next_maintain_at: null, remaining_openings: 288000, maintain_due: false, life_used_pct: 4, products: [{ id: 8, code: 'HY-1008', name: '收纳箱 12L' }], machines: [{ id: 1, code: 'I-01', name: '注塑机 1 号' }, { id: 2, code: 'I-02', name: '注塑机 2 号' }] },
  ]
}

// ==================== 订单 / 任务 / 排程 ====================

function buildOrdersAndTasks(routeOps: Record<number, RouteOp[]>, molds: Mold[]): { orders: Order[]; lines: Record<number, OrderLine[]>; tasks: ProdTask[]; schedules: TaskSchedule[]; pool: PoolTask[] } {
  const orders: Order[] = []
  const lines: Record<number, OrderLine[]> = {}
  const tasks: ProdTask[] = []
  const schedules: TaskSchedule[] = []
  const pool: PoolTask[] = []

  // 机台速查
  const machines = buildMachines()
  const machineOf = (id: number) => machines.find((m) => m.id === id)
  const moldOf = (id: number | null) => id === null ? null : molds.find((m) => m.id === id) ?? null
  const taskSwitchGapMinutes = 30
  const capacityOf = (op: RouteOp, machineId: number | null): number => {
    const machineCapacity = machineId ? machineOf(machineId)?.capacity_per_hour : null
    const routeCapacity = (op as RouteOp & { capacity_per_hour?: number }).capacity_per_hour
    return Number(routeCapacity ?? machineCapacity ?? (op.op_type === 'paint' || op.op_type === 'effect' ? 1500 : op.op_type === 'pack' ? 3000 : 200))
  }
  const durationMinutesOf = (op: RouteOp, machineId: number | null, qty: number): number => {
    // 模拟生产时间与真实排产口径一致：数量 ÷ 资源产能 × 1.05 预留，成型首段再加固定 30 分钟换模。
    const productionMinutes = (qty / Math.max(1, capacityOf(op, machineId))) * 60 * 1.05
    const changeoverMinutes = op.op_type.startsWith('molding') ? 30 : 0
    return Math.max(30, Math.ceil((productionMinutes + changeoverMinutes) / 30) * 30)
  }

  // 订单定义：8 单 = 完成 1 + 逾期 2 + 紧急顺延 1 + 在产 2 + 待排 1 + K3 拉单 1
  const defs: Array<{
    i: number
    src: Order['src']
    k3: string | null
    customer: string
    status: Order['status']
    priority: number
    deliveryOffset: number
    remark?: string
    items: Array<{ productId: number; qty: number; status: OrderLine['status']; lineNo: number }>
  }> = [
    { i: 1, src: 'manual', k3: null, customer: '合肥华联日化', status: 'produced', priority: 3, deliveryOffset: -20, remark: '已交付（演示）', items: [{ productId: 1, qty: 20000, status: 'done', lineNo: 1 }, { productId: 3, qty: 12000, status: 'done', lineNo: 2 }] },
    { i: 2, src: 'manual', k3: null, customer: '芜湖美的电器', status: 'in_progress', priority: 2, deliveryOffset: 2, remark: '临期 2 天 · 整单跟催', items: [{ productId: 6, qty: 30000, status: 'in_progress', lineNo: 1 }] },
    { i: 3, src: 'manual', k3: null, customer: '上海联合利华', status: 'in_progress', priority: 0, deliveryOffset: -3, remark: '逾期 3 天 · 紧急插单已排', items: [{ productId: 1, qty: 50000, status: 'in_progress', lineNo: 1 }, { productId: 6, qty: 25000, status: 'in_progress', lineNo: 2 }] },
    { i: 4, src: 'manual', k3: null, customer: '杭州纳爱斯', status: 'scheduled', priority: 3, deliveryOffset: 5, remark: '上批顺延 1 天（插单影响）', items: [{ productId: 7, qty: 15000, status: 'scheduled', lineNo: 1 }] },
    { i: 5, src: 'manual', k3: null, customer: '苏州绿叶日化', status: 'scheduled', priority: 2, deliveryOffset: 4, remark: '喷雾瓶试产', items: [{ productId: 5, qty: 8000, status: 'scheduled', lineNo: 1 }] },
    { i: 6, src: 'excel', k3: null, customer: '南京苏宁易购', status: 'pending', priority: 4, deliveryOffset: 9, remark: '', items: [{ productId: 2, qty: 40000, status: 'pending', lineNo: 1 }] },
    { i: 7, src: 'manual', k3: null, customer: '武汉中百仓储', status: 'in_progress', priority: 3, deliveryOffset: 6, remark: '分批发货', items: [{ productId: 3, qty: 20000, status: 'in_progress', lineNo: 1 }, { productId: 2, qty: 10000, status: 'in_progress', lineNo: 2 }] },
    { i: 8, src: 'k3', k3: 'MO202501010001', customer: '金蝶同步·宁波客户', status: 'pending', priority: 2, deliveryOffset: 7, remark: '来自金蝶生产订单（四期拉单）', items: [{ productId: 4, qty: 6000, status: 'pending', lineNo: 1 }] },
  ]

  const products = buildProducts()

  defs.forEach((def) => {
    const id = def.i
    const delivery = day(def.deliveryOffset).concat(' 18:00:00')
    orders.push({
      id,
      order_no: ORDER_NO(id),
      src: def.src,
      k3_bill_no: def.k3,
      customer_name: def.customer,
      status: def.status,
      priority: def.priority,
      delivery_date: delivery,
      remark: def.remark ?? null,
      lines_count: def.items.length,
      total_qty: def.items.reduce((s, it) => s + it.qty, 0),
      created_at: dayTime(-10 - id, 9, 0),
    })
    lines[id] = def.items.map((it, idx) => {
      const product = products.find((p) => p.id === it.productId)!
      return {
        id: id * 10 + idx + 1,
        order_id: id,
        product_id: it.productId,
        product,
        qty: it.qty,
        delivery_date: delivery,
        priority: def.priority,
        status: it.status,
        done_qty: it.status === 'done' ? it.qty : it.status === 'in_progress' ? Math.round(it.qty * (0.2 + (idx * 0.1))) : 0,
        line_no: it.lineNo,
      }
    })
  })

  // 任务：每个明细 1 个任务 → 共 13 个（8 单 14 明细，其中 1 个 done 单的任务 → done）
  let taskSeq = 0
  const nextTaskNo = () => TASK_NO(++taskSeq)

  const pushTask = (
    orderId: number,
    lineIdx: number,
    status: ProdTask['status'],
    qty: number,
    priority: number,
    delivery: string | null,
    moldId: number | null,
    productId: number,
    stocked = 0,
    plan?: { start: string; end: string; machineId: number; seq: number; opType: string; opName: string; scheduleStatus: TaskSchedule['status']; doneQty: number; deviation: number; actualStart?: string; actualEnd?: string },
  ) => {
    const line = lines[orderId][lineIdx]
    const product = products.find((p) => p.id === productId)!
    const task: ProdTask = {
      // 注意：id 字段在 task_no（++taskSeq）之前求值，故用 101 + taskSeq 与序号对齐
      id: 101 + taskSeq,
      task_no: nextTaskNo(),
      order_line_id: line.id,
      product_id: productId,
      product: { id: product.id, code: product.code, name: product.name },
      route_id: productId,
      mold_id: moldId,
      mold: moldOf(moldId) ? { id: moldId!, code: moldOf(moldId)!.code, name: moldOf(moldId)!.name } : null,
      qty,
      // 已完成任务默认全部完成（done_qty=qty）；在产任务取报工台账合计
      done_qty: plan?.doneQty ?? (status === 'done' ? qty : 0),
      stocked_qty: stocked,
      status,
      priority,
      delivery_date: delivery,
      deviation_pct: plan?.deviation ?? (status === 'in_progress' && plan ? 8 : 0),
      plan_start: plan?.start ?? null,
      plan_end: plan?.end ?? null,
      is_locked: status === 'in_progress' || status === 'done' ? 1 : 0,
      source: 'manual',
    }
    tasks.push(task)

    // 任务池：未排任务进入
    if (status === 'unscheduled') {
      const ops = routeOps[product.default_route_id ?? -1] ?? []
      const firstOp = ops.slice().sort((a, b) => a.seq - b.seq)[0]
      const resource = poolResourceForOp(firstOp)
      const taskMold = moldOf(moldId)
      const moldUnavailable = taskMold?.status === 'repair' || taskMold?.status === 'scrapped'
      pool.push({
        id: task.id,
        task_no: task.task_no,
        product_id: product.id,
        product_code: product.code,
        product_name: product.name,
        qty,
        priority,
        delivery_date: delivery,
        is_overdue: !!delivery && dayjs(delivery).isBefore(dayjs()),
        mold_id: moldId,
        mold_code: moldId ? `M-${String(moldId).padStart(3, '0')}` : '',
        mold_status: taskMold?.status ?? null,
        mold_status_label: taskMold?.status === 'repair' ? '维修中' : taskMold?.status === 'scrapped' ? '已报废' : taskMold?.status === 'in_use' ? '使用中' : taskMold?.status === 'idle' ? '闲置' : null,
        mold_unavailable: moldUnavailable,
        default_mold_code: moldId ? `M-${String(moldId).padStart(3, '0')}` : '',
        default_route_id: productId,
        route_published: true,
        route_ops_count: ops.length,
        ...resource,
        estimated_hours: Math.round((qty / 400) * 10) / 10,
        estimate_ok: true,
        order_no: ORDER_NO(orderId),
        customer_name: orders.find((o) => o.id === orderId)!.customer_name,
      })
    }

    // 排程：已排任务按标准工艺路线生成多工序甘特条（进度条显示工序分阶段节点）
    if (plan) {
      const ops = routeOps[productId] ?? []
      const firstIdx = ops.findIndex((o) => o.op_type === plan.opType)
      const firstOp = firstIdx >= 0 ? ops[firstIdx] : null
      let cursor = dayjs(plan.end)

      // 如果 plan.opType 不在标准路线里，按旧逻辑单条排程兜底
      if (!ops.length || firstIdx < 0) {
        const driftEnd = plan.deviation && plan.deviation > 0
          ? dayjs(plan.end).add(plan.deviation, 'hour').format('YYYY-MM-DD HH:mm:ss')
          : plan.end
        schedules.push({
          id: 1000 + task.id,
          task_id: task.id,
          task_no: task.task_no,
          seq: plan.seq,
          route_op_id: firstOp?.id ?? firstOp?.seq ?? plan.seq,
          op_type: plan.opType,
          op_name: plan.opName,
          machine_id: plan.machineId,
          mold_id: moldId,
          plan_start: plan.start,
          plan_end: plan.end,
          forecast_start: plan.start,
          forecast_end: driftEnd,
          actual_start: plan.actualStart ?? (plan.scheduleStatus === 'running' || plan.scheduleStatus === 'done' ? plan.start : null),
          actual_end: plan.actualEnd ?? (plan.scheduleStatus === 'done' ? plan.end : null),
          is_frozen: !dayjs(plan.start).startOf('day').isBefore(dayjs().startOf('day')) && dayjs(plan.start).startOf('day').isBefore(dayjs().startOf('day').add(3, 'day')) ? 1 : 0,
          changeover_minutes: plan.opType.startsWith('molding') ? 30 : 0,
          qty,
          done_qty: plan.doneQty,
          status: plan.scheduleStatus,
          source: 'manual',
          is_locked: 1,
          is_overlap: 0,
          is_interrupted: 0,
          is_conflict: 0,
          product_code: product.code,
          product_name: product.name,
          order_no: ORDER_NO(orderId),
          mold_code: moldId ? `M-${String(moldId).padStart(3, '0')}` : null,
          note: null,
        })
      } else {
        ops.forEach((op, idx) => {
          const isFirst = idx === firstIdx
          const isMolding = op.op_type.startsWith('molding')
          const isBeforeFirst = idx < firstIdx

          // 机器/产线：首工序沿用 plan，后续按工序类型选演示资源
          let machineId: number | null = plan.machineId
          if (!isFirst) {
            if (op.op_type === 'molding_injection') machineId = 1
            else if (op.op_type === 'molding_blow') machineId = 14
            else if (op.op_type === 'paint') machineId = 17
            else if (op.op_type === 'effect') machineId = 18
            else if (op.op_type === 'pack') machineId = 19
            else machineId = null
          }

          // 时间：首工序用 plan；后续顺序接龙（每道工序之间留 2h 转移）
          let start: string, end: string, forecastEnd: string
          if (isFirst) {
            start = plan.start
            end = dayjs(start).add(durationMinutesOf(op, plan.machineId, qty), 'minute').format('YYYY-MM-DD HH:mm:ss')
            forecastEnd = plan.deviation && plan.deviation > 0
              ? dayjs(end).add(plan.deviation, 'hour').format('YYYY-MM-DD HH:mm:ss')
              : end
          } else {
            const s = cursor.add(taskSwitchGapMinutes, 'minute')
            const e = s.add(durationMinutesOf(op, machineId, qty), 'minute')
            start = s.format('YYYY-MM-DD HH:mm:ss')
            end = e.format('YYYY-MM-DD HH:mm:ss')
            forecastEnd = end
            cursor = e
          }

          // 状态与完工数：首工序按 plan；已完成任务全部工序 done；否则后续工序 pending
          let scheduleStatus: TaskSchedule['status']
          let doneQty: number
          if (status === 'done' || isBeforeFirst) {
            scheduleStatus = 'done'
            doneQty = qty
          } else if (isFirst) {
            scheduleStatus = plan.scheduleStatus
            doneQty = plan.doneQty
          } else {
            scheduleStatus = 'pending'
            doneQty = 0
          }

          schedules.push({
            id: idx === 0 ? 1000 + task.id : 100000 + task.id * 10 + idx,
            task_id: task.id,
            task_no: task.task_no,
            seq: op.seq,
            route_op_id: op.id ?? op.seq,
            op_type: op.op_type,
            op_name: op.op_name,
            machine_id: machineId,
            mold_id: isMolding ? moldId : null,
            plan_start: start,
            plan_end: end,
            forecast_start: start,
            forecast_end: forecastEnd,
            actual_start: isFirst
              ? (plan.actualStart ?? (scheduleStatus === 'running' || scheduleStatus === 'done' ? start : null))
              : (scheduleStatus === 'done' ? start : null),
            actual_end: isFirst
              ? (plan.actualEnd ?? (scheduleStatus === 'done' ? end : null))
              : (scheduleStatus === 'done' ? end : null),
            is_frozen: scheduleStatus === 'pending' && !dayjs(start).startOf('day').isBefore(dayjs().startOf('day')) && dayjs(start).startOf('day').isBefore(dayjs().startOf('day').add(3, 'day')) ? 1 : 0,
            changeover_minutes: op.mold_change_min ?? 0,
            qty,
            done_qty: doneQty,
            status: scheduleStatus,
            source: 'manual',
            is_locked: 1,
            is_overlap: 0,
            is_interrupted: 0,
            is_conflict: 0,
            product_code: product.code,
            product_name: product.name,
            order_no: ORDER_NO(orderId),
            mold_code: moldId ? `M-${String(moldId).padStart(3, '0')}` : null,
            note: null,
          })
        })
      }
    }
  }

  // O1（已完成）：2 任务 done，历史完工排程（计划/实际双线样例：实际晚开始/提前或滞后收尾）
  pushTask(1, 0, 'done', 20000, 3, day(-2).concat(' 18:00:00'), 1, 1, 20000, { start: dayTime(-13, 8, 0), end: dayTime(-11, 20, 0), machineId: 1, seq: 1, opType: 'molding_injection', opName: '注塑成型', scheduleStatus: 'done', doneQty: 20000, deviation: 0, actualStart: dayTime(-13, 10, 0), actualEnd: dayTime(-11, 16, 0) })
  pushTask(1, 1, 'done', 12000, 3, day(-2).concat(' 18:00:00'), 3, 3, 12000, { start: dayTime(-10, 8, 0), end: dayTime(-8, 20, 0), machineId: 1, seq: 1, opType: 'molding_injection', opName: '注塑成型', scheduleStatus: 'done', doneQty: 12000, deviation: 0, actualStart: dayTime(-10, 8, 0), actualEnd: dayTime(-8, 12, 0) })
  // O2（临期在产）：餐盒 900ml 已迁移至保留机 I-02，落后 5%（正常）
  pushTask(2, 0, 'in_progress', 30000, 2, day(2).concat(' 18:00:00'), 6, 6, 8000, { start: dayTime(-4, 8, 0), end: dayTime(1, 20, 0), machineId: 2, seq: 1, opType: 'molding_injection', opName: '注塑成型', scheduleStatus: 'running', doneQty: 19000, deviation: -2 })
  // O3（逾期紧急插单）：直口杯 50000 在 I-01（进行中，落后 12% → 红灯）+ 餐盒 25000 已迁移至 I-02（延迟开始 → 顺延样例）
  pushTask(3, 0, 'in_progress', 50000, 1, day(-3).concat(' 18:00:00'), 1, 1, 12000, { start: dayTime(-7, 8, 0), end: dayTime(3, 20, 0), machineId: 1, seq: 1, opType: 'molding_injection', opName: '注塑成型', scheduleStatus: 'running', doneQty: 23000, deviation: 12 })
  pushTask(3, 1, 'in_progress', 25000, 1, day(-3).concat(' 18:00:00'), 6, 6, 0, { start: dayTime(-5, 8, 0), end: dayTime(3, 20, 0), machineId: 2, seq: 1, opType: 'molding_injection', opName: '注塑成型', scheduleStatus: 'running', doneQty: 3000, deviation: 6 })
  // O4（顺延）：洗衣液桶 2L 已从 B-03 迁移至保留机 B-01，已排未开始（今晚开始）
  pushTask(4, 0, 'scheduled', 15000, 3, day(5).concat(' 18:00:00'), 7, 7, 0, { start: dayHour(1, 20), end: dayTime(4, 8, 0), machineId: 13, seq: 1, opType: 'molding_blow', opName: '吹塑成型', scheduleStatus: 'pending', doneQty: 0, deviation: 0 })
  // O5（喷雾瓶试产）：已从 I-11 迁移至保留机 I-03；未排第二段（喷漆 → 班组，演示人工线）
  pushTask(5, 0, 'scheduled', 8000, 2, day(4).concat(' 18:00:00'), 5, 5, 0, { start: dayHour(1, 14), end: dayTime(2, 12, 30), machineId: 2, seq: 1, opType: 'molding_injection', opName: '注塑成型', scheduleStatus: 'pending', doneQty: 0, deviation: 0 })
  // O6（待排）：圆瓶 1L 40000 未排 → 红灯「未排产」
  pushTask(6, 0, 'unscheduled', 40000, 4, day(9).concat(' 18:00:00'), 2, 2)
  // O7（在产分批入库）：方盒 20000 已从 I-05 迁移至保留机 I-03 进行中落后 15% → 红灯「落后」；圆瓶 10000 B-02 进行中无报工 → 红灯「未报工」
  pushTask(7, 0, 'in_progress', 20000, 3, day(6).concat(' 18:00:00'), 3, 3, 0, { start: dayTime(-5, 8, 0), end: dayTime(1, 12, 0), machineId: 2, seq: 1, opType: 'molding_injection', opName: '注塑成型', scheduleStatus: 'running', doneQty: 4500, deviation: 15 })
  // 未报工样例：圆瓶 10000 在 B-02 计划昨天开始无报工 → no_report
  pushTask(7, 1, 'in_progress', 10000, 3, day(6).concat(' 18:00:00'), 2, 2, 0, { start: dayTime(-2, 8, 0), end: dayTime(2, 12, 0), machineId: 13, seq: 1, opType: 'molding_blow', opName: '吹塑成型', scheduleStatus: 'running', doneQty: 0, deviation: 0 })
  // O8（K3 拉单待排）：提手桶 5L 6000 未排 → 红灯「未排产」+ 模具 M-004 维修中无法排
  pushTask(8, 0, 'unscheduled', 6000, 2, day(7).concat(' 18:00:00'), 4, 4, 0)

  // 逾期红灯样例：O2 的餐盒任务 plan_end 已过？不，plan_end 是明天。再造一个逾期任务：
  // 用 O3 直口杯（delivery -3 天 = 逾期，plan_end +3 天）→ overdue 红灯（已由任务3提供）。
  // 机台资源已收敛为 4 台注塑、2 台吹塑、喷漆/效果/包装各 1 条产线。

  // 原始订单示例也必须遵守资源互斥：同一机台/产线的不同工单不能相交，任务切换固定预留 30 分钟。
  // 这里在数据工厂层修正，避免只在甘特图渲染层“遮住”错误；整条工序链一起顺延，保持工序先后关系。
  const scheduleTaskRows = (taskId: number): TaskSchedule[] => schedules.filter((row) => row.task_id === taskId)
  const shiftTime = (value: string | null | undefined, minutes: number): string | null => value ? dayjs(value).add(minutes, 'minute').format('YYYY-MM-DD HH:mm:ss') : null
  const shiftTaskChain = (taskId: number, minutes: number): void => {
    for (const row of scheduleTaskRows(taskId)) {
      row.plan_start = shiftTime(row.plan_start, minutes)!
      row.plan_end = shiftTime(row.plan_end, minutes)!
      row.forecast_start = shiftTime(row.forecast_start, minutes)
      row.forecast_end = shiftTime(row.forecast_end, minutes)
      row.actual_start = shiftTime(row.actual_start, minutes)
      row.actual_end = shiftTime(row.actual_end, minutes)
    }
    const task = tasks.find((item) => item.id === taskId)
    if (task) {
      task.plan_start = shiftTime(task.plan_start, minutes)
      task.plan_end = shiftTime(task.plan_end, minutes)
    }
  }

  // 固定资源内的原始顺序：同一资源按原始计划顺序处理，后续工单统一向后顺延。
  // 多轮扫描是必要的：顺延某工单的整条链后，可能在下一个资源上触发新的相交。
  const originalStart = new Map<number, string>()
  for (const row of schedules) {
    const old = originalStart.get(row.task_id)
    if (!old || row.plan_start < old) originalStart.set(row.task_id, row.plan_start)
  }
  for (let pass = 0; pass < schedules.length + 1; pass += 1) {
    let changed = false
    const machineIds = [...new Set(schedules.map((row) => row.machine_id).filter((id): id is number => id !== null))]
    for (const machineId of machineIds) {
      let cursor: dayjs.Dayjs | null = null
      let previousTaskId: number | null = null
      const taskIds = [...new Set(schedules.filter((row) => row.machine_id === machineId).map((row) => row.task_id))]
        .sort((a, b) => {
          return (originalStart.get(a) ?? '').localeCompare(originalStart.get(b) ?? '') || a - b
        })
      for (const taskId of taskIds) {
        const machineTaskRows = scheduleTaskRows(taskId).filter((item) => item.machine_id === machineId)
        const start = machineTaskRows.reduce<dayjs.Dayjs | null>((earliest, item) => {
          const value = dayjs(item.plan_start)
          return !earliest || value.isBefore(earliest) ? value : earliest
        }, null) ?? dayjs()
        if (cursor && taskId !== previousTaskId && start.isBefore(cursor)) {
          shiftTaskChain(taskId, cursor.diff(start, 'minute'))
          changed = true
        }
        const machineTaskEnd = machineTaskRows.reduce<dayjs.Dayjs | null>((latest, item) => {
          const end = dayjs(item.plan_end)
          return !latest || end.isAfter(latest) ? end : latest
        }, null) ?? start
        cursor = machineTaskEnd.add(30, 'minute')
        previousTaskId = taskId
      }
    }
    if (!changed) break
  }
  for (const row of schedules) {
    row.is_overlap = 0
    row.is_conflict = 0
  }

  return { orders, lines, tasks, schedules, pool }
}

// ==================== 报工台账（16 条） ====================

function buildReports(tasks: ProdTask[], schedules: TaskSchedule[]): WorkReport[] {
  const scheduleOf = (task: ProdTask, seq = 1) => schedules.find((s) => s.task_id === task.id && s.seq === seq) ?? null
  const machineById = new Map(buildMachines().map((machine) => [machine.id, machine]))
  const reports: WorkReport[] = []
  let seq = 0
  const push = (r: Omit<WorkReport, 'id'>) => {
    seq += 1
    reports.push({ id: seq, ...r })
  }
  const t6 = tasks.find((t) => t.id === 103) // O2 餐盒进行中
  const t3 = tasks.find((t) => t.id === 104) // O3 直口杯进行中（逾期）
  const t7 = tasks.find((t) => t.id === 109) // O7 方盒落后
  const t2 = tasks.find((t) => t.id === 105) // O3 餐盒进行中

  type ReportOptions = Partial<WorkReport> & { scheduleSeq?: number }
  const base = (task: ProdTask, qty: number, opts: ReportOptions = {}): void => {
    const schedule = scheduleOf(task, opts.scheduleSeq ?? 1)
    const machine = schedule?.machine_id ? machineById.get(schedule.machine_id) ?? null : null
    const operator = opts.operator ?? { id: 13, name: '陈统计', employee_no: 'T001' }
    const reportTime = opts.report_time ?? dayTime(-1, 15, 30)
    push({
      task_id: task.id,
      task_no: task.task_no,
      product_id: task.product_id,
      product: task.product,
      schedule_id: opts.schedule_id ?? schedule?.id ?? null,
      schedule: schedule ? { id: schedule.id, task_id: schedule.task_id, seq: schedule.seq, op_type: schedule.op_type, op_name: schedule.op_name } : null,
      machine_id: opts.machine_id ?? machine?.id ?? null,
      machine: opts.machine ?? (machine ? { id: machine.id, code: machine.code, name: machine.name } : null),
      qty,
      defect_qty: opts.defect_qty ?? (qty > 1000 ? 12 : 2),
      sign: opts.sign ?? 1,
      report_by: opts.report_by ?? operator.id,
      operator,
      // 当前阶段统一由班组长/统计员填报，不再写入个人作业人。
      worker_id: null,
      worker: null,
      report_time: reportTime,
      // 演示数据没有现场原始时长，给每条历史流水保留一个可见的时间段；真实数据由用户录入。
      start_time: opts.start_time ?? dayjs(reportTime).subtract(30, 'minute').format('YYYY-MM-DD HH:mm:ss'),
      end_time: opts.end_time ?? reportTime,
      work_date: opts.work_date ?? dayjs(reportTime).format('YYYY-MM-DD'),
      shift_code: opts.shift_code ?? 'day',
      source: opts.source ?? 'scan',
      source_label: opts.source_label ?? (opts.source === 'leader_report' ? '班组长代报' : opts.source === 'makeup' ? '补报' : '扫码报工'),
      offline_flag: opts.offline_flag ?? 0,
      remark: opts.remark ?? null,
    })
  }

  const reportTimeFor = (schedule: TaskSchedule, edge: 'start' | 'end'): string => {
    const planned = dayjs(edge === 'start' ? (schedule.actual_start ?? schedule.plan_start) : (schedule.actual_end ?? schedule.plan_end))
    const value = edge === 'start' ? planned.add(30, 'minute') : planned.subtract(30, 'minute')
    return value.format('YYYY-MM-DD HH:mm:ss')
  }

  const completeHistoryTask = (task: ProdTask): void => {
    schedules.filter((schedule) => schedule.task_id === task.id).sort((a, b) => a.seq - b.seq).forEach((schedule) => {
      const firstQty = Math.floor(task.qty * 0.6)
      base(task, firstQty, { scheduleSeq: schedule.seq, report_time: reportTimeFor(schedule, 'start'), source: 'leader_batch', source_label: '班组长/统计员日结' })
      base(task, task.qty - firstQty, { scheduleSeq: schedule.seq, report_time: reportTimeFor(schedule, 'end'), source: 'leader_batch', source_label: '班组长/统计员日结' })
    })
  }

  if (t6) {
    // O2 餐盒：3 天报工，昨天 1 次（正常）
    base(t6, 6000, { report_time: dayTime(-1, 11, 20), defect_qty: 8 })
    base(t6, 6000, { report_time: dayTime(-1, 15, 40) })
    base(t6, 4000, { report_time: dayTime(0, 9, 15), source: 'click' })
  }
  if (t3) {
    // O3 直口杯：含冲减与代报样例
    base(t3, 5000, { report_time: dayTime(-2, 10, 0) })
    base(t3, 5000, { report_time: dayTime(-2, 14, 0) })
    base(t3, 5000, { report_time: dayTime(-1, 10, 30) })
    base(t3, 3500, { report_time: dayTime(-1, 14, 0), remark: '下午班提前收工' })
    // 冲减：误报 1000 修正
    base(t3, -1000, { sign: -1, report_time: dayTime(0, 8, 50), report_by: 4, operator: { id: 4, name: '王班长', employee_no: 'L001' }, source: 'correction', source_label: '冲减更正', remark: '昨日 14:00 班次多报 1000，更正' })
    // 代报：班组长代报
    base(t3, 4000, { report_by: 4, operator: { id: 4, name: '王班长', employee_no: 'L001' }, source: 'leader_report', source_label: '班组长代报', report_time: dayTime(0, 11, 0), remark: '班组长统一填报' })
  }
  if (t2) {
    base(t2, 3000, { report_time: dayTime(-1, 16, 0), defect_qty: 15, remark: '次品率偏高，已停机检查' })
  }
  if (t7) {
    base(t7, 2500, { report_time: dayTime(-1, 13, 0) })
    base(t7, 2000, { report_time: dayTime(0, 10, 20), defect_qty: 9 })
  }
  // 补报样例（昨天对前天的补报）
  if (t6) {
    base(t6, 3000, { source: 'makeup', source_label: '补报', report_time: dayTime(0, 7, 30), remark: '昨日晚班漏报，补报' })
  }
  // 弱网离线暂存样例
  if (t3) {
    base(t3, 1500, { offline_flag: 1, report_time: dayTime(0, 13, 10), remark: '弱网离线暂存后补传' })
  }
  // 已完成任务的历史报工（O1）：每道工序都有实际起止与全量报工，甘特图可显示完整实际链。
  const t1 = tasks.find((t) => t.id === 101)
  if (t1) completeHistoryTask(t1)
  const t1b = tasks.find((t) => t.id === 102)
  if (t1b) completeHistoryTask(t1b)
  // 在产任务：前段已有报工；已流转到包装的部分数量同步补齐喷漆/效果/包装，
  // 确保工序链数量不倒挂，甘特图能看见真实走到哪一道工序。
  if (t6) {
    base(t6, 8000, { scheduleSeq: 2, report_time: dayTime(-3, 11, 0), source: 'leader_batch', source_label: '班组长/统计员日结' })
    base(t6, 8000, { scheduleSeq: 3, report_time: dayTime(-2, 15, 0), source: 'leader_batch', source_label: '班组长/统计员日结' })
    base(t6, 8000, { scheduleSeq: 4, report_time: dayTime(-1, 17, 0), source: 'leader_batch', source_label: '班组长/统计员日结' })
  }
  if (t3) {
    base(t3, 10000, { scheduleSeq: 2, report_time: dayTime(-4, 11, 0), source: 'leader_batch', source_label: '班组长/统计员日结' })
    base(t3, 10000, { scheduleSeq: 3, report_time: dayTime(-3, 15, 0), source: 'leader_batch', source_label: '班组长/统计员日结' })
    base(t3, 10000, { scheduleSeq: 4, report_time: dayTime(-2, 17, 0), source: 'leader_batch', source_label: '班组长/统计员日结' })
  }
  if (t7) {
    base(t7, 3000, { scheduleSeq: 2, report_time: dayTime(-2, 11, 0), source: 'leader_batch', source_label: '班组长/统计员日结' })
    base(t7, 3000, { scheduleSeq: 3, report_time: dayTime(-1, 15, 0), source: 'leader_batch', source_label: '班组长/统计员日结' })
    base(t7, 3000, { scheduleSeq: 4, report_time: dayTime(-1, 17, 45), source: 'leader_batch', source_label: '班组长/统计员日结' })
  }

  // 演示历史流水没有现场原始起止时间，按计划有效工时恢复可读的实际段。
  // 人工线是白班制，推算时跳过 17:00-次日 08:00，避免把夜间显示成实际作业。
  const previousEndBySchedule = new Map<number, Dayjs>()
  reports
    .filter((report) => report.sign === 1 && report.schedule_id !== null)
    .sort((a, b) => {
      if (a.schedule_id !== b.schedule_id) return (a.schedule_id ?? 0) - (b.schedule_id ?? 0)
      return a.report_time.localeCompare(b.report_time) || a.id - b.id
    })
    .forEach((report) => {
      const schedule = schedules.find((item) => item.id === report.schedule_id)
      const machine = schedule?.machine_id ? machineById.get(schedule.machine_id) : null
      const shiftMode = machine?.shift_mode === 'two_shift' ? 'two_shift' : 'day'
      const plannedWorkingMinutes = schedule?.plan_start && schedule.plan_end
        ? workingMinutesBetween(dayjs(schedule.plan_start), dayjs(schedule.plan_end), shiftMode)
        : 8 * 60
      const durationMinutes = Math.max(1, Math.ceil(plannedWorkingMinutes * report.qty / Math.max(1, schedule?.qty ?? report.qty)))
      const reportTime = dayjs(report.report_time)
      const previousEnd = previousEndBySchedule.get(report.schedule_id!)
      const candidateStart = subtractWorkingMinutes(reportTime, durationMinutes, shiftMode)
      let start = previousEnd
        ? (previousEnd.isAfter(candidateStart) ? previousEnd : candidateStart)
        : schedule?.plan_start
          ? dayjs(schedule.plan_start)
          : subtractWorkingMinutes(reportTime, durationMinutes, shiftMode)
      if (start.isAfter(reportTime) && !previousEnd) start = subtractWorkingMinutes(reportTime, durationMinutes, shiftMode)
      const candidateEnd = addWorkingMinutes(start, durationMinutes, shiftMode)
      const segment = splitWorkingSegments(
        start.format('YYYY-MM-DD HH:mm:ss'),
        candidateEnd.format('YYYY-MM-DD HH:mm:ss'),
        shiftMode,
      )[0]
      const effectiveStart = segment ? dayjs(segment.start) : start
      const end = segment ? dayjs(segment.end) : candidateEnd
      report.start_time = effectiveStart.format('YYYY-MM-DD HH:mm:ss')
      report.end_time = end.format('YYYY-MM-DD HH:mm:ss')
      previousEndBySchedule.set(report.schedule_id!, end)
    })

  return reports
}

function workingMinutesBetween(start: Dayjs, end: Dayjs, shiftMode: Machine['shift_mode']): number {
  if (shiftMode === 'two_shift') return Math.max(0, end.diff(start, 'minute'))
  let cursor = start
  let minutes = 0
  for (let guard = 0; guard < 10000 && cursor.isBefore(end); guard += 1) {
    const window = dayWindow(cursor)
    if (cursor.isBefore(window.start)) {
      cursor = window.start
      continue
    }
    if (!cursor.isBefore(window.end)) {
      cursor = dayWindow(cursor.add(1, 'day')).start
      continue
    }
    const segmentEnd = end.isBefore(window.end) ? end : window.end
    minutes += segmentEnd.diff(cursor, 'minute')
    cursor = segmentEnd
  }
  return minutes
}

function addWorkingMinutes(start: Dayjs, minutes: number, shiftMode: Machine['shift_mode']): Dayjs {
  if (shiftMode === 'two_shift') return start.add(minutes, 'minute')
  let cursor = start
  let remaining = minutes
  for (let guard = 0; guard < 10000 && remaining > 0; guard += 1) {
    const window = dayWindow(cursor)
    if (cursor.isBefore(window.start)) cursor = window.start
    else if (!cursor.isBefore(window.end)) {
      cursor = dayWindow(cursor.add(1, 'day')).start
      continue
    }
    const available = window.end.diff(cursor, 'minute')
    if (remaining <= available) return cursor.add(remaining, 'minute')
    remaining -= Math.max(0, available)
    cursor = dayWindow(cursor.add(1, 'day')).start
  }
  return cursor
}

function subtractWorkingMinutes(end: Dayjs, minutes: number, shiftMode: Machine['shift_mode']): Dayjs {
  if (shiftMode === 'two_shift') return end.subtract(minutes, 'minute')
  let cursor = end
  let remaining = minutes
  for (let guard = 0; guard < 10000 && remaining > 0; guard += 1) {
    const window = dayWindow(cursor)
    if (cursor.isAfter(window.end)) cursor = window.end
    else if (!cursor.isAfter(window.start)) {
      cursor = dayWindow(cursor.subtract(1, 'day')).end
      continue
    }
    const available = cursor.diff(window.start, 'minute')
    if (remaining <= available) return cursor.subtract(remaining, 'minute')
    remaining -= Math.max(0, available)
    cursor = dayWindow(cursor.subtract(1, 'day')).end
  }
  return cursor
}

function dayWindow(cursor: Dayjs): { start: Dayjs; end: Dayjs } {
  const dayStart = cursor.startOf('day')
  return {
    start: dayStart.hour(8).minute(0).second(0),
    end: dayStart.hour(17).minute(0).second(0),
  }
}

/** 将一条报工归一为一个连续有效作业段，白班跨天时取首个白班区间。 */
export function splitWorkingSegments(
  start: string | null | undefined,
  end: string | null | undefined,
  shiftMode: Machine['shift_mode'] = 'day',
): Array<{ start: string; end: string }> {
  if (!start || !end) return []
  const startAt = dayjs(start)
  const endAt = dayjs(end)
  if (startAt.isSame(endAt) || shiftMode === 'two_shift') {
    return [{ start: startAt.format('YYYY-MM-DD HH:mm:ss'), end: endAt.format('YYYY-MM-DD HH:mm:ss') }]
  }
  let cursor = startAt
  for (let guard = 0; guard < 10000 && cursor.isBefore(endAt); guard += 1) {
    const window = dayWindow(cursor)
    if (cursor.isBefore(window.start)) {
      cursor = window.start
      continue
    }
    if (!cursor.isBefore(window.end)) {
      cursor = dayWindow(cursor.add(1, 'day')).start
      continue
    }
    const segmentEnd = endAt.isBefore(window.end) ? endAt : window.end
    return [{
      start: cursor.format('YYYY-MM-DD HH:mm:ss'),
      end: segmentEnd.format('YYYY-MM-DD HH:mm:ss'),
    }]
  }
  return []
}

// ==================== 入库单（6 张） ====================

function buildStockIns(tasks: ProdTask[]): StockIn[] {
  const list: StockIn[] = []
  let seq = 0
  const push = (s: Omit<StockIn, 'id'>) => {
    seq += 1
    list.push({ id: seq, ...s })
  }
  const t6 = tasks.find((t) => t.id === 103) // O2 餐盒进行中
  const t3 = tasks.find((t) => t.id === 104) // O3 直口杯（逾期）
  const t1 = tasks.find((t) => t.id === 101)
  const t1b = tasks.find((t) => t.id === 102) // O1 方盒（已完成）

  if (t1) {
    // 已完成订单：已推
    push({
      ins_no: INS_NO(1), task_id: t1.id, task_no: t1.task_no, product_id: t1.product_id, product: t1.product,
      qty: 15000, ins_status: 'pushed', k3_push_status: 'success', k3_bill_no: `K3-IN-${dayjs().format('YYYYMMDD')}-A1F2E3`,
      k3_result: { Id: 900001, Number: 'K3-IN-0001' }, created_by: 1, creator: { id: 1, name: '系统管理员' },
      remark: '已推金蝶（回执落库）', created_at: dayTime(-12, 15, 0),
    })
    push({
      ins_no: INS_NO(2), task_id: t1.id, task_no: t1.task_no, product_id: t1.product_id, product: t1.product,
      qty: 5000, ins_status: 'pushed', k3_push_status: 'success', k3_bill_no: `K3-IN-${dayjs().format('YYYYMMDD')}-77C9D0`,
      k3_result: { Id: 900002, Number: 'K3-IN-0002' }, created_by: 1, creator: { id: 1, name: '系统管理员' },
      remark: '尾批入库', created_at: dayTime(-10, 16, 0),
    })
  }
  if (t1b) {
    // O1 第二任务：全量入库（已完成订单数据自洽）
    push({
      ins_no: INS_NO(3), task_id: t1b.id, task_no: t1b.task_no, product_id: t1b.product_id, product: t1b.product,
      qty: 12000, ins_status: 'pushed', k3_push_status: 'success', k3_bill_no: `K3-IN-${dayjs().format('YYYYMMDD')}-9E8F7A`,
      k3_result: { Id: 900003, Number: 'K3-IN-0003' }, created_by: 1, creator: { id: 1, name: '系统管理员' },
      remark: '已推金蝶（回执落库）', created_at: dayTime(-9, 11, 0),
    })
  }
  if (t6) {
    push({
      ins_no: INS_NO(4), task_id: t6.id, task_no: t6.task_no, product_id: t6.product_id, product: t6.product,
      qty: 8000, ins_status: 'pushed', k3_push_status: 'queued', k3_bill_no: null,
      k3_result: null, created_by: 4, creator: { id: 4, name: '王班长' },
      remark: '待推送（队列中）', created_at: dayTime(0, 14, 30),
    })
  }
  if (t3) {
    push({
      ins_no: INS_NO(5), task_id: t3.id, task_no: t3.task_no, product_id: t3.product_id, product: t3.product,
      qty: 12000, ins_status: 'pushed', k3_push_status: 'failed', k3_bill_no: null,
      k3_result: { error: 'K3 业务校验失败：物料未备案（mock）' }, created_by: 4, creator: { id: 4, name: '王班长' },
      remark: '推送失败，待手动重推', created_at: dayTime(-1, 15, 10),
    })
    push({
      ins_no: INS_NO(6), task_id: t3.id, task_no: t3.task_no, product_id: t3.product_id, product: t3.product,
      qty: -2000, ins_status: 'confirmed', k3_push_status: 'success', k3_bill_no: `K3-IN-${dayjs().format('YYYYMMDD')}-RED-RETURN`,
      k3_result: { Id: 900003, Number: 'K3-IN-RED' }, created_by: 1, creator: { id: 1, name: '系统管理员' },
      remark: '红字回冲：上批 2000 只退货冲回', created_at: dayTime(0, 10, 5),
    })
  }
  const t7 = tasks.find((t) => t.id === 109) // O7 方盒（落后）
  if (t7) {
    push({
      ins_no: INS_NO(7), task_id: t7.id, task_no: t7.task_no, product_id: t7.product_id, product: t7.product,
      qty: 3000, ins_status: 'confirmed', k3_push_status: 'queued', k3_bill_no: null,
      k3_result: null, created_by: 8, creator: { id: 8, name: '刘班长' },
      remark: '分批入库（第一批）', created_at: dayTime(0, 16, 20),
    })
  }
  return list
}

/** 用报工/入库流水回算模拟快照，避免任务冗余字段与明细表各算各的。 */
function syncMockExecutionSnapshot(
  orders: Order[],
  lines: Record<number, OrderLine[]>,
  tasks: ProdTask[],
  schedules: TaskSchedule[],
  reports: WorkReport[],
  stockIns: StockIn[],
): void {
  const reportsOf = (scheduleId: number) => reports.filter((report) => report.schedule_id === scheduleId)
  const taskReportsOf = (taskId: number) => reports.filter((report) => report.task_id === taskId)

  for (const schedule of schedules) {
    const scheduleReports = reportsOf(schedule.id)
    const reportedQty = scheduleReports.reduce((sum, report) => sum + report.qty, 0)
    const positiveReports = scheduleReports.filter((report) => report.sign === 1)
    schedule.done_qty = Math.max(0, reportedQty)
    schedule.status = schedule.done_qty >= schedule.qty ? 'done' : schedule.done_qty > 0 ? 'running' : 'pending'
    schedule.actual_start = positiveReports.map((report) => report.start_time ?? report.report_time).sort()[0] ?? null
    schedule.actual_end = schedule.done_qty >= schedule.qty ? positiveReports.map((report) => report.end_time ?? report.report_time).sort().reverse()[0] ?? null : null
  }

  for (const task of tasks) {
    const rows = schedules.filter((schedule) => schedule.task_id === task.id && !schedule.is_interrupted).sort((a, b) => a.seq - b.seq)
    const lastScheduleId = rows[rows.length - 1]?.id ?? null
    const taskReports = taskReportsOf(task.id)
    const finalReports = taskReports.filter((report) => report.schedule_id === null || report.schedule_id === lastScheduleId)
    const doneQty = Math.max(0, finalReports.reduce((sum, report) => sum + report.qty, 0))
    const stockedQty = stockIns.filter((stockIn) => stockIn.task_id === task.id).reduce((sum, stockIn) => sum + stockIn.qty, 0)
    const positiveReports = taskReports.filter((report) => report.sign === 1)

    task.done_qty = doneQty
    task.stocked_qty = stockedQty
    task.plan_start = rows.map((row) => row.plan_start).sort()[0] ?? null
    task.plan_end = rows.map((row) => row.plan_end).sort().reverse()[0] ?? null
    task.actual_start = positiveReports.map((report) => report.start_time ?? report.report_time).sort()[0] ?? null
    task.actual_end = doneQty >= task.qty ? positiveReports.map((report) => report.end_time ?? report.report_time).sort().reverse()[0] ?? null : null
    const taskLevelQty = taskReports.filter((report) => report.schedule_id === null).reduce((sum, report) => sum + report.qty, 0)
    const hasActiveProcess = rows.some((row) => {
      const reported = taskReports.filter((report) => report.schedule_id === row.id).reduce((sum, report) => sum + report.qty, 0)
      return reported > 0 && reported < row.qty
    })
    task.status = doneQty >= task.qty
      ? 'done'
      : taskLevelQty > 0 || hasActiveProcess
        ? 'in_progress'
        : rows.length > 0
          ? 'scheduled'
          : 'unscheduled'

    const line = Object.values(lines).flat().find((item) => item.id === task.order_line_id)
    if (line) {
      const lineTasks = tasks.filter((item) => item.order_line_id === line.id)
      line.done_qty = Math.min(line.qty, lineTasks.reduce((sum, item) => sum + item.done_qty, 0))
      line.status = lineTasks.every((item) => item.status === 'done')
        ? 'done'
        : lineTasks.some((item) => item.status === 'in_progress')
          ? 'in_progress'
          : lineTasks.some((item) => item.status === 'scheduled')
            ? 'scheduled'
            : 'pending'
    }
  }

  for (const order of orders) {
    const orderLines = lines[order.id] ?? []
    const orderTasks = tasks.filter((task) => orderLines.some((line) => line.id === task.order_line_id))
    order.status = orderTasks.length > 0 && orderTasks.every((task) => task.status === 'done')
      ? 'produced'
      : orderTasks.some((task) => task.status === 'in_progress')
        ? 'in_progress'
        : orderTasks.some((task) => task.status === 'scheduled')
          ? 'scheduled'
          : 'pending'
  }
}

// ==================== 红灯（6 条 · 四源全覆盖） ====================

function buildRedLights(tasks: ProdTask[]): RedLight[] {
  const tByNo = (no: number) => tasks.find((t) => t.id === no)!
  const mk = (r: Omit<RedLight, 'id'>): RedLight => {
    seq += 1
    return { id: seq, ...r }
  }
  let seq = 0
  const list: RedLight[] = []

  const t6 = tByNo(108) // O6 圆瓶未排（交期 9 天）
  const t8 = tByNo(111) // O8 提手桶未排（K3 单·模具 M-004 维修中）
  const t7b = tByNo(110) // O7 圆瓶 B-02 昨日开始无报工
  const t3 = tByNo(104) // O3 直口杯逾期
  const t7a = tByNo(109) // O7 方盒落后 >10%
  // 第 6 条：O4 洗衣液桶昨晚 20:00 开始成型至今无报工 → 未报工
  const t5 = tByNo(106) // O4 洗衣液桶

  // ① 未排产 ×2
  list.push(mk({
    source: 'unscheduled', source_label: '未排产', level: 'high',
    task_id: t6.id, task_no: t6.task_no, product_code: t6.product!.code, product_name: t6.product!.name,
    order_no: ORDER_NO(6), qty: t6.qty, done_qty: 0, progress_pct: 0,
    reason: '交期 9 天后，任务已建 3 天仍未排产', plan_start: null, plan_end: null, delivery_date: t6.delivery_date,
    owner_id: 3, owner_name: '李计划', owner_workshop: '计划科', status: 'open', closed_by: null, close_reason: null,
    created_at: dayTime(-3, 9, 0), closed_at: null,
  }))
  list.push(mk({
    source: 'unscheduled', source_label: '未排产', level: 'medium',
    task_id: t8.id, task_no: t8.task_no, product_code: t8.product!.code, product_name: t8.product!.name,
    order_no: 'K3 同步单', qty: t8.qty, done_qty: 0, progress_pct: 0,
    reason: '模具 M-004 维修中（剩余寿命 2000 次），待模具回厂后排产', plan_start: null, plan_end: null, delivery_date: t8.delivery_date,
    owner_id: 8, owner_name: '刘班长', owner_workshop: '吹塑一班', status: 'open', closed_by: null, close_reason: null,
    created_at: dayTime(-2, 9, 30), closed_at: null,
  }))
  // ② 未报工超时 ×2
  list.push(mk({
    source: 'no_report', source_label: '未报工', level: 'high',
    task_id: t7b.id, task_no: t7b.task_no, product_code: t7b.product!.code, product_name: t7b.product!.name,
    order_no: ORDER_NO(7), qty: t7b.qty, done_qty: 0, progress_pct: 0,
    reason: '计划昨日 08:00 开始，至今无任何报工记录', plan_start: dayTime(-1, 8, 0), plan_end: dayTime(2, 12, 0), delivery_date: t7b.delivery_date,
    owner_id: 8, owner_name: '刘班长', owner_workshop: '吹塑一班', status: 'open', closed_by: null, close_reason: null,
    created_at: dayTime(0, 8, 0), closed_at: null,
  }))
  list.push(mk({
    source: 'no_report', source_label: '未报工', level: 'medium',
    task_id: t5.id, task_no: t5.task_no, product_code: t5.product!.code, product_name: t5.product!.name,
    order_no: ORDER_NO(4), qty: t5.qty, done_qty: 0, progress_pct: 0,
    reason: '昨晚 20:00 白班开成型至今 12+ 小时无报工（晚班遗漏）', plan_start: dayTime(-1, 20, 0), plan_end: dayTime(4, 8, 0), delivery_date: t5.delivery_date,
    owner_id: 8, owner_name: '刘班长', owner_workshop: '吹塑一班', status: 'open', closed_by: null, close_reason: null,
    created_at: dayTime(0, 11, 0), closed_at: null,
  }))
  // ③ 逾期 ×1
  list.push(mk({
    source: 'overdue', source_label: '逾期', level: 'high',
    task_id: t3.id, task_no: t3.task_no, product_code: t3.product!.code, product_name: t3.product!.name,
    order_no: 'O3', qty: t3.qty, done_qty: t3.done_qty, progress_pct: Math.round((t3.done_qty / t3.qty) * 100),
    reason: '交期已过 3 天，预计还需 4 天（含插单顺延）', plan_start: dayTime(-4, 8, 0), plan_end: dayTime(3, 20, 0), delivery_date: day(-3).concat(' 18:00:00'),
    owner_id: 4, owner_name: '王班长', owner_workshop: '注塑一班', status: 'open', closed_by: null, close_reason: null,
    created_at: dayTime(-1, 8, 0), closed_at: null,
  }))
  // ④ 落后 >10% ×1
  list.push(mk({
    source: 'behind', source_label: '落后', level: 'high',
    task_id: t7a.id, task_no: t7a.task_no, product_code: t7a.product!.code, product_name: t7a.product!.name,
    order_no: 'O7', qty: t7a.qty, done_qty: t7a.done_qty, progress_pct: Math.round((t7a.done_qty / t7a.qty) * 100),
    reason: '实际进度 28%，落后计划 15%（要求 >10% 触发）', plan_start: dayTime(-3, 8, 0), plan_end: dayTime(1, 12, 0), delivery_date: t7a.delivery_date,
    owner_id: 4, owner_name: '王班长', owner_workshop: '注塑一班', status: 'open', closed_by: null, close_reason: null,
    created_at: dayTime(0, 9, 0), closed_at: null,
  }))
  return list
}

// ==================== 产能热力（7 天 × 机台） ====================

function buildHeatmap(machines: Machine[], schedules: TaskSchedule[]): LoadHeatmapData {
  const daysArr: string[] = []
  for (let d = 0; d < 7; d += 1) daysArr.push(day(d))
  const rows: HeatmapRow[] = machines.map((m) => {
    const cells = daysArr.map((date, idx) => {
      if (m.status !== 'running') return { date, load_pct: null, alert: false, machine_id: m.id }
      const dayStart = dayjs(date).startOf('day')
      const dayEnd = dayjs(date).endOf('day')
      const plannedMinutes = schedules
        .filter((schedule) => schedule.machine_id === m.id && dayjs(schedule.plan_start).isBefore(dayEnd) && dayjs(schedule.plan_end).isAfter(dayStart))
        .reduce((sum, schedule) => {
          const start = dayjs(schedule.plan_start).isAfter(dayStart) ? dayjs(schedule.plan_start) : dayStart
          const end = dayjs(schedule.plan_end).isBefore(dayEnd) ? dayjs(schedule.plan_end) : dayEnd
          return sum + Math.max(0, end.diff(start, 'minute'))
        }, 0)
      const availableMinutes = m.shift_mode === 'day' ? 9 * 60 : 24 * 60
      const load = availableMinutes > 0 ? Math.min(100, Math.round(plannedMinutes / availableMinutes * 100)) : null
      return { date, load_pct: load, alert: load !== null && load > 85, machine_id: m.id }
    })
    const values = cells.map((cell) => cell.load_pct).filter((value): value is number => value !== null)
    const avg = values.length > 0 ? Math.round(values.reduce((s, value) => s + value, 0) / values.length) : 0
    return { machine_id: m.id, machine_code: m.code, machine_name: m.name, workshop: m.workshop, cells, avg_load: avg }
  })
  const alertCount = rows.filter((r) => r.cells.some((c) => c.alert)).length
  const avgAll = rows.reduce((s, r) => s + r.avg_load, 0) / rows.length
  const peak = Math.max(...rows.flatMap((r) => r.cells.map((c) => c.load_pct ?? 0)))
  return { start: daysArr[0], end: daysArr[daysArr.length - 1], days: daysArr, rows, alert_threshold: 85, summary: { alert_count: alertCount, avg_load: Math.round(avgAll), peak_load: peak } }
}

// ==================== 报表指标 ====================

function buildReportsData(
  orders: Order[],
  tasks: ProdTask[],
  machines: Machine[],
  schedules: TaskSchedule[],
  reports: WorkReport[],
  molds: Mold[],
): ReportsData {
  const today = dayjs().startOf('day')
  const firstReport = (task: ProdTask) => reports
    .filter((report) => report.task_id === task.id)
    .sort((a, b) => String(a.report_time).localeCompare(String(b.report_time)))[0]
  const deliveryRateFor = (date: dayjs.Dayjs): number | null => {
    const due = orders.filter((order) => order.delivery_date && dayjs(order.delivery_date).isSame(date, 'day'))
    if (due.length === 0) return null
    const onTime = due.filter((order) => order.status === 'produced' && order.completed_at && !dayjs(order.completed_at).isAfter(date.endOf('day')))
    return Math.round(onTime.length / due.length * 1000) / 10
  }
  const utilizationFor = (date: dayjs.Dayjs): number | null => {
    const activeMachines = machines.filter((machine) => machine.status === 'running')
    if (activeMachines.length === 0) return null
    const values = activeMachines.map((machine) => {
      const plannedMinutes = schedules
        .filter((schedule) => schedule.machine_id === machine.id && dayjs(schedule.plan_start).isBefore(date.endOf('day')) && dayjs(schedule.plan_end).isAfter(date.startOf('day')))
        .reduce((sum, schedule) => {
          const start = dayjs(schedule.plan_start).isAfter(date.startOf('day')) ? dayjs(schedule.plan_start) : date.startOf('day')
          const end = dayjs(schedule.plan_end).isBefore(date.endOf('day')) ? dayjs(schedule.plan_end) : date.endOf('day')
          return sum + Math.max(0, end.diff(start, 'minute'))
        }, 0)
      const availableMinutes = machine.shift_mode === 'day' ? 9 * 60 : 24 * 60
      return Math.min(100, Math.round(plannedMinutes / availableMinutes * 100))
    })
    return Math.round(values.reduce((sum, value) => sum + value, 0) / values.length * 10) / 10
  }
  const timelyRateFor = (start: dayjs.Dayjs, end: dayjs.Dayjs): number | null => {
    const weekTasks = tasks.filter((task) => task.plan_start && !dayjs(task.plan_start).isBefore(start) && !dayjs(task.plan_start).isAfter(end))
    if (weekTasks.length === 0) return null
    const timely = weekTasks.filter((task) => {
      const first = firstReport(task)
      return first !== undefined && task.plan_start !== null && !dayjs(first.report_time).isAfter(dayjs(task.plan_start).add(3, 'hour'))
    })
    return Math.round(timely.length / weekTasks.length * 1000) / 10
  }
  const defectRateFor = (date: dayjs.Dayjs): number | null => {
    const dayReports = reports.filter((report) => dayjs(report.work_date ?? report.report_time).isSame(date, 'day'))
    const qualified = dayReports.reduce((sum, report) => sum + report.qty, 0)
    const defect = dayReports.reduce((sum, report) => sum + (report.defect_qty ?? 0), 0)
    const base = qualified + defect
    return base > 0 ? Math.round(defect / base * 1000) / 10 : null
  }
  const deliverySeries = Array.from({ length: 7 }, (_, index) => {
    const date = today.subtract(6 - index, 'day')
    return { date: date.format('YYYY-MM-DD'), value: deliveryRateFor(date) }
  })
  const utilizationSeries = Array.from({ length: 7 }, (_, index) => {
    const date = today.add(index, 'day')
    return { date: date.format('YYYY-MM-DD'), value: utilizationFor(date) }
  })
  const timelySeries = Array.from({ length: 4 }, (_, index) => {
    const start = today.startOf('week').subtract(3 - index, 'week')
    const end = start.endOf('week')
    return { date: start.format('YYYY-MM-DD') + ' ~ ' + end.format('MM-DD'), value: timelyRateFor(start, end) }
  })
  const defectSeries = Array.from({ length: 7 }, (_, index) => {
    const date = today.subtract(6 - index, 'day')
    return { date: date.format('YYYY-MM-DD'), value: defectRateFor(date) }
  })
  const totalQty = reports.reduce((sum, report) => sum + report.qty, 0)
  const defectQty = reports.reduce((sum, report) => sum + (report.defect_qty ?? 0), 0)
  const defectBase = totalQty + defectQty
  const scheduledTasks = tasks.filter((task) => task.plan_start)
  const timely = scheduledTasks.filter((task) => {
    const first = firstReport(task)
    return first !== undefined && task.plan_start !== null && !dayjs(first.report_time).isAfter(dayjs(task.plan_start).add(3, 'hour'))
  })
  const utilizationValues = utilizationSeries.map((point) => point.value).filter((value): value is number => value !== null)
  const moldLife = molds
    .filter((mold) => mold.lifetime_openings > 0)
    .map((mold) => ({
      mold_id: mold.id,
      mold_code: mold.code,
      mold_name: mold.name,
      life_used_pct: Math.round(mold.opened_count / mold.lifetime_openings * 1000) / 10,
      maintain_due: Boolean(mold.next_maintain_at && !dayjs(mold.next_maintain_at).isAfter(today)),
    }))
    .sort((a, b) => b.life_used_pct - a.life_used_pct)
    .slice(0, 5)
  const deliveryValues = deliverySeries.map((point) => point.value).filter((value): value is number => value !== null)
  const deliveryRate = orders.length > 0 ? Math.round(orders.filter((order) => order.status === 'produced').length / orders.length * 100) : 100
  const overdueOrders = orders.filter((order) => order.status !== 'produced' && order.delivery_date && dayjs(order.delivery_date).isBefore(today)).length
  return {
    metrics: [
      { key: 'delivery_rate', label: '交期达成率', value: deliveryRate, unit: '%', trend: 'flat', compare_text: '按订单状态实时计算' },
      { key: 'utilization', label: '机台利用率', value: utilizationValues.length ? Math.round(utilizationValues.reduce((sum, value) => sum + value, 0) / utilizationValues.length) : 0, unit: '%', trend: 'flat', compare_text: '未来 7 天计划负荷' },
      { key: 'report_timely', label: '报工及时率', value: scheduledTasks.length ? Math.round(timely.length / scheduledTasks.length * 100) : 0, unit: '%', trend: 'flat', compare_text: '首报 ≤ 计划起点+3 小时' },
      { key: 'defect_rate', label: '综合次品率', value: defectBase > 0 ? Math.round(defectQty / defectBase * 1000) / 10 : 0, unit: '%', trend: 'flat', compare_text: '报工流水净值' },
      { key: 'overdue_orders', label: '逾期订单', value: overdueOrders, unit: '单', trend: 'flat', compare_text: '未完工且已逾期' },
      { key: 'mold_life_avg', label: '模具寿命平均消耗', value: moldLife.length ? Math.round(moldLife.reduce((sum, mold) => sum + mold.life_used_pct, 0) / moldLife.length * 10) / 10 : 0, unit: '%', trend: 'flat', compare_text: '开模数/设计寿命' },
    ],
    delivery_series: { label: '交期达成率（近 7 天）', data: deliverySeries },
    utilization_series: { label: '机台利用率（未来 7 天计划负荷）', data: utilizationSeries },
    report_timely_series: { label: '报工及时率（近 4 周）', data: timelySeries },
    defect_series: { label: '次品率（近 7 天）', data: defectSeries },
    mold_life: moldLife,
  }
}

// ==================== 工作台 / 订单总览 ====================

function buildWorkbench(orders: Order[], tasks: ProdTask[], redLights: RedLight[], machines: Machine[], schedules: TaskSchedule[], reports: WorkReport[]): WorkbenchData {
  const stats: WorkbenchStats = {
    orders: {
      total: orders.length,
      pending: orders.filter((o) => o.status === 'pending').length,
      in_progress: orders.filter((o) => o.status === 'in_progress').length,
      overdue: orders.filter((o) => o.status !== 'produced' && o.delivery_date && dayjs(o.delivery_date).isBefore(dayjs().endOf('day'))).length,
      done: orders.filter((o) => o.status === 'produced').length,
    },
    tasks: {
      total: tasks.length,
      unscheduled: tasks.filter((t) => t.status === 'unscheduled').length,
      running: tasks.filter((t) => t.status === 'in_progress').length,
      overdue: tasks.filter((t) => t.status !== 'done' && t.delivery_date && dayjs(t.delivery_date).isBefore(dayjs())).length,
      behind: tasks.filter((t) => t.deviation_pct > 10).length,
    },
    today: {
      report_qty: reports.filter((r) => dayjs(r.report_time).isSame(dayjs(), 'day') && r.sign === 1).reduce((s, r) => s + r.qty, 0),
      stock_in_qty: 11000,
      report_count: reports.filter((r) => dayjs(r.report_time).isSame(dayjs(), 'day')).length,
    },
    red_lights: {
      total: redLights.filter((r) => r.status === 'open').length,
      by_source: Object.entries(
        redLights.filter((r) => r.status === 'open').reduce<Record<string, number>>((acc, r) => {
          acc[r.source_label] = (acc[r.source_label] ?? 0) + 1
          return acc
        }, {}),
      ).map(([label, count]) => ({ source: label, source_label: label, count })),
    },
    machines: {
      total: machines.length,
      running: machines.filter((m) => m.status === 'running').length,
      maintenance: machines.filter((m) => m.status === 'maintenance').length,
      utilization_avg: 74,
    },
  }
  const soonOrders: WorkbenchData['soon_orders'] = orders
    .filter((o) => o.status !== 'produced' && o.delivery_date && dayjs(o.delivery_date).isAfter(dayjs()) && dayjs(o.delivery_date).diff(dayjs(), 'day') <= 3)
    .slice(0, 5)
    .map((o) => ({
      order_id: o.id,
      order_no: o.order_no,
      customer_name: o.customer_name,
      delivery_date: o.delivery_date ?? '',
      total_qty: o.total_qty ?? 0,
      progress_pct: o.status === 'in_progress' ? 45 : o.status === 'scheduled' ? 20 : 0,
    }))
  const todayTasks: WorkbenchData['today_tasks'] = schedules
    .filter((s) => dayjs(s.plan_start).isSame(dayjs(), 'day'))
    .slice(0, 6)
    .map((s) => {
      const machine = machines.find((m) => m.id === s.machine_id)
      return {
        task_id: s.task_id,
        task_no: s.task_no ?? '',
        product_code: s.product_code ?? '',
        product_name: s.product_name ?? '',
        machine_code: machine?.code ?? null,
        plan_start: s.plan_start,
        plan_end: s.plan_end,
        status: s.status,
        progress_pct: s.done_qty && s.qty ? Math.round((s.done_qty / s.qty) * 100) : 0,
      }
    })
  return { stats, top_red_lights: redLights.filter((r) => r.status === 'open').slice(0, 5), soon_orders: soonOrders, today_tasks: todayTasks }
}

function buildOrderOverview(orders: Order[], orderLines: Record<number, OrderLine[]>, tasks: ProdTask[], schedules: TaskSchedule[]): OrderOverviewData {
  const items: OrderProgress[] = orders.map((o) => {
    const lines = orderLines[o.id] ?? []
    const lineIds = new Set(lines.map((line) => line.id))
    const relatedTasks = tasks.filter((task) => lineIds.has(task.order_line_id) && !task.is_split_parent)
    const relatedSchedules = schedules.filter((schedule) => relatedTasks.some((task) => task.id === schedule.task_id))
    const scheduledTaskIds = new Set(relatedSchedules.map((schedule) => schedule.task_id))
    const unscheduledTasks = relatedTasks.filter((task) => !scheduledTaskIds.has(task.id))
    const totalQty = lines.reduce((sum, line) => sum + line.qty, 0)
    const doneQty = Math.min(totalQty, Math.max(0, relatedTasks.reduce((sum, task) => sum + task.done_qty, 0)))
    const processPlanQty = relatedSchedules.reduce((sum, schedule) => sum + schedule.qty, 0)
      + unscheduledTasks.reduce((sum, task) => sum + task.qty, 0)
    const processDoneQty = relatedSchedules.reduce((sum, schedule) => sum + Math.max(0, schedule.done_qty ?? 0), 0)
      + unscheduledTasks.reduce((sum, task) => sum + Math.max(0, task.done_qty), 0)
    const stockedQty = Math.min(totalQty, Math.max(0, relatedTasks.reduce((sum, task) => sum + (task.stocked_qty ?? 0), 0)))
    const planStart = relatedTasks.map((task) => task.plan_start).filter(Boolean).sort()[0] ?? null
    const planEnd = relatedTasks.map((task) => task.plan_end).filter(Boolean).sort().reverse()[0] ?? null
    const forecastEnd = relatedSchedules.map((schedule) => schedule.forecast_end ?? schedule.plan_end).filter(Boolean).sort().reverse()[0] ?? planEnd
    const actualEnd = relatedSchedules.map((schedule) => schedule.actual_end).filter(Boolean).sort().reverse()[0] ?? null
    const deliveryDate = o.delivery_date ?? lines.map((line) => line.delivery_date).filter(Boolean).sort()[0] ?? null
    const completed = o.status === 'produced' || (totalQty > 0 && doneQty >= totalQty)
    const due = deliveryDate ? dayjs(deliveryDate).startOf('day') : null
    const today = dayjs().startOf('day')
    const completionDay = actualEnd ? dayjs(actualEnd).startOf('day') : null
    const lateCompleted = completed && !!due && !!completionDay && completionDay.isAfter(due)
    const overdue = !completed && !!due && today.isAfter(due)
    const forecastLate = !completed && !overdue && !!due && !!forecastEnd && dayjs(forecastEnd).startOf('day').isAfter(due)
    const paused = o.status === 'paused' || relatedTasks.some((task) => task.status === 'paused')
    const hasStarted = doneQty > 0 || relatedTasks.some((task) => task.status === 'in_progress')
    const hasSchedule = relatedTasks.some((task) => !!task.plan_start || schedules.some((schedule) => schedule.task_id === task.id))
    const fulfillmentStatus = o.status === 'aborted'
      ? 'aborted'
      : lateCompleted
        ? 'completed_late'
        : completed
          ? 'completed_on_time'
          : paused
            ? 'paused'
            : overdue
              ? 'overdue'
              : forecastLate
                ? 'forecast_late'
                : !hasSchedule && !hasStarted
                  ? 'not_started'
                  : relatedTasks.some((task) => task.status === 'scheduled') && !hasStarted
                    ? 'scheduled'
                    : 'in_progress'
    const fulfillmentLabels: Record<string, string> = {
      completed_on_time: '按期完成', completed_late: '延期完成', overdue: '已延期', forecast_late: '预计延期',
      paused: '已暂停', not_started: '未开工', scheduled: '已排产', in_progress: '生产中', aborted: '已中止',
    }
    const compareDate = fulfillmentStatus.startsWith('completed') ? completionDay : (forecastEnd ? dayjs(forecastEnd).startOf('day') : today)
    const delayDays = due && compareDate && compareDate.isAfter(due) ? due.diff(compareDate, 'day') * -1 : 0
    const dueFlag: OrderProgress['due_flag'] = !completed && due
      ? today.isAfter(due) ? 'overdue' : today.diff(due, 'day') >= -3 ? 'due_soon' : 'normal'
      : 'normal'

    return {
      order_id: o.id,
      order_no: o.order_no,
      k3_bill_no: o.k3_bill_no,
      customer_name: o.customer_name,
      priority: o.priority,
      delivery_date: deliveryDate,
      status: o.status,
      status_label: o.status === 'pending' ? '待排' : o.status === 'scheduled' ? '已排' : o.status === 'in_progress' ? '在产' : o.status === 'paused' ? '已停工' : o.status === 'produced' ? '已产' : o.status === 'aborted' ? '已中止' : '已关闭',
      total_qty: totalQty,
      done_qty: doneQty,
      process_plan_qty: processPlanQty,
      process_done_qty: processDoneQty,
      process_progress_pct: processPlanQty > 0 ? Math.min(100, Math.round(processDoneQty / processPlanQty * 1000) / 10) : 0,
      stocked_qty: stockedQty,
      progress_pct: totalQty ? Math.min(100, Math.round((doneQty / totalQty) * 100)) : 0,
      stock_progress_pct: totalQty ? Math.min(100, Math.round((stockedQty / totalQty) * 100)) : 0,
      due_flag: dueFlag,
      delay_days: delayDays,
      fulfillment_status: fulfillmentStatus,
      fulfillment_status_label: fulfillmentLabels[fulfillmentStatus],
      tasks_count: relatedTasks.length,
      completed_tasks: relatedTasks.filter((task) => task.status === 'done').length,
      paused_tasks: relatedTasks.filter((task) => task.status === 'paused').length,
      plan_start: planStart,
      plan_end: planEnd,
      forecast_end: forecastEnd,
      actual_end: actualEnd,
      task_status: completed ? 'done' : fulfillmentStatus === 'not_started' ? 'unscheduled' : fulfillmentStatus === 'scheduled' ? 'scheduled' : 'in_progress',
    }
  })
  const sum = (pred: (o: OrderProgress) => boolean) => items.filter(pred).reduce((s, i) => s + i.total_qty, 0)
  const completed = items.filter((i) => i.fulfillment_status?.startsWith('completed'))
  const completedWithDelivery = completed.filter((i) => i.delivery_date)
  return {
    summary: {
      total_orders: items.length,
      production_completed: completed.length,
      on_time_completed: items.filter((i) => i.fulfillment_status === 'completed_on_time').length,
      late_completed: items.filter((i) => i.fulfillment_status === 'completed_late').length,
      in_progress: items.filter((i) => i.fulfillment_status === 'in_progress' || i.fulfillment_status === 'scheduled').length,
      forecast_late: items.filter((i) => i.fulfillment_status === 'forecast_late').length,
      overdue: items.filter((i) => i.fulfillment_status === 'overdue').length,
      not_started: items.filter((i) => i.fulfillment_status === 'not_started').length,
      paused: items.filter((i) => i.fulfillment_status === 'paused').length,
      total_qty: items.reduce((sum, item) => sum + item.total_qty, 0),
      done_qty: items.reduce((sum, item) => sum + item.done_qty, 0),
      process_plan_qty: items.reduce((sum, item) => sum + item.process_plan_qty, 0),
      process_done_qty: items.reduce((sum, item) => sum + item.process_done_qty, 0),
      process_progress_pct: items.reduce((sum, item) => sum + item.process_plan_qty, 0) > 0
        ? Math.min(100, Math.round(items.reduce((sum, item) => sum + item.process_done_qty, 0) / items.reduce((sum, item) => sum + item.process_plan_qty, 0) * 1000) / 10)
        : 0,
      stocked_qty: items.reduce((sum, item) => sum + (item.stocked_qty ?? 0), 0),
      production_completion_pct: items.reduce((sum, item) => sum + item.total_qty, 0) > 0 ? Math.round(items.reduce((sum, item) => sum + item.done_qty, 0) / items.reduce((sum, item) => sum + item.total_qty, 0) * 1000) / 10 : 0,
      stock_completion_pct: items.reduce((sum, item) => sum + item.total_qty, 0) > 0 ? Math.round(items.reduce((sum, item) => sum + (item.stocked_qty ?? 0), 0) / items.reduce((sum, item) => sum + item.total_qty, 0) * 1000) / 10 : 0,
      on_time_rate: completedWithDelivery.length > 0 ? Math.round(completedWithDelivery.filter((i) => i.fulfillment_status === 'completed_on_time').length / completedWithDelivery.length * 1000) / 10 : null,
    },
    groups: {
      pending: { count: items.filter((i) => i.fulfillment_status === 'not_started').length, qty: sum((i) => i.fulfillment_status === 'not_started') },
      in_progress: { count: items.filter((i) => i.fulfillment_status === 'in_progress' || i.fulfillment_status === 'scheduled').length, qty: sum((i) => i.fulfillment_status === 'in_progress' || i.fulfillment_status === 'scheduled') },
      overdue: { count: items.filter((i) => i.due_flag === 'overdue').length, qty: sum((i) => i.due_flag === 'overdue') },
      done: { count: completed.length, qty: sum((i) => i.fulfillment_status?.startsWith('completed') === true) },
    },
    items,
  }
}

// ==================== 集成日志 / 审计 ====================

function buildIntegrationLogs(): IntegrationLog[] {
  return [
    { id: 1, direction: 'out', target: 'k3', api_type: 'save', biz_type: 'stock_in', biz_id: 1, request_body: { formid: 'STK_MISCELLANEOUS' }, response_body: { Id: 900001, Number: 'K3-IN-0001' }, status: 'success', status_label: '成功', retry_count: 0, error_msg: null, created_at: dayTime(-12, 15, 1), finished_at: dayTime(-12, 15, 1) },
    { id: 2, direction: 'out', target: 'k3', api_type: 'save', biz_type: 'stock_in', biz_id: 3, request_body: { formid: 'STK_MISCELLANEOUS' }, response_body: null, status: 'queued', status_label: '待推送', retry_count: 0, error_msg: null, created_at: dayTime(0, 14, 31), finished_at: null },
    { id: 3, direction: 'out', target: 'k3', api_type: 'save', biz_type: 'stock_in', biz_id: 4, request_body: { formid: 'STK_MISCELLANEOUS' }, response_body: null, status: 'failed', status_label: '失败', retry_count: 2, error_msg: 'K3 业务校验失败：物料未备案（mock）', created_at: dayTime(-1, 15, 11), finished_at: dayTime(-1, 15, 21) },
    { id: 4, direction: 'in', target: 'k3', api_type: 'execute_bill_query', biz_type: 'prod_order', biz_id: null, request_body: { cursor: 100 }, response_body: { count: 2 }, status: 'success', status_label: '成功', retry_count: 0, error_msg: null, created_at: dayTime(-2, 10, 0), finished_at: dayTime(-2, 10, 0) },
    { id: 5, direction: 'out', target: 'k3', api_type: 'save', biz_type: 'bom', biz_id: 2, request_body: { formid: 'ENG_BOM' }, response_body: { Id: 500001, Number: 'BOM-0002' }, status: 'success', status_label: '成功', retry_count: 0, error_msg: null, created_at: dayTime(-10, 9, 1), finished_at: dayTime(-10, 9, 1) },
    { id: 6, direction: 'in', target: 'mini_program', api_type: 'progress_writeback', biz_type: 'order', biz_id: 3, request_body: { done_qty: 19500 }, response_body: { accepted: true }, status: 'success', status_label: '成功', retry_count: 0, error_msg: null, created_at: dayTime(0, 10, 0), finished_at: dayTime(0, 10, 0) },
  ]
}

function buildAuditLogs(): AuditLog[] {
  const user2 = { id: 2, employee_no: 'E001', name: '张工' }
  const user4 = { id: 4, employee_no: 'L001', name: '王班长' }
  const user3 = { id: 3, employee_no: 'P001', name: '李计划' }
  return [
    { id: 1, user_id: 2, user: user2, module: 'plm', action: 'publish_bom', target_type: 'bom', target_id: 2, detail: { product_id: 1, version: 'V2.0', k3_push: 'success' }, ip: '127.0.0.1', created_at: dayTime(-10, 9, 0) },
    { id: 2, user_id: 3, user: user3, module: 'scheduling', action: 'schedule_insert_confirm', target_type: 'order', target_id: 3, detail: { affected_orders: 2, strategy: 'interrupt' }, ip: '127.0.0.1', created_at: dayTime(-4, 16, 20) },
    { id: 3, user_id: 4, user: user4, module: 'production', action: 'report_submit', target_type: 'task', target_id: 103, detail: { qty: 3500, source: 'scan' }, ip: '192.168.1.21', created_at: dayTime(-1, 14, 0) },
    { id: 4, user_id: 1, user: { id: 1, employee_no: 'admin', name: '系统管理员' }, module: 'warehouse', action: 'stock_in_create', target_type: 'task', target_id: 103, detail: { qty: -2000, remark: '红字回冲' }, ip: '127.0.0.1', created_at: dayTime(0, 10, 5) },
    { id: 5, user_id: 1, user: { id: 1, employee_no: 'admin', name: '系统管理员' }, module: 'dashboard', action: 'red_light_close', target_type: 'red_light', target_id: 1, detail: { reason: '已重新排产确认' }, ip: '127.0.0.1', created_at: dayTime(-1, 17, 0) },
  ]
}

// ==================== 生产异常（8 条 · 四类全覆盖） ====================

function buildExceptions(tasks: ProdTask[]): ProductionException[] {
  const t3 = tasks.find((t) => t.id === 104) // O3 直口杯（逾期）
  const t7 = tasks.find((t) => t.id === 109) // O7 方盒（落后）
  const t6 = tasks.find((t) => t.id === 103) // O2 餐盒
  const machine = (code: string) => buildMachines().find((m) => m.code === code) ?? null
  const mk = (r: Omit<ProductionException, 'id'>): ProductionException => {
    seq += 1
    return { id: seq, ...r }
  }
  let seq = 0
  const list: ProductionException[] = []

  // 停机
  list.push(mk({
    type: 'stop_machine', type_label: '停机', task_id: t3?.id ?? null, task_no: t3?.task_no ?? null,
    machine_id: machine('I-01')?.id ?? null, machine_code: 'I-01', product_code: t3?.product?.code ?? null, product_name: t3?.product?.name ?? null,
    level: 'high', desc: '注塑机 I-01 液压阀报警停机，已通知维修，预计停机 2 小时', report_by: '王班长', report_time: dayTime(0, 9, 10),
    status: 'processing', status_label: '处理中', handler: '维修组·李师傅', handle_time: dayTime(0, 9, 40), handle_result: '液压阀清洗中',
  }))
  list.push(mk({
    type: 'stop_machine', type_label: '停机', task_id: null, task_no: null,
    machine_id: machine('B-02')?.id ?? null, machine_code: 'B-02', product_code: null, product_name: null,
    level: 'medium', desc: '吹塑机 B-02 空压压力不足，夜班临时停机 40 分钟', report_by: '刘班长', report_time: dayTime(-1, 22, 30),
    status: 'closed', status_label: '已关闭', handler: '维修组·王师傅', handle_time: dayTime(-1, 23, 10), handle_result: '空压机恢复，已重新开机',
  }))
  // 换模
  list.push(mk({
    type: 'mold_change', type_label: '换模', task_id: t6?.id ?? null, task_no: t6?.task_no ?? null,
    machine_id: machine('I-02')?.id ?? null, machine_code: 'I-02', product_code: t6?.product?.code ?? null, product_name: t6?.product?.name ?? null,
    level: 'low', desc: '餐盒模具计划换模（M-006 → M-003），预计 35 分钟', report_by: '王班长', report_time: dayTime(0, 7, 50),
    status: 'open', status_label: '待处理', handler: null, handle_time: null, handle_result: null,
  }))
  list.push(mk({
    type: 'mold_change', type_label: '换模', task_id: t7?.id ?? null, task_no: t7?.task_no ?? null,
    machine_id: machine('I-03')?.id ?? null, machine_code: 'I-03', product_code: t7?.product?.code ?? null, product_name: t7?.product?.name ?? null,
    level: 'medium', desc: '方盒模具 M-003 拉伤，换备用模 M-003A（寿命 12 万次）', report_by: '赵师傅', report_time: dayTime(-1, 14, 20),
    status: 'processing', status_label: '处理中', handler: '模具组·张工', handle_time: dayTime(-1, 15, 0), handle_result: '备用模已上机试打',
  }))
  // 次品
  list.push(mk({
    type: 'defect', type_label: '次品', task_id: t3?.id ?? null, task_no: t3?.task_no ?? null,
    machine_id: machine('I-01')?.id ?? null, machine_code: 'I-01', product_code: t3?.product?.code ?? null, product_name: t3?.product?.name ?? null,
    level: 'high', desc: '直口杯边缘毛刺批量次品（约 8%），已停机检查模具排气', report_by: '赵师傅', report_time: dayTime(0, 10, 5),
    status: 'processing', status_label: '处理中', handler: '工艺组·张工', handle_time: dayTime(0, 10, 30), handle_result: '调整注塑参数（保压 2s→2.5s）',
  }))
  list.push(mk({
    type: 'defect', type_label: '次品', task_id: t6?.id ?? null, task_no: t6?.task_no ?? null,
    machine_id: machine('I-02')?.id ?? null, machine_code: 'I-02', product_code: t6?.product?.code ?? null, product_name: t6?.product?.name ?? null,
    level: 'medium', desc: '餐盒壁厚偏薄 0.15mm，抽检 20 只 3 只不达标', report_by: '钱师傅', report_time: dayTime(-1, 16, 0),
    status: 'closed', status_label: '已关闭', handler: '工艺组·王工', handle_time: dayTime(-1, 17, 30), handle_result: '模温上调 5℃，复检合格',
  }))
  // 缺料
  list.push(mk({
    type: 'material_short', type_label: '缺料', task_id: t7?.id ?? null, task_no: t7?.task_no ?? null,
    machine_id: null, machine_code: null, product_code: t7?.product?.code ?? null, product_name: t7?.product?.name ?? null,
    level: 'high', desc: '白色母粒库存仅剩 12kg，预计 6 小时后断料，需紧急采购', report_by: '王班长', report_time: dayTime(0, 8, 20),
    status: 'open', status_label: '待处理', handler: null, handle_time: null, handle_result: null,
  }))
  list.push(mk({
    type: 'material_short', type_label: '缺料', task_id: null, task_no: null,
    machine_id: null, machine_code: null, product_code: null, product_name: null,
    level: 'medium', desc: '封口膜 FILM-12-01 采购到货延迟，影响餐盒包装工序', report_by: '郑组长', report_time: dayTime(-1, 11, 0),
    status: 'closed', status_label: '已关闭', handler: '采购组·孙业务', handle_time: dayTime(-1, 15, 20), handle_result: '已催供应商，次日到货',
  }))
  return list
}

// ==================== 新品开发流程（8 条 · 7 阶段看板） ====================

function buildDevelopTasks(): DevelopTask[] {
  const stageLabel = (key: string): string =>
    ({ inquiry: '询价', project: '立项', design: '设计', mold_making: '开模', trial: '试模', small_batch: '小批', mass: '量产' })[key] ?? key
  const mk = (r: Omit<DevelopTask, 'id'>): DevelopTask => {
    seq += 1
    return { id: seq, ...r }
  }
  let seq = 0
  const list: DevelopTask[] = []
  const logs = (current: string, at: string): DevelopTask['logs'] => {
    const order = ['inquiry', 'project', 'design', 'mold_making', 'trial', 'small_batch', 'mass']
    const done: Array<{ stage: string; stage_label: string; status: string; at: string }> = []
    for (const key of order) {
      if (key === current) {
        done.push({ stage: key, stage_label: stageLabel(key), status: 'doing', at })
        break
      }
      done.push({ stage: key, stage_label: stageLabel(key), status: 'done', at: dayTime(-(order.length - order.indexOf(key)), 9, 0) })
    }
    return done
  }

  list.push(mk({
    dev_no: 'DEV-2026-001', product_code: 'HY-2001', product_name: '折叠收纳箱 18L', spec: 'PP 材质 · 折叠款 · 带轮', customer_name: '江苏新巢日化',
    stage: 'mass', stage_label: '量产', stage_status: 'done', priority: 4, plan_finish: day(-2).concat(' 18:00:00'), owner_name: '张工', overdue: false,
    remark: '已量产 2 个月，月产 8 万只', logs: logs('mass', dayTime(-30, 10, 0)),
  }))
  list.push(mk({
    dev_no: 'DEV-2026-002', product_code: 'HY-2002', product_name: '按压泵瓶 500ml', spec: 'PP 材质 · 泵头款 · 磨砂', customer_name: '上海丽人日化',
    stage: 'small_batch', stage_label: '小批', stage_status: 'doing', priority: 5, plan_finish: day(3).concat(' 18:00:00'), owner_name: '张工', overdue: false,
    remark: '小批 5000 只，试模通过，等待客户签样', logs: logs('small_batch', dayTime(-6, 14, 0)),
  }))
  list.push(mk({
    dev_no: 'DEV-2026-003', product_code: 'HY-2003', product_name: '广口罐 2L', spec: 'HDPE 材质 · 广口 · 密封盖', customer_name: '杭州绿洁',
    stage: 'trial', stage_label: '试模', stage_status: 'doing', priority: 3, plan_finish: day(5).concat(' 18:00:00'), owner_name: '李工', overdue: false,
    remark: '首次试模，注塑压力偏高需调整', logs: logs('trial', dayTime(-3, 10, 0)),
  }))
  list.push(mk({
    dev_no: 'DEV-2026-004', product_code: 'HY-2004', product_name: '化妆瓶 100ml', spec: 'PET 材质 · 圆瓶 · 透明', customer_name: '广州美妆集',
    stage: 'mold_making', stage_label: '开模', stage_status: 'doing', priority: 4, plan_finish: day(8).concat(' 18:00:00'), owner_name: '陈工', overdue: false,
    remark: '模具 M-301 开模中，预计 7 天', logs: logs('mold_making', dayTime(-8, 9, 0)),
  }))
  list.push(mk({
    dev_no: 'DEV-2026-005', product_code: 'HY-2005', product_name: '油壶 5L', spec: 'PE 材质 · 带刻度 · 防漏嘴', customer_name: '北京粮油汇',
    stage: 'design', stage_label: '设计', stage_status: 'doing', priority: 3, plan_finish: day(10).concat(' 18:00:00'), owner_name: '李工', overdue: false,
    remark: '3D 建模 90%，待评审', logs: logs('design', dayTime(-12, 9, 0)),
  }))
  list.push(mk({
    dev_no: 'DEV-2026-006', product_code: 'HY-2006', product_name: '水杯 350ml 儿童款', spec: 'Tritan 材质 · 防摔 · 吸管', customer_name: '深圳童乐',
    stage: 'design', stage_label: '设计', stage_status: 'todo', priority: 2, plan_finish: day(15).concat(' 18:00:00'), owner_name: '陈工', overdue: false,
    remark: '客户需求确认中', logs: logs('design', dayTime(-14, 10, 0)),
  }))
  list.push(mk({
    dev_no: 'DEV-2026-007', product_code: 'HY-2007', product_name: '提手桶 8L', spec: 'PE 材质 · 加强筋 · 提手', customer_name: '武汉中百',
    stage: 'project', stage_label: '立项', stage_status: 'doing', priority: 3, plan_finish: day(18).concat(' 18:00:00'), owner_name: '张工', overdue: false,
    remark: '立项评审通过，进入设计阶段', logs: logs('project', dayTime(-16, 9, 30)),
  }))
  list.push(mk({
    dev_no: 'DEV-2026-008', product_code: 'HY-2008', product_name: '收纳盒 6L 三件套', spec: 'PP 材质 · 透明 · 叠放', customer_name: '京东自营',
    stage: 'inquiry', stage_label: '询价', stage_status: 'doing', priority: 4, plan_finish: day(22).concat(' 18:00:00'), owner_name: '孙业务', overdue: false,
    remark: '客户询价中，待 AI 估价输出', logs: logs('inquiry', dayTime(-18, 11, 0)),
  }))
  // 逾期样例：油壶 5L 设计阶段已逾期 3 天
  list.push(mk({
    dev_no: 'DEV-2026-009', product_code: 'HY-2009', product_name: '洗衣液桶 4L', spec: 'HDPE 材质 · 带提手', customer_name: '苏州绿叶',
    stage: 'design', stage_label: '设计', stage_status: 'doing', priority: 5, plan_finish: day(-3).concat(' 18:00:00'), owner_name: '李工', overdue: true,
    remark: '客户临时变更瓶口螺纹，设计返工 3 天', logs: logs('design', dayTime(-15, 9, 0)),
  }))
  return list
}

// ==================== 报价参数 / 报价单（五期） ====================

function buildMaterialPrices(): MaterialPrice[] {
  const today = day(0)
  return [
    { id: 1, material_code: 'RAW-PP-001', material_name: 'PP 原料 聚丙烯', price_per_unit: 8.5, unit: 'kg', effective_date: today, source: 'manual' },
    { id: 2, material_code: 'RAW-PET-001', material_name: 'PET 瓶坯', price_per_unit: 6.2, unit: '个', effective_date: today, source: 'manual' },
    { id: 3, material_code: 'PKG-BOX-001', material_name: '中号彩盒（直口杯）', price_per_unit: 0.8, unit: '个', effective_date: today, source: 'manual' },
    { id: 4, material_code: 'PKG-BOX-002', material_name: '圆瓶彩盒', price_per_unit: 0.9, unit: '个', effective_date: today, source: 'manual' },
    { id: 5, material_code: 'PUMP-300-01', material_name: '300ml 泵头总成', price_per_unit: 1.6, unit: '个', effective_date: today, source: 'manual' },
  ]
}

function buildProcessRates(): ProcessRate[] {
  const today = day(0)
  return [
    { id: 1, rate_key: 'molding', op_type: 'molding_injection', rate_type: 'machine', rate: 55, effective_date: today },
    { id: 2, rate_key: 'molding_blow', op_type: 'molding_blow', rate_type: 'machine', rate: 45, effective_date: today },
    { id: 3, rate_key: 'paint', op_type: 'paint', rate_type: 'line', rate: 30, effective_date: today },
    { id: 4, rate_key: 'effect', op_type: 'effect', rate_type: 'line', rate: 28, effective_date: today },
    { id: 5, rate_key: 'pack', op_type: 'pack', rate_type: 'line', rate: 20, effective_date: today },
  ]
}

function buildQuoteRules(): QuoteRuleItem[] {
  return [
    { id: 1, rule_key: 'loss_rate', rule_value: 2, remark: '行业损耗率 %' },
    { id: 2, rule_key: 'mold_amort_rule', rule_value: 1, remark: '摊销规则：1=全额按数量摊薄' },
    { id: 3, rule_key: 'min_margin', rule_value: 15, remark: '毛利下限 %' },
    { id: 4, rule_key: 'anomaly_deviation', rule_value: 30, remark: '异常价偏差 %' },
  ]
}

function buildQuotes(products: Product[]): Quote[] {
  const p1 = products.find((p) => p.code === 'P-0001') ?? null
  const p2 = products.find((p) => p.code === 'P-0002') ?? null
  const mkLine = (quoteId: number, spec: string, qty: number, price: number, total: number): QuoteLine => ({
    id: quoteId * 10 + 1,
    quote_id: quoteId,
    spec_text: spec,
    qty,
    ref_product_id: p1?.id ?? null,
    product_id: p1?.id ?? null,
    product: p1 ? { id: p1.id, code: p1.code, name: p1.name } : null,
    material_cost: 9.47,
    process_cost: 0.62,
    mold_amort: 88,
    loss_cost: 0.2,
    total_cost: total,
    margin: 20,
    suggest_price: price,
    delivery_days: 5,
    llm_remark: 'AI 辅助未启用（演示），以上为规则引擎确定性估算',
    ai_assist: 'not_enabled',
    anomaly_flag: false,
  })
  return [
    {
      id: 1, quote_no: `QT${day(0).replace(/-/g, '')}-A01`, customer_name: '江苏新巢日化', source: 'sales', status: 'draft', status_label: '草稿',
      target_margin: 20, suggest_delivery: day(5), final_price: null, created_by: 6, remark: '折叠收纳箱询价（演示）',
      lines: [mkLine(1, 'PP 材质 · 折叠款 · 带轮', 1000, 122.86, 98.29)], created_at: dayTime(-1, 10, 0),
    },
    {
      id: 2, quote_no: `QT${day(0).replace(/-/g, '')}-A02`, customer_name: '上海丽人日化', source: 'mini_program', status: 'sent', status_label: '已发',
      target_margin: 22, suggest_delivery: day(6), final_price: null, created_by: 6, remark: '按压泵瓶询价（小程序）',
      lines: [mkLine(2, 'PP 材质 · 泵头款 · 磨砂', 5000, 120.5, 96.4)], created_at: dayTime(-2, 14, 0),
    },
    {
      id: 3, quote_no: `QT${day(0).replace(/-/g, '')}-A03`, customer_name: '杭州绿洁', source: 'sales', status: 'won', status_label: '成交',
      target_margin: 20, suggest_delivery: day(8), final_price: 118.0, created_by: 6, remark: '广口罐 2L（成交）',
      lines: [mkLine(3, 'HDPE 材质 · 广口 · 密封盖', 3000, 119.0, 95.2)], created_at: dayTime(-5, 9, 0),
    },
  ].filter((q) => p1 || q.id === 2 || q.id === 3) as Quote[]
}

// ==================== 版本记录 ====================

// ==================== APS 顺延建议（建议层） ====================

/** 从滞后排程生成顺延建议：预计结束晚于计划且突破交期的任务 → pending 建议（另附一条已采纳历史样例） */
function buildSuggestions(tasks: ProdTask[], schedules: TaskSchedule[]): ScheduleSuggestion[] {
  const items: ScheduleSuggestion[] = []
  let sid = 1
  for (const s of schedules) {
    if (!s.forecast_end || s.forecast_end === s.plan_end) continue
    const task = tasks.find((t) => t.id === s.task_id)
    if (!task || !task.delivery_date) continue
    const deadline = `${task.delivery_date} 23:59:59`
    if (s.forecast_end <= deadline) continue
    items.push({
      id: sid++,
      type: 'postpone',
      trigger_report_id: 9000 + s.task_id,
      task_id: task.id,
      affected: [{ schedule_id: s.id, seq: s.seq, op_name: s.op_name ?? s.op_type, plan_end: s.plan_end, forecast_end: s.forecast_end }],
      delayed_orders: [{
        order_id: null,
        order_no: s.order_no ?? null,
        task_no: task.task_no,
        product_code: s.product_code ?? null,
        product_name: s.product_name ?? null,
        delivery_date: task.delivery_date,
        forecast_end: s.forecast_end,
      }],
      reason: `预计完工 ${s.forecast_end.slice(0, 16)} 突破交期 ${task.delivery_date}`,
      status: 'pending',
      created_at: dayjs().subtract(1, 'hour').format('YYYY-MM-DD HH:mm:ss'),
      task: { id: task.id, task_no: task.task_no, delivery_date: task.delivery_date },
    })
  }
  if (items.length > 0) {
    items.push({
      ...items[0],
      id: sid,
      status: 'accepted',
      processed_at: dayTime(-1, 16, 30),
      created_at: dayTime(-1, 15, 0),
      reason: `${items[0].reason}（历史样例，已采纳）`,
    })
  }
  return items
}

// ==================== 版本记录 ====================

function buildVersions(schedules: TaskSchedule[]): ScheduleVersion[] {
  return [
    {
      id: 1,
      version_no: 1,
      type: 'manual',
      status: 'applied',
      snapshot: {
        before: { schedules: [], tasks: {} },
        after: { schedules: schedules.slice(0, 5), tasks: {} },
      },
      affected_orders: [],
      biz_key: 'manual-v1',
      created_by: 3,
      created_at: dayTime(-4, 10, 0),
      applied_at: dayTime(-4, 10, 0),
      rolled_back_at: null,
      creator: { id: 3, name: '李计划', employee_no: 'P001' },
    },
    {
      id: 2,
      version_no: 2,
      type: 'insert',
      status: 'applied',
      snapshot: {
        before: { schedules: schedules.slice(0, 5), tasks: {} },
        after: { schedules: schedules.slice(0, 7), tasks: {} },
      },
      affected_orders: [
        { order_id: 4, order_no: ORDER_NO(4), customer_name: '杭州纳爱斯', delay_hours: 8, task_count: 1, tasks: [] },
        { order_id: 5, order_no: ORDER_NO(5), customer_name: '苏州绿叶日化', delay_hours: 2, task_count: 1, tasks: [] },
      ],
      biz_key: 'insert-v2',
      created_by: 3,
      created_at: dayTime(-3, 15, 0),
      applied_at: dayTime(-3, 15, 0),
      rolled_back_at: null,
      creator: { id: 3, name: '李计划', employee_no: 'P001' },
    },
    {
      id: 3,
      version_no: 3,
      type: 'ai',
      status: 'rolled_back',
      snapshot: {
        before: { schedules: schedules.slice(0, 7), tasks: {} },
        after: { schedules: schedules.slice(0, 7), tasks: {} },
      },
      affected_orders: null,
      biz_key: 'ai-v1',
      created_by: 3,
      created_at: dayTime(-2, 11, 0),
      applied_at: null,
      rolled_back_at: dayTime(-2, 11, 20),
      creator: { id: 3, name: '李计划', employee_no: 'P001' },
    },
  ]
}

// ==================== 组装 ====================

export interface MockDb {
  machines: Machine[]
  workshops: PlmWorkshop[]
  workers: SysUser[]
  users: SysUser[]
  roles: SysRole[]
  products: Product[]
  boms: Bom[]
  bomLines: Record<number, BomLine[]>
  routes: PlmRoute[]
  routeOps: Record<number, RouteOp[]>
  molds: Mold[]
  orders: Order[]
  orderLines: Record<number, OrderLine[]>
  tasks: ProdTask[]
  schedules: TaskSchedule[]
  pool: PoolTask[]
  versions: ScheduleVersion[]
  suggestions: ScheduleSuggestion[]
  reports: WorkReport[]
  stockIns: StockIn[]
  redLights: RedLight[]
  exceptions: ProductionException[]
  developTasks: DevelopTask[]
  quotes: Quote[]
  materialPrices: MaterialPrice[]
  processRates: ProcessRate[]
  quoteRules: QuoteRuleItem[]
  opTypes: OpType[]
  heatmap: LoadHeatmapData
  reportsData: ReportsData
  workbench: WorkbenchData
  orderOverview: OrderOverviewData
  integrationLogs: IntegrationLog[]
  auditLogs: AuditLog[]
}

/**
 * 模拟数据闭环校验：构建时即阻断断链数据，避免页面看似有数据但报工/入库无法落到真实工序。
 * 该校验只针对演示内存库，不会访问或修改真实后端数据库。
 */
export function validateMockData(data: Pick<MockDb, 'orders' | 'orderLines' | 'tasks' | 'schedules' | 'routeOps' | 'products' | 'molds' | 'machines' | 'reports' | 'stockIns' | 'users'>): void {
  const errors: string[] = []
  const taskById = new Map(data.tasks.map((task) => [task.id, task]))
  const productById = new Map(data.products.map((product) => [product.id, product]))
  const moldById = new Map(data.molds.map((mold) => [mold.id, mold]))
  const machineById = new Map(data.machines.map((machine) => [machine.id, machine]))
  const scheduleById = new Map(data.schedules.map((schedule) => [schedule.id, schedule]))
  const userIds = new Set(data.users.map((user) => user.id))

  for (const order of data.orders) {
    for (const line of data.orderLines[order.id] ?? []) {
      if (line.order_id !== order.id) errors.push(`订单行 ${line.id} 未关联订单 ${order.id}`)
      if (!productById.has(line.product_id)) errors.push(`订单行 ${line.id} 产品不存在`)
    }
  }
  for (const task of data.tasks) {
    if (!data.orders.some((order) => (data.orderLines[order.id] ?? []).some((line) => line.id === task.order_line_id))) errors.push(`任务 ${task.id} 未关联订单行`)
    if (!productById.has(task.product_id)) errors.push(`任务 ${task.id} 产品不存在`)
    if (!data.routeOps[task.route_id ?? -1]) errors.push(`任务 ${task.id} 工艺路线不存在`)
    if (task.mold_id !== null && task.mold_id !== undefined) {
      const mold = moldById.get(task.mold_id)
      if (!mold || !mold.products?.some((product) => product.id === task.product_id)) errors.push(`任务 ${task.id} 的模具与产品不匹配`)
    }
  }
  for (const schedule of data.schedules) {
    const task = taskById.get(schedule.task_id)
    const op = task ? data.routeOps[task.route_id ?? -1]?.find((item) => item.id === schedule.route_op_id) : undefined
    const machine = schedule.machine_id === null ? null : machineById.get(schedule.machine_id)
    if (!task) errors.push(`排程 ${schedule.id} 未关联任务`)
    if (!op) errors.push(`排程 ${schedule.id} 工序外键无效`)
    if (op && (op.seq !== schedule.seq || op.op_type !== schedule.op_type)) errors.push(`排程 ${schedule.id} 工序顺序/类型不一致`)
    if (!machine) errors.push(`排程 ${schedule.id} 未关联机台/产线`)
    if (machine && op && op.workshop_id !== machine.workshop_id) errors.push(`排程 ${schedule.id} 资源车间不匹配`)
    if (machine && op?.machine_type && machine.type !== op.machine_type) errors.push(`排程 ${schedule.id} 资源类型不匹配`)
    if (schedule.mold_id !== null && schedule.mold_id !== undefined && !moldById.has(schedule.mold_id)) errors.push(`排程 ${schedule.id} 模具不存在`)
  }
  const insNos = new Set<string>()
  for (const report of data.reports) {
    const task = taskById.get(report.task_id)
    const schedule = report.schedule_id === null || report.schedule_id === undefined ? null : scheduleById.get(report.schedule_id)
    if (!task) errors.push(`报工 ${report.id} 未关联任务`)
    if (report.product_id !== task?.product_id) errors.push(`报工 ${report.id} 产品与任务不一致`)
    if (report.schedule_id !== null && (!schedule || schedule.task_id !== report.task_id)) errors.push(`报工 ${report.id} 工序与任务不一致`)
    if (schedule && report.machine_id !== schedule.machine_id) errors.push(`报工 ${report.id} 机台与工序排程不一致`)
    if (!userIds.has(report.report_by)) errors.push(`报工 ${report.id} 操作人不存在`)
    if (report.worker_id !== null) errors.push(`报工 ${report.id} 不应写入个人作业人`)
  }
  for (const stockIn of data.stockIns) {
    const task = taskById.get(stockIn.task_id)
    if (!task) errors.push(`入库 ${stockIn.id} 未关联任务`)
    if (stockIn.product_id !== task?.product_id) errors.push(`入库 ${stockIn.id} 产品与任务不一致`)
    if (insNos.has(stockIn.ins_no)) errors.push(`入库单号重复 ${stockIn.ins_no}`)
    insNos.add(stockIn.ins_no)
  }
  for (const task of data.tasks) {
    const rows = data.schedules.filter((schedule) => schedule.task_id === task.id && !schedule.is_interrupted).sort((a, b) => a.seq - b.seq)
    const lastId = rows[rows.length - 1]?.id
    const finalReported = data.reports.filter((report) => report.task_id === task.id && (report.schedule_id === null || report.schedule_id === lastId)).reduce((sum, report) => sum + report.qty, 0)
    const stocked = data.stockIns.filter((stockIn) => stockIn.task_id === task.id).reduce((sum, stockIn) => sum + stockIn.qty, 0)
    if (task.done_qty !== Math.max(0, finalReported)) errors.push(`任务 ${task.id} 完工量未按末工序报工汇总`)
    if (task.stocked_qty !== stocked) errors.push(`任务 ${task.id} 已入库量未与入库流水对账`)
    if (stocked > Math.max(0, finalReported)) errors.push(`任务 ${task.id} 已入库量超过末工序完成量`)
  }
  for (let i = 0; i < data.schedules.length; i += 1) {
    const a = data.schedules[i]
    if (a.machine_id === null || a.is_interrupted) continue
    for (let j = i + 1; j < data.schedules.length; j += 1) {
      const b = data.schedules[j]
      if (b.machine_id !== a.machine_id || b.task_id === a.task_id || b.is_interrupted) continue
      if (dayjs(a.plan_start).isBefore(dayjs(b.plan_end)) && dayjs(b.plan_start).isBefore(dayjs(a.plan_end))) errors.push(`机台 ${a.machine_id} 排程 ${a.id}/${b.id} 时间重叠`)
    }
  }
  if (errors.length > 0) throw new Error(`模拟数据闭环校验失败：\n${errors.join('\n')}`)
}

/** 构建完整演示数据（调用方应深拷贝后再做可变操作） */
export function buildMockDb(): MockDb {
  const machines = buildMachines()
  const workshops = buildWorkshops()
  const workers = buildWorkers()
  const users = buildUsers()
  const roles = buildRoles()
  const products = buildProducts()
  const { boms, lines } = buildBoms()
  const { routes, ops } = buildRoutes()
  const molds = buildMolds()
  const { orders, lines: orderLines, tasks, schedules, pool } = buildOrdersAndTasks(ops, molds)
  const reports = buildReports(tasks, schedules)
  const stockIns = buildStockIns(tasks)
  syncMockExecutionSnapshot(orders, orderLines, tasks, schedules, reports, stockIns)
  validateMockData({ orders, orderLines, tasks, schedules, routeOps: ops, products, molds, machines, reports, stockIns, users })
  const redLights = buildRedLights(tasks)
  const exceptions = buildExceptions(tasks)
  const developTasks = buildDevelopTasks()
  const materialPrices = buildMaterialPrices()
  const processRates = buildProcessRates()
  const quoteRules = buildQuoteRules()
  const quotes = buildQuotes(products)
  const opTypes = buildOpTypes()
  const heatmap = buildHeatmap(machines, schedules)
  const reportsData = buildReportsData(orders, tasks, machines, schedules, reports, molds)
  const workbench = buildWorkbench(orders, tasks, redLights, machines, schedules, reports)
  const orderOverview = buildOrderOverview(orders, orderLines, tasks, schedules)
  const integrationLogs = buildIntegrationLogs()
  const auditLogs = buildAuditLogs()
  const versions = buildVersions(schedules)
  const suggestions = buildSuggestions(tasks, schedules)

  return {
    machines, workshops, workers, users, roles, products, boms, bomLines: lines, routes, routeOps: ops,
    molds, orders, orderLines, tasks, schedules, pool, versions, suggestions, reports, stockIns, redLights,
    exceptions, developTasks,
    quotes, materialPrices, processRates, quoteRules, opTypes,
    heatmap, reportsData, workbench, orderOverview, integrationLogs, auditLogs,
  }
}
