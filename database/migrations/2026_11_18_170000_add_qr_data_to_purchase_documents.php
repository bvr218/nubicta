<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** El documento soporte guarda el mismo texto de QR que la factura electrónica. */
    public function up(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table) {
            $table->text('qr_data')->nullable()->after('dian_message');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_documents', fn (Blueprint $table) => $table->dropColumn('qr_data'));
    }
};
