<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os anexos do cadastro (App\Enums\AnexoCadastro). As linhas vão na migration, como as áreas de atuação: a tela da
     * retaguarda só edita, e todas começam sem conferência por IA, para nada mudar até a equipe escolher.
     *
     * @var list<string>
     */
    private const array CAMPOS = [
        'foto_perfil', 'documento_cpf', 'documento_rg', 'documento_passaporte',
        'documento_crnm', 'documento_outros', 'comprovante_residencia',
    ];

    public function up(): void
    {
        Schema::create('configuracoes_anexos', function (Blueprint $table) {
            $table->id();
            $table->string('campo', 30)->unique('config_anexos_campo_uk');
            $table->boolean('validar_ia')->default(false);
            $table->string('instrucao_ia', 2000)->nullable();
            $table->timestamps();
        });

        $agora = now();

        foreach (self::CAMPOS as $campo) {
            DB::table('configuracoes_anexos')->insert(['campo' => $campo, 'validar_ia' => false, 'created_at' => $agora, 'updated_at' => $agora]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_anexos');
    }
};
