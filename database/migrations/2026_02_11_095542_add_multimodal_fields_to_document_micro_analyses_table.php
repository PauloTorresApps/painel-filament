<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_micro_analyses', function (Blueprint $table) {
            $table->string('original_content_path', 500)->nullable()->after('mimetype');
            $table->string('processing_strategy', 30)->nullable()->after('original_content_path');
            $table->boolean('is_scanned')->nullable()->after('processing_strategy');
            $table->string('file_annotation_hash', 255)->nullable()->after('is_scanned');
        });
    }

    public function down(): void
    {
        Schema::table('document_micro_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'original_content_path',
                'processing_strategy',
                'is_scanned',
                'file_annotation_hash',
            ]);
        });
    }
};
