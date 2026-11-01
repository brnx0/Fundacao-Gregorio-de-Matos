<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avaliação de uma inscrição (um avaliador por inscrição) e a nota de cada critério, com peso e pontuação máxima
     * copiados do edital no momento da nota.
     */
    public function up(): void
    {
        Schema::create('inscricao_avaliacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscricao_id')->constrained('inscricoes', indexName: 'insc_aval_inscricao_fk')->cascadeOnDelete();
            $table->foreignId('avaliador_id')->constrained('avaliadores', indexName: 'insc_aval_avaliador_fk');
            $table->foreignId('atribuida_por')->nullable()->constrained('users', indexName: 'insc_aval_atrib_por_fk')->nullOnDelete();
            $table->dateTime('atribuida_em');
            $table->string('observacao', 4000)->nullable();
            $table->dateTime('concluida_em')->nullable();
            $table->decimal('nota_final', 10, 2)->nullable();
            $table->timestamps();

            $table->unique('inscricao_id', 'insc_aval_inscricao_uk');
            $table->index('avaliador_id', 'insc_aval_avaliador_idx');
        });

        Schema::create('inscricao_avaliacao_notas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscricao_avaliacao_id')->constrained('inscricao_avaliacoes', indexName: 'insc_aval_nota_aval_fk')->cascadeOnDelete();
            // Sem cascata (o Oracle não tem RESTRICT): critério com nota não se apaga.
            $table->foreignId('edital_criterio_id')->constrained('edital_criterios', indexName: 'insc_aval_nota_crit_fk');
            $table->decimal('nota', 6, 2);
            $table->decimal('peso', 6, 2);
            $table->decimal('pontos_maximos', 6, 2);
            $table->timestamps();

            $table->unique(['inscricao_avaliacao_id', 'edital_criterio_id'], 'insc_aval_nota_uk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscricao_avaliacao_notas');
        Schema::dropIfExists('inscricao_avaliacoes');
    }
};
