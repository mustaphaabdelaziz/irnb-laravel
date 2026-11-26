<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission orders (أمر بمهمة): who travels where, why, when and how, one
 * printed sheet per traveler. No number of their own — the reference is the
 * mail-register entry taken at the first print (source mission_order / id /
 * "order"), so there is no FK here. Never deleted: cancelled instead. The
 * two old Access orders are imported read-only with their text reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_orders', function (Blueprint $table) {
            $table->id();
            // Required by the form; null only on an imported order whose old date could not be read.
            $table->date('order_date')->nullable();
            $table->string('destination', 200)->nullable();
            $table->text('reason')->nullable();
            $table->date('departure_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('transport', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_legacy')->default(false);
            $table->string('legacy_reference', 50)->nullable();
            $table->string('legacy_source', 20)->nullable();
            // Bumped by every claim so the claim always changes a row (MySQL counts changed rows only).
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('order_date');
            $table->index(['legacy_source', 'legacy_reference']);
        });

        Schema::create('mission_order_travelers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_order_id')->constrained('mission_orders')->cascadeOnDelete();
            // user / board_member / player, or null for a typed name.
            $table->string('traveler_type', 20)->nullable();
            $table->unsignedBigInteger('traveler_id')->nullable();
            // Snapshots, editable: the sheet prints what the order says.
            $table->string('name', 200);
            $table->string('function', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['traveler_type', 'traveler_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_order_travelers');
        Schema::dropIfExists('mission_orders');
    }
};
