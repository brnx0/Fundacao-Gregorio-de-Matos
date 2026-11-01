<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avaliadores escolhidos para um edital, com o andamento do convite por e-mail e as categorias que avaliam.
     */
    public function up(): void
    {
        Schema::create('edital_avaliadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edital_id')->constrained('editais', indexName: 'edital_aval_edital_fk');
            $table->foreignId('avaliador_id')->constrained('avaliadores', indexName: 'edital_aval_avaliador_fk');
            $table->string('convite_situacao', 20)->nullable();
            $table->string('convite_erro', 500)->nullable();
            $table->dateTime('convite_enviado_em')->nullable();
            $table->dateTime('convite_iniciado_em')->nullable();
            $table->string('convite_execucao', 36)->nullable();
            $table->timestamps();

            $table->unique(['edital_id', 'avaliador_id'], 'edital_aval_unico');
        });

        Schema::create('edital_avaliador_categoria', function (Blueprint $table) {
            $table->foreignId('edital_avaliador_id')->constrained('edital_avaliadores', indexName: 'edital_aval_cat_aval_fk')->cascadeOnDelete();
            $table->foreignId('edital_categoria_id')->constrained('edital_categorias', indexName: 'edital_aval_cat_cat_fk')->cascadeOnDelete();

            $table->primary(['edital_avaliador_id', 'edital_categoria_id'], 'edital_aval_cat_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edital_avaliador_categoria');
        Schema::dropIfExists('edital_avaliadores');
    }
};
