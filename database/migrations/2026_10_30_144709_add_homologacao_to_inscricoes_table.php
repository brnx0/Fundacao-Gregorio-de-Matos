<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inscricoes', function (Blueprint $table) {
            $table->string('homologacao', 20)->nullable();
            // Texto longo (CLOB no Oracle): fora de listagens.
            $table->longText('homologacao_motivo')->nullable();
            $table->foreignId('homologada_por')->nullable()->constrained('users', indexName: 'inscricoes_homolog_por_fk')->nullOnDelete();
            $table->dateTime('homologada_em')->nullable();

            $table->index('homologacao', 'inscricoes_homolog_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inscricoes', function (Blueprint $table) {
            $table->dropForeign('inscricoes_homolog_por_fk');
            $table->dropIndex('inscricoes_homolog_idx');
            $table->dropColumn(['homologacao', 'homologacao_motivo', 'homologada_por', 'homologada_em']);
        });
    }
};
