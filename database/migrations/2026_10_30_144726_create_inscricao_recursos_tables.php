<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscricao_recursos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscricao_id')->constrained('inscricoes', indexName: 'insc_rec_inscricao_fk')->cascadeOnDelete();
            $table->longText('justificativa')->nullable();
            $table->dateTime('enviado_em');
            $table->string('decisao', 20)->nullable();
            $table->longText('parecer')->nullable();
            $table->foreignId('julgado_por')->nullable()->constrained('users', indexName: 'insc_rec_julgado_por_fk')->nullOnDelete();
            $table->dateTime('julgado_em')->nullable();
            $table->timestamps();

            $table->unique('inscricao_id', 'insc_rec_inscricao_unico');
        });

        Schema::create('inscricao_recurso_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscricao_recurso_id')->constrained('inscricao_recursos', indexName: 'insc_rec_doc_recurso_fk')->cascadeOnDelete();
            $table->foreignId('edital_documento_id')->constrained('edital_documentos', indexName: 'insc_rec_doc_documento_fk')->cascadeOnDelete();
            $table->string('arquivo', 255);
            $table->string('arquivo_nome', 255);
            $table->timestamps();

            $table->unique(['inscricao_recurso_id', 'edital_documento_id'], 'insc_rec_doc_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscricao_recurso_documentos');
        Schema::dropIfExists('inscricao_recursos');
    }
};
