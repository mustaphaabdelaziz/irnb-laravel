<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('code', 8)->nullable();
            $table->string('flag')->nullable();
            $table->timestamps();
        });

        Schema::create('country_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('external_id')->nullable();
            $table->string('name');
            $table->string('ar_name')->nullable();
            $table->string('longitude')->nullable();
            $table->string('latitude')->nullable();
            $table->timestamps();

            $table->index(['country_id', 'name']);
            $table->unique(['country_id', 'external_id']);
        });

        Schema::create('country_state_communes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_state_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['country_state_id', 'name']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('member_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('abbreviation', 16)->unique();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->decimal('amount_student', 12, 2)->default(0);
            $table->decimal('amount_worker', 12, 2)->default(0);
            $table->text('details')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'year']);
            $table->index(['year', 'is_active']);
        });

        Schema::create('category_subscription', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            $table->primary(['category_id', 'subscription_id']);
        });

        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('membership_id', 10)->unique();
            $table->string('firstname');
            $table->string('lastname')->nullable()->index();
            $table->string('nickname')->nullable();
            $table->string('father')->nullable();
            $table->string('grandfather')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('gender', 20)->default('Male');
            $table->string('picture_url')->nullable();
            $table->string('picture_filename')->nullable();
            $table->json('phones')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('status_class')->nullable();
            $table->string('status_value')->nullable();
            $table->string('state')->default('Unknown');
            $table->string('city')->default('Unknown');
            $table->boolean('is_student')->default(true);
            $table->foreignId('member_job_id')->nullable()->constrained('member_jobs')->nullOnDelete();
            $table->unsignedSmallInteger('join_year')->nullable();
            $table->boolean('archived')->default(false)->index();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('team')->nullable();
            $table->unsignedTinyInteger('skill_level')->default(5);
            $table->text('health_medical_conditions')->nullable();
            $table->string('health_blood_group_rhesus', 10)->nullable();
            $table->decimal('outstanding_debt', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['lastname', 'firstname']);
            $table->index(['category_id', 'archived']);
        });

        Schema::create('player_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relationship')->nullable();
            $table->json('phones')->nullable();
            $table->timestamps();
        });

        Schema::create('player_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('date')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamp('transaction_date')->useCurrent();
            $table->enum('transaction_type', ['income', 'expense'])->default('income');
            $table->string('category');
            $table->string('sub_category')->nullable();
            $table->string('payment_method')->default('cash');
            $table->string('payment_account')->nullable();
            $table->string('related_entity_type')->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['Paid', 'Partial', 'Unpaid', 'Exempt'])->default('Unpaid');
            $table->boolean('archived')->default(false);
            $table->string('receipt_url')->nullable();
            $table->string('receipt_filename')->nullable();
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->timestamps();

            $table->index(['fiscal_year', 'category', 'transaction_type']);
            $table->index(['transaction_type', 'transaction_date']);
            $table->index(['related_entity_type', 'related_entity_id']);
        });

        Schema::create('player_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->enum('status_at_time', ['student', 'worker', 'unknown'])->default('unknown');
            $table->decimal('amount_owed', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->boolean('is_legacy')->default(false);
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'year']);
            $table->index(['subscription_id', 'year']);
        });

        Schema::create('equipment_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('category')->index();
            $table->string('brand')->nullable();
            $table->text('description')->nullable();
            $table->json('specifications')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->string('picture_url')->nullable();
            $table->string('picture_filename')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamps();
        });

        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_id')->constrained('equipment_catalogs')->cascadeOnDelete();
            $table->string('unique_identifier')->unique();
            $table->date('purchase_date');
            $table->enum('status', ['Available', 'Rented', 'Under Repair', 'Out of Service', 'Lost', 'Retired'])->default('Available')->index();
            $table->enum('condition', ['New', 'Good', 'Fair', 'Poor', 'Damaged'])->default('New');
            $table->string('location')->nullable();
            $table->foreignId('purchase_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('rented_to_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->timestamp('last_checkout_date')->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'condition']);
            $table->index(['rented_to_player_id', 'status']);
            $table->index(['due_date', 'status']);
        });

        Schema::create('equipment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type')->index();
            $table->json('details')->nullable();
            $table->timestamp('event_timestamp')->useCurrent();
            $table->timestamps();

            $table->index(['item_id', 'event_timestamp']);
        });

        Schema::create('website_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('singleton_key')->default(1)->unique();

            $table->json('club_name')->nullable();
            $table->string('club_short_name')->default('SC');
            $table->json('tagline')->nullable();
            $table->json('description')->nullable();
            $table->date('founding_date')->nullable();
            $table->string('founder')->nullable();
            $table->json('motto')->nullable();

            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_mobile')->nullable();
            $table->string('contact_fax')->nullable();
            $table->string('contact_website')->nullable();
            $table->json('contact_address')->nullable();

            $table->json('social_media')->nullable();
            $table->json('banking_info')->nullable();
            $table->json('legal_info')->nullable();
            $table->json('leadership')->nullable();
            $table->json('branding')->nullable();
            $table->json('facilities')->nullable();
            $table->json('settings')->nullable();
            $table->json('seo')->nullable();
            $table->json('documents')->nullable();

            $table->foreignId('last_modified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_configs');
        Schema::dropIfExists('equipment_histories');
        Schema::dropIfExists('equipment_items');
        Schema::dropIfExists('equipment_catalogs');
        Schema::dropIfExists('player_subscriptions');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('player_achievements');
        Schema::dropIfExists('player_emergency_contacts');
        Schema::dropIfExists('players');
        Schema::dropIfExists('category_subscription');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('member_jobs');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('country_state_communes');
        Schema::dropIfExists('country_states');
        Schema::dropIfExists('countries');
    }
};
