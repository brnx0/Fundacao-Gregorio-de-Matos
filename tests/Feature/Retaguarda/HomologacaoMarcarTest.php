<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Retaguarda\HomologacaoController;
use App\Models\Inscricao;
use App\Models\InscricaoConferencia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class HomologacaoMarcarTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-12-05 10:00', 'America/Bahia'));
        $this->gestor = User::factory()->gestor()->create();
        $this->actingAs($this->gestor);
    }

    /** @param array<string, mixed> $dados */
    private function marcarItem(Inscricao $inscricao, array $dados): TestResponse
    {
        return $this->put("/retaguarda/homologacao/{$inscricao->id}/conferencias", $dados);
    }

    public function test_marca_documento_e_pergunta_com_quem_e_quando(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $documento = $this->documentoEnviado($inscricao, 'RG');
        $pergunta = $this->perguntaRespondida($inscricao, 'Resumo');

        $this->marcarItem($inscricao, ['tipo' => 'documento', 'item' => $documento->id, 'situacao' => 'ok'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->marcarItem($inscricao, ['tipo' => 'pergunta', 'item' => $pergunta->id, 'situacao' => 'problema', 'observacao' => 'Vago.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inscricao_conferencias', [
            'inscricao_id' => $inscricao->id, 'edital_documento_id' => $documento->id, 'edital_pergunta_id' => null,
            'situacao' => 'ok', 'user_id' => $this->gestor->id,
        ]);
        $this->assertDatabaseHas('inscricao_conferencias', [
            'inscricao_id' => $inscricao->id, 'edital_documento_id' => null, 'edital_pergunta_id' => $pergunta->id,
            'situacao' => 'problema', 'observacao' => 'Vago.', 'user_id' => $this->gestor->id,
        ]);

        $this->marcarItem($inscricao, ['tipo' => 'documento', 'item' => $documento->id, 'situacao' => 'problema', 'observacao' => 'Ilegível.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, InscricaoConferencia::query()->where('inscricao_id', $inscricao->id)->count());
        $this->assertDatabaseHas('inscricao_conferencias', [
            'inscricao_id' => $inscricao->id, 'edital_documento_id' => $documento->id,
            'situacao' => 'problema', 'observacao' => 'Ilegível.',
        ]);
    }

    public function test_problema_exige_observacao_e_espacos_nao_contam(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $documento = $this->documentoEnviado($inscricao, 'RG');

        $this->marcarItem($inscricao, ['tipo' => 'documento', 'item' => $documento->id, 'situacao' => 'problema', 'observacao' => '   '])
            ->assertSessionHasErrors(['observacao' => 'Descreva o problema.']);
        $this->marcarItem($inscricao, ['tipo' => 'documento', 'item' => $documento->id, 'situacao' => 'problema'])
            ->assertSessionHasErrors(['observacao' => 'Descreva o problema.']);

        $this->assertSame(0, InscricaoConferencia::query()->count());
    }

    public function test_observacao_acima_de_1000_bytes_e_recusada(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $documento = $this->documentoEnviado($inscricao, 'RG');

        $this->marcarItem($inscricao, [
            'tipo' => 'documento', 'item' => $documento->id, 'situacao' => 'problema', 'observacao' => str_repeat('ç', 501),
        ])->assertSessionHasErrors('observacao');

        $this->assertSame(0, InscricaoConferencia::query()->count());
    }

    public function test_item_fora_da_conferencia_e_recusado(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $opcional = $this->documentoExigido($inscricao->edital, 'Opcional', obrigatorio: false);
        $deOutroEdital = $this->documentoExigido($this->editalEmHomologacao(), 'Alheio');

        foreach ([$opcional->id, $deOutroEdital->id, 999999] as $id) {
            $this->marcarItem($inscricao, ['tipo' => 'documento', 'item' => $id, 'situacao' => 'ok'])
                ->assertSessionHasErrors(['item' => HomologacaoController::ERRO_ITEM]);
        }

        $this->marcarItem($inscricao, ['tipo' => 'pergunta', 'item' => 999999, 'situacao' => 'ok'])
            ->assertSessionHasErrors(['item' => HomologacaoController::ERRO_ITEM]);

        $this->assertSame(0, InscricaoConferencia::query()->count());
    }

    public function test_fora_do_periodo_nao_marca(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $documento = $this->documentoEnviado($inscricao, 'RG');

        foreach (['2026-11-30 12:00', '2026-12-11 12:00'] as $quando) {
            $this->travelTo(Carbon::parse($quando, 'America/Bahia'));

            $this->marcarItem($inscricao, ['tipo' => 'documento', 'item' => $documento->id, 'situacao' => 'ok'])
                ->assertSessionHas('erro', HomologacaoController::ERRO_FORA_DO_PERIODO);
        }

        $this->assertSame(0, InscricaoConferencia::query()->count());
    }

    public function test_inscricao_sem_chamada_nao_marca(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $documento = $this->documentoEnviado($inscricao, 'RG');
        $inscricao->update(['edital_chamada_id' => null]);

        $this->marcarItem($inscricao, ['tipo' => 'documento', 'item' => $documento->id, 'situacao' => 'ok'])
            ->assertSessionHas('erro', HomologacaoController::ERRO_FORA_DO_PERIODO);

        $this->assertSame(0, InscricaoConferencia::query()->count());
    }

    public function test_com_decisao_nao_marca(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $documento = $this->documentoEnviado($inscricao, 'RG');
        $inscricao->update([
            'homologacao' => SituacaoHomologacao::NaoHomologada,
            'homologacao_motivo' => 'Documentos: RG — ilegível.',
            'homologada_em' => now(),
            'homologada_por' => $this->gestor->id,
        ]);

        $this->marcarItem($inscricao, ['tipo' => 'documento', 'item' => $documento->id, 'situacao' => 'ok'])
            ->assertSessionHas('erro', HomologacaoController::ERRO_JA_DECIDIDA);

        $this->assertSame(0, InscricaoConferencia::query()->count());
    }

    public function test_rascunho_nao_marca(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $documento = $this->documentoEnviado($inscricao, 'RG');
        $inscricao->update(['situacao' => SituacaoInscricao::Rascunho, 'finalizada_em' => null]);

        $this->marcarItem($inscricao, ['tipo' => 'documento', 'item' => $documento->id, 'situacao' => 'ok'])
            ->assertSessionHas('erro');

        $this->assertSame(0, InscricaoConferencia::query()->count());
    }
}
