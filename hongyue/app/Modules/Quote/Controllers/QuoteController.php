<?php

namespace App\Modules\Quote\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Modules\Quote\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 报价单管理（五期 E5）
 *
 * - GET   /api/quotes            报价单列表（状态/成交率过滤）
 * - POST  /api/quotes            创建报价单（from_estimate 一键转单 / 手工）
 * - GET   /api/quotes/{id}       详情（含成本构成明细）
 * - PATCH /api/quotes/{id}/price 调价（留审计 quote_price_change）
 * - PATCH /api/quotes/{id}/status 状态流转（draft/sent/won/lost）
 */
class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quoteService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Quote::withCount('lines')
            ->withSum('lines as total_qty', 'qty')
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('keyword'), fn ($q, $kw) => $q->where(fn ($w) => $w->where('quote_no', 'like', "%{$kw}%")->orWhere('customer_name', 'like', "%{$kw}%")))
            ->orderByDesc('id');

        $perPage = min((int) $request->input('per_page', 15), 100);

        return $this->ok($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->boolean('from_estimate')) {
            $estimate = $request->input('estimate');
            if (! $estimate || ! is_array($estimate)) {
                return $this->fail('缺少估价数据', 422, 422);
            }
            $quote = $this->quoteService->createFromEstimate(
                $estimate,
                (string) $request->input('customer_name', ''),
                (string) $request->input('source', Quote::SOURCE_SALES),
                $request->user()?->id,
                (float) $request->input('target_margin', 20)
            );
        } else {
            $request->validate([
                'customer_name' => ['required', 'string', 'max:128'],
                'lines' => ['required', 'array', 'min:1'],
            ]);
            $quote = $this->quoteService->createManual($request->only(['customer_name', 'source', 'target_margin', 'suggest_delivery', 'remark', 'lines']), $request->user()?->id);
        }

        return $this->ok($this->quoteService->detail($quote), '报价单创建成功', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok($this->quoteService->detail(Quote::findOrFail($id)));
    }

    public function adjustPrice(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'final_price' => ['required', 'numeric', 'min:0'],
            'remark' => ['nullable', 'string', 'max:512'],
        ]);

        $quote = $this->quoteService->adjustPrice(
            Quote::findOrFail($id),
            (float) $request->input('final_price'),
            $request->user()?->id,
            $request->input('remark')
        );

        return $this->ok($this->quoteService->detail($quote), '调价成功（审计已留痕）');
    }

    public function transitionStatus(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:draft,sent,won,lost'],
            'final_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $quote = $this->quoteService->transitionStatus(
                Quote::findOrFail($id),
                (string) $request->input('status'),
                $request->input('final_price') !== null ? (float) $request->input('final_price') : null,
                $request->user()?->id
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($this->quoteService->detail($quote), '报价单状态已更新');
    }
}
