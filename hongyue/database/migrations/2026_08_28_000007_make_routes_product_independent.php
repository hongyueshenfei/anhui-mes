<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 工艺路线与产品解耦（2026-08-28 业务确认）
 *
 * 工艺路线是独立数据实体（路线库），不再归属某个产品：
 * - 新增 name（路线名称），唯一键改为 (name, version)
 * - 删除 product_id 列及其外键/唯一索引
 * - 历史数据按「所属产品名 + 工艺」回填路线名称，迁移后原归属仅存于审计日志
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->string('name', 128)->nullable()->after('id');
        });

        // 历史数据回填：名称 = 所属产品名 + 「工艺」；无产品的兜底「工艺-版本」
        // 用查询构造器逐行回填（兼容 SQLite 测试库与 MySQL）
        DB::table('routes')->orderBy('id')->chunkById(200, function ($routes) {
            foreach ($routes as $route) {
                $productName = DB::table('products')->where('id', $route->product_id)->value('name');
                DB::table('routes')->where('id', $route->id)->update([
                    'name' => $productName ? $productName.' 工艺' : '工艺-'.$route->version,
                ]);
            }
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->string('name', 128)->nullable(false)->change();
            $table->dropForeign(['product_id']);
            $table->dropUnique(['product_id', 'version']);
            $table->dropColumn('product_id');
            $table->unique(['name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropUnique(['name', 'version']);
            $table->dropColumn('name');
            $table->unsignedBigInteger('product_id')->nullable()->after('id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['product_id', 'version']);
        });
    }
};
