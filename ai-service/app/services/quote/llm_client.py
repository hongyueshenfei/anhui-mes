"""DeepSeek LLM 客户端（报价辅助，五期）

无 DEEPSEEK_API_KEY 时所有方法返回 None（降级），由上层标记「AI 辅助未启用」。
使用 OpenAI 兼容 SDK 指向 DeepSeek（base_url https://api.deepseek.com）。
"""

from __future__ import annotations

import os
from typing import Optional

try:
    from openai import OpenAI
except Exception:  # pragma: no cover - openai 未安装时降级
    OpenAI = None  # type: ignore

DEEPSEEK_MODEL = os.getenv("DEEPSEEK_MODEL", "deepseek-chat")


class LlmUnavailable(Exception):
    """LLM 不可用（未配置 key / SDK 缺失 / 调用失败）"""


def llm_available() -> bool:
    return OpenAI is not None and bool(os.getenv("DEEPSEEK_API_KEY", "").strip())


def chat(messages: list[dict], max_tokens: int = 300) -> Optional[str]:
    """调用 DeepSeek chat completion；不可用时抛 LlmUnavailable"""
    if not llm_available():
        raise LlmUnavailable("DEEPSEEK_API_KEY 未配置")

    client = OpenAI(api_key=os.getenv("DEEPSEEK_API_KEY"), base_url="https://api.deepseek.com")

    try:
        resp = client.chat.completions.create(
            model=DEEPSEEK_MODEL,
            messages=messages,
            max_tokens=max_tokens,
            temperature=0.3,
        )
        return resp.choices[0].message.content
    except Exception as exc:  # pragma: no cover - 网络/额度异常
        raise LlmUnavailable(f"DeepSeek 调用失败：{exc}") from exc


def explain_quote(spec_text: str, qty: int, product_name: Optional[str], margin: float, suggest_price: Optional[float]) -> Optional[str]:
    """生成报价说明（中文话术）；不可用返回 None"""
    if not llm_available():
        return None

    prompt = (
        "你是注塑/吹塑工厂的资深报价工程师。请为客户生成一段简洁专业的报价说明（80字内，中文），"
        f"涵盖：产品（{product_name or '按规格'}）、数量 {qty}、目标毛利率 {margin}%。"
        + (f"规则引擎建议单价约 {suggest_price} 元。" if suggest_price else "")
        + "说明应解释价格构成逻辑（材料+加工+模具摊销+损耗），语气友好。"
    )

    try:
        return chat([{"role": "user", "content": prompt}])
    except LlmUnavailable:
        return None


def parse_spec(spec_text: str) -> Optional[dict]:
    """规格文本解析（材质/容量/工艺要点）→ 结构化字段；不可用返回 None"""
    if not llm_available():
        return None

    prompt = (
        "解析以下注塑/吹塑产品规格文本，输出 JSON：{\"material\":\"材质\",\"capacity\":\"容量\","
        "\"features\":\"要点\",\"target_type\":\"注塑/吹塑/其他\"}。仅输出 JSON，不要多余文字。\n文本：" + spec_text
    )

    try:
        content = chat([{"role": "user", "content": prompt}], max_tokens=200)
        if not content:
            return None
        import json

        start = content.find("{")
        end = content.rfind("}")
        if start < 0 or end <= start:
            return None
        return json.loads(content[start : end + 1])
    except Exception:
        return None
