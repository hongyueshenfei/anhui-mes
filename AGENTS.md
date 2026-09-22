# 虹越智造平台 · AI 协作指南（AGENTS.md）

> 本文件是任何 AI 参与本项目工作的**第一入口**。开始任何任务前，先读本文件；涉及具体模块时，再按「文档索引」打开对应文档。
> 最后更新：2026-08-28

---

## 1. 项目是什么

**虹越智造平台**是安徽虹越（注塑/吹塑行业，园艺资材类塑料制品）自建的一体化生产管理平台，定位 **PLM + MES + AI 模拟报价 + 管理看板**，用于替代现有「黑湖小工单」MES。

- 客户现状：黑湖小工单无排产功能、任务不落地；金蝶云星空（ERP）保留不动，负责物料/库存/财务核算；模具台账、工艺档案、报价数据全在线下。
- 核心业务闭环：小程序接单 → 拉取生产订单 → **排产**（手工甘特为主 + OR-Tools 智能辅助）→ 任务派工 → 现场极简报工 → 分批入库 → 进度回写小程序 → 发货（一期范围外）。
- 产品文档（已冻结，最高权威）：[PRD/虹越智造平台PRD-v1.0.html](PRD/虹越智造平台PRD-v1.0.html)
- 部署目标：阿里云 ECS + Docker Compose。

## 2. 技术栈（客户锁定，不可轻易更换）

| 部分 | 技术 | 目录 |
|------|------|------|
| 后端主服务 | PHP 8.4 + Laravel 12（模块化 `app/Modules/*`） | `hongyue/` |
| PC 管理端 | Vue 3 + TypeScript + Element Plus + Vite | `frontend/` |
| 车间移动端 H5 | Vue 3 + TypeScript + Vant + Vite | `mobile/` |
| AI 计算微服务 | Python FastAPI + OR-Tools CP-SAT + DeepSeek LLM | `ai-service/` |
| 数据层 | MySQL 8 + Redis（缓存/队列/job 状态） | Docker Compose |
| 集成 | 金蝶云星空 K3Cloud WebAPI、微信小程序 API、企业微信 | `Integration` 模块 |

> ⚠️ **四个子目录是四个独立 Git 仓库**，各自提交、各自状态。改动时注意进入对应仓库目录操作。

## 3. 目录结构与文档索引

```
.
├── PRD/         # 产品需求文档（v1.0 已冻结，业务口径以此为准）
├── docs/        # 架构、分期、集成契约、排查报告
│   ├── architecture.md          # 系统架构 v1.0：模块/表结构/API/集成契约（最详细）
│   ├── phases.md                # 五期任务分解 + 每期验收标准 + 当前期实现顺序
│   ├── P4-小程序回写契约.md     # 四期：小程序进度回写 API（HMAC-SHA256 签名）
│   ├── AI变更记录.md            # ⚠️ 所有 AI 改动留痕档案（§8.4 强制维护：格式/弯路/可优化点）
│   └── 排查报告-2026-08-27.md   # PRD↔代码↔UI 三向核对 + 问题清单（改版基准）
├── 原型/        # HTML 交互原型（PC 总览/排产甘特/手机报工/小程序）
├── hongyue/     # Laravel 后端（主仓库，含独立 docs/）
├── frontend/    # PC 管理端（演示模式优先，mock 数据）
├── mobile/      # 车间 H5（今日任务/扫码报工/离线队列）
└── ai-service/  # FastAPI 排产求解 + 报价引擎
```

任务起步规则：**业务规则 → PRD §7；数据表/API/架构 → docs/architecture.md；当前期进度 → docs/phases.md；已完成 vs 未完成 → docs/排查报告 + git log**。

## 4. 业务领域速览（必须理解的概念）

**生产形态**：工序链 = 注塑/吹塑成型 → 喷漆 → 效果 → 包装。成型是**机器产能**（每台机标准产能，换产品需换模具、占用时间）；喷漆/效果/包装是**人工产线**（班组工时）。2 班制。

**核心实体链路**：`Order(订单) → OrderLine(产品行) → ProdTask(生产任务) → TaskSchedule(工序排程) → WorkReport(报工流水)`，另有 `Product / Bom / Route(工艺路线) / Mold(模具) / Machine(机台) / Team(班组，迁移中改为 Workshop 车间)`。

**五期进度**（按 phases.md，每期验收通过再进下一期）：

