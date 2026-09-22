"""报价辅助端点（五期：DeepSeek 辅助，无 key 时降级）"""

from __future__ import annotations

from fastapi import APIRouter

from app.models.quote_models import (
    QuoteAnomalyCheckRequest,
    QuoteAnomalyCheckResponse,
    QuoteExplainRequest,
    QuoteExplainResponse,
    QuoteParseSpecRequest,
    QuoteParseSpecResponse,
)
from app.services.quote import llm_client
from app.services.quote.rule_engine import anomaly_check

router = APIRouter(prefix="/quote", tags=["quote"])


@router.post("/explain", response_model=QuoteExplainResponse)
def quote_explain(req: QuoteExplainRequest) -> QuoteExplainResponse:
    """生成报价说明 + 规格解析（DeepSeek 辅助；无 key 降级 not_enabled）"""
    explanation = llm_client.explain_quote(
        spec_text=req.spec_text,
        qty=req.qty,
        product_name=req.product_name,
        margin=req.margin,
        suggest_price=req.suggest_price,
    )
    parsed = llm_client.parse_spec(req.spec_text)

    return QuoteExplainResponse(
        explanation=explanation
        or "AI 辅助未启用（未配置 DEEPSEEK_API_KEY 或调用失败），以上为规则引擎确定性估算",
        parsed_spec=parsed.get("features") if isinstance(parsed, dict) else None,
        ai_enabled=explanation is not None,
    )


@router.post("/parse-spec", response_model=QuoteParseSpecResponse)
def quote_parse_spec(req: QuoteParseSpecRequest) -> QuoteParseSpecResponse:
    """规格文本解析（材质/容量/要点）—— 无 key 降级 parsed=null"""
    parsed = llm_client.parse_spec(req.spec_text)

    return QuoteParseSpecResponse(parsed=parsed, ai_enabled=parsed is not None)


@router.post("/anomaly-check", response_model=QuoteAnomalyCheckResponse)
def quote_anomaly_check(req: QuoteAnomalyCheckRequest) -> QuoteAnomalyCheckResponse:
    """异常价校验（确定性计算，无需 LLM）：偏离历史成交 ±deviation% 提示"""
    result = anomaly_check(req.suggest_price, req.avg_history_price, req.deviation_pct)

    return QuoteAnomalyCheckResponse(**result)
