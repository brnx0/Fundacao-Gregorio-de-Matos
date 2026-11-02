<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Arquivos da etapa Anexos do cadastro, um por campo, com o resultado da conferência por IA (como inscricao_documentos). */
    public function up(): void
    {
        Schema::create('pessoa_fisica_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pessoa_fisica_id')->constrained('pessoas_fisicas')->cascadeOnDelete();
            $table->string('campo', 30);
            $table->string('arquivo', 255);
            $table->string('arquivo_nome', 255);
            $table->string('situacao', 20);
            $table->string('motivo', 1000)->nullable();
            $table->string('erro', 1000)->nullable();
            $table->string('provedor', 20)->nullable();
            $table->string('modelo', 100)->nullable();
            $table->string('execucao', 36)->nullable();
            $table->dateTime('iniciada_em')->nullable();
            $table->dateTime('conferido_em')->nullable();
            $table->timestamps();

            $table->unique(['pessoa_fisica_id', 'campo'], 'pf_anexos_campo_uk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pessoa_fisica_anexos');
    }
};
