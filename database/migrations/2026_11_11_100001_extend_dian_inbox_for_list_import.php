<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_inbox_documents', function (Blueprint $table) {
            $table->string('document_class', 4)->default('FC')->after('kind');
            $table->string('related_cufe', 120)->nullable()->after('document_class');
        });
        DB::table('dian_inbox_documents')->where('kind', 'Nota crédito')->update(['document_class' => 'NC']);
        DB::table('dian_inbox_documents')->where('kind', 'Nota débito')->update(['document_class' => 'ND']);

        Schema::table('dian_inbox_syncs', function (Blueprint $table) {
            $table->unsignedInteger('total')->default(0)->after('status');
            $table->unsignedInteger('processed')->default(0)->after('total');
            $table->unsignedInteger('errors')->default(0)->after('received');
        });

        Schema::create('dian_inbox_sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_id')->constrained('dian_inbox_syncs')->cascadeOnDelete();
            $table->string('cufe', 120);
            $table->string('status', 20)->default('En cola');
            $table->string('message', 500)->nullable();
            $table->timestamps();
            $table->unique(['sync_id', 'cufe']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_inbox_sync_items');
        Schema::table('dian_inbox_syncs', fn (Blueprint $table) => $table->dropColumn(['total', 'processed', 'errors']));
        Schema::table('dian_inbox_documents', fn (Blueprint $table) => $table->dropColumn(['document_class', 'related_cufe']));
    }
};
