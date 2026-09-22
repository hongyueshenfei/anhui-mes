<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 报价参数（架构 §3.6 material_prices / process_rates / quote_rules）—— 五期 E1
 *
 * - material_prices 材料价：UNIQUE(material_code, effective_date) 生效日期版本化，
 *   修改后新估价取新价，历史报价不受影响（报价单落库快照成本，不依赖参数表）。
 * - process_rates 工序费率：rate_key（对应 route_ops.rate_key）生效日期版本化。
 * - quote_rules 报价规则：loss_rate 行业损耗率 / mold_amort_rule 摊销规则 /
 *   min_margin 毛利下限 / anomaly_deviation 异常价偏差（默认 30）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_prices', function (Blueprint $table) {
            $table->id();
            $table->string('material_code', 64);
            $table->string('material_name', 128)->default('');
            $table->decimal('price_per_unit', 12, 4)->default(0);
            $table->string('unit', 16)->nullable();
            $table->date('effective_date')->comment('生效日期');
            $table->string('source', 16)->default('manual')->comment('manual/import/k3');
            $table->timestamps();
            $table->unique(['material_code', 'effective_date']);
            $table->index(['effective_date']);
        });

        Schema::create('process_rates', function (Blueprint $table) {
            $table->id();
            $table->string('rate_key', 32);
            $table->string('op_type', 16)->nullable();
            $table->string('rate_type', 16)->default('machine')->comment('machine/team');
            $table->decimal('rate', 12, 4)->default(0)->comment('元/小时');
            $table->date('effective_date')->comment('生效日期');
            $table->timestamps();
            $table->unique(['rate_key', 'effective_date']);
            $table->index(['effective_date']);
        });

        Schema::create('quote_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 32)->unique();
            $table->decimal('rule_value', 12, 4)->default(0);
            $table->string('remark', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_rules');
        Schema::dropIfExists('process_rates');
        Schema::dropIfExists('material_prices');
    }
};
