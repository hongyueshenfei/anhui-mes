<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 彻底移除班组（teams）—— 用户需求「车间，机台产线合到一个模块，先有车间，车间下面是机台或产线」
 *
 * - users：team_id → workshop_id（回填：原 users.team_id → teams.workshop → workshops.id）
 * - route_ops / task_schedules：删 team_id（外键 + 索引 + 列）
 * - machines：删 team_id（产线归属已改 workshop_id）与 workshop 字符串列（批次 1 保留的过渡列）
 * - drop teams 表
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. users：先加 workshop_id 并回填（回填需 teams/workshops 均在，先于删列），再删 team_id
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('workshop_id')->nullable()->after('team_id')->index()->comment('所属车间（可空=管理岗）');
        });

        if (Schema::hasTable('teams') && Schema::hasColumn('users', 'team_id')) {
            DB::table('users')->orderBy('id')->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    if (! $user->team_id) {
                        continue;
                    }
                    $workshopCode = DB::table('teams')->where('id', $user->team_id)->value('workshop');
                    $workshopId = $workshopCode
                        ? DB::table('workshops')->where('code', $workshopCode)->value('id')
                        : null;
                    if ($workshopId) {
                        DB::table('users')->where('id', $user->id)->update(['workshop_id' => $workshopId]);
                    }
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
            $table->dropColumn('team_id');
        });

        // 2. route_ops：删 team_id（外键 + 索引 + 列）
        Schema::table('route_ops', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropIndex(['team_id']);
            $table->dropColumn('team_id');
        });

        // 3. task_schedules：删 team_id（外键 + 复合索引 [team_id, plan_start] + 列）
        Schema::table('task_schedules', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropIndex(['team_id', 'plan_start']);
            $table->dropColumn('team_id');
        });

        // 4. machines：删 team_id（无外键仅列）与 workshop 字符串列（已由 workshop_id 取代）
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('team_id');
            $table->dropColumn('workshop');
        });

        // 5. 班组表下线
        Schema::dropIfExists('teams');
    }

    public function down(): void
    {
        // 重建 teams 表（开发期尽量完整；业务数据不回灌）
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->comment('班组名');
            $table->string('workshop', 32)->comment('车间');
            $table->unsignedBigInteger('leader_user_id')->nullable()->comment('班组长');
            $table->foreign('leader_user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('shift_mode', 16)->default('two_shift')->comment('班制');
            $table->tinyInteger('is_manual_line')->default(0)->comment('1=人工线，0=机台线');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->string('workshop', 32)->nullable()->comment('所在车间（字符串过渡列）');
            $table->unsignedBigInteger('team_id')->nullable()->comment('产线绑定班组');
        });

        Schema::table('task_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->comment('班组（人工线工序）');
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->index(['team_id', 'plan_start']);
        });

        Schema::table('route_ops', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->comment('人工线班组');
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->index('team_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('所属班组（可空=管理岗）');
            $table->dropIndex(['workshop_id']);
            $table->dropColumn('workshop_id');
        });
    }
};
