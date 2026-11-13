<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('fancy-sign.connection');
    }

    public function up(): void
    {
        Schema::create('fancy_sign_envelopes', function (Blueprint $t): void {
            $t->string('id', 26)->primary();
            $t->string('org_id', 64)->nullable()->index();
            $t->string('status', 16)->index();
            $t->string('created_by', 191)->index();
            $t->longText('data');
            $t->string('created_at', 32);
        });
        Schema::create('fancy_sign_events', function (Blueprint $t): void {
            $t->id();
            $t->string('envelope_id', 26)->index();
            $t->unsignedInteger('seq');
            $t->string('at', 32);
            $t->string('type', 32);
            $t->string('actor', 191);
            $t->string('source', 8);
            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('data');
            $t->char('prev_hash', 64);
            $t->char('hash', 64);
            $t->unique(['envelope_id', 'seq']);
        });
        Schema::create('fancy_sign_links', function (Blueprint $t): void {
            $t->char('token_hash', 64)->primary();
            $t->string('envelope_id', 26);
            $t->string('signer_id', 64);
            $t->index(['envelope_id', 'signer_id']);
        });
        Schema::create('fancy_sign_otps', function (Blueprint $t): void {
            $t->string('key', 191)->primary();
            $t->char('hash', 64);
            $t->unsignedBigInteger('expires_at');
            $t->unsignedInteger('attempts');
        });
        Schema::create('fancy_sign_sessions', function (Blueprint $t): void {
            $t->char('hash', 64)->primary();
            $t->string('envelope_id', 26);
            $t->string('signer_id', 64);
            $t->unsignedBigInteger('expires_at');
        });
        Schema::create('fancy_sign_hits', function (Blueprint $t): void {
            $t->string('key', 191)->primary();
            $t->unsignedInteger('count');
            $t->unsignedBigInteger('ends');
        });
        Schema::create('fancy_sign_blobs', function (Blueprint $t): void {
            $t->string('key', 191)->primary();
            $t->longText('bytes');
        });
    }

    public function down(): void
    {
        foreach (['blobs', 'hits', 'sessions', 'otps', 'links', 'events', 'envelopes'] as $table) {
            Schema::dropIfExists("fancy_sign_{$table}");
        }
    }
};
