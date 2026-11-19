<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The organisation's default for settings.final_page (always, auto or never).
 * Existing rows read as always, which is what they rendered before.
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
            $t->string('final_page', 8)->default('always');
        });
    }

    public function down(): void
    {
        Schema::table('fancy_sign_org_settings', function (Blueprint $t): void {
            $t->dropColumn('final_page');
        });
    }
};
