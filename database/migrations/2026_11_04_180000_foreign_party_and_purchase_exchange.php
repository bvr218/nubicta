<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('foreign_city', 80)->nullable()->after('country_code');
        });
        Schema::table('purchase_documents', function (Blueprint $table) {
            $table->decimal('exchange_rate', 14, 4)->default(1)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('foreign_city');
        });
        Schema::table('purchase_documents', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
};
