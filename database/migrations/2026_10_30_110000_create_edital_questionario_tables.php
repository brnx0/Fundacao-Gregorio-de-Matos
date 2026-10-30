<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edital_questionario_secoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edital_id')->constrained('editais')->cascadeOnDelete();
            $table->string('titulo', 255);
            $table->string('descricao', 2000)->nullable();
            $table->integer('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('edital_perguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edital_id')->constrained('editais')->cascadeOnDelete();
            $table->foreignId('edital_questionario_secao_id')->constrained('edital_questionario_secoes')->cascadeOnDelete();
            $table->string('enunciado', 2000);
            $table->string('ajuda', 2000)->nullable();
            $table->string('tipo', 20);
            $table->boolean('obrigatoria')->default(true);
            $table->integer('limite_caracteres')->nullable();
            // JSON: lista de {chave, texto, outro}.
            $table->longText('opcoes')->nullable();
            $table->boolean('todas_categorias')->default(false);
            $table->foreignId('condicao_pergunta_id')->nullable()->constrained('edital_perguntas')->nullOnDelete();
            // JSON: lista de chaves da pergunta de origem.
            $table->string('condicao_opcoes', 2000)->nullable();
            $table->integer('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('edital_pergunta_categoria', function (Blueprint $table) {
            $table->foreignId('edital_pergunta_id')->constrained('edital_perguntas')->cascadeOnDelete();
            $table->foreignId('edital_categoria_id')->constrained('edital_categorias')->cascadeOnDelete();
            $table->primary(['edital_pergunta_id', 'edital_categoria_id'], 'edital_perg_cat_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edital_pergunta_categoria');
        Schema::dropIfExists('edital_perguntas');
        Schema::dropIfExists('edital_questionario_secoes');
    }
};
