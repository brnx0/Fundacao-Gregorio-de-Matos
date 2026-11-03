<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\AnexoCadastro;
use App\Models\ConfiguracaoAnexo;
use App\Models\ConfiguracaoIa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AnexosDoCadastroTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/retaguarda/anexos-cadastro';

    /**
     * Os sete anexos dispensados e sem texto, com as alterações de cada campo.
     *
     * @param  array<string, array<string, mixed>>  $alteracoes
     * @return array{anexos: array<string, array<string, mixed>>}
     */
    private static function envio(array $alteracoes = []): array
    {
        $anexos = [];

        foreach (AnexoCadastro::valores() as $campo) {
            $anexos[$campo] = [...['validar_ia' => false, 'instrucao_ia' => null], ...($alteracoes[$campo] ?? [])];
        }

        return ['anexos' => $anexos];
    }

    private static function doCampo(AnexoCadastro $campo): ConfiguracaoAnexo
    {
        return ConfiguracaoAnexo::query()->where('campo', $campo->value)->sole();
    }

    private function entrarComoGestor(): void
    {
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');
    }

    public function test_migration_traz_os_sete_anexos_com_validacao_dispensada(): void
    {
        $linhas = DB::table('configuracoes_anexos')->orderBy('id')->get();

        $this->assertSame(AnexoCadastro::valores(), $linhas->pluck('campo')->all());
        $this->assertTrue($linhas->every(fn (object $linha): bool => ! $linha->validar_ia && $linha->instrucao_ia === null));
    }

    public function test_tela_mostra_os_anexos_na_ordem_da_etapa_anexos(): void
    {
        $this->entrarComoGestor();

        $resposta = $this->get(self::URL);

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/AnexosDoCadastro')
            ->has('anexos', 7)
            ->where('anexos', fn ($anexos): bool => $anexos->pluck('campo')->all() === AnexoCadastro::valores())
            ->where('anexos.0', [
                'campo' => 'foto_perfil',
                'rotulo' => 'Foto de perfil',
                'descricao' => AnexoCadastro::FotoPerfil->descricao(),
                'obrigatorio' => false,
                'validar_ia' => false,
                'instrucao_ia' => '',
            ])
            ->where('anexos.1.obrigatorio', true)
            ->where('anexos.2.obrigatorio', true)
            ->where('validacaoLigada', false));
    }

    public function test_tela_mostra_a_configuracao_salva_e_a_validacao_ligada(): void
    {
        ConfiguracaoIa::factory()->create();
        self::doCampo(AnexoCadastro::DocumentoRg)->update(['validar_ia' => true, 'instrucao_ia' => 'Confira se é o RG da pessoa do cadastro.']);
        $this->entrarComoGestor();

        $resposta = $this->get(self::URL);

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('anexos.2.campo', 'documento_rg')
            ->where('anexos.2.validar_ia', true)
            ->where('anexos.2.instrucao_ia', 'Confira se é o RG da pessoa do cadastro.')
            ->where('validacaoLigada', true));
    }

    public function test_salva_a_validacao_e_a_instrucao_de_cada_anexo(): void
    {
        $this->entrarComoGestor();

        $resposta = $this->put(self::URL, self::envio([
            'documento_rg' => ['validar_ia' => true, 'instrucao_ia' => 'Confira se é o RG da pessoa do cadastro.'],
            // Dispensado, o texto fica guardado para quando a validação voltar; em branco, vira null.
            'comprovante_residencia' => ['validar_ia' => false, 'instrucao_ia' => 'Confira o endereço.'],
            'documento_cpf' => ['validar_ia' => false, 'instrucao_ia' => '   '],
        ]));

        $resposta->assertSessionHasNoErrors()
            ->assertRedirect(self::URL)
            ->assertSessionHas('sucesso', 'Anexos do cadastro salvos.');
        $this->assertSame(7, ConfiguracaoAnexo::count());
        $rg = self::doCampo(AnexoCadastro::DocumentoRg);
        $this->assertTrue($rg->validar_ia);
        $this->assertSame('Confira se é o RG da pessoa do cadastro.', $rg->instrucao_ia);
        $comprovante = self::doCampo(AnexoCadastro::ComprovanteResidencia);
        $this->assertFalse($comprovante->validar_ia);
        $this->assertSame('Confira o endereço.', $comprovante->instrucao_ia);
        $this->assertNull(self::doCampo(AnexoCadastro::DocumentoCpf)->instrucao_ia);
    }

    public function test_validar_exige_o_que_conferir(): void
    {
        $this->entrarComoGestor();

        $resposta = $this->put(self::URL, self::envio(['documento_rg' => ['validar_ia' => true, 'instrucao_ia' => '']]));

        $resposta->assertSessionHasErrors([
            'anexos.documento_rg.instrucao_ia' => 'Descreva o que a IA deve conferir neste documento ou use Gerar com IA.',
        ]);
        $this->assertFalse(self::doCampo(AnexoCadastro::DocumentoRg)->validar_ia);
    }

    #[TestWith(['a', 2001, 'Use no máximo 2000 caracteres.'])]
    #[TestWith(['é', 1001, 'Texto longo demais: use no máximo 2000 caracteres sem acento (o limite conta acentos como mais de um).'])]
    public function test_instrucao_cabe_na_coluna_em_caracteres_e_em_bytes(string $letra, int $vezes, string $mensagem): void
    {
        $this->entrarComoGestor();

        $resposta = $this->put(self::URL, self::envio(['documento_rg' => ['validar_ia' => true, 'instrucao_ia' => str_repeat($letra, $vezes)]]));

        $resposta->assertSessionHasErrors(['anexos.documento_rg.instrucao_ia' => $mensagem]);
        $this->assertNull(self::doCampo(AnexoCadastro::DocumentoRg)->instrucao_ia);
    }

    /**
     * Cada envio liga o RG, que não pode ficar gravado com o resto recusado.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function enviosInvalidos(): array
    {
        $comRg = ['documento_rg' => ['validar_ia' => true, 'instrucao_ia' => 'Confira o RG.']];

        $semCrnm = self::envio($comRg);
        unset($semCrnm['anexos']['documento_crnm']);
        $foraDaLista = self::envio($comRg);
        $foraDaLista['anexos']['documento_cnh'] = ['validar_ia' => false, 'instrucao_ia' => null];
        $semEscolha = self::envio($comRg);
        unset($semEscolha['anexos']['foto_perfil']['validar_ia']);

        return [
            'sem os anexos' => [[], 'anexos', 'Este campo é obrigatório.'],
            'faltando um anexo' => [$semCrnm, 'anexos.documento_crnm', 'Este campo é obrigatório.'],
            'anexo fora da lista' => [$foraDaLista, 'anexos', 'Valor inválido.'],
            'sem a escolha da validação' => [$semEscolha, 'anexos.foto_perfil.validar_ia', 'Escolha se a IA valida este documento.'],
            'validação fora do sim ou não' => [self::envio([...$comRg, 'foto_perfil' => ['validar_ia' => 'talvez']]), 'anexos.foto_perfil.validar_ia', 'Valor inválido.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $envio
     */
    #[DataProvider('enviosInvalidos')]
    public function test_recusa_envio_incompleto_ou_com_valor_invalido(array $envio, string $campo, string $mensagem): void
    {
        $this->entrarComoGestor();

        $resposta = $this->put(self::URL, $envio);

        $resposta->assertSessionHasErrors([$campo => $mensagem]);
        $this->assertFalse(self::doCampo(AnexoCadastro::DocumentoRg)->validar_ia);
    }

    public function test_proponente_nao_acessa(): void
    {
        $this->actingAs(User::factory()->proponente()->create());

        $this->get(self::URL)->assertRedirect('/retaguarda/login');
        $this->put(self::URL, self::envio(['documento_rg' => ['validar_ia' => true, 'instrucao_ia' => 'Confira o RG.']]))->assertRedirect('/retaguarda/login');

        $this->assertFalse(self::doCampo(AnexoCadastro::DocumentoRg)->validar_ia);
    }

    public function test_visitante_vai_para_login(): void
    {
        $this->get(self::URL)->assertRedirect('/retaguarda/login');
    }

    public function test_menu_aponta_para_anexos_do_cadastro_depois_da_integracao_com_ia(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString(route('retaguarda.anexos-cadastro.edit', absolute: false), $menu);
        $this->assertStringContainsString("'Anexos do cadastro'", $menu);
        $this->assertGreaterThan(strpos($menu, "'Integração com IA'"), strpos($menu, "'Anexos do cadastro'"));
    }
}
