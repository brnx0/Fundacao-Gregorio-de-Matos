<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quando a análise do questionário terminou: com o início, dá a duração que prevê a barra de progresso das próximas.
     * As anteriores ficam sem a data (e fora da previsão): o updated_at também muda quando a equipe confere o quadro.
     */
    public function up(): void
    {
        Schema::table('importacoes_questionario', function (Blueprint $table) {
            $table->timestamp('concluida_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('importacoes_questionario', function (Blueprint $table) {
            $table->dropColumn('concluida_em');
        });
    }
};
