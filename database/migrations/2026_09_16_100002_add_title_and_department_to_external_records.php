<?php

use App\Models\ExternalRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job title and department become fixed directory fields (shown on the
 * client and the staff user, exportable). Values already imported into the
 * "other columns" JSON under the title/department keys are carried over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_records', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('company');
            $table->string('department')->nullable()->after('title');
        });

        ExternalRecord::query()->withTrashed()->whereNotNull('attributes')->each(function (ExternalRecord $record): void {
            $title = $record->attribute('title');
            $department = $record->attribute('department');

            if ($title !== null || $department !== null) {
                $record->timestamps = false;
                $record->forceFill(['title' => $title, 'department' => $department])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('external_records', function (Blueprint $table): void {
            $table->dropColumn(['title', 'department']);
        });
    }
};
