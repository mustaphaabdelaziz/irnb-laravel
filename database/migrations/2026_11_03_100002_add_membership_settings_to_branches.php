<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A branch's short code (the first part of its membership IDs) and the club's
 * main branch. One column on the club row means there can never be two main
 * branches. Suggested codes can be edited until an ID uses them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->string('code', 6)->nullable();
                $table->unique('code', 'branches_code_unique');
            });
        }
        if (! Schema::hasColumn('website_configs', 'main_branch_id')) {
            Schema::table('website_configs', function (Blueprint $table) {
                $table->foreignId('main_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            });
        }

        $used = [];
        foreach (DB::table('branches')->orderBy('id')->get() as $branch) {
            $code = $branch->code ?? $this->suggest($branch, $used);
            $used[] = $code;
            DB::table('branches')->where('id', $branch->id)->update(['code' => $code]);
        }

        $largest = DB::table('branch_player')->selectRaw('branch_id, COUNT(*) AS c')
            ->groupBy('branch_id')->orderByDesc('c')->orderBy('branch_id')->value('branch_id');
        if ($largest !== null) {
            DB::table('website_configs')->whereNull('main_branch_id')->update(['main_branch_id' => $largest]);
        }
    }

    private function suggest(object $branch, array $used): string
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $branch->name_en ?: ($branch->name_fr ?: $branch->name)));

        for ($length = 2; $length <= min(6, strlen($letters)); $length++) {
            if (! in_array(substr($letters, 0, $length), $used, true)) {
                return substr($letters, 0, $length);
            }
        }

        $suffix = 'A';
        while (in_array('BR'.$suffix, $used, true)) {
            $suffix++;
        }

        return 'BR'.$suffix;
    }

    public function down(): void
    {
        if (Schema::hasColumn('website_configs', 'main_branch_id')) {
            Schema::table('website_configs', fn (Blueprint $table) => $table->dropConstrainedForeignId('main_branch_id'));
        }
        if (Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropUnique('branches_code_unique');
                $table->dropColumn('code');
            });
        }
    }
};
