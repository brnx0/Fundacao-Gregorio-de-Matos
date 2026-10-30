<?php

namespace Tests\Feature\Portal;

use App\Enums\MarcacaoDoItem;
use App\Enums\SituacaoHomologacao;
use App\Http\Controllers\InscricaoDocumentoController;
use App\Http\Controllers\InscricaoRecursoController;
use App\Http\Requests\EnviarRecursoRequest;
use App\Models\EditalDocumento;
use App\Models\Inscricao;
use App\Models\InscricaoRecurso;
use App\Models\InscricaoRecursoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class RecursoDaInscricaoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private User $user;

    /** Documento marcado com problema na conferência. */
    private EditalDocumento $rg;

    /** Documento marcado como ok na conferência. */
    private EditalDocumento $comprovante;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Inscricao::DISCO);
        $this->travelTo(Carbon::parse('2026-12-12 10:00', 'America/Bahia'));
        $this->user = User::factory()->proponente()->comPerfil()->create();
        $this->actingAs($this->user);
    }

    public function test_envia_justificativa_e_documento_marcado(): void
    {
        $inscricao = $this->naoHomologada();

        $this->get("/portal/inscricoes/{$inscricao->id}/recurso")->assertInertia(fn (Assert $page) => $page
            ->component('Portal/RecursoDaInscricao')
            ->where('inscricao.id', (int) $inscricao->id)
            ->where('prazo', '13/12/2026')
            ->where('motivo', 'Documentos: RG — Ilegível.')
            ->where('itens', [['nome' => 'RG', 'observacao' => 'Ilegível.']])
            ->has('documentos', 1)
            ->where('documentos.0.id', (int) $this->rg->id)
            ->where('documentos.0.nome', 'RG')
            ->where('documentos.0.observacao', 'Ilegível.'));

        $this->enviar($inscricao, [
            'justificativa' => 'Reenvio o RG legível.',
            'arquivos' => [$this->rg->id => $this->pdf('rg-novo.pdf')],
        ])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('sucesso', 'Recurso enviado.');

        $recurso = InscricaoRecurso::query()->where('inscricao_id', $inscricao->id)->sole();
        $this->assertSame('Reenvio o RG legível.', $recurso->justificativa);
        $this->assertTrue($recurso->enviado_em->equalTo(now()));
        $novo = InscricaoRecursoDocumento::query()->where('inscricao_recurso_id', $recurso->id)->sole();
        $this->assertSame((int) $this->rg->id, (int) $novo->edital_documento_id);
        $this->assertSame('rg-novo.pdf', $novo->arquivo_nome);
        $this->assertStringStartsWith("inscricoes/{$inscricao->id}/recurso/", $novo->arquivo);
        Storage::disk(Inscricao::DISCO)->assertExists($novo->arquivo);
        Storage::disk(Inscricao::DISCO)->assertExists('inscricoes/original.pdf');
        $this->assertSame('inscricoes/original.pdf', $inscricao->documentos()->where('edital_documento_id', $this->rg->id)->value('arquivo'));
        $this->assertSame(SituacaoHomologacao::EmRecurso, $inscricao->fresh()->homologacao);
    }

    public function test_so_justificativa_ou_so_arquivo_basta(): void
    {
        $soJustificativa = $this->naoHomologada();
        $soArquivo = $this->naoHomologada();

        $this->enviar($soJustificativa, ['justificativa' => 'O RG está legível.'])->assertSessionHas('sucesso', 'Recurso enviado.');
        $this->enviar($soArquivo, ['arquivos' => [$this->rg->id => $this->pdf('rg.pdf')]])->assertSessionHas('sucesso', 'Recurso enviado.');

        $this->assertSame(0, InscricaoRecursoDocumento::query()->whereHas('recurso', fn ($query) => $query->where('inscricao_id', $soJustificativa->id))->count());
        $this->assertNull($soArquivo->recurso()->sole()->justificativa);
        $this->assertSame(1, $soArquivo->recurso()->sole()->documentos()->count());
        $this->assertSame(SituacaoHomologacao::EmRecurso, $soJustificativa->fresh()->homologacao);
        $this->assertSame(SituacaoHomologacao::EmRecurso, $soArquivo->fresh()->homologacao);
    }

    public function test_sem_justificativa_nem_arquivo_e_recusado_e_espacos_nao_contam(): void
    {
        $inscricao = $this->naoHomologada();

        $this->enviar($inscricao, [])->assertSessionHasErrors(['justificativa' => EnviarRecursoRequest::ERRO_VAZIO]);
        $this->enviar($inscricao, ['justificativa' => '   '])->assertSessionHasErrors(['justificativa' => EnviarRecursoRequest::ERRO_VAZIO]);

        $this->assertSame(0, InscricaoRecurso::query()->count());
        $this->assertSame(SituacaoHomologacao::NaoHomologada, $inscricao->fresh()->homologacao);
    }

    public function test_quebra_de_linha_da_justificativa_conta_uma_vez(): void
    {
        $inscricao = $this->naoHomologada();
        // 4000 caracteres contando cada quebra uma vez, enviados com CRLF como no multipart do navegador.
        $linhas = array_fill(0, 10, str_repeat('a', 390));
        $texto = implode("\n", $linhas).str_repeat('b', 4000 - (10 * 390 + 9));

        $this->enviar($inscricao, ['justificativa' => str_replace("\n", "\r\n", $texto)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sucesso', 'Recurso enviado.');

        $this->assertSame($texto, $inscricao->recurso()->sole()->justificativa);
    }

    public function test_arquivo_para_documento_sem_problema_e_recusado_sem_sobrar_arquivo(): void
    {
        $inscricao = $this->naoHomologada();
        $deOutroEdital = EditalDocumento::factory()->create();

        $this->enviar($inscricao, [
            'justificativa' => 'Troco o comprovante.',
            'arquivos' => [$this->rg->id => $this->pdf('rg.pdf'), $this->comprovante->id => $this->pdf('comprovante.pdf')],
        ])->assertSessionHasErrors(['arquivos' => EnviarRecursoRequest::ERRO_DOCUMENTO]);
        $this->enviar($inscricao, [
            'justificativa' => 'Troco um documento de outro edital.',
            'arquivos' => [$deOutroEdital->id => $this->pdf('outro.pdf')],
        ])->assertSessionHasErrors(['arquivos' => EnviarRecursoRequest::ERRO_DOCUMENTO]);

        $doRecurso = array_filter(Storage::disk(Inscricao::DISCO)->allFiles(), fn (string $arquivo): bool => Str::contains($arquivo, 'recurso/'));
        $this->assertSame([], array_values($doRecurso));
        $this->assertSame(0, InscricaoRecurso::query()->count());
        $this->assertSame(SituacaoHomologacao::NaoHomologada, $inscricao->fresh()->homologacao);
    }

    public function test_recurso_no_ultimo_minuto_entra_e_no_dia_seguinte_e_recusado(): void
    {
        $atrasado = $this->naoHomologada();
        $noLimite = $this->naoHomologada();

        $this->travelTo(Carbon::parse('2026-12-14 00:00', 'America/Bahia'));
        $this->get("/portal/inscricoes/{$atrasado->id}/recurso")
            ->assertRedirect(route('portal.inscricoes.show', $atrasado))
            ->assertSessionHas('aviso', InscricaoRecursoController::ERRO_FORA_DO_PRAZO);
        $this->enviar($atrasado, ['justificativa' => 'Atrasado.'])
            ->assertRedirect(route('portal.inscricoes.show', $atrasado))
            ->assertSessionHas('aviso', InscricaoRecursoController::ERRO_FORA_DO_PRAZO);

        $this->travelTo(Carbon::parse('2026-12-13 23:59', 'America/Bahia'));
        $this->enviar($noLimite, ['justificativa' => 'No último minuto.'])->assertSessionHas('sucesso', 'Recurso enviado.');

        $this->assertFalse($atrasado->recurso()->exists());
        $this->assertSame(SituacaoHomologacao::NaoHomologada, $atrasado->fresh()->homologacao);
        $this->assertSame(SituacaoHomologacao::EmRecurso, $noLimite->fresh()->homologacao);
    }

    public function test_sem_fim_da_homologacao_o_prazo_conta_da_decisao(): void
    {
        $chamadaSemPeriodo = ['homologacao_inicio' => null, 'homologacao_fim' => null];
        $atrasado = $this->naoHomologada($chamadaSemPeriodo);
        $noPrazo = $this->naoHomologada($chamadaSemPeriodo);

        $this->travelTo(Carbon::parse('2026-12-09 00:00', 'America/Bahia'));
        $this->enviar($atrasado, ['justificativa' => 'Atrasado.'])
            ->assertSessionHas('aviso', InscricaoRecursoController::ERRO_FORA_DO_PRAZO);

        $this->travelTo(Carbon::parse('2026-12-08 23:59', 'America/Bahia'));
        $this->enviar($noPrazo, ['justificativa' => 'No prazo.'])->assertSessionHas('sucesso', 'Recurso enviado.');

        $this->assertFalse($atrasado->recurso()->exists());
        $this->assertTrue($noPrazo->recurso()->exists());
    }

    public function test_so_um_recurso(): void
    {
        $inscricao = $this->naoHomologada();

        $this->enviar($inscricao, ['justificativa' => 'Primeiro.'])->assertSessionHas('sucesso', 'Recurso enviado.');
        $this->enviar($inscricao, ['justificativa' => 'Segundo.'])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('aviso', InscricaoRecursoController::ERRO_SEM_RECURSO);
        $this->get("/portal/inscricoes/{$inscricao->id}/recurso")
            ->assertSessionHas('aviso', InscricaoRecursoController::ERRO_SEM_RECURSO);

        $this->assertSame('Primeiro.', $inscricao->recurso()->sole()->justificativa);
    }

    public function test_tipo_e_tamanho_do_arquivo_como_no_envio_normal(): void
    {
        $inscricao = $this->naoHomologada();
        $chave = "arquivos.{$this->rg->id}";

        $this->enviar($inscricao, ['arquivos' => [$this->rg->id => UploadedFile::fake()->create('rg.docx', 10)]])
            ->assertSessionHasErrors([$chave => InscricaoDocumentoController::ERRO_ARQUIVO]);
        $this->enviar($inscricao, ['arquivos' => [$this->rg->id => UploadedFile::fake()->create('rg.pdf', 11 * 1024, 'application/pdf')]])
            ->assertSessionHasErrors([$chave => InscricaoDocumentoController::ERRO_ARQUIVO]);

        $this->assertSame(0, InscricaoRecurso::query()->count());
        $this->assertSame(['inscricoes/original.pdf'], Storage::disk(Inscricao::DISCO)->allFiles());
    }

    public function test_outro_usuario_recebe_404(): void
    {
        $inscricao = $this->naoHomologada();
        $this->actingAs(User::factory()->proponente()->comPerfil()->create());

        $this->get("/portal/inscricoes/{$inscricao->id}/recurso")->assertNotFound();
        $this->enviar($inscricao, ['justificativa' => 'Não é minha.'])->assertNotFound();

        $this->assertSame(0, InscricaoRecurso::query()->count());
    }

    /** @param array<string, mixed> $dados */
    private function enviar(Inscricao $inscricao, array $dados): TestResponse
    {
        return $this->post("/portal/inscricoes/{$inscricao->id}/recurso", $dados);
    }

    /**
     * Inscrição do usuário não homologada em 05/12/2026, com o RG (arquivo original no disco) marcado com problema e o
     * comprovante ok.
     *
     * @param  array<string, mixed>  $chamada  Atributos da chamada a trocar.
     */
    private function naoHomologada(array $chamada = []): Inscricao
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao($chamada), $this->user);
        Storage::disk(Inscricao::DISCO)->put('inscricoes/original.pdf', 'original');
        $this->rg = $this->documentoEnviado($inscricao, 'RG');
        $this->comprovante = $this->documentoEnviado($inscricao, 'Comprovante');
        $this->marcar($inscricao, $this->rg, MarcacaoDoItem::Problema, 'Ilegível.');
        $this->marcar($inscricao, $this->comprovante, MarcacaoDoItem::Ok);

        $inscricao->update([
            'homologacao' => SituacaoHomologacao::NaoHomologada,
            'homologacao_motivo' => 'Documentos: RG — Ilegível.',
            'homologada_em' => Carbon::parse('2026-12-05 10:00', 'America/Bahia'),
            'homologada_por' => User::factory()->gestor()->create()->id,
        ]);

        return $inscricao;
    }

    private function pdf(string $nome): UploadedFile
    {
        return UploadedFile::fake()->create($nome, 10, 'application/pdf');
    }
}
