<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * machines 增加 workshop_id（车间外键）并回填
 *
 * 背景：机台/产线此前用 workshop 字符串列（injection/blow/paint/effect/pack）。
 * 车间实体化后改为 workshop_id 外键；workshop 字符串列保留至批次 2 清理。
 * 回填按 workshop 字符串映射到 workshops.code。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->unsignedBigInteger('workshop_id')->nullable()->after('workshop');
            $table->index('workshop_id');
        });

        // 回填：workshop 字符串 → workshops.id（按 code 匹配；未命中置 null）
        if (Schema::hasTable('workshops')) {
            DB::table('machines')->orderBy('id')->chunkById(500, function ($machines) {
                foreach ($machines as $machine) {
                    if (! $machine->workshop) {
                        continue;
                    }
                    $workshopId = DB::table('workshops')->where('code', $machine->workshop)->value('id');
                    if ($workshopId) {
                        DB::table('machines')->where('id', $machine->id)->update(['workshop_id' => $workshopId]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropIndex(['workshop_id']);
            $table->dropColumn('workshop_id');
        });
    }
};
