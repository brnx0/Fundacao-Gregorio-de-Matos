<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link de acesso do avaliador: o hash localiza o avaliador, o texto cifrado permite reexibir o link ao gestor.
     */
    public function up(): void
    {
        Schema::table('avaliadores', function (Blueprint $table) {
            $table->string('acesso_hash', 64)->nullable();
            $table->string('acesso_cifrado', 1000)->nullable();

            $table->unique('acesso_hash', 'avaliadores_acesso_uk');
        });
    }

    public function down(): void
    {
        Schema::table('avaliadores', function (Blueprint $table) {
            $table->dropUnique('avaliadores_acesso_uk');
            $table->dropColumn(['acesso_hash', 'acesso_cifrado']);
        });
    }
};
