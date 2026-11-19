<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The organisation's default for settings.order (parallel or sequential).
 * `order` is a reserved word in SQL, so the column is signing_order. Existing
 * rows read as parallel, which is what they did before.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('fancy-sign.connection');
    }

    public function up(): void
    {
        Schema::table('fancy_sign_org_settings', function (Blueprint $t): void {
            $t->string('signing_order', 12)->default('parallel');
        });
    }

    public function down(): void
    {
        Schema::table('fancy_sign_org_settings', function (Blueprint $t): void {
            $t->dropColumn('signing_order');
        });
    }
};
