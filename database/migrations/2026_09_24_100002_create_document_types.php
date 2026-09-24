<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The documents a player's file should hold, managed in Settings.
     *
     * `code` is how the app recognises a type (the Photo rule keys off
     * `photo`); records point at the id, so renaming a type or changing its
     * rules never breaks them. `max_age` replaces the category limit of the
     * first draft (owner decision): 17 means "asked while the player is 17 or
     * younger", null means every age.
     *
     * The seven defaults are seeded here, not in a seeder, because the desktop
     * build runs `migrate` on boot and never runs seeders.
     *
     * Re-runnable: SQLite DDL is not rolled back when a later statement fails,
     * so a second run meets its own table (guarded) and its own rows (inserted
     * only when the code is absent — an edited default is never overwritten).
     */
    public function up(): void
    {
        if (! Schema::hasTable('document_types')) {
            Schema::create('document_types', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('name');
                $table->string('name_ar')->nullable();
                $table->string('name_fr')->nullable();
                $table->string('name_en')->nullable();
                $table->boolean('is_required')->default(false);
                $table->string('validity', 16)->default('none');
                $table->unsignedTinyInteger('max_age')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $now = now();

        foreach ($this->defaults() as $order => $type) {
            if (DB::table('document_types')->where('code', $type['code'])->exists()) {
                continue;
            }

            DB::table('document_types')->insert([
                ...$type,
                'name' => $type['name_fr'],
                'is_active' => true,
                'sort_order' => ($order + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }

    /** @return list<array<string, mixed>> */
    private function defaults(): array
    {
        return [
            ['code' => 'birth_certificate', 'name_fr' => 'Acte de naissance', 'name_ar' => 'شهادة الميلاد', 'name_en' => 'Birth certificate', 'is_required' => true, 'validity' => 'none', 'max_age' => null],
            // Owner decision: received whenever the player has a profile picture.
            ['code' => 'photo', 'name_fr' => 'Photo', 'name_ar' => 'صورة شمسية', 'name_en' => 'Photo', 'is_required' => true, 'validity' => 'none', 'max_age' => null],
            ['code' => 'medical_certificate', 'name_fr' => 'Certificat médical', 'name_ar' => 'شهادة طبية', 'name_en' => 'Medical certificate', 'is_required' => true, 'validity' => 'season', 'max_age' => null],
            // Owner decision: required, but only while the player is 17 or younger.
            ['code' => 'parental_authorization', 'name_fr' => 'Autorisation parentale', 'name_ar' => 'ترخيص أبوي', 'name_en' => 'Parental authorization', 'is_required' => true, 'validity' => 'season', 'max_age' => 17],
            ['code' => 'id_card_copy', 'name_fr' => "Copie de la carte d'identité", 'name_ar' => 'نسخة من بطاقة التعريف', 'name_en' => 'ID card copy', 'is_required' => false, 'validity' => 'date', 'max_age' => null],
            ['code' => 'residence_certificate', 'name_fr' => 'Certificat de résidence', 'name_ar' => 'شهادة الإقامة', 'name_en' => 'Residence certificate', 'is_required' => false, 'validity' => 'none', 'max_age' => null],
            ['code' => 'school_certificate', 'name_fr' => 'Certificat de scolarité', 'name_ar' => 'شهادة مدرسية', 'name_en' => 'School certificate', 'is_required' => false, 'validity' => 'season', 'max_age' => null],
        ];
    }
};
