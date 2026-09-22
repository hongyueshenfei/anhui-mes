<?php

namespace App\Modules\Quote\Services;

use App\Models\AuditLog;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 报价单管理（五期 E5）
 *
 * - 创建：AI 估价一键转报价单 / 手工建单（含成本构成快照）
 * - 调价留痕：quote_price_change（audit_logs）
 * - 状态流转：draft→sent→won/lost；成交标记 final_price；可统计成交率
 */
class QuoteService
{
    /**
     * 由 AI 估价结果创建报价单（E5 估价转单）
     *
     * @param  array  $estimate  估价返回 data（含成本构成）
     */
    public function createFromEstimate(array $estimate, string $customerName, string $source = Quote::SOURCE_SALES, ?int $userId = null, float $targetMargin = 20): Quote
    {
        return DB::transaction(function () use ($estimate, $customerName, $source, $userId, $targetMargin) {
            $quote = Quote::create([
                'quote_no' => $this->generateNo(),
                'customer_name' => $customerName,
                'source' => $source,
                'status' => Quote::STATUS_DRAFT,
                'target_margin' => (float) ($estimate['margin'] ?? $targetMargin),
                'suggest_delivery' => isset($estimate['delivery_days']) ? now()->addDays((int) $estimate['delivery_days'])->toDateString() : null,
                'created_by' => $userId,
                'remark' => '由 AI 估价生成',
            ]);

            QuoteLine::create([
                'quote_id' => $quote->id,
                'spec_text' => (string) ($estimate['spec_text'] ?? ''),
                'qty' => (int) ($estimate['qty'] ?? 0),
                'ref_product_id' => $estimate['product_id'] ?? null,
                'product_id' => $estimate['product_id'] ?? null,
                'material_cost' => (float) ($estimate['material_cost'] ?? 0),
                'process_cost' => (float) ($estimate['process_cost'] ?? 0),
                'mold_amort' => (float) ($estimate['mold_amort'] ?? 0),
                'loss_cost' => (float) ($estimate['loss_cost'] ?? 0),
                'total_cost' => (float) ($estimate['total_cost'] ?? 0),
                'margin' => (float) ($estimate['margin'] ?? $targetMargin),
                'suggest_price' => (float) ($estimate['suggest_price'] ?? 0),
                'delivery_days' => (int) ($estimate['delivery_days'] ?? 0),
                'llm_remark' => $estimate['llm_remark'] ?? null,
                'ai_assist' => $estimate['ai_assist'] ?? 'not_enabled',
                'anomaly_flag' => (bool) ($estimate['anomaly_flag'] ?? false),
            ]);

            AuditLog::record(
                user: $userId ? User::find($userId) : null,
                module: 'quote',
                action: 'quote_create',
                targetType: 'quote',
                targetId: $quote->id,
                detail: ['source' => 'estimate', 'customer' => $customerName, 'suggest_price' => $estimate['suggest_price'] ?? null]
            );

            return $quote;
        });
    }

    /**
     * 手工建单（含明细）
     *
     * @param  array  $lines  [['spec_text', 'qty', 'product_id'?, 'suggest_price'?, 'delivery_days'?]]
     */
    public function createManual(array $data, ?int $userId = null): Quote
    {
        return DB::transaction(function () use ($data, $userId) {
            $quote = Quote::create([
                'quote_no' => $this->generateNo(),
                'customer_name' => $data['customer_name'] ?? '',
                'source' => $data['source'] ?? Quote::SOURCE_SALES,
                'status' => Quote::STATUS_DRAFT,
                'target_margin' => (float) ($data['target_margin'] ?? 20),
                'suggest_delivery' => $data['suggest_delivery'] ?? null,
                'created_by' => $userId,
                'remark' => $data['remark'] ?? null,
            ]);

            foreach ($data['lines'] ?? [] as $line) {
                QuoteLine::create([
                    'quote_id' => $quote->id,
                    'spec_text' => (string) ($line['spec_text'] ?? ''),
                    'qty' => (int) ($line['qty'] ?? 0),
                    'ref_product_id' => $line['ref_product_id'] ?? null,
                    'product_id' => $line['product_id'] ?? null,
                    'material_cost' => (float) ($line['material_cost'] ?? 0),
                    'process_cost' => (float) ($line['process_cost'] ?? 0),
                    'mold_amort' => (float) ($line['mold_amort'] ?? 0),
                    'loss_cost' => (float) ($line['loss_cost'] ?? 0),
                    'total_cost' => (float) ($line['total_cost'] ?? 0),
                    'margin' => (float) ($line['margin'] ?? $data['target_margin'] ?? 20),
                    'suggest_price' => (float) ($line['suggest_price'] ?? 0),
                    'delivery_days' => (int) ($line['delivery_days'] ?? 0),
                    'llm_remark' => $line['llm_remark'] ?? null,
                    'ai_assist' => $line['ai_assist'] ?? 'not_enabled',
                    'anomaly_flag' => (bool) ($line['anomaly_flag'] ?? false),
                ]);
            }

            return $quote;
        });
    }

    /**
     * 调价（留审计 quote_price_change）
     */
    public function adjustPrice(Quote $quote, float $finalPrice, ?int $userId = null, ?string $remark = null): Quote
    {
        $old = $quote->final_price;

        $quote->update(['final_price' => $finalPrice, 'remark' => $remark ?? $quote->remark]);

        AuditLog::record(
            user: $userId ? User::find($userId) : null,
            module: 'quote',
            action: 'quote_price_change',
            targetType: 'quote',
            targetId: $quote->id,
            detail: ['from' => $old, 'to' => $finalPrice]
        );

        return $quote->fresh();
    }

    /**
     * 状态流转（draft→sent→won/lost）；成交标记 final_price 可统计成交率
     */
    public function transitionStatus(Quote $quote, string $status, ?float $finalPrice = null, ?int $userId = null): Quote
    {
        if (! in_array($status, [Quote::STATUS_DRAFT, Quote::STATUS_SENT, Quote::STATUS_WON, Quote::STATUS_LOST], true)) {
            throw new \InvalidArgumentException('非法报价单状态');
        }

        $quote->update([
            'status' => $status,
            'final_price' => $finalPrice ?? $quote->final_price,
        ]);

        AuditLog::record(
            user: $userId ? User::find($userId) : null,
            module: 'quote',
            action: 'quote_status',
            targetType: 'quote',
            targetId: $quote->id,
            detail: ['to' => $status, 'final_price' => $quote->final_price]
        );

        return $quote->fresh();
    }

    /**
     * 报价单详情（含明细 + 成本构成）
     */
    public function detail(Quote $quote): array
    {
        $quote->load(['lines.refProduct', 'lines.product', 'creator:id,name,employee_no']);

        return $quote->toArray();
    }

    /**
     * 生成报价单号：QT-YYYYMMDD-随机后缀
     */
    public function generateNo(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $no = sprintf('QT-%s-%s', now()->format('Ymd'), strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)));
            if (! Quote::where('quote_no', $no)->exists()) {
                return $no;
            }
        }

        return sprintf('QT-%s-%s', now()->format('YmdHis'), strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)));
    }
}
