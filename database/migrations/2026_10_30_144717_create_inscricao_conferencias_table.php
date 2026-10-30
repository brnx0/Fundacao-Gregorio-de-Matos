<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscricao_conferencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscricao_id')->constrained('inscricoes', indexName: 'insc_conf_inscricao_fk')->cascadeOnDelete();
            // Exatamente um dos dois é preenchido (garantido pelo código).
            $table->foreignId('edital_documento_id')->nullable()->constrained('edital_documentos', indexName: 'insc_conf_documento_fk')->cascadeOnDelete();
            $table->foreignId('edital_pergunta_id')->nullable()->constrained('edital_perguntas', indexName: 'insc_conf_pergunta_fk')->cascadeOnDelete();
            $table->string('situacao', 20);
            $table->string('observacao', 1000)->nullable();
            $table->foreignId('user_id')->constrained('users', indexName: 'insc_conf_user_fk');
            $table->timestamps();

            // No Oracle, (1, 5, NULL) repetido já viola o único composto; nullsNotDistinct dá o mesmo no PostgreSQL.
            $table->unique(['inscricao_id', 'edital_documento_id', 'edital_pergunta_id'], 'insc_conf_item_unico')->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscricao_conferencias');
    }
};