| 期 | 内容 | 状态（2026-08-28） |
|----|------|------|
| 一期 | 工程骨架 + PLM 数据底座（登录/RBAC/机台/产品/BOM/工艺/模具） | ✅ 已完成（近期仍在迭代：车间实体化、工序类型字典、BOM 发布互斥等） |
| 二期 | 排产引擎（订单中心/甘特/插单顺延/OR-Tools/版本回滚） | ✅ 已完成（后端 152+ 排产测试） |
| 三期 | 报工 + 分批入库 + 金蝶入库单推送 | ✅ 已完成（192 passed / 1152 assertions 基线） |
| 四期 | 看板红灯 + 金蝶拉单 + BOM 直发/物料同步 + 小程序进度回写 | 🟡 进行中（Integration/Dashboard 模块已建，契约测试已存在；**发货已移出范围**） |
| 五期 | AI 报价中心（参数/规则引擎/LLM 辅助/报价单） | 🟡 进行中（Quote 模块与 ai-service quote 分支已开工，尚未提交完整） |

**重要范围变更（2026-08-26）**：本平台**不包含发货管理**。发货单、分批发货、库存防超发、小程序发货回写等全部移出范围；订单生命周期终止于「已入库（produced）」。不要为实现发货功能新建表或接口。

## 5. 关键业务规则（不可违背的不变量）

这些规则是客户访谈确认 + 验收测试强约束，改动必须先看对应测试：

1. **报工台账只增不改**：`work_reports` 仅 INSERT，更正用负数量「冲减」（sign=-1，必须填 remark）；合格数/次品数分列；代报必须选实际作业人（worker_id）。
2. **分批入库**：可入库量 = Σ报工合格数 − Σ已入库量，服务端强校验；冲减后自动回冲已入库量（负库存防御）。
3. **产品-模具-机台三匹配**：排产保存前强制校验，不符拒绝保存；「强制排入」必须显式标记超期。
4. **插单自动顺延**：插入后原任务自动后移，输出受影响订单延期清单，一键确认或回滚。
5. **排产保存即锁定**：解锁必须留审计；排产版本（schedule_versions）JSON 快照支持整版接受/回滚。
6. **手工排产是铁保底**，智能排产只是「建议层」（整版接受/局部采纳/回滚）。
7. **BOM 发布**：同一产品已发布版本互斥（发布新版本前旧版本需先退回/禁用）；未发布版本不可被排产/报价引用。
8. **红灯四源**：未排产 / 超时未报工 / 逾期 / 落后 >10%，实时计算 + Redis 缓存 10s。
9. **金蝶集成幂等**：推送前查回执，成功记录唯一键 `api_type+biz_type+biz_id`；失败入队重试（最多 5 次 + 手动）；敏感字段脱敏。
10. **报工/入库联动**：报工落库触发模具寿命累加（开模次数 = 合格数 ÷ 模穴数）、任务进度更新、看板刷新。

## 6. 代码组织约定

### 后端（hongyue/）
- 模块化：`app/Modules/<Module>/{routes.php, Controllers, Services, Requests, Resources}`，模块在 `routes/api.php` 的 `$modules` 数组注册。**新增模块要加进数组**。
- 共享 Model 统一放 `app/Models/`；共享服务放 `app/Services/`；队列任务、事件监听器负责跨模块联动。
- API 统一前缀 `/api`（Sanctum Bearer Token），移动端 H5 走 `/mobile/*`；统一响应 `{code, message, data}`。
- 测试：`tests/Feature/<Module>/`，验收场景在 `tests/Feature/Qa/`。跑全量：`cd hongyue && php artisan test`（基线 192 passed）。
- 数据库迁移：`database/migrations/` 按模块前缀编号；涉及资金用 DECIMAL(12,2)；表带 `created_by/updated_by` 与软删除。

### PC 前端（frontend/）
- **演示模式是默认交付形态**：`npm run dev` 直接跑 mock 数据（`src/mock/`），无需后端即可演示全部页面；顶栏常驻「演示模式」标识。
- 关闭演示模式：`localStorage['hy_demo_mode']='0'` 或 `VITE_DEMO_MODE=0`，改走真实后端 `/api`（Vite 已代理到 8000）。
- 页面在 `src/views/<模块>/`，API 封装在 `src/api/`（与后端模块一一对应），类型在 `src/types/`，甘特封装在 `src/components/gantt/`。
- 构建/类型检查：`npm run build`（含 vue-tsc）。
- **前端 mock 与后端接口要保持同步**：新增/修改后端接口时，同步更新 `src/api/*.ts` 与 `src/mock/` 的对应逻辑，保证演示模式不破。

