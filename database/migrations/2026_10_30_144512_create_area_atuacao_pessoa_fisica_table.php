<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Áreas de atuação que a pessoa física marca no cadastro. A área não se exclui (só se desativa), então a chave
     * para ela não cascateia. Nomes curtos para o limite de 30 caracteres do Oracle.
     */
    public function up(): void
    {
        Schema::create('area_atuacao_pessoa_fisica', function (Blueprint $table) {
            $table->foreignId('pessoa_fisica_id')->constrained('pessoas_fisicas', indexName: 'area_pessoa_pessoa_fk')->cascadeOnDelete();
            $table->foreignId('area_atuacao_id')->constrained('areas_atuacao', indexName: 'area_pessoa_area_fk');
            $table->primary(['pessoa_fisica_id', 'area_atuacao_id'], 'area_pessoa_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_atuacao_pessoa_fisica');
    }
};
