<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * As colunas de caminho de arquivo de pessoas_fisicas, com o rótulo de cada uma (o nome de download dos arquivos
     * antigos, que não guardavam o nome original). Ficam aqui, e não no enum, para a migration não mudar se ele mudar.
     *
     * @var array<string, string>
     */
    private const array COLUNAS = [
        'foto_perfil' => 'Foto de perfil',
        'documento_cpf' => 'Cópia do CPF',
        'documento_rg' => 'Cópia do RG',
        'documento_passaporte' => 'Passaporte',
        'documento_crnm' => 'Registro Nacional Migratório (CRNM)',
        'documento_outros' => 'Outros documentos',
        'comprovante_residencia' => 'Comprovante de residência',
    ];

    /**
     * Copia cada arquivo já enviado para uma linha de pessoa_fisica_anexos (enviado, sem conferência) e apaga as colunas.
     * Os arquivos continuam no mesmo lugar do disco. O DDL do Oracle não volta atrás numa falha: por isso a migration
     * pode rodar de novo (só copia o que ainda não tem linha e para se as colunas já saíram).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('pessoas_fisicas', 'documento_cpf')) {
            return;
        }

        DB::table('pessoas_fisicas')
            ->select(['id', 'updated_at', ...array_keys(self::COLUNAS)])
            ->orderBy('id')
            ->chunkById(200, function ($pessoas): void {
                foreach ($pessoas as $pessoa) {
                    foreach (self::COLUNAS as $coluna => $rotulo) {
                        $this->copiar($pessoa, $coluna, $rotulo);
                    }
                }
            });

        Schema::table('pessoas_fisicas', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::COLUNAS));
        });
    }

    /** Recria as colunas (aceitando nulo: não há como exigir CPF e RG de quem não os tem) e devolve os caminhos. */
    public function down(): void
    {
        if (Schema::hasColumn('pessoas_fisicas', 'documento_cpf')) {
            return;
        }

        Schema::table('pessoas_fisicas', function (Blueprint $table) {
            foreach (array_keys(self::COLUNAS) as $coluna) {
                $table->string($coluna)->nullable();
            }
        });

        DB::table('pessoa_fisica_anexos')
            ->select(['id', 'pessoa_fisica_id', 'campo', 'arquivo'])
            ->orderBy('id')
            ->chunkById(200, function ($anexos): void {
                foreach ($anexos as $anexo) {
                    if (array_key_exists($anexo->campo, self::COLUNAS)) {
                        DB::table('pessoas_fisicas')->where('id', $anexo->pessoa_fisica_id)->update([$anexo->campo => $anexo->arquivo]);
                    }
                }
            });
    }

    private function copiar(object $pessoa, string $coluna, string $rotulo): void
    {
        $caminho = $pessoa->{$coluna};

        if ($caminho === null || $caminho === '') {
            return;
        }

        $jaCopiado = DB::table('pessoa_fisica_anexos')
            ->where('pessoa_fisica_id', $pessoa->id)
            ->where('campo', $coluna)
            ->exists();

        if ($jaCopiado) {
            return;
        }

        $extensao = pathinfo($caminho, PATHINFO_EXTENSION);

        DB::table('pessoa_fisica_anexos')->insert([
            'pessoa_fisica_id' => $pessoa->id,
            'campo' => $coluna,
            'arquivo' => $caminho,
            'arquivo_nome' => Str::slug($rotulo).($extensao === '' ? '' : '.'.strtolower($extensao)),
            'situacao' => 'enviado',
            'created_at' => $pessoa->updated_at ?? now(),
            'updated_at' => $pessoa->updated_at ?? now(),
        ]);
    }
};
