"""报价规则引擎（五期，确定性可解释）

与 Laravel QuoteRuleEngine 同构：材料 + 加工 + 模具摊销 + 损耗 + 毛利 → 建议价。
供 ai-service 测试与独立测算；Laravel 侧规则引擎为业务主路径。
"""

from __future__ import annotations

from typing import Optional


def estimate(
    qty: int,
    material_cost_per_unit: float = 0.0,
    process_cost_per_unit: float = 0.0,
    mold_cost: float = 0.0,
    loss_rate: float = 2.0,
    margin: float = 20.0,
) -> dict:
    """确定性成本构成估价

    - mold_amort = mold_cost / qty（摊销随数量摊薄）
    - loss_cost = (material + process) * loss_rate%
    - total_cost = material + process + mold_amort + loss
    - suggest_price = total_cost / (1 - margin/100)
    """
    if qty <= 0:
        raise ValueError("qty must be > 0")

    mold_amort = mold_cost / qty
    loss_cost = (material_cost_per_unit + process_cost_per_unit) * (loss_rate / 100.0)
    total_cost = material_cost_per_unit + process_cost_per_unit + mold_amort + loss_cost
    margin_pct = max(0.0, min(90.0, margin))
    suggest_price = total_cost / (1 - margin_pct / 100.0) if margin_pct < 100 else total_cost

    return {
        "qty": qty,
        "material_cost": round(material_cost_per_unit, 4),
        "process_cost": round(process_cost_per_unit, 4),
        "mold_amort": round(mold_amort, 4),
        "loss_cost": round(loss_cost, 4),
        "total_cost": round(total_cost, 4),
        "margin": margin_pct,
        "suggest_price": round(suggest_price, 2),
    }


def delivery_days(total_hours: float, daily_available_hours: float = 16.0, load_buffer_days: int = 0) -> int:
    """建议交期（天）= ceil(总工时 ÷ 每日可用小时) + 负荷缓冲"""
    if total_hours <= 0:
        return max(1, 7 + load_buffer_days)
    import math

    return max(1, math.ceil(total_hours / max(1.0, daily_available_hours)) + load_buffer_days)


def anomaly_check(suggest_price: float, avg_history_price: float, deviation_pct: float = 30.0) -> dict:
    """异常价校验：偏离历史成交 ±deviation% 提示（E3）"""
    if suggest_price <= 0 or avg_history_price <= 0:
        return {"anomaly": False, "deviation": 0.0, "message": ""}

    deviation = abs(suggest_price - avg_history_price) / avg_history_price * 100.0
    if deviation <= deviation_pct:
        return {"anomaly": False, "deviation": round(deviation, 1), "message": ""}

    return {
        "anomaly": True,
        "deviation": round(deviation, 1),
        "message": f"建议价 {suggest_price:.2f} 偏离历史成交均价 {avg_history_price:.2f} 达 {deviation:.1f}%（阈值 {deviation_pct}%），请复核",
    }
