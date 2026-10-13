<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vortech\Trace\Support\TraceConfig;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return TraceConfig::connection();
    }

    public function up(): void
    {
        Schema::create('trace_streams', function (Blueprint $table): void {
            $table->id();
            $table->string('model_type')->unique();
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('pruned_version')->default(0);
            $table->timestamps();
        });

        Schema::create('trace_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stream_id');
            $table->unsignedBigInteger('version');
            $table->string('model_key', 191);
            $table->string('operation', 20);
            $table->timestamp('created_at')->nullable();

            $table->unique(['stream_id', 'version']);
            $table->index(['stream_id', 'model_key', 'version']);
            $table->index(['stream_id', 'created_at']);
        });

        Schema::create('trace_meta', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_meta');
        Schema::dropIfExists('trace_changes');
        Schema::dropIfExists('trace_streams');
    }
};
