<?php

namespace Tests\Feature\Retaguarda;

use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\EditalCategoria;
use App\Models\EditalCriterio;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

/** Retaguarda: a aba Distribuição da avaliação (estimativa e distribuição igual ou por categoria). */
class AvaliacaoDistribuicaoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gestor = User::factory()->gestor()->create();
        $this->actingAs($this->gestor);
    }

    /** Edital publicado com avaliação de 15 a 20/12/2026 e um critério com pontuação: só falta o avaliador. */
    private function editalComCriterio(): Edital
    {
        $edital = $this->editalEmHomologacao(['validacao_inicio' => '2026-12-15', 'validacao_fim' => '2026-12-20']);
        EditalCriterio::factory()->for($edital)->create(['pontos_maximos' => 10]);

        return $edital;
    }

    private function inscricaoHomologada(Edital $edital, ?EditalCategoria $categoria = null): Inscricao
    {
        $inscricao = $this->inscricaoFinalizada($edital);
        $inscricao->update(['homologacao' => 'homologada', 'edital_categoria_id' => $categoria?->id]);

        return $inscricao;
    }

    /**
     * Avaliador no edital, com as categorias dadas.
     *
     * @param  list<EditalCategoria>  $categorias
     */
    private function avaliador(Edital $edital, string $nome, array $categorias = [], bool $ativo = true): Avaliador
    {
        $avaliador = ($ativo ? Avaliador::factory() : Avaliador::factory()->inativo())->create(['nome' => $nome]);
        $vinculo = EditalAvaliador::factory()->create(['edital_id' => $edital->id, 'avaliador_id' => $avaliador->id]);
        $vinculo->categorias()->attach(array_map(fn (EditalCategoria $categoria): int => (int) $categoria->id, $categorias));

        return $avaliador;
    }

    private function atribuir(Inscricao $inscricao, Avaliador $avaliador): InscricaoAvaliacao
    {
        return InscricaoAvaliacao::factory()->create(['inscricao_id' => $inscricao->id, 'avaliador_id' => $avaliador->id]);
    }

    private function aba(Edital $edital): string
    {
        return "/retaguarda/avaliacao/editais/{$edital->slug}/distribuicao";
    }

    /**
     * Quantas inscrições do edital estão com cada avaliador, pelo nome.
     *
     * @return array<string, int>
     */
    private function cargas(Edital $edital): array
    {
        return InscricaoAvaliacao::query()
            ->whereHas('inscricao', fn ($q) => $q->where('edital_id', $edital->id))
            ->with('avaliador:id,nome')
            ->get()
            ->countBy(fn (InscricaoAvaliacao $avaliacao): string => $avaliacao->avaliador->nome)
            ->sortKeys()
            ->all();
    }

    public function test_igual_divide_cinco_entre_dois_em_tres_e_dois(): void
    {
        $this->travelTo('2026-12-16 10:00:00');
        $edital = $this->editalComCriterio();
        $this->avaliador($edital, 'Ana');
        $this->avaliador($edital, 'Bruno');
        foreach (range(1, 5) as $vez) {
            $this->inscricaoHomologada($edital);
        }

        $resposta = $this->post($this->aba($edital), ['modo' => 'igual'])->assertRedirect();

        $cargas = $this->cargas($edital);
        $this->assertSame(['Ana', 'Bruno'], array_keys($cargas));
        $this->assertSame([2, 3], collect($cargas)->sort()->values()->all());
        [$maior, $menor] = $cargas['Ana'] === 3 ? ['Ana', 'Bruno'] : ['Bruno', 'Ana'];
        $resposta->assertSessionHas('sucesso', "Distribuídas 5 inscrições: {$maior} 3, {$menor} 2.");
        $resposta->assertSessionMissing('aviso');

        InscricaoAvaliacao::query()->get()->each(function (InscricaoAvaliacao $avaliacao): void {
            $this->assertSame((int) $this->gestor->id, (int) $avaliacao->atribuida_por);
            $this->assertSame('2026-12-16 10:00:00', $avaliacao->atribuida_em->format('Y-m-d H:i:s'));
            $this->assertNull($avaliacao->concluida_em);
        });
    }

    public function test_so_homologadas_entram(): void
    {
        $edital = $this->editalComCriterio();
        $this->avaliador($edital, 'Ana');
        $homologada = $this->inscricaoHomologada($edital);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'nao_homologada']);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'em_recurso']);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'recurso_negado']);
        $this->inscricaoFinalizada($edital);
        $rascunho = $this->inscricaoFinalizada($edital);
        $rascunho->update(['situacao' => 'rascunho', 'homologacao' => 'homologada']);
        // Homologada de outro edital também fica de fora.
        $this->inscricaoHomologada($this->editalComCriterio());

        $this->post($this->aba($edital), ['modo' => 'igual'])
            ->assertSessionHas('sucesso', 'Distribuída 1 inscrição: Ana 1.');

        $this->assertSame([(int) $homologada->id], InscricaoAvaliacao::query()->pluck('inscricao_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_segunda_rodada_so_pega_as_novas_e_equilibra(): void
    {
        $edital = $this->editalComCriterio();
        $ana = $this->avaliador($edital, 'Ana');
        $bruno = $this->avaliador($edital, 'Bruno');
        $antigas = collect([$ana, $ana, $ana, $bruno, $bruno])
            ->map(fn (Avaliador $avaliador): InscricaoAvaliacao => $this->atribuir($this->inscricaoHomologada($edital), $avaliador));
        $antes = InscricaoAvaliacao::query()->orderBy('id')->get(['id', 'inscricao_id', 'avaliador_id', 'atribuida_em'])->toArray();
        $nova = $this->inscricaoHomologada($edital);

        $this->travelTo(now()->addDay());
        $this->post($this->aba($edital), ['modo' => 'igual'])
            ->assertSessionHas('sucesso', 'Distribuída 1 inscrição: Bruno 1.');

        $this->assertSame((int) $bruno->id, (int) InscricaoAvaliacao::query()->where('inscricao_id', $nova->id)->value('avaliador_id'));
        $this->assertSame($antes, InscricaoAvaliacao::query()->whereKey($antigas->pluck('id'))->orderBy('id')->get(['id', 'inscricao_id', 'avaliador_id', 'atribuida_em'])->toArray());
        $this->assertSame(['Ana' => 3, 'Bruno' => 3], $this->cargas($edital));

        // Nada mais para distribuir.
        $this->post($this->aba($edital), ['modo' => 'igual'])
            ->assertSessionHas('aviso', 'Não há inscrições homologadas sem avaliador.')
            ->assertSessionMissing('sucesso');
        $this->assertSame(6, InscricaoAvaliacao::query()->count());
    }

    public function test_por_categoria_respeita_as_categorias_e_avisa_sobras(): void
    {
        $edital = $this->editalComCriterio();
        $teatro = EditalCategoria::factory()->for($edital)->create(['nome' => 'Teatro', 'ordem' => 1]);
        $danca = EditalCategoria::factory()->for($edital)->create(['nome' => 'Dança', 'ordem' => 2]);
        $musica = EditalCategoria::factory()->for($edital)->create(['nome' => 'Música', 'ordem' => 3]);
        $ana = $this->avaliador($edital, 'Ana', [$musica]);
        $bruno = $this->avaliador($edital, 'Bruno', [$musica]);
        $this->avaliador($edital, 'Carla', [$teatro], ativo: false);
        $davi = $this->avaliador($edital, 'Davi', [$danca]);
        $this->avaliador($edital, 'Edu');
        $teatros = [$this->inscricaoHomologada($edital, $teatro), $this->inscricaoHomologada($edital, $teatro)];
        $daDanca = $this->inscricaoHomologada($edital, $danca);
        $musicas = [$this->inscricaoHomologada($edital, $musica), $this->inscricaoHomologada($edital, $musica)];
        $semCategoria = $this->inscricaoHomologada($edital);

        $this->post($this->aba($edital), ['modo' => 'por_categoria'])
            ->assertSessionHas('sucesso', 'Distribuídas 3 inscrições: Ana 1, Bruno 1, Davi 1.')
            ->assertSessionHas('aviso', 'Ficaram sem avaliador: 2 de Teatro, 1 de Sem categoria. Inclua um avaliador para essas categorias.');

        $destinos = InscricaoAvaliacao::query()->pluck('avaliador_id', 'inscricao_id')
            ->mapWithKeys(fn ($avaliador, $inscricao): array => [(int) $inscricao => (int) $avaliador])
            ->all();
        $this->assertSame((int) $davi->id, $destinos[(int) $daDanca->id]);
        $this->assertEqualsCanonicalizing([(int) $ana->id, (int) $bruno->id], [$destinos[(int) $musicas[0]->id], $destinos[(int) $musicas[1]->id]]);
        foreach ([...$teatros, $semCategoria] as $inscricao) {
            $this->assertArrayNotHasKey((int) $inscricao->id, $destinos);
        }
        // Carla (desativada) e Edu (sem categoria) não recebem.
        $this->assertSame(['Ana' => 1, 'Bruno' => 1, 'Davi' => 1], $this->cargas($edital));
    }

    public function test_inativo_nao_recebe(): void
    {
        $edital = $this->editalComCriterio();
        $this->avaliador($edital, 'Ana');
        $this->avaliador($edital, 'Bruno', ativo: false);
        foreach (range(1, 3) as $vez) {
            $this->inscricaoHomologada($edital);
        }

        $this->get($this->aba($edital).'?modo=igual')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('destinos', [['avaliadorId' => (int) Avaliador::query()->where('nome', 'Ana')->value('id'), 'nome' => 'Ana']])
            ->where('previa.porAvaliador', [['nome' => 'Ana', 'quantidade' => 3]]));

        $this->post($this->aba($edital), ['modo' => 'igual'])
            ->assertSessionHas('sucesso', 'Distribuídas 3 inscrições: Ana 3.');
        $this->assertSame(['Ana' => 3], $this->cargas($edital));
    }

    public function test_pendencias_impedem_distribuir(): void
    {
        // Sem avaliador ativo (o único está desativado).
        $semAvaliador = $this->editalComCriterio();
        $this->avaliador($semAvaliador, 'Ana', ativo: false);
        $this->inscricaoHomologada($semAvaliador);

        // Sem critério.
        $semCriterio = $this->editalEmHomologacao();
        $this->avaliador($semCriterio, 'Bruno');
        $this->inscricaoHomologada($semCriterio);

        // Critério sem pontuação máxima.
        $semPontos = $this->editalComCriterio();
        EditalCriterio::factory()->for($semPontos)->create(['pontos_maximos' => null, 'ordem' => 2]);
        $this->avaliador($semPontos, 'Carla');
        $this->inscricaoHomologada($semPontos);

        foreach ([
            [$semAvaliador, Edital::PENDENCIA_SEM_AVALIADOR],
            [$semCriterio, Edital::PENDENCIA_SEM_CRITERIO],
            [$semPontos, Edital::PENDENCIA_CRITERIO_SEM_PONTOS],
        ] as [$edital, $pendencia]) {
            $this->get($this->aba($edital))->assertOk()->assertInertia(fn (Assert $p) => $p->where('pendencias', [$pendencia]));
            $this->post($this->aba($edital), ['modo' => 'igual'])
                ->assertSessionHas('erro', $pendencia)
                ->assertSessionMissing('sucesso');
        }

        $this->assertSame(0, InscricaoAvaliacao::query()->count());
    }

    public function test_previa_mostra_a_estimativa(): void
    {
        $edital = $this->editalComCriterio();
        $ana = $this->avaliador($edital, 'Ana');
        $this->avaliador($edital, 'Bruno');
        $jaComAna = $this->inscricaoHomologada($edital);
        $this->atribuir($jaComAna, $ana);
        foreach (range(1, 3) as $vez) {
            $this->inscricaoHomologada($edital);
        }
        $this->inscricaoFinalizada($edital);

        $this->get($this->aba($edital))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Avaliacao/Distribuicao')
            ->where('edital.slug', $edital->slug)
            ->where('edital.periodos', ['15/12/2026 a 20/12/2026'])
            ->where('pendencias', [])
            // Sem categorias, só o modo igual.
            ->where('modos', [['valor' => 'igual', 'rotulo' => 'Igual entre os avaliadores']])
            ->where('previa', null)
            ->where('inscricoes.total', 4)
            ->where('inscricoes.data.0', [
                'id' => (int) $jaComAna->id,
                'numero' => $jaComAna->numero,
                'proponente' => $jaComAna->user->name,
                'categoria' => null,
                'avaliador' => 'Ana',
                'situacaoRotulo' => 'Não iniciada',
            ])
            ->where('inscricoes.data.1.avaliador', null)
            ->where('inscricoes.data.1.situacaoRotulo', 'Sem avaliador')
            ->has('destinos', 2));

        // Ana já tem 1: das 3 novas, 2 vão para o Bruno e 1 para a Ana.
        $this->get($this->aba($edital).'?modo=igual')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('previa', [
                'modo' => 'igual',
                'porAvaliador' => [['nome' => 'Bruno', 'quantidade' => 2], ['nome' => 'Ana', 'quantidade' => 1]],
                'semAvaliador' => [],
            ]));

        $this->get($this->aba($edital).'?modo=qualquer')->assertOk()->assertInertia(fn (Assert $p) => $p->where('previa', null));
        // Edital sem categorias não distribui por categoria.
        $this->post($this->aba($edital), ['modo' => 'por_categoria'])->assertSessionHasErrors('modo');

        $this->assertSame(1, InscricaoAvaliacao::query()->count());
    }

    public function test_edital_nao_publicado_da_404(): void
    {
        $edital = $this->editalComCriterio();
        $this->avaliador($edital, 'Ana');
        $this->inscricaoHomologada($edital);
        $edital->update(['publicado' => false]);

        $this->get($this->aba($edital))->assertNotFound();
        $this->post($this->aba($edital), ['modo' => 'igual'])->assertNotFound();

        $this->assertSame(0, InscricaoAvaliacao::query()->count());
    }

    public function test_quem_nao_e_gestor_vai_para_o_login(): void
    {
        $edital = $this->editalComCriterio();
        $this->avaliador($edital, 'Ana');
        $this->inscricaoHomologada($edital);

        Auth::logout();
        $this->get($this->aba($edital))->assertRedirect('/retaguarda/login');
        $this->post($this->aba($edital), ['modo' => 'igual'])->assertRedirect('/retaguarda/login');

        $this->actingAs(User::factory()->proponente()->create());
        $this->get($this->aba($edital))->assertRedirect('/retaguarda/login');
        $this->post($this->aba($edital), ['modo' => 'igual'])->assertRedirect('/retaguarda/login');

        $this->assertSame(0, InscricaoAvaliacao::query()->count());
    }
}
