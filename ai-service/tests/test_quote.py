"""报价规则引擎 / 异常价校验 / LLM 降级测试（五期）"""

from __future__ import annotations

from app.services.quote.rule_engine import anomaly_check, delivery_days, estimate


def test_estimate_deterministic_and_breakdown():
    """同参数两次结果一致（PRD T6）；成本构成：材料+加工+摊销+损耗+毛利"""
    a = estimate(qty=1000, material_cost_per_unit=1.2, process_cost_per_unit=0.8, mold_cost=50000, loss_rate=2.0, margin=20)
    b = estimate(qty=1000, material_cost_per_unit=1.2, process_cost_per_unit=0.8, mold_cost=50000, loss_rate=2.0, margin=20)
    assert a == b, "同参数估价必须一致"

    # 材料 1.2 + 加工 0.8 + 摊销 50 + 损耗 (2.0*2%) = 53.96... 校验构成
    assert a["material_cost"] == 1.2
    assert a["process_cost"] == 0.8
    assert a["mold_amort"] == 50.0
    assert a["loss_cost"] == round(2.0 * 0.02, 4) == 0.04
    assert a["total_cost"] == round(1.2 + 0.8 + 50.0 + 0.04, 4) == 52.04
    assert a["suggest_price"] == round(52.04 / 0.8, 2) == 65.05


def test_mold_amort_decreases_with_qty():
    """摊销随数量摊薄（E1）"""
    small = estimate(qty=100, mold_cost=50000)["mold_amort"]
    large = estimate(qty=10000, mold_cost=50000)["mold_amort"]
    assert small == 500.0
    assert large == 5.0
    assert large < small


def test_delivery_days_with_load_buffer():
    """交期联动产能负荷（E4）：负荷缓冲使交期变长"""
    base = delivery_days(total_hours=160, daily_available_hours=16, load_buffer_days=0)
    loaded = delivery_days(total_hours=160, daily_available_hours=16, load_buffer_days=3)
    assert base == 10
    assert loaded == 13
    assert loaded > base


def test_anomaly_check_threshold():
    """异常价校验 ±30%（E3）"""
    ok = anomaly_check(suggest_price=10, avg_history_price=11, deviation_pct=30)
    assert ok["anomaly"] is False

    bad = anomaly_check(suggest_price=15, avg_history_price=10, deviation_pct=30)
    assert bad["anomaly"] is True
    assert bad["deviation"] == 50.0
    assert "偏离" in bad["message"]


def test_llm_degraded_when_no_key(monkeypatch):
    """无 DEEPSEEK_API_KEY → LLM 降级（None），规则引擎结果可用"""
    monkeypatch.delenv("DEEPSEEK_API_KEY", raising=False)

    from app.services.quote import llm_client

    assert llm_client.llm_available() is False
    assert llm_client.explain_quote("PP 材质 500ml 直口杯", 1000, "直口杯", 20, 5.0) is None
    assert llm_client.parse_spec("PP 材质 500ml 直口杯") is None
