<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\TipoPergunta;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\ImportacaoQuestionario;
use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuestionarioDoEditalTest extends TestCase
{
    use RefreshDatabase;

    private Edital $edital;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs(User::factory()->gestor()->create());

        $this->edital = Edital::factory()->create(['publicado' => false]);
        EditalCategoria::factory()->create(['edital_id' => $this->edital->id, 'nome' => 'Teatro', 'ordem' => 1]);
        EditalCategoria::factory()->create(['edital_id' => $this->edital->id, 'nome' => 'Dança', 'ordem' => 2]);
    }

    /**
     * Envio do formulário completo: as categorias atuais e o questionário como texto JSON num campo só.
     *
     * @param  array<string, mixed>  $questionario
     * @param  array<string, mixed>  $extras
     */
    private function enviar(Edital $edital, array $questionario, array $extras = []): TestResponse
    {
        $categorias = $edital->categorias()->get()->map(fn (EditalCategoria $categoria): array => [
            'id' => $categoria->id,
            'nome' => $categoria->nome,
        ])->all();

        return $this->put("/retaguarda/editais/{$edital->slug}", [
            'com_listas' => '1',
            'com_questionario' => '1',
            'titulo' => $edital->titulo,
            'categorias' => $categorias,
            'questionario' => json_encode($questionario),
            ...$extras,
        ]);
    }

    /**
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    private function pergunta(array $alteracoes = []): array
    {
        return [
            'enunciado' => 'Qual é a sua pergunta?',
            'tipo' => 'texto_curto',
            'obrigatoria' => true,
            'todas_categorias' => true,
            ...$alteracoes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $perguntas
     * @return array{titulo: string, perguntas: list<array<string, mixed>>}
     */
    private function secao(array $perguntas, string $titulo = 'Dados do projeto'): array
    {
        return ['titulo' => $titulo, 'perguntas' => $perguntas];
    }

    /** @return list<array{chave: string, texto: string, outro: bool}> */
    private function opcoes(): array
    {
        return [
            ['chave' => 'o1', 'texto' => 'Primeira', 'outro' => false],
            ['chave' => 'o2', 'texto' => 'Segunda', 'outro' => false],
        ];
    }

    private function arquivoPdf(): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($caminho, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($caminho, 'edital.pdf', null, null, true);
    }

    /** @return list<EditalPergunta> */
    private function perguntasGravadas(): array
    {
        return EditalPergunta::query()
            ->where('edital_perguntas.edital_id', $this->edital->id)
            ->join('edital_questionario_secoes as s', 's.id', '=', 'edital_perguntas.edital_questionario_secao_id')
            ->orderBy('s.ordem')
            ->orderBy('edital_perguntas.ordem')
            ->select('edital_perguntas.*')
            ->get()
            ->all();
    }

    public function test_questionario_de_pagina_antiga_nao_sobrescreve_o_importado(): void
    {
        $secao = fn (string $titulo): array => ['secoes' => [$this->secao([$this->pergunta()], $titulo)]];

        $this->enviar($this->edital, $secao('Antes'))->assertSessionHasNoErrors();

        $importacao = ImportacaoQuestionario::factory()->concluida()->create(['edital_id' => $this->edital->id]);
        $this->edital->secoesDoQuestionario()->update(['titulo' => 'Importada']);

        $this->enviar($this->edital, $secao('Velha'))
            ->assertSessionHasErrors(['questionario' => ImportacaoQuestionario::ERRO_VERSAO]);
        $this->enviar($this->edital, $secao('Velha'), ['questionario_versao' => (string) ($importacao->id - 1)])
            ->assertSessionHasErrors(['questionario' => ImportacaoQuestionario::ERRO_VERSAO]);
        $this->assertSame(['Importada'], $this->edital->secoesDoQuestionario()->pluck('titulo')->all());

        $this->enviar($this->edital, $secao('Nova'), ['questionario_versao' => (string) $importacao->id])->assertSessionHasNoErrors();
        $this->assertSame(['Nova'], $this->edital->secoesDoQuestionario()->pluck('titulo')->all());
    }

    public function test_salva_secoes_e_perguntas_de_todos_os_tipos_na_ordem(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([
                $this->pergunta(['enunciado' => 'Resumo?', 'tipo' => 'texto_curto', 'limite_caracteres' => 100, 'ajuda' => 'Seja breve.']),
                $this->pergunta(['enunciado' => 'Conte tudo', 'tipo' => 'texto_longo', 'obrigatoria' => false]),
                $this->pergunta(['enunciado' => 'Qual linguagem?', 'tipo' => 'escolha_unica', 'opcoes' => $this->opcoes()]),
                $this->pergunta(['enunciado' => 'Quais linguagens?', 'tipo' => 'multipla_escolha', 'opcoes' => [
                    ...$this->opcoes(),
                    ['chave' => 'o3', 'texto' => 'Outra', 'outro' => true],
                ]]),
            ], 'Dados do projeto'),
            [
                'titulo' => 'Compromissos',
                'descricao' => 'Leia com atenção.',
                'perguntas' => [
                    $this->pergunta(['enunciado' => 'Já recebeu fomento?', 'tipo' => 'sim_nao']),
                    $this->pergunta(['enunciado' => 'Início previsto', 'tipo' => 'data', 'limite_caracteres' => 50, 'opcoes' => $this->opcoes()]),
                    $this->pergunta(['enunciado' => 'Pessoas na equipe', 'tipo' => 'numero']),
                    $this->pergunta(['enunciado' => 'Declaro que li o edital', 'tipo' => 'declaracao']),
                ],
            ],
        ]])->assertSessionHasNoErrors();

        $secoes = EditalQuestionarioSecao::query()->where('edital_id', $this->edital->id)->orderBy('ordem')->get();
        $this->assertSame(['Dados do projeto', 'Compromissos'], $secoes->pluck('titulo')->all());
        $this->assertSame([1, 2], $secoes->pluck('ordem')->all());
        $this->assertNull($secoes[0]->descricao);
        $this->assertSame('Leia com atenção.', $secoes[1]->descricao);

        $perguntas = $this->perguntasGravadas();
        $this->assertSame(
            ['texto_curto', 'texto_longo', 'escolha_unica', 'multipla_escolha', 'sim_nao', 'data', 'numero', 'declaracao'],
            array_map(fn (EditalPergunta $p): string => $p->tipo->value, $perguntas),
        );
        $this->assertSame([1, 2, 3, 4, 1, 2, 3, 4], array_map(fn (EditalPergunta $p): int => $p->ordem, $perguntas));
        $this->assertSame(100, $perguntas[0]->limite_caracteres);
        $this->assertSame('Seja breve.', $perguntas[0]->ajuda);
        $this->assertNull($perguntas[1]->limite_caracteres);
        $this->assertFalse($perguntas[1]->obrigatoria);
        $this->assertNull($perguntas[0]->opcoes);
        $this->assertSame($this->opcoes(), $perguntas[2]->opcoes);
        $this->assertSame('o3', $perguntas[3]->opcoes[2]['chave']);
        $this->assertTrue($perguntas[3]->opcoes[2]['outro']);
        $this->assertNull($perguntas[5]->limite_caracteres);
        $this->assertNull($perguntas[5]->opcoes);
        $this->assertSame($this->edital->id, (int) $perguntas[7]->edital_id);
    }

    public function test_vinculo_todas_algumas_e_sem_vinculo(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([
                $this->pergunta(['enunciado' => 'Todas', 'todas_categorias' => true, 'categorias' => [0]]),
                $this->pergunta(['enunciado' => 'Só dança', 'todas_categorias' => false, 'categorias' => [1]]),
                $this->pergunta(['enunciado' => 'Sem vínculo', 'todas_categorias' => false, 'categorias' => []]),
            ]),
        ]])->assertSessionHasNoErrors();

        [$todas, $algumas, $semVinculo] = $this->perguntasGravadas();
        $danca = EditalCategoria::query()->where('nome', 'Dança')->sole();

        $this->assertTrue($todas->todas_categorias);
        $this->assertSame(0, $todas->categorias()->count());

        $this->assertFalse($algumas->todas_categorias);
        $this->assertSame([$danca->id], $algumas->categorias()->pluck('edital_categorias.id')->map(fn ($id): int => (int) $id)->all());

        $this->assertFalse($semVinculo->todas_categorias);
        $this->assertSame(0, $semVinculo->categorias()->count());
        $this->assertTrue($semVinculo->semVinculo());
    }

    public function test_perguntas_diferentes_podem_ter_a_mesma_categoria(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([
                $this->pergunta(['enunciado' => 'Primeira', 'todas_categorias' => false, 'categorias' => [1]]),
                $this->pergunta(['enunciado' => 'Segunda', 'todas_categorias' => false, 'categorias' => [1]]),
            ]),
            $this->secao([
                $this->pergunta(['enunciado' => 'Terceira', 'todas_categorias' => false, 'categorias' => [0, 1]]),
            ], 'Outra seção'),
        ]])->assertSessionHasNoErrors();

        $this->assertCount(3, $this->perguntasGravadas());
    }

    public function test_categoria_repetida_na_mesma_pergunta_e_gravada_uma_vez(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([$this->pergunta(['todas_categorias' => false, 'categorias' => [1, 1]])]),
        ]])->assertSessionHasNoErrors();

        [$pergunta] = $this->perguntasGravadas();
        $this->assertSame(1, $pergunta->categorias()->count());
    }

    public function test_natureza_enviada_na_pergunta_e_ignorada(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([$this->pergunta(['naturezas' => ['mei', 'sociedade']])]),
        ]])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('naturezas', $this->perguntasGravadas()[0]->getAttributes());
    }

    public function test_condicao_guarda_a_origem_e_as_chaves(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([
                $this->pergunta(['enunciado' => 'Qual linguagem?', 'tipo' => 'escolha_unica', 'opcoes' => $this->opcoes()]),
                $this->pergunta(['enunciado' => 'Se a segunda', 'condicao_pergunta' => 0, 'condicao_opcoes' => ['o2']]),
            ], 'Primeira'),
            $this->secao([
                $this->pergunta(['enunciado' => 'Tem apoio?', 'tipo' => 'sim_nao']),
                $this->pergunta(['enunciado' => 'Se tem apoio', 'condicao_pergunta' => 2, 'condicao_opcoes' => ['sim']]),
            ], 'Segunda'),
        ]])->assertSessionHasNoErrors();

        [$escolha, $dependente, $simNao, $dependenteDoSimNao] = $this->perguntasGravadas();

        $this->assertSame($escolha->id, (int) $dependente->condicao_pergunta_id);
        $this->assertSame(['o2'], $dependente->condicao_opcoes);
        $this->assertSame($simNao->id, (int) $dependenteDoSimNao->condicao_pergunta_id);
        $this->assertSame(['sim'], $dependenteDoSimNao->condicao_opcoes);
        $this->assertNull($escolha->condicao_pergunta_id);
        $this->assertNull($escolha->condicao_opcoes);
    }

    public function test_origem_depois_da_dependente_e_recusada(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([
                $this->pergunta(['condicao_pergunta' => 1, 'condicao_opcoes' => ['sim']]),
                $this->pergunta(['tipo' => 'sim_nao']),
                $this->pergunta(['condicao_pergunta' => 2, 'condicao_opcoes' => ['sim']]),
            ]),
        ]])->assertSessionHasErrors([
            'questionario.secoes.0.perguntas.0.condicao_pergunta' => 'A pergunta da condição precisa vir antes desta.',
            'questionario.secoes.0.perguntas.2.condicao_pergunta' => 'A pergunta da condição precisa vir antes desta.',
        ]);

        $this->assertSame(0, EditalPergunta::query()->count());
    }

    public function test_origem_de_texto_e_recusada(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([
                $this->pergunta(['tipo' => 'texto_curto']),
                $this->pergunta(['condicao_pergunta' => 0, 'condicao_opcoes' => ['sim']]),
            ]),
        ]])->assertSessionHasErrors([
            'questionario.secoes.0.perguntas.1.condicao_pergunta' => 'A condição só pode depender de pergunta de escolha ou de sim/não.',
        ]);
    }

    public function test_resposta_da_condicao_que_nao_e_da_origem_e_recusada(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([
                $this->pergunta(['tipo' => 'escolha_unica', 'opcoes' => $this->opcoes()]),
                $this->pergunta(['condicao_pergunta' => 0, 'condicao_opcoes' => ['o9']]),
                $this->pergunta(['condicao_pergunta' => 0, 'condicao_opcoes' => []]),
            ]),
        ]])->assertSessionHasErrors([
            'questionario.secoes.0.perguntas.1.condicao_opcoes' => 'Escolha respostas da pergunta da condição.',
            'questionario.secoes.0.perguntas.2.condicao_opcoes' => 'Escolha ao menos uma resposta da condição.',
        ]);
    }

    public function test_escolha_com_uma_opcao_ou_dois_outros_e_recusada(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([
                $this->pergunta(['tipo' => 'escolha_unica', 'opcoes' => [['chave' => 'o1', 'texto' => 'Única']]]),
                $this->pergunta(['tipo' => 'multipla_escolha', 'opcoes' => [
                    ['chave' => 'o1', 'texto' => 'Outro A', 'outro' => true],
                    ['chave' => 'o2', 'texto' => 'Outro B', 'outro' => true],
                ]]),
                $this->pergunta(['tipo' => 'escolha_unica', 'opcoes' => [
                    ['chave' => 'o1', 'texto' => 'A'],
                    ['chave' => 'o1', 'texto' => 'B'],
                ]]),
                $this->pergunta(['tipo' => 'escolha_unica']),
            ]),
        ]])->assertSessionHasErrors([
            'questionario.secoes.0.perguntas.0.opcoes' => 'Cadastre ao menos duas opções.',
            'questionario.secoes.0.perguntas.1.opcoes' => 'Só uma opção pode ser "Outro".',
            'questionario.secoes.0.perguntas.2.opcoes.1.chave' => 'Opção repetida.',
            'questionario.secoes.0.perguntas.3.opcoes' => 'Cadastre ao menos duas opções.',
        ]);
    }

    public function test_declaracao_fica_sempre_obrigatoria(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([$this->pergunta(['tipo' => 'declaracao', 'obrigatoria' => false])]),
        ]])->assertSessionHasNoErrors();

        $this->assertTrue($this->perguntasGravadas()[0]->obrigatoria);
    }

    public function test_ids_existentes_sao_mantidos_e_os_ausentes_apagados(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([
                $this->pergunta(['enunciado' => 'A']),
                $this->pergunta(['enunciado' => 'B']),
                $this->pergunta(['enunciado' => 'C']),
            ], 'Primeira'),
            $this->secao([$this->pergunta(['enunciado' => 'D'])], 'Segunda'),
        ]])->assertSessionHasNoErrors();

        [$a, $b, $c, $d] = $this->perguntasGravadas();
        $primeira = EditalQuestionarioSecao::query()->where('titulo', 'Primeira')->sole();
        $segunda = EditalQuestionarioSecao::query()->where('titulo', 'Segunda')->sole();

        $this->enviar($this->edital, ['secoes' => [
            ['id' => $segunda->id, 'titulo' => 'Segunda renomeada', 'perguntas' => [
                $this->pergunta(['id' => $d->id, 'enunciado' => 'D editada']),
                $this->pergunta(['id' => $a->id, 'enunciado' => 'A movida']),
            ]],
            ['id' => $primeira->id, 'titulo' => 'Primeira', 'perguntas' => [
                $this->pergunta(['id' => $c->id, 'enunciado' => 'C']),
                $this->pergunta(['enunciado' => 'Nova']),
            ]],
        ]])->assertSessionHasNoErrors();

        $perguntas = $this->perguntasGravadas();
        $this->assertSame(['D editada', 'A movida', 'C', 'Nova'], array_map(fn (EditalPergunta $p): string => $p->enunciado, $perguntas));
        $this->assertSame([$d->id, $a->id, $c->id], array_map(fn (EditalPergunta $p): int => (int) $p->id, array_slice($perguntas, 0, 3)));
        $this->assertNotContains($b->id, array_map(fn (EditalPergunta $p): int => (int) $p->id, $perguntas));
        $this->assertNull(EditalPergunta::query()->find($b->id));
        $this->assertSame($segunda->id, (int) $perguntas[1]->edital_questionario_secao_id);
        $this->assertSame(['Segunda renomeada', 'Primeira'], EditalQuestionarioSecao::query()->orderBy('ordem')->pluck('titulo')->all());
        $this->assertSame(2, EditalQuestionarioSecao::query()->count());
    }

    public function test_sem_o_marcador_o_questionario_nao_muda(): void
    {
        $secao = EditalQuestionarioSecao::factory()->create(['edital_id' => $this->edital->id]);
        EditalPergunta::factory()->create(['edital_id' => $this->edital->id, 'edital_questionario_secao_id' => $secao->id, 'enunciado' => 'Fica']);

        $this->put("/retaguarda/editais/{$this->edital->slug}", [
            'com_listas' => '1',
            'titulo' => 'Outro título',
            'questionario' => json_encode(['secoes' => []]),
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Fica'], EditalPergunta::query()->pluck('enunciado')->all());
        $this->assertSame(1, EditalQuestionarioSecao::query()->count());
    }

    public function test_questionario_travado_recusa_o_envio(): void
    {
        $secao = EditalQuestionarioSecao::factory()->create(['edital_id' => $this->edital->id]);
        EditalPergunta::factory()->create(['edital_id' => $this->edital->id, 'edital_questionario_secao_id' => $secao->id, 'enunciado' => 'Antes']);
        Inscricao::factory()->finalizada()->create(['edital_id' => $this->edital->id]);

        $this->enviar($this->edital, ['secoes' => [
            $this->secao([$this->pergunta(['enunciado' => 'Depois'])]),
        ]])->assertSessionHasErrors(['questionario' => Edital::AVISO_QUESTIONARIO_TRAVADO]);

        $this->assertSame(['Antes'], EditalPergunta::query()->pluck('enunciado')->all());
    }

    public function test_inscricao_finalizada_durante_o_salvamento_trava_e_nada_muda(): void
    {
        $secao = EditalQuestionarioSecao::factory()->create(['edital_id' => $this->edital->id]);
        EditalPergunta::factory()->create(['edital_id' => $this->edital->id, 'edital_questionario_secao_id' => $secao->id, 'enunciado' => 'Antes']);

        $ativo = true;
        Edital::saving(function (Edital $edital) use (&$ativo): void {
            if ($ativo && $edital->exists) {
                $ativo = false;
                Inscricao::factory()->finalizada()->create(['edital_id' => $edital->id]);
            }
        });

        $this->enviar($this->edital, ['secoes' => [
            $this->secao([$this->pergunta(['enunciado' => 'Depois'])]),
        ]])->assertSessionHasErrors(['questionario' => Edital::AVISO_QUESTIONARIO_TRAVADO]);

        $this->assertFalse($ativo);
        $this->assertSame(['Antes'], EditalPergunta::query()->pluck('enunciado')->all());
        $this->assertSame(1, EditalQuestionarioSecao::query()->count());
    }

    public function test_id_de_outro_edital_e_ignorado(): void
    {
        $outro = Edital::factory()->create(['publicado' => false]);
        $secaoDoOutro = EditalQuestionarioSecao::factory()->create(['edital_id' => $outro->id, 'titulo' => 'Do outro']);
        $perguntaDoOutro = EditalPergunta::factory()->create([
            'edital_id' => $outro->id, 'edital_questionario_secao_id' => $secaoDoOutro->id, 'enunciado' => 'Do outro',
        ]);

        $this->enviar($this->edital, ['secoes' => [
            ['id' => $secaoDoOutro->id, 'titulo' => 'Minha', 'perguntas' => [
                $this->pergunta(['id' => $perguntaDoOutro->id, 'enunciado' => 'Minha']),
            ]],
        ]])->assertSessionHasNoErrors();

        $minha = $this->perguntasGravadas();
        $this->assertCount(1, $minha);
        $this->assertNotSame($perguntaDoOutro->id, (int) $minha[0]->id);
        $this->assertSame('Minha', $minha[0]->enunciado);
        $this->assertSame('Do outro', $perguntaDoOutro->fresh()->enunciado);
        $this->assertSame($secaoDoOutro->id, (int) $perguntaDoOutro->fresh()->edital_questionario_secao_id);
        $this->assertSame('Do outro', $secaoDoOutro->fresh()->titulo);
        $this->assertSame(1, EditalQuestionarioSecao::query()->where('edital_id', $this->edital->id)->count());
    }

    public function test_edital_publicado_nao_salva_com_pergunta_sem_vinculo(): void
    {
        $publicado = Edital::factory()->create(['publicado' => true]);
        EditalCategoria::factory()->create(['edital_id' => $publicado->id, 'ordem' => 1]);

        $this->enviar($publicado, ['secoes' => [
            $this->secao([
                $this->pergunta(['enunciado' => 'Com vínculo']),
                $this->pergunta(['enunciado' => 'Sem vínculo', 'todas_categorias' => false, 'categorias' => []]),
            ]),
        ]], ['publicado' => '1', 'resumo' => 'Resumo.', 'categoria' => 'Fomento'])
            ->assertSessionHasErrors(['questionario' => 'Vincule às categorias 1 pergunta do questionário.']);

        $this->assertSame(0, EditalPergunta::query()->where('edital_id', $publicado->id)->count());
    }

    public function test_travado_salva_mesmo_com_pergunta_que_ficou_sem_vinculo(): void
    {
        $publicado = Edital::factory()->create(['publicado' => true]);
        $teatro = EditalCategoria::factory()->create(['edital_id' => $publicado->id, 'nome' => 'Teatro', 'ordem' => 1]);
        $danca = EditalCategoria::factory()->create(['edital_id' => $publicado->id, 'nome' => 'Dança', 'ordem' => 2]);
        $secao = EditalQuestionarioSecao::factory()->create(['edital_id' => $publicado->id]);
        $pergunta = EditalPergunta::factory()->create([
            'edital_id' => $publicado->id, 'edital_questionario_secao_id' => $secao->id, 'todas_categorias' => false,
        ]);
        $pergunta->categorias()->attach($danca->id);
        Inscricao::factory()->finalizada()->create(['edital_id' => $publicado->id]);

        // Só as listas (o formulário travado não manda o questionário): apagar Dança deixa a pergunta sem vínculo.
        $this->put("/retaguarda/editais/{$publicado->slug}", [
            'com_listas' => '1',
            'publicado' => '1',
            'titulo' => $publicado->titulo,
            'resumo' => 'Resumo.',
            'categoria' => 'Fomento',
            'categorias' => [['id' => $teatro->id, 'nome' => 'Teatro']],
        ])->assertSessionHasNoErrors();

        $this->assertNull($danca->fresh());
        $this->assertTrue($pergunta->fresh()->semVinculo());
    }

    public function test_enunciado_acima_de_2000_bytes_e_recusado(): void
    {
        $this->enviar($this->edital, ['secoes' => [
            $this->secao([$this->pergunta(['enunciado' => str_repeat('ç', 1001)])]),
        ]])->assertSessionHasErrors(['questionario.secoes.0.perguntas.0.enunciado']);

        $this->assertSame(0, EditalPergunta::query()->count());
    }

    public function test_json_invalido_e_recusado(): void
    {
        $this->put("/retaguarda/editais/{$this->edital->slug}", [
            'com_questionario' => '1',
            'questionario' => '{secoes: ',
        ])->assertSessionHasErrors(['questionario' => 'Valor inválido.']);
    }

    public function test_questionario_grande_vai_inteiro_num_campo_json(): void
    {
        $secoes = [];
        foreach (range(1, 4) as $numero) {
            $secoes[] = $this->secao(
                array_map(fn (int $i): array => $this->pergunta(['enunciado' => "Pergunta {$numero}.{$i}"]), range(1, 50)),
                "Seção {$numero}",
            );
        }

        $documentos = array_map(fn (int $i): array => [
            'nome' => "Documento {$i}",
            'etapa' => 'habilitacao',
            'checagem' => 'aceita_sem_validar',
            'obrigatorio' => '1',
            'todas_categorias' => '1',
        ], range(1, 50));

        $this->post('/retaguarda/editais', [
            'com_listas' => '1',
            'com_questionario' => '1',
            'titulo' => 'Edital grande',
            'categorias' => [['nome' => 'Teatro'], ['nome' => 'Dança']],
            'documentos' => $documentos,
            'questionario' => json_encode(['secoes' => $secoes]),
            'pdf' => $this->arquivoPdf(),
        ])->assertSessionHasNoErrors();

        $edital = Edital::query()->where('titulo', 'Edital grande')->sole();
        $this->assertSame(200, $edital->perguntas()->count());
        $this->assertSame(4, $edital->secoesDoQuestionario()->count());
        $this->assertSame(50, $edital->documentos()->count());
    }

    public function test_mais_de_200_perguntas_e_recusado(): void
    {
        $secoes = [];
        foreach (range(1, 5) as $numero) {
            $secoes[] = $this->secao(array_map(fn (int $i): array => $this->pergunta(), range(1, 41)), "Seção {$numero}");
        }

        $this->enviar($this->edital, ['secoes' => $secoes])
            ->assertSessionHasErrors(['questionario' => 'Cadastre no máximo 200 perguntas.']);
    }

    public function test_tela_de_edicao_traz_o_questionario_com_posicoes_e_a_trava(): void
    {
        [, $danca] = $this->edital->categorias()->get()->all();
        $secao = EditalQuestionarioSecao::factory()->create(['edital_id' => $this->edital->id, 'titulo' => 'Dados']);
        $origem = EditalPergunta::factory()
            ->escolha(TipoPergunta::EscolhaUnica, ['Sim, claro', 'Talvez'])
            ->create(['edital_id' => $this->edital->id, 'edital_questionario_secao_id' => $secao->id, 'ordem' => 1]);
        $dependente = EditalPergunta::factory()
            ->semVinculo()
            ->condicionadaA($origem, ['o2'])
            ->create(['edital_id' => $this->edital->id, 'edital_questionario_secao_id' => $secao->id, 'ordem' => 2]);
        $dependente->categorias()->attach($danca->id);

        $this->get("/retaguarda/editais/{$this->edital->slug}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('questionarioTravado', false)
                ->has('tiposPergunta', 8)
                ->where('edital.questionario.secoes.0.titulo', 'Dados')
                ->where('edital.questionario.secoes.0.perguntas.0.id', $origem->id)
                ->where('edital.questionario.secoes.0.perguntas.0.tipo', 'escolha_unica')
                ->where('edital.questionario.secoes.0.perguntas.0.opcoes.1.chave', 'o2')
                ->where('edital.questionario.secoes.0.perguntas.0.condicao_pergunta', null)
                ->where('edital.questionario.secoes.0.perguntas.1.condicao_pergunta', 0)
                ->where('edital.questionario.secoes.0.perguntas.1.condicao_opcoes', ['o2'])
                ->where('edital.questionario.secoes.0.perguntas.1.categorias', [1])
                ->where('edital.questionario.secoes.0.perguntas.1.todas_categorias', false)
                ->missing('edital.questionario.secoes.0.perguntas.1.naturezas'));

        Inscricao::factory()->finalizada()->create(['edital_id' => $this->edital->id]);

        $this->get("/retaguarda/editais/{$this->edital->slug}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('questionarioTravado', true)->where('avisoQuestionarioTravado', Edital::AVISO_QUESTIONARIO_TRAVADO));
    }

    public function test_novo_edital_nao_tem_trava(): void
    {
        $this->get('/retaguarda/editais/create')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('questionarioTravado', false)->has('tiposPergunta', 8));
    }
}
