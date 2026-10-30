<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\MarcacaoDoItem;
use App\Enums\SituacaoHomologacao;
use App\Http\Controllers\Retaguarda\HomologacaoController;
use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class HomologacaoDecisaoTest extends TestCase
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
    private function decidir(Inscricao $inscricao, array $dados): TestResponse
    {
        return $this->post("/retaguarda/homologacao/{$inscricao->id}/decisao", $dados);
    }

    public function test_homologa_com_tudo_ok_e_grava_quem_e_quando(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $this->marcar($inscricao, $this->documentoEnviado($inscricao, 'RG'), MarcacaoDoItem::Ok);
        $this->marcar($inscricao, $this->perguntaRespondida($inscricao, 'Resumo'), MarcacaoDoItem::Ok);

        $this->decidir($inscricao, ['decisao' => 'homologada'])
            ->assertRedirect(route('retaguarda.homologacao.show', $inscricao))
            ->assertSessionHas('sucesso', 'Inscrição homologada.');

        $inscricao->refresh();
        $this->assertSame(SituacaoHomologacao::Homologada, $inscricao->homologacao);
        $this->assertSame($this->gestor->id, (int) $inscricao->homologada_por);
        $this->assertTrue($inscricao->homologada_em->equalTo(now()));
        $this->assertNull($inscricao->homologacao_motivo);
    }

    public function test_nao_homologa_com_item_pendente_ou_problema_quando_pede_homologar(): void
    {
        $comPendencia = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $this->marcar($comPendencia, $this->documentoEnviado($comPendencia, 'RG'), MarcacaoDoItem::Ok);
        $this->perguntaRespondida($comPendencia, 'Resumo');

        $comProblema = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $this->marcar($comProblema, $this->documentoEnviado($comProblema, 'RG'), MarcacaoDoItem::Problema, 'Ilegível.');

        foreach ([$comPendencia, $comProblema] as $inscricao) {
            $this->decidir($inscricao, ['decisao' => 'homologada'])
                ->assertSessionHas('erro', HomologacaoController::ERRO_HOMOLOGAR_COM_PENDENCIA);

            $this->assertNull($inscricao->fresh()->homologacao);
        }
    }

    public function test_nao_homologar_exige_problema_ou_motivo(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $this->marcar($inscricao, $this->documentoEnviado($inscricao, 'RG'), MarcacaoDoItem::Ok);

        $this->decidir($inscricao, ['decisao' => 'nao_homologada', 'motivo' => '  '])
            ->assertSessionHas('erro', HomologacaoController::ERRO_NAO_HOMOLOGAR_SEM_MOTIVO);
        $this->assertNull($inscricao->fresh()->homologacao);

        $this->decidir($inscricao, ['decisao' => 'nao_homologada', 'motivo' => 'Fora do objeto.'])
            ->assertRedirect(route('retaguarda.homologacao.show', $inscricao))
            ->assertSessionHas('sucesso', 'Inscrição não homologada.');

        $inscricao->refresh();
        $this->assertSame(SituacaoHomologacao::NaoHomologada, $inscricao->homologacao);
        $this->assertSame('Fora do objeto.', $inscricao->homologacao_motivo);
        $this->assertSame($this->gestor->id, (int) $inscricao->homologada_por);
        $this->assertNotNull($inscricao->homologada_em);
    }

    public function test_nao_homologar_sem_texto_grava_o_motivo_montado_e_os_problemas_automaticos(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $certidao = $this->documentoExigido($inscricao->edital, 'Certidão');

        $this->decidir($inscricao, ['decisao' => 'nao_homologada'])
            ->assertSessionHas('sucesso', 'Inscrição não homologada.');

        $this->assertDatabaseHas('inscricao_conferencias', [
            'inscricao_id' => $inscricao->id,
            'edital_documento_id' => $certidao->id,
            'situacao' => 'problema',
            'observacao' => 'Não enviado.',
            'user_id' => $this->gestor->id,
        ]);

        $inscricao->refresh();
        $this->assertSame(SituacaoHomologacao::NaoHomologada, $inscricao->homologacao);
        $this->assertSame('Documentos: Certidão — Não enviado.', $inscricao->homologacao_motivo);
    }

    public function test_segunda_decisao_e_recusada(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $this->marcar($inscricao, $this->documentoEnviado($inscricao, 'RG'), MarcacaoDoItem::Ok);

        $this->decidir($inscricao, ['decisao' => 'homologada'])->assertSessionHas('sucesso');
        $decididaEm = $inscricao->fresh()->homologada_em;

        $this->travelTo(Carbon::parse('2026-12-06 10:00', 'America/Bahia'));

        $this->decidir($inscricao, ['decisao' => 'nao_homologada', 'motivo' => 'Mudei de ideia.'])
            ->assertSessionHas('erro', HomologacaoController::ERRO_JA_DECIDIDA);

        $inscricao->refresh();
        $this->assertSame(SituacaoHomologacao::Homologada, $inscricao->homologacao);
        $this->assertTrue($inscricao->homologada_em->equalTo($decididaEm));
        $this->assertNull($inscricao->homologacao_motivo);
    }

    public function test_fora_do_periodo_e_sem_chamada_nao_decide(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $this->marcar($inscricao, $this->documentoEnviado($inscricao, 'RG'), MarcacaoDoItem::Ok);

        foreach (['2026-11-30 12:00', '2026-12-11 12:00'] as $quando) {
            $this->travelTo(Carbon::parse($quando, 'America/Bahia'));

            $this->decidir($inscricao, ['decisao' => 'homologada'])
                ->assertSessionHas('erro', HomologacaoController::ERRO_FORA_DO_PERIODO);
        }

        $this->travelTo(Carbon::parse('2026-12-05 10:00', 'America/Bahia'));
        $inscricao->update(['edital_chamada_id' => null]);

        $this->decidir($inscricao, ['decisao' => 'homologada'])
            ->assertSessionHas('erro', HomologacaoController::ERRO_FORA_DO_PERIODO);

        $this->assertNull($inscricao->fresh()->homologacao);
    }
}