### 移动端（mobile/）
- 独立轻量 Vite 应用（端口 5174），页面：登录/绑工号、今日任务、任务详情（扫码）、报工、我的报工；弱网离线暂存（`src/utils/offlineQueue.ts`）联网自动补传。

### AI 服务（ai-service/）
- FastAPI 入口 `app/main.py`；排产求解 `app/services/scheduler/cp_sat_solver.py`（OR-Tools CP-SAT；安装失败自动降级贪心启发式，**降级不影响可跑性但算法质量下降**）。
- job 状态存 Redis（`job_store.py`），Laravel 侧 `AutoScheduleService` 提交任务 + 前端轮询 `GET /schedule/jobs/{id}`。
- 报价：规则引擎（确定性成本构成）优先，LLM（DeepSeek）只做规格解析/报价说明/异常价校验辅助。
- 测试：`cd ai-service && pytest`（排产求解基线 11 passed；报价测试在途）。
- 鉴权：与 Laravel 之间用内部共享 Token（`AI_SERVICE_TOKEN`），不公网暴露。

## 7. 本地运行

```bash
# 后端（需 Docker）：在 hongyue/ 下
docker compose up -d --build
docker compose exec php-fpm php artisan migrate --seed
docker compose exec php-fpm php artisan test

# PC 前端（演示模式，无需后端）
cd frontend && npm install && npm run dev    # http://localhost:5173

# 移动端 H5
cd mobile && npm install && npm run dev      # http://localhost:5174

# AI 服务
cd ai-service && source .venv/bin/activate && uvicorn app.main:app --port 8001 --reload
```

演示账号：任意演示账号 + 密码 `123456`（admin / E001 工程师 / P001 计划员 / L001 班组长 / W001 工人 / S001 业务员）。

## 8. 工作注意事项

- **先看测试，再改业务规则**：涉及 §5 不变量时，先读对应 `tests/Feature/` 用例，改完必须跑相关测试，最后跑 `php artisan test` 全量。
- **文档要同步**：改动表结构/接口/契约时，同步更新 `docs/architecture.md`（或对应契约文档）；前端新增页面记得补 mock 数据。
- **⚠️ AI 变更留痕（强制，所有 AI 必须遵守）**：每次对本项目任何实质性改动（代码/文档/数据/配置/排障）完成后，**必须先追加一条记录到 `docs/AI变更记录.md` 顶部（按其中固定格式：需求/改动/弯路/可优化点/验证/提交），再回复用户**。只追加不改写历史；commit 可以推迟，记录不能推迟。项目完成时靠该文件复盘弯路与可优化点——漏记即失职。
- **四个仓库各自提交**：跨端改动（如后端接口 + 前端页面）要在各自目录分别提交，commit message 遵循现有 `feat(模块): 描述` 风格。
- **中文沟通**：代码注释、commit message、文档均使用中文；变量/函数命名用英文。
- **演示模式勿破**：前端任何改版后，确认 mock 演示仍可完整走通（这是客户演示的硬要求）。
- **不要触碰 `.env` / `.env.*.bak` 中的真实密钥**；敏感配置只放 `.env`，代码里用 `config()` 读取并脱敏。
- 原型目录中的「-已废弃」文件仅存档，**不要引用或修改**。

## 9. 常见任务入口

| 任务 | 入口 |
|------|------|
| 加业务功能/表 | phases.md 当前期实现顺序 + architecture.md §3 表结构 |
| 改排产逻辑 | `hongyue/app/Modules/Scheduling/Services/` + `tests/Feature/Scheduling/` |
| 改报工/入库 | `Modules/Production/`、`Modules/Warehouse/` + 对应 QA 测试 |
| 改看板/红灯 | `Modules/Dashboard/Services/`（RedLightService/DashboardService） |
| 改金蝶/小程序集成 | `Modules/Integration/`（K3Client、各 Adapter、Jobs）+ docs/P4 契约 |
| 改报价 | `Modules/Quote/` + `ai-service/app/services/quote/` |
| 改 PC 页面 | `frontend/src/views/` + `src/mock/` + `src/api/` 三处同步 |
| 改手机页面 | `mobile/src/views/` + `mobile/src/api/mobile.ts` |
| 验收回归 | 排查报告 §一 的基线命令 + phases.md 当期验收表 |
