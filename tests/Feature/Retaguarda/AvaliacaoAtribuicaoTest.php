<?php

namespace Tests\Feature\Retaguarda;

use App\Http\Controllers\Retaguarda\AtribuicaoController;
use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\EditalCriterio;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\InscricaoAvaliacaoNota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

/** Retaguarda: atribuição manual de uma inscrição e transferência das inscrições entre avaliadores. */
class AvaliacaoAtribuicaoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private User $gestor;

    private Edital $edital;

    private EditalCriterio $criterio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gestor = User::factory()->gestor()->create();
        $this->actingAs($this->gestor);
        $this->edital = $this->editalEmHomologacao(['validacao_inicio' => '2026-12-15', 'validacao_fim' => '2026-12-20']);
        $this->criterio = EditalCriterio::factory()->for($this->edital)->create(['pontos_maximos' => 10]);
    }

    private function inscricaoHomologada(?Edital $edital = null): Inscricao
    {
        $inscricao = $this->inscricaoFinalizada($edital ?? $this->edital);
        $inscricao->update(['homologacao' => 'homologada']);

        return $inscricao;
    }

    private function avaliador(string $nome, bool $ativo = true, ?Edital $edital = null): Avaliador
    {
        $avaliador = ($ativo ? Avaliador::factory() : Avaliador::factory()->inativo())->create(['nome' => $nome]);
        EditalAvaliador::factory()->create(['edital_id' => ($edital ?? $this->edital)->id, 'avaliador_id' => $avaliador->id]);

        return $avaliador;
    }

    /** Avaliação da inscrição com o avaliador; em andamento, com uma nota e a observação; ou concluída. */
    private function atribuir(Inscricao $inscricao, Avaliador $avaliador, bool $rascunho = false, bool $concluida = false): InscricaoAvaliacao
    {
        $fabrica = $concluida ? InscricaoAvaliacao::factory()->concluida() : InscricaoAvaliacao::factory();
        $avaliacao = $fabrica->create([
            'inscricao_id' => $inscricao->id,
            'avaliador_id' => $avaliador->id,
            'atribuida_em' => '2026-12-15 08:00:00',
            'observacao' => $rascunho || $concluida ? 'Projeto consistente.' : null,
        ]);

        if ($rascunho || $concluida) {
            InscricaoAvaliacaoNota::query()->create([
                'inscricao_avaliacao_id' => $avaliacao->id,
                'edital_criterio_id' => $this->criterio->id,
                'nota' => '8.00',
                'peso' => '1.00',
                'pontos_maximos' => '10.00',
            ]);
        }

        return $avaliacao;
    }

    private function urlDoAvaliador(Inscricao $inscricao, ?Edital $edital = null): string
    {
        return '/retaguarda/avaliacao/editais/'.($edital ?? $this->edital)->slug."/inscricoes/{$inscricao->id}/avaliador";
    }

    private function urlDaTransferencia(): string
    {
        return "/retaguarda/avaliacao/editais/{$this->edital->slug}/transferencia";
    }

    private function avaliadorDa(Inscricao $inscricao): ?int
    {
        $id = InscricaoAvaliacao::query()->where('inscricao_id', $inscricao->id)->value('avaliador_id');

        return $id === null ? null : (int) $id;
    }

    public function test_atribui_manualmente_inscricao_sem_avaliador(): void
    {
        $this->travelTo('2026-12-16 10:00:00');
        $ana = $this->avaliador('Ana');
        $inscricao = $this->inscricaoHomologada();

        $this->put($this->urlDoAvaliador($inscricao), ['avaliador_id' => $ana->id])
            ->assertRedirect()
            ->assertSessionHas('sucesso', "Inscrição {$inscricao->numero} atribuída a Ana.");

        $avaliacao = InscricaoAvaliacao::query()->sole();
        $this->assertSame((int) $inscricao->id, (int) $avaliacao->inscricao_id);
        $this->assertSame((int) $ana->id, (int) $avaliacao->avaliador_id);
        $this->assertSame((int) $this->gestor->id, (int) $avaliacao->atribuida_por);
        $this->assertSame('2026-12-16 10:00:00', $avaliacao->atribuida_em->format('Y-m-d H:i:s'));
        $this->assertNull($avaliacao->concluida_em);
    }

    public function test_com_pendencia_nao_atribui(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $semAvaliador = $this->inscricaoHomologada();
        $comAna = $this->inscricaoHomologada();
        $this->atribuir($comAna, $ana);
        EditalCriterio::factory()->for($this->edital)->create(['pontos_maximos' => null]);

        $this->put($this->urlDoAvaliador($semAvaliador), ['avaliador_id' => $ana->id])
            ->assertRedirect()
            ->assertSessionHas('erro', Edital::PENDENCIA_CRITERIO_SEM_PONTOS);
        $this->put($this->urlDoAvaliador($comAna), ['avaliador_id' => $bruno->id])
            ->assertSessionHas('erro', Edital::PENDENCIA_CRITERIO_SEM_PONTOS);

        $this->assertNull($this->avaliadorDa($semAvaliador));
        $this->assertSame((int) $ana->id, $this->avaliadorDa($comAna));
        $this->assertSame(1, InscricaoAvaliacao::query()->count());
    }

    public function test_transfere_e_apaga_o_rascunho(): void
    {
        $this->travelTo('2026-12-16 10:00:00');
        $ana = $this->avaliador('Ana', ativo: false);
        $bruno = $this->avaliador('Bruno');
        $inscricao = $this->inscricaoHomologada();
        $avaliacao = $this->atribuir($inscricao, $ana, rascunho: true);

        $this->put($this->urlDoAvaliador($inscricao), ['avaliador_id' => $bruno->id])
            ->assertSessionHas('sucesso', "Inscrição {$inscricao->numero} transferida de Ana para Bruno.");

        $avaliacao->refresh();
        $this->assertSame((int) $bruno->id, (int) $avaliacao->avaliador_id);
        $this->assertNull($avaliacao->observacao);
        $this->assertSame(0, $avaliacao->notas()->count());
        $this->assertSame((int) $this->gestor->id, (int) $avaliacao->atribuida_por);
        $this->assertSame('2026-12-16 10:00:00', $avaliacao->atribuida_em->format('Y-m-d H:i:s'));
        $this->assertSame(1, InscricaoAvaliacao::query()->count());

        // Para o mesmo avaliador: nada muda.
        $avaliacao->update(['observacao' => 'Começando.']);
        $this->travelTo('2026-12-17 10:00:00');
        $this->put($this->urlDoAvaliador($inscricao), ['avaliador_id' => $bruno->id])
            ->assertSessionHas('aviso', "A inscrição {$inscricao->numero} já está com Bruno.")
            ->assertSessionMissing('sucesso');

        $avaliacao->refresh();
        $this->assertSame('Começando.', $avaliacao->observacao);
        $this->assertSame('2026-12-16 10:00:00', $avaliacao->atribuida_em->format('Y-m-d H:i:s'));
    }

    public function test_nao_transfere_concluida(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $inscricao = $this->inscricaoHomologada();
        $avaliacao = $this->atribuir($inscricao, $ana, concluida: true);
        $antes = $avaliacao->fresh()->toArray();

        $this->put($this->urlDoAvaliador($inscricao), ['avaliador_id' => $bruno->id])
            ->assertSessionHas('erro', AtribuicaoController::ERRO_JA_CONCLUIDA)
            ->assertSessionMissing('sucesso');

        $this->assertSame($antes, $avaliacao->fresh()->toArray());
        $this->assertSame(1, $avaliacao->notas()->count());
    }

    public function test_destino_precisa_ser_ativo_e_do_edital(): void
    {
        $ana = $this->avaliador('Ana');
        $inativo = $this->avaliador('Bruno', ativo: false);
        $deOutroEdital = $this->avaliador('Carla', edital: $this->editalEmHomologacao());
        $semEdital = Avaliador::factory()->create(['nome' => 'Davi']);
        $inscricao = $this->inscricaoHomologada();
        $this->atribuir($this->inscricaoHomologada(), $inativo);

        foreach ([$inativo->id, $deOutroEdital->id, $semEdital->id, 999999] as $destino) {
            $this->put($this->urlDoAvaliador($inscricao), ['avaliador_id' => $destino])
                ->assertSessionHasErrors(['avaliador_id' => AtribuicaoController::ERRO_DESTINO]);
        }
        $this->put($this->urlDoAvaliador($inscricao), [])->assertSessionHasErrors('avaliador_id');

        // Transferir todas: o destino também precisa ser ativo do edital e outro avaliador; a origem, do edital.
        foreach ([$inativo->id, $deOutroEdital->id, $semEdital->id] as $destino) {
            $this->post($this->urlDaTransferencia(), ['de' => $ana->id, 'para' => $destino])
                ->assertSessionHasErrors(['para' => AtribuicaoController::ERRO_DESTINO]);
        }
        $this->post($this->urlDaTransferencia(), ['de' => $ana->id, 'para' => $ana->id])->assertSessionHasErrors('para');
        $this->post($this->urlDaTransferencia(), ['de' => $deOutroEdital->id, 'para' => $ana->id])
            ->assertSessionHasErrors(['de' => AtribuicaoController::ERRO_ORIGEM]);

        $this->assertNull($this->avaliadorDa($inscricao));
        $this->assertSame(1, InscricaoAvaliacao::query()->where('avaliador_id', $inativo->id)->count());
    }

    public function test_inscricao_de_outro_edital_da_404(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $outroEdital = $this->editalEmHomologacao();
        $deOutroEdital = $this->inscricaoHomologada($outroEdital);
        $naoHomologada = $this->inscricaoFinalizada($this->edital);
        $rascunho = $this->inscricaoHomologada();
        $rascunho->update(['situacao' => 'rascunho']);

        foreach ([$deOutroEdital, $naoHomologada, $rascunho] as $inscricao) {
            $this->put($this->urlDoAvaliador($inscricao), ['avaliador_id' => $ana->id])->assertNotFound();
        }

        // Edital não publicado.
        $homologada = $this->inscricaoHomologada();
        $this->edital->update(['publicado' => false]);
        $this->put($this->urlDoAvaliador($homologada), ['avaliador_id' => $ana->id])->assertNotFound();
        $this->post($this->urlDaTransferencia(), ['de' => $ana->id, 'para' => $bruno->id])->assertNotFound();

        $this->assertSame(0, InscricaoAvaliacao::query()->count());
    }

    public function test_transfere_todas_as_nao_concluidas(): void
    {
        $this->travelTo('2026-12-16 10:00:00');
        $ana = $this->avaliador('Ana', ativo: false);
        $bruno = $this->avaliador('Bruno');
        $naoIniciada = $this->atribuir($this->inscricaoHomologada(), $ana);
        $rascunho = $this->atribuir($this->inscricaoHomologada(), $ana, rascunho: true);
        $concluida = $this->atribuir($this->inscricaoHomologada(), $ana, concluida: true);
        // A de outro edital fica com a Ana.
        $outroEdital = $this->editalEmHomologacao();
        foreach ([$ana, $bruno] as $avaliador) {
            EditalAvaliador::factory()->create(['edital_id' => $outroEdital->id, 'avaliador_id' => $avaliador->id]);
        }
        $deOutroEdital = $this->atribuir($this->inscricaoHomologada($outroEdital), $ana);

        // A aba Distribuição avisa da desativada com inscrições não concluídas.
        $this->get("/retaguarda/avaliacao/editais/{$this->edital->slug}/distribuicao")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('desativados', [['avaliadorId' => (int) $ana->id, 'nome' => 'Ana', 'naoConcluidas' => 2]])
            ->where('origens', [
                ['avaliadorId' => (int) $ana->id, 'nome' => 'Ana', 'naoConcluidas' => 2],
                ['avaliadorId' => (int) $bruno->id, 'nome' => 'Bruno', 'naoConcluidas' => 0],
            ]));

        $this->post($this->urlDaTransferencia(), ['de' => $ana->id, 'para' => $bruno->id])
            ->assertRedirect()
            ->assertSessionHas('sucesso', '2 inscrições transferidas de Ana para Bruno.');

        foreach ([$naoIniciada, $rascunho] as $avaliacao) {
            $avaliacao->refresh();
            $this->assertSame((int) $bruno->id, (int) $avaliacao->avaliador_id);
            $this->assertNull($avaliacao->observacao);
            $this->assertSame(0, $avaliacao->notas()->count());
            $this->assertSame((int) $this->gestor->id, (int) $avaliacao->atribuida_por);
            $this->assertSame('2026-12-16 10:00:00', $avaliacao->atribuida_em->format('Y-m-d H:i:s'));
        }
        $concluida->refresh();
        $this->assertSame((int) $ana->id, (int) $concluida->avaliador_id);
        $this->assertSame('Projeto consistente.', $concluida->observacao);
        $this->assertSame(['8.00'], $concluida->notas()->pluck('nota')->all());
        $this->assertSame((int) $ana->id, (int) $deOutroEdital->fresh()->avaliador_id);

        $this->get("/retaguarda/avaliacao/editais/{$this->edital->slug}/distribuicao")->assertInertia(fn (Assert $p) => $p->where('desativados', []));

        // Nada mais a transferir; com uma só, no singular.
        $this->post($this->urlDaTransferencia(), ['de' => $ana->id, 'para' => $bruno->id])
            ->assertSessionHas('aviso', 'Não há inscrições não concluídas com Ana.')
            ->assertSessionMissing('sucesso');
        $ana->update(['ativo' => true]);
        $this->atribuir($this->inscricaoHomologada(), $bruno);
        $this->post($this->urlDaTransferencia(), ['de' => $bruno->id, 'para' => $ana->id])
            ->assertSessionHas('sucesso', '3 inscrições transferidas de Bruno para Ana.');
        $this->atribuir($this->inscricaoHomologada(), $bruno);
        $this->post($this->urlDaTransferencia(), ['de' => $bruno->id, 'para' => $ana->id])
            ->assertSessionHas('sucesso', '1 inscrição transferida de Bruno para Ana.');
    }

    public function test_quem_nao_e_gestor_vai_para_o_login(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $inscricao = $this->inscricaoHomologada();
        $this->atribuir($this->inscricaoHomologada(), $ana);

        Auth::logout();
        $this->put($this->urlDoAvaliador($inscricao), ['avaliador_id' => $ana->id])->assertRedirect('/retaguarda/login');
        $this->post($this->urlDaTransferencia(), ['de' => $ana->id, 'para' => $bruno->id])->assertRedirect('/retaguarda/login');

        $this->actingAs(User::factory()->proponente()->create());
        $this->put($this->urlDoAvaliador($inscricao), ['avaliador_id' => $ana->id])->assertRedirect('/retaguarda/login');
        $this->post($this->urlDaTransferencia(), ['de' => $ana->id, 'para' => $bruno->id])->assertRedirect('/retaguarda/login');

        $this->assertNull($this->avaliadorDa($inscricao));
        $this->assertSame(1, InscricaoAvaliacao::query()->where('avaliador_id', $ana->id)->count());
    }
}
