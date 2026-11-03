<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration que leva os arquivos do cadastro das colunas de pessoas_fisicas para pessoa_fisica_anexos.
 *
 * Roda numa conexão SQLite em memória, como LimpezaAjustesAntigosTest: no banco de testes as colunas antigas já
 * saíram. O caminho no Oracle é conferido com o migrate do banco de desenvolvimento.
 */
class MoverAnexosDoCadastroTest extends TestCase
{
    private const string MIGRATION = 'migrations/2026_11_02_151051_move_arquivos_to_pessoa_fisica_anexos.php';

    private const string TABELA_DE_ANEXOS = 'migrations/2026_11_02_151049_create_pessoa_fisica_anexos_table.php';

    private const array COLUNAS_DE_ARQUIVO = [
        'foto_perfil', 'documento_cpf', 'documento_rg', 'documento_passaporte', 'documento_crnm', 'documento_outros', 'comprovante_residencia',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mover_anexos' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('mover_anexos');

        // As colunas de pessoas_fisicas que importam aqui, como a migration original as criou.
        Schema::create('pessoas_fisicas', function (Blueprint $table) {
            $table->id();
            $table->string('foto_perfil')->nullable();
            $table->string('rg', 20);
            $table->string('documento_cpf');
            $table->string('documento_rg');
            $table->string('documento_passaporte')->nullable();
            $table->string('documento_crnm')->nullable();
            $table->string('documento_outros')->nullable();
            $table->string('comprovante_residencia')->nullable();
            $table->timestamps();
        });
        (require database_path(self::TABELA_DE_ANEXOS))->up();

        DB::table('pessoas_fisicas')->insert([
            [
                'id' => 1, 'rg' => '1234567', 'foto_perfil' => 'cadastros/7/foto.PNG',
                'documento_cpf' => 'cadastros/7/cpf.pdf', 'documento_rg' => 'cadastros/7/rg.jpg',
                'documento_passaporte' => '', 'documento_crnm' => null, 'documento_outros' => null,
                'comprovante_residencia' => 'cadastros/7/conta.pdf',
                'created_at' => '2026-01-05 08:00:00', 'updated_at' => '2026-01-10 09:00:00',
            ],
            [
                'id' => 2, 'rg' => '7654321', 'foto_perfil' => null,
                'documento_cpf' => 'cadastros/8/cpf.pdf', 'documento_rg' => 'cadastros/8/rg.pdf',
                'documento_passaporte' => null, 'documento_crnm' => null, 'documento_outros' => null,
                'comprovante_residencia' => null,
                'created_at' => '2026-02-01 08:00:00', 'updated_at' => '2026-02-01 08:00:00',
            ],
        ]);
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /** @return list<array{pessoa: int, campo: string, arquivo: string, nome: string, situacao: string}> */
    private function anexos(): array
    {
        return DB::table('pessoa_fisica_anexos')->orderBy('pessoa_fisica_id')->orderBy('campo')->get()
            ->map(fn (object $anexo): array => [
                'pessoa' => (int) $anexo->pessoa_fisica_id,
                'campo' => $anexo->campo,
                'arquivo' => $anexo->arquivo,
                'nome' => $anexo->arquivo_nome,
                'situacao' => $anexo->situacao,
            ])
            ->all();
    }

    /** O que a migration deve deixar: só os arquivos enviados, como recebidos e com o rótulo no nome. */
    private function assertAnexosCopiados(): void
    {
        $this->assertSame([
            ['pessoa' => 1, 'campo' => 'comprovante_residencia', 'arquivo' => 'cadastros/7/conta.pdf', 'nome' => 'comprovante-de-residencia.pdf', 'situacao' => 'enviado'],
            ['pessoa' => 1, 'campo' => 'documento_cpf', 'arquivo' => 'cadastros/7/cpf.pdf', 'nome' => 'copia-do-cpf.pdf', 'situacao' => 'enviado'],
            ['pessoa' => 1, 'campo' => 'documento_rg', 'arquivo' => 'cadastros/7/rg.jpg', 'nome' => 'copia-do-rg.jpg', 'situacao' => 'enviado'],
            ['pessoa' => 1, 'campo' => 'foto_perfil', 'arquivo' => 'cadastros/7/foto.PNG', 'nome' => 'foto-de-perfil.png', 'situacao' => 'enviado'],
            ['pessoa' => 2, 'campo' => 'documento_cpf', 'arquivo' => 'cadastros/8/cpf.pdf', 'nome' => 'copia-do-cpf.pdf', 'situacao' => 'enviado'],
            ['pessoa' => 2, 'campo' => 'documento_rg', 'arquivo' => 'cadastros/8/rg.pdf', 'nome' => 'copia-do-rg.pdf', 'situacao' => 'enviado'],
        ], $this->anexos());
        $this->assertSame(['id', 'rg', 'created_at', 'updated_at'], Schema::getColumnListing('pessoas_fisicas'));
    }

    public function test_copia_os_arquivos_enviados_sem_os_vazios_e_apaga_as_colunas(): void
    {
        $this->migration()->up();

        $this->assertAnexosCopiados();
        $this->assertSame('2026-01-10 09:00:00', DB::table('pessoa_fisica_anexos')->where('pessoa_fisica_id', 1)->value('created_at'));
        $this->assertSame(['1234567', '7654321'], DB::table('pessoas_fisicas')->orderBy('id')->pluck('rg')->all());
    }

    public function test_rodar_de_novo_nao_falha_nem_duplica(): void
    {
        $this->migration()->up();

        $this->migration()->up();

        $this->assertAnexosCopiados();
    }

    public function test_retomada_depois_de_uma_falha_nao_copia_de_novo_o_que_ja_tem_linha(): void
    {
        // Uma rodada que parou antes de apagar as colunas: o CPF da pessoa 1 já foi copiado e conferido depois.
        DB::table('pessoa_fisica_anexos')->insert([
            'pessoa_fisica_id' => 1, 'campo' => 'documento_cpf', 'arquivo' => 'cadastros/7/cpf.pdf',
            'arquivo_nome' => 'copia-do-cpf.pdf', 'situacao' => 'aprovado',
        ]);

        $this->migration()->up();

        $this->assertSame(1, DB::table('pessoa_fisica_anexos')->where('pessoa_fisica_id', 1)->where('campo', 'documento_cpf')->count());
        $this->assertSame('aprovado', DB::table('pessoa_fisica_anexos')->where('pessoa_fisica_id', 1)->where('campo', 'documento_cpf')->value('situacao'));
        $this->assertSame(6, DB::table('pessoa_fisica_anexos')->count());
    }

    public function test_down_recria_as_colunas_com_os_caminhos(): void
    {
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame([], array_diff(self::COLUNAS_DE_ARQUIVO, Schema::getColumnListing('pessoas_fisicas')));
        $this->assertSame([
            ['foto_perfil' => 'cadastros/7/foto.PNG', 'documento_cpf' => 'cadastros/7/cpf.pdf', 'documento_rg' => 'cadastros/7/rg.jpg', 'documento_passaporte' => null, 'documento_crnm' => null, 'documento_outros' => null, 'comprovante_residencia' => 'cadastros/7/conta.pdf'],
            ['foto_perfil' => null, 'documento_cpf' => 'cadastros/8/cpf.pdf', 'documento_rg' => 'cadastros/8/rg.pdf', 'documento_passaporte' => null, 'documento_crnm' => null, 'documento_outros' => null, 'comprovante_residencia' => null],
        ], DB::table('pessoas_fisicas')->orderBy('id')->get(self::COLUNAS_DE_ARQUIVO)->map(fn (object $pessoa): array => (array) $pessoa)->all());
    }
}
