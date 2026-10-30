<?php

namespace Tests\Feature\Portal;

use App\Enums\NaturezaProponente;
use App\Enums\TipoPergunta;
use App\Http\Controllers\InscricaoController;
use App\Http\Controllers\PortalController;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalChamada;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\Inscricao;
use App\Models\InscricaoResposta;
use App\Models\User;
use App\Support\Inscricao\PassosDaInscricao;
use App\Support\Inscricao\QuestionarioDaInscricao;
use Database\Factories\EditalPerguntaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class QuestionarioDaInscricaoTest extends TestCase
{
    use RefreshDatabase;

    private const string CPF = '52998224725';

    private User $user;

    private Edital $edital;

    private EditalCategoria $teatro;

    private EditalCategoria $danca;

    private EditalQuestionarioSecao $proposta;

    private Inscricao $inscricao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));

        $this->user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $this->edital = Edital::factory()->create(['slug' => 'pnab', 'inscricoes_inicio' => '2026-11-01', 'inscricoes_fim' => '2026-11-15']);
        $chamada = EditalChamada::factory()->for($this->edital)->create(['inicio' => '2026-11-01', 'fim' => '2026-11-15', 'ordem' => 1]);
        $this->teatro = EditalCategoria::factory()->for($this->edital)->create(['nome' => 'Teatro', 'ordem' => 1, 'edital_chamada_id' => $chamada->id]);
        $this->danca = EditalCategoria::factory()->for($this->edital)->create(['nome' => 'Dança', 'ordem' => 2, 'edital_chamada_id' => $chamada->id]);
        $this->proposta = $this->secao('Proposta', 1);
        $this->inscricao = Inscricao::factory()->for($this->edital)->for($this->user)->create([
            'edital_chamada_id' => $chamada->id,
            'documento_cadastro' => self::CPF,
            'natureza' => NaturezaProponente::PessoaFisica,
            'edital_categoria_id' => $this->teatro->id,
        ]);
    }

    public function test_vale_so_o_que_o_vinculo_inclui_para_a_categoria(): void
    {
        $equipe = $this->secao('Equipe', 2);
        $daEquipe = $this->pergunta([], $equipe);
        $todas = $this->pergunta(['ordem' => 2]);
        $doTeatro = $this->pergunta(['ordem' => 1], categorias: [$this->teatro]);
        $this->pergunta(['ordem' => 3], categorias: [$this->danca]);
        $this->pergunta(['ordem' => 5], fabrica: EditalPergunta::factory()->semVinculo());

        $ids = $this->questionario()->perguntasQueValem()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $this->assertSame([(int) $doTeatro->id, (int) $todas->id, (int) $daEquipe->id], $ids);
    }

    public function test_condicao_mostra_so_com_a_resposta_escolhida_e_em_cadeia(): void
    {
        $origem = $this->pergunta(['ordem' => 1], fabrica: EditalPergunta::factory()->simNao());
        $dependente = $this->pergunta(['ordem' => 2], fabrica: EditalPergunta::factory()->simNao()->condicionadaA($origem, ['sim']));
        $daDependente = $this->pergunta(['ordem' => 3], fabrica: EditalPergunta::factory()->condicionadaA($dependente, ['sim']));
        $questionario = $this->questionario();
        $visiveis = fn (array $respostas): array => $questionario->visiveis($respostas)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $this->assertSame([(int) $origem->id], $visiveis([]));
        $this->assertSame([(int) $origem->id, (int) $dependente->id], $visiveis([$origem->id => $this->opcoes(['sim'])]));
        $this->assertSame(
            [(int) $origem->id, (int) $dependente->id, (int) $daDependente->id],
            $visiveis([$origem->id => $this->opcoes(['sim']), $dependente->id => $this->opcoes(['sim'])]),
        );
        $this->assertSame([(int) $origem->id], $visiveis([$origem->id => $this->opcoes(['nao']), $dependente->id => $this->opcoes(['sim'])]));
    }

    public function test_consultas_nao_crescem_com_o_numero_de_perguntas(): void
    {
        $equipe = $this->secao('Equipe', 2);
        $origem = $this->pergunta(['ordem' => 0], fabrica: EditalPergunta::factory()->simNao());
        foreach (range(1, 49) as $ordem) {
            $categorias = match ($ordem % 3) {
                0 => [],
                1 => [$this->teatro],
                default => [$this->danca],
            };
            $fabrica = $ordem % 5 === 0 ? EditalPergunta::factory()->condicionadaA($origem, ['sim']) : null;
            $this->pergunta(['ordem' => $ordem], $ordem % 2 === 0 ? $equipe : null, $fabrica, $categorias);
        }
        $questionario = $this->questionario();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $valem = $questionario->perguntasQueValem();
        $visiveis = $questionario->visiveis([$origem->id => $this->opcoes(['sim'])]);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(34, $valem);
        $this->assertCount(34, $visiveis);
        $this->assertLessThan(20, $consultas);
    }

    public function test_salvar_a_secao_grava_e_avanca_para_o_passo_seguinte(): void
    {
        $pergunta = $this->pergunta();
        $equipe = $this->secao('Equipe', 2);
        $this->pergunta([], $equipe);

        $this->salvar([$pergunta->id => ['texto' => '  Minha proposta  ']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('portal.inscricoes.show', [$this->inscricao, 'passo' => 'secao-'.$equipe->id]));

        $this->assertSame('Minha proposta', InscricaoResposta::sole()->texto);
    }

    public function test_secao_incompleta_salva_o_que_veio_e_volta_com_os_erros(): void
    {
        $respondida = $this->pergunta(['ordem' => 1]);
        $faltando = $this->pergunta(['ordem' => 2]);
        $this->pergunta(['ordem' => 3, 'obrigatoria' => false]);

        $this->salvar([$respondida->id => ['texto' => 'Resposta']])
            ->assertRedirect(route('portal.inscricoes.show', $this->inscricao))
            ->assertSessionHasErrors(["respostas.{$faltando->id}" => 'Responda esta pergunta.']);

        $resposta = InscricaoResposta::sole();
        $this->assertSame((int) $respondida->id, (int) $resposta->edital_pergunta_id);
        $this->assertSame('Resposta', $resposta->texto);
    }

    public function test_limite_conta_caracteres_com_acento_emoji_e_quebra_de_linha(): void
    {
        $pergunta = $this->pergunta(['limite_caracteres' => 5]);

        $this->salvar([$pergunta->id => ['texto' => 'ação😀!']])
            ->assertSessionHasErrors(["respostas.{$pergunta->id}.texto" => 'Use no máximo 5 caracteres.']);
        $this->assertSame(0, InscricaoResposta::count());

        $this->salvar([$pergunta->id => ['texto' => "ção\r\n😀"]])->assertSessionHasNoErrors();
        $this->assertSame("ção\n😀", InscricaoResposta::sole()->texto);
    }

    public function test_outro_escolhido_em_obrigatoria_exige_qual(): void
    {
        $pergunta = $this->pergunta(fabrica: EditalPergunta::factory()->escolha(TipoPergunta::EscolhaUnica, ['Rua', 'Palco'], comOutro: true));

        $this->salvar([$pergunta->id => ['opcoes' => ['o3']]])
            ->assertSessionHasErrors(["respostas.{$pergunta->id}.outro" => 'Diga qual.']);
        $this->assertSame(['o3'], InscricaoResposta::sole()->opcoes);

        $this->salvar([$pergunta->id => ['opcoes' => ['o3'], 'outro' => 'Circo']])->assertSessionHasNoErrors();
        $this->assertSame('Circo', InscricaoResposta::sole()->outro);

        // Sem a opção "Outro" o "Qual?" não fica.
        $this->salvar([$pergunta->id => ['opcoes' => ['o1'], 'outro' => 'Circo']])->assertSessionHasNoErrors();
        $this->assertNull(InscricaoResposta::sole()->outro);
    }

    public function test_qual_com_acentos_acima_do_limite_em_bytes_e_recusado(): void
    {
        $pergunta = $this->pergunta(fabrica: EditalPergunta::factory()->escolha(TipoPergunta::EscolhaUnica, ['Rua'], comOutro: true));

        $this->salvar([$pergunta->id => ['opcoes' => ['o2'], 'outro' => str_repeat('ç', 1000)]])
            ->assertSessionHasErrors(["respostas.{$pergunta->id}.outro"]);

        $this->assertSame(0, InscricaoResposta::count());
    }

    public function test_declaracao_precisa_ser_marcada(): void
    {
        $pergunta = $this->pergunta(fabrica: EditalPergunta::factory()->declaracao());

        $this->salvar([])->assertSessionHasErrors(["respostas.{$pergunta->id}" => 'Marque a declaração para continuar.']);

        $this->salvar([$pergunta->id => ['opcoes' => ['declaro']]])->assertSessionHasNoErrors();
        $this->assertSame(['declaro'], InscricaoResposta::sole()->opcoes);
    }

    public function test_opcao_fora_da_lista_data_invalida_e_numero_invalido_sao_recusados(): void
    {
        $escolha = $this->pergunta(['ordem' => 1], fabrica: EditalPergunta::factory()->escolha(TipoPergunta::EscolhaUnica, ['Rua', 'Palco']));
        $duas = $this->pergunta(['ordem' => 2], fabrica: EditalPergunta::factory()->escolha(TipoPergunta::EscolhaUnica, ['Rua', 'Palco']));
        $data = $this->pergunta(['ordem' => 3, 'tipo' => TipoPergunta::Data]);
        $numero = $this->pergunta(['ordem' => 4, 'tipo' => TipoPergunta::Numero]);

        $this->salvar([
            $escolha->id => ['opcoes' => ['o9']],
            $duas->id => ['opcoes' => ['o1', 'o2']],
            $data->id => ['texto' => '31/12/2026'],
            $numero->id => ['texto' => 'doze'],
        ])->assertSessionHasErrors([
            "respostas.{$escolha->id}.opcoes.0" => 'Escolha uma opção da lista.',
            "respostas.{$duas->id}.opcoes" => 'Escolha uma opção da lista.',
            "respostas.{$data->id}.texto" => 'Digite uma data válida.',
            "respostas.{$numero->id}.texto" => 'Informe um número.',
        ]);

        $this->assertSame(0, InscricaoResposta::count());
    }

    public function test_trocar_a_resposta_da_origem_apaga_a_resposta_que_ficou_oculta(): void
    {
        $origem = $this->pergunta(fabrica: EditalPergunta::factory()->simNao());
        $detalhes = $this->secao('Detalhes', 2);
        $dependente = $this->pergunta([], $detalhes, EditalPergunta::factory()->condicionadaA($origem, ['sim']));

        $this->salvar([$origem->id => ['opcoes' => ['sim']]])
            ->assertRedirect(route('portal.inscricoes.show', [$this->inscricao, 'passo' => 'secao-'.$detalhes->id]));
        $this->salvar([$dependente->id => ['texto' => 'Detalhe']], $detalhes)->assertSessionHasNoErrors();
        $this->assertSame(2, InscricaoResposta::count());

        $this->salvar([$origem->id => ['opcoes' => ['nao']]])
            ->assertRedirect(route('portal.inscricoes.show', [$this->inscricao, 'passo' => PassosDaInscricao::DOCUMENTOS]));

        $this->assertSame((int) $origem->id, (int) InscricaoResposta::sole()->edital_pergunta_id);
    }

    public function test_trocar_a_categoria_apaga_respostas_que_deixaram_de_valer(): void
    {
        $doTeatro = $this->pergunta(['ordem' => 1], categorias: [$this->teatro]);
        $todas = $this->pergunta(['ordem' => 2]);
        $this->resposta($doTeatro);
        $this->resposta($todas);

        $this->actingAs($this->user)
            ->put(route('portal.inscricoes.update', $this->inscricao), ['cadastro' => 'pessoa_fisica', 'categoria' => $this->danca->id])
            ->assertSessionHasNoErrors();

        $this->assertSame((int) $todas->id, (int) InscricaoResposta::sole()->edital_pergunta_id);
    }

    public function test_secao_de_outro_edital_404_outro_usuario_404_finalizada_e_fora_do_periodo_recusam(): void
    {
        $pergunta = $this->pergunta();
        $envio = [$pergunta->id => ['texto' => 'Resposta']];
        $deOutroEdital = EditalQuestionarioSecao::factory()->create();

        $this->salvar($envio, $deOutroEdital)->assertNotFound();

        $this->actingAs(User::factory()->proponente()->comPerfil()->create())
            ->put(route('portal.inscricoes.secoes.update', [$this->inscricao, $this->proposta]), ['respostas' => $envio])
            ->assertNotFound();

        $this->inscricao->update(['situacao' => 'finalizada', 'numero' => '2026000001', 'finalizada_em' => now()]);
        $this->salvar($envio)
            ->assertRedirect(route('portal.inscricoes.show', $this->inscricao))
            ->assertSessionHas('erro', InscricaoController::ERRO_REABRIR);

        $this->inscricao->update(['situacao' => 'rascunho', 'finalizada_em' => null]);
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'America/Bahia'));
        $this->salvar($envio)
            ->assertRedirect(route('portal.editais'))
            ->assertSessionHas('aviso', PortalController::AVISO_INSCRICOES_FECHADAS);

        $this->assertSame(0, InscricaoResposta::count());
    }

    public function test_passos_pulam_secao_sem_pergunta_visivel_e_param_no_primeiro_incompleto(): void
    {
        $origem = $this->pergunta(fabrica: EditalPergunta::factory()->simNao());
        $vazia = $this->secao('Só com condição', 2);
        $this->pergunta([], $vazia, EditalPergunta::factory()->condicionadaA($origem, ['sim']));
        $questionario = $this->questionario();

        $passos = PassosDaInscricao::para($this->inscricao, $questionario);

        $this->assertSame([
            ['id' => 'escolhas', 'rotulo' => 'Escolhas', 'completo' => true],
            ['id' => 'secao-'.$this->proposta->id, 'rotulo' => 'Proposta', 'completo' => false],
            ['id' => 'documentos', 'rotulo' => 'Documentos', 'completo' => true],
            ['id' => 'revisao', 'rotulo' => 'Revisão e envio', 'completo' => false],
        ], $passos);
        $this->assertSame('secao-'.$this->proposta->id, PassosDaInscricao::atual($passos, 'revisao'));
        $this->assertSame('secao-'.$this->proposta->id, PassosDaInscricao::atual($passos, null));
        $this->assertSame('escolhas', PassosDaInscricao::atual($passos, 'escolhas'));

        // As respostas são lidas uma vez por instância: depois de gravar, uma nova as relê.
        $this->resposta($origem, null, ['nao']);
        $passos = PassosDaInscricao::para($this->inscricao, $this->questionario());
        $this->assertSame(['escolhas', 'secao-'.$this->proposta->id, 'documentos', 'revisao'], array_column($passos, 'id'));
        $this->assertSame('revisao', PassosDaInscricao::atual($passos, 'revisao'));
    }

    public function test_escolhas_ficam_completas_sem_categoria_quando_nenhuma_aceita_a_natureza(): void
    {
        // Como as escolhas aceitam: sem categoria para Pessoa Física, a inscrição segue sem categoria.
        $this->teatro->update(['naturezas' => [NaturezaProponente::SemFins]]);
        $this->danca->update(['naturezas' => [NaturezaProponente::SemFins]]);
        $this->inscricao->update(['edital_categoria_id' => null]);

        $passos = PassosDaInscricao::para($this->inscricao->fresh(), $this->questionario());

        $this->assertTrue($passos[0]['completo']);
        $this->assertNotSame('escolhas', PassosDaInscricao::atual($passos, null));
    }

    public function test_escolhas_ficam_incompletas_sem_categoria_quando_alguma_aceita_a_natureza(): void
    {
        $this->danca->update(['naturezas' => [NaturezaProponente::SemFins]]);
        $this->inscricao->update(['edital_categoria_id' => null]);

        $passos = PassosDaInscricao::para($this->inscricao->fresh(), $this->questionario());

        $this->assertFalse($passos[0]['completo']);
        $this->assertSame('escolhas', PassosDaInscricao::atual($passos, null));
    }

    private function secao(string $titulo, int $ordem): EditalQuestionarioSecao
    {
        return EditalQuestionarioSecao::factory()->for($this->edital)->create(['titulo' => $titulo, 'ordem' => $ordem]);
    }

    /**
     * Pergunta obrigatória de texto curto na seção (Proposta, se não dada), para todas as categorias se nenhuma é dada.
     *
     * @param  array<string, mixed>  $atributos
     * @param  list<EditalCategoria>  $categorias
     */
    private function pergunta(array $atributos = [], ?EditalQuestionarioSecao $secao = null, ?EditalPerguntaFactory $fabrica = null, array $categorias = []): EditalPergunta
    {
        $pergunta = ($fabrica ?? EditalPergunta::factory())->for($this->edital)->create([
            'edital_questionario_secao_id' => ($secao ?? $this->proposta)->id,
            ...($categorias === [] ? [] : ['todas_categorias' => false]),
            ...$atributos,
        ]);
        $pergunta->categorias()->attach(array_map(fn (EditalCategoria $categoria): int => (int) $categoria->id, $categorias));

        return $pergunta;
    }

    /**
     * @param  list<string>  $opcoes
     */
    private function resposta(EditalPergunta $pergunta, ?string $texto = 'Resposta', array $opcoes = []): InscricaoResposta
    {
        return InscricaoResposta::factory()->create([
            'inscricao_id' => $this->inscricao->id,
            'edital_pergunta_id' => $pergunta->id,
            'texto' => $texto,
            'opcoes' => $opcoes === [] ? null : $opcoes,
        ]);
    }

    /**
     * @param  list<string>  $opcoes
     * @return array{texto: ?string, opcoes: list<string>, outro: ?string}
     */
    private function opcoes(array $opcoes): array
    {
        return ['texto' => null, 'opcoes' => $opcoes, 'outro' => null];
    }

    private function questionario(): QuestionarioDaInscricao
    {
        return new QuestionarioDaInscricao($this->inscricao->fresh());
    }

    /**
     * @param  array<int|string, mixed>  $respostas
     */
    private function salvar(array $respostas, ?EditalQuestionarioSecao $secao = null): TestResponse
    {
        return $this->actingAs($this->user)
            ->from(route('portal.inscricoes.show', $this->inscricao))
            ->put(route('portal.inscricoes.secoes.update', [$this->inscricao, $secao ?? $this->proposta]), ['respostas' => $respostas]);
    }
}
