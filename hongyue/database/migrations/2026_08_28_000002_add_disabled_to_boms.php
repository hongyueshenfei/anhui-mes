<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $t) {
            $t->timestamp('disabled_at')->nullable()->after('published_at');
            $t->unsignedInteger('disabled_by')->nullable()->after('disabled_at');
            $t->timestamp('revoked_at')->nullable()->after('disabled_by');
            $t->unsignedInteger('revoked_by')->nullable()->after('revoked_at');
            $t->index('disabled_at');
            $t->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('boms', function (Blueprint $t) {
            $t->dropIndex(['disabled_at']);
            $t->dropIndex(['revoked_at']);
            $t->dropColumn(['disabled_at', 'disabled_by', 'revoked_at', 'revoked_by']);
        });
    }
};
