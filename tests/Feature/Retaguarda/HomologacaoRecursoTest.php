<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\DecisaoRecurso;
use App\Enums\SituacaoConferencia;
use App\Enums\SituacaoHomologacao;
use App\Http\Controllers\Retaguarda\RecursoDaHomologacaoController;
use App\Models\EditalDocumento;
use App\Models\Inscricao;
use App\Models\InscricaoDocumento;
use App\Models\InscricaoRecurso;
use App\Models\InscricaoRecursoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class HomologacaoRecursoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Inscricao::DISCO);
        $this->travelTo(Carbon::parse('2026-12-20 10:00', 'America/Bahia'));
        $this->gestor = User::factory()->gestor()->create();
        $this->actingAs($this->gestor);
    }

    /** @param array<string, mixed> $dados */
    private function julgar(Inscricao $inscricao, array $dados): TestResponse
    {
        return $this->post("/retaguarda/homologacao/{$inscricao->id}/recurso", $dados);
    }

    /** Inscrição não homologada por outro gestor, com o recurso enviado e aguardando julgamento. */
    private function emRecurso(Inscricao $inscricao): InscricaoRecurso
    {
        $inscricao->update([
            'homologacao' => SituacaoHomologacao::EmRecurso,
            'homologacao_motivo' => 'Documentos: RG — Ilegível.',
            'homologada_por' => User::factory()->gestor()->create()->id,
            'homologada_em' => Carbon::parse('2026-12-05 10:00', 'America/Bahia'),
        ]);

        return InscricaoRecurso::factory()->create([
            'inscricao_id' => $inscricao->id,
            'justificativa' => 'Reenvio o RG legível.',
            'enviado_em' => Carbon::parse('2026-12-07 10:00', 'America/Bahia'),
        ]);
    }

    /** Arquivo novo do recurso para o documento, gravado no disco. */
    private function novoNoRecurso(InscricaoRecurso $recurso, EditalDocumento $documento, string $arquivo): InscricaoRecursoDocumento
    {
        Storage::disk(Inscricao::DISCO)->put($arquivo, 'novo');

        return InscricaoRecursoDocumento::factory()->create([
            'inscricao_recurso_id' => $recurso->id,
            'edital_documento_id' => $documento->id,
            'arquivo' => $arquivo,
            'arquivo_nome' => 'rg-legivel.pdf',
        ]);
    }

    public function test_aceito_troca_os_arquivos_e_homologa(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        Storage::disk(Inscricao::DISCO)->put('inscricoes/original.pdf', 'original');
        $rg = $this->documentoEnviado($inscricao, 'RG');
        InscricaoDocumento::query()->where('inscricao_id', $inscricao->id)->update([
            'situacao' => SituacaoConferencia::Reprovado->value,
            'motivo' => 'Ilegível.',
            'conferido_em' => now(),
        ]);
        $recurso = $this->emRecurso($inscricao);
        $this->novoNoRecurso($recurso, $rg, 'inscricoes/rg-novo.pdf');
        $decisaoOriginal = $inscricao->fresh();

        $this->julgar($inscricao, ['decisao' => 'aceito', 'parecer' => 'RG legível agora.'])
            ->assertRedirect(route('retaguarda.homologacao.show', $inscricao))
            ->assertSessionHas('sucesso', 'Recurso aceito: inscrição homologada.');

        $enviado = InscricaoDocumento::query()->where('inscricao_id', $inscricao->id)->where('edital_documento_id', $rg->id)->sole();
        $this->assertSame('inscricoes/rg-novo.pdf', $enviado->arquivo);
        $this->assertSame('rg-legivel.pdf', $enviado->arquivo_nome);
        $this->assertSame(SituacaoConferencia::Enviado, $enviado->situacao);
        $this->assertNull($enviado->motivo);
        $this->assertNull($enviado->conferido_em);
        Storage::disk(Inscricao::DISCO)->assertMissing('inscricoes/original.pdf');
        Storage::disk(Inscricao::DISCO)->assertExists('inscricoes/rg-novo.pdf');

        $inscricao->refresh();
        $this->assertSame(SituacaoHomologacao::Homologada, $inscricao->homologacao);
        $this->assertSame((int) $decisaoOriginal->homologada_por, (int) $inscricao->homologada_por);
        $this->assertTrue($inscricao->homologada_em->equalTo($decisaoOriginal->homologada_em));
        $this->assertSame('Documentos: RG — Ilegível.', $inscricao->homologacao_motivo);

        $recurso->refresh();
        $this->assertSame(DecisaoRecurso::Aceito, $recurso->decisao);
        $this->assertSame('RG legível agora.', $recurso->parecer);
        $this->assertSame($this->gestor->id, (int) $recurso->julgado_por);
        $this->assertTrue($recurso->julgado_em->equalTo(now()));
    }

    public function test_aceito_cria_o_documento_que_nao_tinha_sido_enviado(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $certidao = $this->documentoExigido($inscricao->edital, 'Certidão');
        $recurso = $this->emRecurso($inscricao);
        $this->novoNoRecurso($recurso, $certidao, 'inscricoes/certidao.pdf');

        $this->julgar($inscricao, ['decisao' => 'aceito', 'parecer' => 'Certidão enviada.'])
            ->assertSessionHas('sucesso', 'Recurso aceito: inscrição homologada.');

        $enviado = InscricaoDocumento::query()->where('inscricao_id', $inscricao->id)->where('edital_documento_id', $certidao->id)->sole();
        $this->assertSame('inscricoes/certidao.pdf', $enviado->arquivo);
        $this->assertSame('rg-legivel.pdf', $enviado->arquivo_nome);
        $this->assertSame(SituacaoConferencia::Enviado, $enviado->situacao);
        $this->assertSame(SituacaoHomologacao::Homologada, $inscricao->fresh()->homologacao);
    }

    public function test_negado_mantem_os_originais_e_guarda_os_novos(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        Storage::disk(Inscricao::DISCO)->put('inscricoes/original.pdf', 'original');
        $rg = $this->documentoEnviado($inscricao, 'RG');
        $recurso = $this->emRecurso($inscricao);
        $this->novoNoRecurso($recurso, $rg, 'inscricoes/rg-novo.pdf');

        $this->julgar($inscricao, ['decisao' => 'negado', 'parecer' => 'Continua ilegível.'])
            ->assertRedirect(route('retaguarda.homologacao.show', $inscricao))
            ->assertSessionHas('sucesso', 'Recurso negado.');

        $enviado = InscricaoDocumento::query()->where('inscricao_id', $inscricao->id)->where('edital_documento_id', $rg->id)->sole();
        $this->assertSame('inscricoes/original.pdf', $enviado->arquivo);
        Storage::disk(Inscricao::DISCO)->assertExists('inscricoes/original.pdf');
        Storage::disk(Inscricao::DISCO)->assertExists('inscricoes/rg-novo.pdf');

        $this->assertSame(SituacaoHomologacao::RecursoNegado, $inscricao->fresh()->homologacao);
        $recurso->refresh();
        $this->assertSame(DecisaoRecurso::Negado, $recurso->decisao);
        $this->assertSame('Continua ilegível.', $recurso->parecer);
        $this->assertSame($this->gestor->id, (int) $recurso->julgado_por);
        $this->assertNotNull($recurso->julgado_em);
    }

    public function test_julga_depois_do_periodo_de_homologacao(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $this->emRecurso($inscricao);
        $this->travelTo(Carbon::parse('2027-02-15 10:00', 'America/Bahia'));

        $this->julgar($inscricao, ['decisao' => 'negado', 'parecer' => 'Sem novos elementos.'])
            ->assertSessionHas('sucesso', 'Recurso negado.');

        $this->assertSame(SituacaoHomologacao::RecursoNegado, $inscricao->fresh()->homologacao);
    }

    public function test_parecer_e_obrigatorio_e_espacos_nao_contam(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $recurso = $this->emRecurso($inscricao);

        foreach ([[], ['parecer' => '   ']] as $parecer) {
            $this->julgar($inscricao, ['decisao' => 'aceito', ...$parecer])
                ->assertSessionHasErrors(['parecer' => 'Escreva o parecer.']);
        }

        $this->julgar($inscricao, ['decisao' => 'talvez', 'parecer' => 'Ok.'])->assertSessionHasErrors('decisao');

        $this->assertSame(SituacaoHomologacao::EmRecurso, $inscricao->fresh()->homologacao);
        $this->assertNull($recurso->fresh()->decisao);
    }

    public function test_sem_recurso_pendente_nao_julga(): void
    {
        $semRecurso = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $semRecurso->update(['homologacao' => SituacaoHomologacao::NaoHomologada, 'homologacao_motivo' => 'Fora do objeto.']);

        $jaJulgado = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $this->emRecurso($jaJulgado)->update([
            'decisao' => DecisaoRecurso::Negado,
            'parecer' => 'Negado antes.',
            'julgado_por' => $this->gestor->id,
            'julgado_em' => now(),
        ]);

        foreach ([$semRecurso, $jaJulgado] as $inscricao) {
            $this->julgar($inscricao, ['decisao' => 'aceito', 'parecer' => 'Aceito.'])
                ->assertSessionHas('erro', RecursoDaHomologacaoController::ERRO_SEM_RECURSO);
        }

        $this->assertSame(SituacaoHomologacao::NaoHomologada, $semRecurso->fresh()->homologacao);
        $this->assertSame(SituacaoHomologacao::EmRecurso, $jaJulgado->fresh()->homologacao);
        $this->assertSame('Negado antes.', $jaJulgado->recurso()->first()->parecer);

        $pendente = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $recurso = $this->emRecurso($pendente);

        $this->julgar($pendente, ['decisao' => 'negado', 'parecer' => 'Negado.'])->assertSessionHas('sucesso', 'Recurso negado.');
        $this->julgar($pendente, ['decisao' => 'aceito', 'parecer' => 'Aceito.'])
            ->assertSessionHas('erro', RecursoDaHomologacaoController::ERRO_SEM_RECURSO);

        $this->assertSame(SituacaoHomologacao::RecursoNegado, $pendente->fresh()->homologacao);
        $recurso->refresh();
        $this->assertSame(DecisaoRecurso::Negado, $recurso->decisao);
        $this->assertSame('Negado.', $recurso->parecer);
    }
}
