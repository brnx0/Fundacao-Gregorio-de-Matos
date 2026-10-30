<?php

namespace Tests\Feature\Portal;

use App\Enums\DecisaoRecurso;
use App\Enums\MarcacaoDoItem;
use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\InscricaoController;
use App\Models\Edital;
use App\Models\Inscricao;
use App\Models\InscricaoDocumento;
use App\Models\InscricaoRecurso;
use App\Models\InscricaoResposta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class ResultadoDaHomologacaoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private User $user;

    private Edital $edital;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-12-12 10:00', 'America/Bahia'));
        $this->user = User::factory()->proponente()->comPerfil()->create();
        $this->edital = $this->editalEmHomologacao();
        $this->actingAs($this->user);
    }

    public function test_minhas_inscricoes_lista_as_do_usuario_em_qualquer_fase(): void
    {
        $encerrado = $this->editalEmHomologacao();
        $encerrado->update(['titulo' => 'Edital encerrado']);
        $rascunho = Inscricao::factory()->for($encerrado)->for($this->user)->create([
            'edital_chamada_id' => $encerrado->chamadas()->first()->id,
            'documento_cadastro' => $this->user->cpf,
        ]);
        $finalizada = $this->inscricaoFinalizada($this->edital, $this->user);
        $naoHomologada = $this->naoHomologada($this->inscricaoFinalizada($this->edital, $this->user));
        $this->inscricaoFinalizada($this->edital);

        $this->get('/portal/inscricoes')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/MinhasInscricoes')
            ->has('inscricoes', 3)
            ->where('inscricoes.0.id', (int) $naoHomologada->id)
            ->where('inscricoes.0.situacao', 'nao_homologada')
            ->where('inscricoes.0.situacaoRotulo', 'Não homologada')
            ->where('inscricoes.1.id', (int) $finalizada->id)
            ->where('inscricoes.1.numero', $finalizada->numero)
            ->where('inscricoes.1.edital', $this->edital->titulo)
            ->where('inscricoes.1.cadastro', $this->user->name)
            ->where('inscricoes.1.categoria', null)
            ->where('inscricoes.1.situacao', 'em_homologacao')
            ->where('inscricoes.1.situacaoRotulo', 'Em homologação')
            ->where('inscricoes.1.finalizadaEm', $finalizada->finalizada_em->format('d/m/Y H:i'))
            ->where('inscricoes.2.id', (int) $rascunho->id)
            ->where('inscricoes.2.edital', 'Edital encerrado')
            ->where('inscricoes.2.numero', null)
            ->where('inscricoes.2.situacao', 'rascunho')
            ->where('inscricoes.2.situacaoRotulo', 'Rascunho')
            ->where('inscricoes.2.finalizadaEm', null));
    }

    public function test_resultado_nao_homologada_traz_motivo_itens_e_prazo(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->edital, $this->user);
        $this->marcar($inscricao, $this->documentoEnviado($inscricao, 'RG'), MarcacaoDoItem::Problema, 'Ilegível.');
        $this->marcar($inscricao, $this->documentoEnviado($inscricao, 'Comprovante'), MarcacaoDoItem::Ok);
        $this->naoHomologada($inscricao);

        $this->get(route('portal.inscricoes.show', $inscricao))->assertInertia(fn (Assert $page) => $page
            ->where('resultado.situacao', 'nao_homologada')
            ->where('resultado.rotulo', 'Não homologada')
            ->where('resultado.motivo', 'Documentos: RG — Ilegível.')
            ->has('resultado.itens', 1)
            ->where('resultado.itens.0', ['nome' => 'RG', 'observacao' => 'Ilegível.'])
            ->where('resultado.prazoDoRecurso', '13/12/2026')
            ->where('resultado.podeRecorrer', true)
            ->where('resultado.parecer', null));
    }

    public function test_resultado_homologada_e_em_homologacao(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->edital, $this->user);
        $this->marcar($inscricao, $this->documentoEnviado($inscricao, 'RG'), MarcacaoDoItem::Problema, 'Ilegível.');
        $url = route('portal.inscricoes.show', $inscricao);

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('resultado.situacao', 'em_homologacao')
            ->where('resultado.rotulo', 'Em homologação')
            ->where('resultado.motivo', null)
            ->where('resultado.itens', [])
            ->where('resultado.prazoDoRecurso', null)
            ->where('resultado.podeRecorrer', false));

        $inscricao->update([
            'homologacao' => SituacaoHomologacao::Homologada,
            'homologada_em' => Carbon::parse('2026-12-05 10:00', 'America/Bahia'),
            'homologada_por' => User::factory()->gestor()->create()->id,
        ]);

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('resultado.situacao', 'homologada')
            ->where('resultado.rotulo', 'Homologada')
            ->where('resultado.motivo', null)
            ->where('resultado.itens', [])
            ->where('resultado.prazoDoRecurso', null)
            ->where('resultado.podeRecorrer', false));

        $rascunho = Inscricao::factory()->for($this->edital)->for($this->user)->create([
            'edital_chamada_id' => $this->edital->chamadas()->first()->id,
            'documento_cadastro' => $this->user->cpf,
        ]);

        $this->get(route('portal.inscricoes.show', $rascunho))->assertInertia(fn (Assert $page) => $page
            ->where('resultado', null));
    }

    public function test_recurso_negado_mostra_o_parecer(): void
    {
        $inscricao = $this->naoHomologada($this->inscricaoFinalizada($this->edital, $this->user));
        $inscricao->update(['homologacao' => SituacaoHomologacao::RecursoNegado]);
        InscricaoRecurso::factory()->create([
            'inscricao_id' => $inscricao->id,
            'justificativa' => 'O RG está legível.',
            'enviado_em' => Carbon::parse('2026-12-11 10:00', 'America/Bahia'),
            'decisao' => DecisaoRecurso::Negado,
            'parecer' => 'Continua ilegível.',
            'julgado_por' => User::factory()->gestor()->create()->id,
            'julgado_em' => now(),
        ]);

        $this->get(route('portal.inscricoes.show', $inscricao))->assertInertia(fn (Assert $page) => $page
            ->where('resultado.situacao', 'recurso_negado')
            ->where('resultado.rotulo', 'Não homologada (recurso negado)')
            ->where('resultado.motivo', 'Documentos: RG — Ilegível.')
            ->where('resultado.parecer', 'Continua ilegível.')
            ->where('resultado.podeRecorrer', false));
    }

    public function test_com_decisao_nao_reabre(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'America/Bahia'));
        $inscricao = $this->naoHomologada($this->inscricaoFinalizada($this->edital, $this->user));

        $this->post(route('portal.inscricoes.reabrir', $inscricao))
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('erro', InscricaoController::ERRO_REABRIR_COM_DECISAO);

        $this->assertSame(SituacaoInscricao::Finalizada, $inscricao->fresh()->situacao);
        $this->get(route('portal.inscricoes.show', $inscricao))->assertInertia(fn (Assert $page) => $page
            ->where('podeReabrir', false));
    }

    public function test_reabrir_apaga_as_marcacoes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'America/Bahia'));
        $inscricao = $this->inscricaoFinalizada($this->edital, $this->user);
        $this->marcar($inscricao, $this->documentoEnviado($inscricao, 'RG'), MarcacaoDoItem::Ok);
        $this->marcar($inscricao, $this->perguntaRespondida($inscricao, 'Nome do projeto'), MarcacaoDoItem::Problema, 'Incompleta.');

        $this->post(route('portal.inscricoes.reabrir', $inscricao))
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('sucesso');

        $this->assertSame(SituacaoInscricao::Rascunho, $inscricao->fresh()->situacao);
        $this->assertSame(0, $inscricao->conferencias()->count());
    }

    public function test_com_decisao_nao_troca_escolhas_respostas_nem_documentos(): void
    {
        Storage::fake(Inscricao::DISCO);
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'America/Bahia'));
        $inscricao = $this->inscricaoFinalizada($this->edital, $this->user);
        $rg = $this->documentoEnviado($inscricao, 'RG');
        $pergunta = $this->perguntaRespondida($inscricao, 'Nome do projeto', 'Original');
        $this->naoHomologada($inscricao);
        $show = route('portal.inscricoes.show', $inscricao);

        $this->put(route('portal.inscricoes.update', $inscricao), ['cadastro' => 'pessoa_fisica'])
            ->assertRedirect($show)
            ->assertSessionHas('erro', InscricaoController::ERRO_REABRIR);
        $this->put(route('portal.inscricoes.secoes.update', [$inscricao, $pergunta->edital_questionario_secao_id]), [
            'respostas' => [$pergunta->id => ['texto' => 'Nova']],
        ])
            ->assertRedirect($show)
            ->assertSessionHas('erro', InscricaoController::ERRO_REABRIR);
        $this->post(route('portal.inscricoes.documentos.store', [$inscricao, $rg]), [
            'arquivo' => UploadedFile::fake()->create('rg.pdf', 10, 'application/pdf'),
        ])
            ->assertRedirect($show)
            ->assertSessionHas('erro', InscricaoController::ERRO_REABRIR);

        $inscricao->refresh();
        $this->assertSame(SituacaoInscricao::Finalizada, $inscricao->situacao);
        $this->assertSame(SituacaoHomologacao::NaoHomologada, $inscricao->homologacao);
        $this->assertSame('Original', InscricaoResposta::query()->where('inscricao_id', $inscricao->id)->sole()->texto);
        $this->assertSame('inscricoes/original.pdf', InscricaoDocumento::query()->where('inscricao_id', $inscricao->id)->sole()->arquivo);
        $this->assertSame([], Storage::disk(Inscricao::DISCO)->allFiles());
    }

    /** Põe a inscrição como não homologada por um gestor em 05/12/2026, com o motivo do RG. */
    private function naoHomologada(Inscricao $inscricao): Inscricao
    {
        $inscricao->update([
            'homologacao' => SituacaoHomologacao::NaoHomologada,
            'homologacao_motivo' => 'Documentos: RG — Ilegível.',
            'homologada_em' => Carbon::parse('2026-12-05 10:00', 'America/Bahia'),
            'homologada_por' => User::factory()->gestor()->create()->id,
        ]);

        return $inscricao;
    }
}
