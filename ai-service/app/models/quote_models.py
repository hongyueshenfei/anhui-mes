"""报价请求/响应模型（五期）"""

from __future__ import annotations

from typing import Optional

from pydantic import BaseModel, Field


class QuoteExplainRequest(BaseModel):
    """报价说明请求（DeepSeek 辅助）"""

    spec_text: str = Field(..., description="客户规格描述")
    qty: int = Field(..., ge=1, description="预估数量")
    product_name: Optional[str] = Field(None, description="匹配产品名称")
    margin: float = Field(20, ge=0, le=90, description="目标毛利率 %")
    suggest_price: Optional[float] = Field(None, description="规则引擎建议价")


class QuoteExplainResponse(BaseModel):
    explanation: str
    parsed_spec: Optional[str] = None
    ai_enabled: bool = False


class QuoteParseSpecRequest(BaseModel):
    spec_text: str = Field(..., description="客户规格文本")


class QuoteParseSpecResponse(BaseModel):
    parsed: Optional[dict] = None
    ai_enabled: bool = False


class QuoteAnomalyCheckRequest(BaseModel):
    suggest_price: float = Field(..., ge=0, description="建议价")
    avg_history_price: float = Field(..., ge=0, description="历史成交均价")
    deviation_pct: float = Field(30, ge=0, le=100, description="异常偏差阈值 %")


class QuoteAnomalyCheckResponse(BaseModel):
    anomaly: bool
    deviation: float
    message: str
