<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\MarcacaoDoItem;
use App\Enums\SituacaoConferencia;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Retaguarda\HomologacaoController;
use App\Models\InscricaoDocumento;
use App\Models\InscricaoRecurso;
use App\Models\InscricaoRecursoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class HomologacaoConferenciaTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-12-05 10:00', 'America/Bahia'));
        $this->actingAs(User::factory()->gestor()->create());
    }

    public function test_conferencia_traz_documentos_com_ia_respostas_e_marcacoes(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $rg = $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/1/rg.pdf');
        InscricaoDocumento::query()->where('edital_documento_id', $rg->id)
            ->update(['situacao' => SituacaoConferencia::Reprovado->value, 'motivo' => 'Ilegível.']);
        $pergunta = $this->perguntaRespondida($inscricao, 'Resumo do projeto', 'Um projeto');
        $this->marcar($inscricao, $pergunta, MarcacaoDoItem::Problema, 'Resumo vago.');

        $resposta = $this->get("/retaguarda/homologacao/{$inscricao->id}");

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Homologacao/Show')
            ->where('inscricao.id', $inscricao->id)
            ->where('inscricao.numero', $inscricao->numero)
            ->has('documentos', 1)
            ->where('documentos.0.id', $rg->id)
            ->where('documentos.0.ia.situacao', 'reprovado')
            ->where('documentos.0.ia.motivo', 'Ilegível.')
            ->where('documentos.0.enviado.nome', 'rg.pdf')
            ->where('documentos.0.enviado.url', route('retaguarda.homologacao.documentos.arquivo', [$inscricao, $rg]))
            ->where('documentos.0.marcacao', null)
            ->where('perguntasConferidas', [$pergunta->id])
            ->where("respostas.{$pergunta->id}.texto", 'Um projeto')
            ->where("marcacoesDasPerguntas.{$pergunta->id}.situacao", 'problema')
            ->where("marcacoesDasPerguntas.{$pergunta->id}.observacao", 'Resumo vago.')
            ->where('podeConferir', true)
            ->where('podeHomologar', false)
            ->where('aviso', null)
            ->where('periodo', ['inicio' => '01/12/2026', 'fim' => '10/12/2026'])
            ->where('recurso', null)
            ->where('podeJulgar', false));

        $props = json_encode($resposta->viewData('page')['props'], JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('inscricoes/', $props);
    }

    public function test_sem_periodo_de_homologacao_abre_so_para_leitura_com_aviso(): void
    {
        $semDatas = $this->inscricaoFinalizada($this->editalEmHomologacao(['homologacao_inicio' => null, 'homologacao_fim' => null]));
        $semChamada = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $semChamada->update(['edital_chamada_id' => null]);

        foreach ([$semDatas, $semChamada] as $inscricao) {
            $this->get("/retaguarda/homologacao/{$inscricao->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('podeConferir', false)
                ->where('podeHomologar', false)
                ->where('aviso', HomologacaoController::AVISO_SEM_PERIODO)
                ->where('periodo', ['inicio' => null, 'fim' => null]));
        }
    }

    public function test_fora_do_periodo_abre_so_para_leitura(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $this->travelTo(Carbon::parse('2026-12-11 00:00', 'America/Bahia'));

        $this->get("/retaguarda/homologacao/{$inscricao->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('podeConferir', false)
            ->where('aviso', 'Fora do período de homologação da chamada (01/12/2026 a 10/12/2026): a inscrição fica só para consulta.'));
    }

    public function test_decidida_mostra_a_decisao_e_o_recurso_sem_aviso(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $gestora = User::factory()->gestor()->create(['name' => 'ANA GESTORA']);
        $inscricao->update([
            'homologacao' => 'em_recurso',
            'homologacao_motivo' => 'Documentos: RG — Ilegível.',
            'homologada_por' => $gestora->id,
            'homologada_em' => Carbon::parse('2026-12-04 15:30', 'America/Bahia'),
        ]);
        $rg = $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/1/rg.pdf');
        $recurso = InscricaoRecurso::factory()->create(['inscricao_id' => $inscricao->id, 'justificativa' => 'Enviei de novo.']);
        InscricaoRecursoDocumento::factory()->create([
            'inscricao_recurso_id' => $recurso->id,
            'edital_documento_id' => $rg->id,
            'arquivo' => 'inscricoes/1/recurso/rg-novo.pdf',
            'arquivo_nome' => 'rg-novo.pdf',
        ]);

        $resposta = $this->get("/retaguarda/homologacao/{$inscricao->id}");

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('inscricao.homologacao', 'em_recurso')
            ->where('inscricao.situacaoRotulo', 'Em recurso')
            ->where('inscricao.motivo', 'Documentos: RG — Ilegível.')
            ->where('inscricao.decididaPor', 'ANA GESTORA')
            ->where('inscricao.decididaEm', '04/12/2026 15:30')
            ->where('inscricao.prazoDoRecurso', '13/12/2026')
            ->where('podeConferir', false)
            ->where('aviso', null)
            ->where('podeJulgar', true)
            ->where('recurso.justificativa', 'Enviei de novo.')
            ->where('recurso.decisao', null)
            ->where('recurso.documentos.0.id', $rg->id)
            ->where('recurso.documentos.0.nome', 'RG')
            ->where('recurso.documentos.0.original', ['nome' => 'rg.pdf', 'url' => route('retaguarda.homologacao.documentos.arquivo', [$inscricao, $rg])])
            ->where('recurso.documentos.0.novo', ['nome' => 'rg-novo.pdf', 'url' => route('retaguarda.homologacao.recurso.arquivo', [$inscricao, $rg])]));

        $props = json_encode($resposta->viewData('page')['props'], JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('inscricoes/', $props);
    }

    public function test_homologada_nao_mostra_prazo_do_recurso(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $inscricao->update([
            'homologacao' => 'homologada',
            'homologada_por' => User::factory()->gestor()->create()->id,
            'homologada_em' => Carbon::parse('2026-12-04 15:30', 'America/Bahia'),
        ]);

        $this->get("/retaguarda/homologacao/{$inscricao->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('inscricao.homologacao', 'homologada')
            ->where('inscricao.prazoDoRecurso', null));
    }

    public function test_edital_apagado_da_404_na_conferencia_e_nos_arquivos(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $rg = $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/1/rg.pdf');
        Storage::disk('local')->put('inscricoes/1/rg.pdf', 'rg-original');
        $inscricao->edital->delete();

        $this->get("/retaguarda/homologacao/{$inscricao->id}")->assertNotFound();
        $this->get("/retaguarda/homologacao/{$inscricao->id}/documentos/{$rg->id}/arquivo")->assertNotFound();
        $this->get("/retaguarda/homologacao/{$inscricao->id}/recurso/documentos/{$rg->id}/arquivo")->assertNotFound();
    }

    public function test_rascunho_da_404(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $inscricao->update(['situacao' => SituacaoInscricao::Rascunho, 'finalizada_em' => null]);

        $this->get("/retaguarda/homologacao/{$inscricao->id}")->assertNotFound();
    }

    public function test_retrato_do_perfil_aparece_com_rotulos(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $inscricao->update(['perfil' => [
            'escolaridade' => 'superior_completo',
            'pcd' => true,
            'deficiencia' => 'outra',
            'deficiencia_outra' => 'Baixa visão',
        ]]);

        $this->get("/retaguarda/homologacao/{$inscricao->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('perfil', fn ($linhas) => collect($linhas)->pluck('valor', 'rotulo')->only(['Escolaridade', 'Pessoa com deficiência (PcD)', 'Tipo de deficiência', 'Raça/cor'])->all() === [
                'Raça/cor' => null,
                'Pessoa com deficiência (PcD)' => 'Sim',
                'Tipo de deficiência' => 'Outra: Baixa visão',
                'Escolaridade' => 'Ensino superior completo',
            ]));
    }

    public function test_gestor_abre_o_arquivo_da_inscricao_e_do_recurso(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $rg = $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/1/rg.pdf');
        Storage::disk('local')->put('inscricoes/1/rg.pdf', 'rg-original');
        $recurso = InscricaoRecurso::factory()->create(['inscricao_id' => $inscricao->id]);
        InscricaoRecursoDocumento::factory()->create([
            'inscricao_recurso_id' => $recurso->id,
            'edital_documento_id' => $rg->id,
            'arquivo' => 'inscricoes/1/recurso/rg-novo.pdf',
            'arquivo_nome' => 'rg-novo.pdf',
        ]);
        Storage::disk('local')->put('inscricoes/1/recurso/rg-novo.pdf', 'rg-do-recurso');

        $original = $this->get("/retaguarda/homologacao/{$inscricao->id}/documentos/{$rg->id}/arquivo");
        $novo = $this->get("/retaguarda/homologacao/{$inscricao->id}/recurso/documentos/{$rg->id}/arquivo");

        $original->assertOk()->assertStreamedContent('rg-original');
        $this->assertStringStartsWith('inline; filename=', $original->headers->get('Content-Disposition'));
        $this->assertStringContainsString('rg.pdf', $original->headers->get('Content-Disposition'));
        $novo->assertOk()->assertStreamedContent('rg-do-recurso');
        $this->assertStringStartsWith('inline; filename=', $novo->headers->get('Content-Disposition'));
        $this->assertStringContainsString('rg-novo.pdf', $novo->headers->get('Content-Disposition'));
    }

    public function test_arquivo_de_documento_nao_enviado_ou_fora_do_disco_da_404(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $semArquivoNoDisco = $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/1/rg.pdf');
        $naoEnviado = $this->documentoExigido($inscricao->edital, 'Comprovante');

        $this->get("/retaguarda/homologacao/{$inscricao->id}/documentos/{$semArquivoNoDisco->id}/arquivo")->assertNotFound();
        $this->get("/retaguarda/homologacao/{$inscricao->id}/documentos/{$naoEnviado->id}/arquivo")->assertNotFound();
        $this->get("/retaguarda/homologacao/{$inscricao->id}/recurso/documentos/{$semArquivoNoDisco->id}/arquivo")->assertNotFound();
    }

    public function test_quem_nao_e_gestor_vai_para_o_login(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $rg = $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/1/rg.pdf');
        Storage::disk('local')->put('inscricoes/1/rg.pdf', 'rg-original');

        $this->actingAs($inscricao->user);

        foreach ([
            "/retaguarda/homologacao/{$inscricao->id}",
            "/retaguarda/homologacao/{$inscricao->id}/documentos/{$rg->id}/arquivo",
            "/retaguarda/homologacao/{$inscricao->id}/recurso/documentos/{$rg->id}/arquivo",
        ] as $endereco) {
            $this->get($endereco)->assertRedirect('/retaguarda/login');
        }
    }
}
