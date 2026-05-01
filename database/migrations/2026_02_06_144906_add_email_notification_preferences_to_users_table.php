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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications_enabled')->default(false)->after('default_dashboard_tab');
            $table->boolean('email_notify_process_analysis')->default(false)->after('email_notifications_enabled');
            $table->boolean('email_notify_contract_analysis')->default(false)->after('email_notify_process_analysis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_notifications_enabled',
                'email_notify_process_analysis',
                'email_notify_contract_analysis',
            ]);
        });
    }
};
