<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users（用户/员工）—— 对应架构 §3.1
 *
 * 注意：team_id 与 teams 存在循环引用（teams.leader_user_id → users），
 * 因此 team_id 仅建索引，外键约束由应用层保证。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 32)->unique()->comment('工号（微信绑定时必填，登录账号）');
            $table->string('name', 64)->comment('姓名');
            $table->string('phone', 20)->nullable()->comment('手机号');
            $table->string('password_hash', 255)->nullable()->comment('账号密码回退登录');
            $table->string('wecom_userid', 64)->nullable()->unique()->comment('企微免登 userid');
            $table->string('wx_openid', 64)->nullable()->unique()->comment('小程序/微信扫码绑定 openid');
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('所属班组（可空=管理岗）');
            $table->string('job_type', 32)->default('worker')->comment('worker/leader/planner/engineer/sales/admin/boss');
            $table->tinyInteger('status')->default(1)->index()->comment('1启用 0停用');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};