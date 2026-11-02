<?php

namespace Tests\Feature\Portal;

use App\Enums\ChecagemDocumento;
use App\Enums\ProvedorIa;
use App\Enums\SituacaoConferencia;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\InscricaoController;
use App\Http\Controllers\InscricaoDocumentoController;
use App\Http\Controllers\PortalController;
use App\Models\ConfiguracaoIa;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalChamada;
use App\Models\EditalDocumento;
use App\Models\Inscricao;
use App\Models\InscricaoDocumento;
use App\Models\User;
use App\Support\DocumentoIa\LancadorDeConferencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class InscricaoDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private const string CPF = '52998224725';

    private const string CONTEUDO_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** Só o começo de cada imagem: é por ele que o tipo do arquivo é reconhecido. */
    private const string CONTEUDO_JPG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    private const string CONTEUDO_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00";

    private const string ERRO_ARQUIVO = 'Envie um PDF ou uma imagem (JPG ou PNG) de até 10 MB.';

    /** Lançador que só registra as conferências pedidas (a conferência em si é testada em ConferenciaDocumentoTest). */
    private LancadorDeConferencia $lancador;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));

        // Validação por IA ligada (a chave da Integração com IA); os testes com ela desligada a desligam.
        ConfiguracaoIa::factory()->create();

        $this->lancador = new class extends LancadorDeConferencia
        {
            /** @var list<array{int, string}> */
            public array $chamadas = [];

            public ?RuntimeException $falha = null;

            public function lancar(InscricaoDocumento $documento): void
            {
                if ($this->falha !== null) {
                    throw $this->falha;
                }

                $this->chamadas[] = [(int) $documento->getKey(), (string) $documento->execucao];
            }
        };
        $this->app->instance(LancadorDeConferencia::class, $this->lancador);
    }

    public function test_anexa_pdf_e_lanca_a_conferencia(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa);
        $inscricao = $this->rascunho($edital, $user);

        $this->actingAs($user)->post($this->urlDoDocumento($inscricao, $exigido), ['arquivo' => $this->arquivo('rg.pdf')])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('sucesso');

        $enviado = InscricaoDocumento::sole();
        $this->assertSame((int) $inscricao->id, (int) $enviado->inscricao_id);
        $this->assertSame((int) $exigido->id, (int) $enviado->edital_documento_id);
        $this->assertSame('rg.pdf', $enviado->arquivo_nome);
        $this->assertStringStartsWith("inscricoes/{$inscricao->id}/", $enviado->arquivo);
        $this->assertSame(self::CONTEUDO_PDF, Storage::disk('local')->get($enviado->arquivo));
        $this->assertSame(SituacaoConferencia::Conferindo, $enviado->situacao);
        $this->assertNotEmpty($enviado->execucao);
        $this->assertTrue(now()->equalTo($enviado->iniciada_em));
        $this->assertNull($enviado->motivo);
        $this->assertNull($enviado->erro);
        $this->assertSame([[(int) $enviado->id, $enviado->execucao]], $this->lancador->chamadas);
    }

    public function test_anexa_imagem_jpg_ou_png_e_lanca_a_conferencia(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $identidade = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa);
        $comprovante = $this->documentoExigido($edital, ChecagemDocumento::AceitaSemValidar);
        $inscricao = $this->rascunho($edital, $user);

        $this->actingAs($user);
        $this->post($this->urlDoDocumento($inscricao, $identidade), ['arquivo' => $this->arquivo('rg.jpg', self::CONTEUDO_JPG)])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('sucesso');
        $this->post($this->urlDoDocumento($inscricao, $comprovante), ['arquivo' => $this->arquivo('comprovante.png', self::CONTEUDO_PNG)])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('sucesso');

        $jpg = InscricaoDocumento::query()->where('edital_documento_id', $identidade->id)->sole();
        $png = InscricaoDocumento::query()->where('edital_documento_id', $comprovante->id)->sole();
        $this->assertSame('rg.jpg', $jpg->arquivo_nome);
        $this->assertSame(self::CONTEUDO_JPG, Storage::disk('local')->get($jpg->arquivo));
        $this->assertSame('comprovante.png', $png->arquivo_nome);
        $this->assertSame(self::CONTEUDO_PNG, Storage::disk('local')->get($png->arquivo));
        $this->assertSame(SituacaoConferencia::Conferindo, $jpg->situacao);
        $this->assertSame(SituacaoConferencia::Conferindo, $png->situacao);
        $this->assertSame([[(int) $jpg->id, $jpg->execucao], [(int) $png->id, $png->execucao]], $this->lancador->chamadas);
    }

    public function test_com_a_validacao_por_ia_desligada_o_documento_fica_enviado_sem_lancar(): void
    {
        ConfiguracaoIa::query()->update(['ativo' => false]);
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa);
        $inscricao = $this->rascunho($edital, $user);

        $this->actingAs($user)->post($this->urlDoDocumento($inscricao, $exigido), ['arquivo' => $this->arquivo('rg.pdf')])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao));

        $enviado = InscricaoDocumento::sole();
        $this->assertSame(SituacaoConferencia::Enviado, $enviado->situacao);
        $this->assertNull($enviado->execucao);
        $this->assertSame([], $this->lancador->chamadas);
        // A tela trata o documento como "Não validar" enquanto a chave estiver desligada.
        $this->get(route('portal.inscricoes.show', $inscricao))
            ->assertInertia(fn (Assert $p) => $p->where('documentos.0.checagem', ChecagemDocumento::Nenhuma->value));
    }

    public function test_documento_sem_checagem_fica_enviado_sem_lancar(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Nenhuma);
        $inscricao = $this->rascunho($edital, $user);

        $this->actingAs($user)->post($this->urlDoDocumento($inscricao, $exigido), ['arquivo' => $this->arquivo('rg.pdf')])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao));

        $enviado = InscricaoDocumento::sole();
        $this->assertSame(SituacaoConferencia::Enviado, $enviado->situacao);
        $this->assertNull($enviado->execucao);
        $this->assertNull($enviado->iniciada_em);
        $this->assertTrue(Storage::disk('local')->exists($enviado->arquivo));
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_anexar_de_novo_substitui_o_arquivo_e_reconfere(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa);
        $inscricao = $this->rascunho($edital, $user);
        Storage::disk('local')->put('inscricoes/antigo.pdf', 'velho');
        $anterior = InscricaoDocumento::factory()->for($inscricao)->for($exigido, 'documento')->create([
            'arquivo' => 'inscricoes/antigo.pdf',
            'situacao' => SituacaoConferencia::Reprovado,
            'motivo' => 'O arquivo está ilegível.',
            'erro' => 'detalhe técnico',
            'provedor' => ProvedorIa::Gemini,
            'modelo' => 'gemini-2.5-pro',
            'execucao' => 'execucao-antiga',
            'iniciada_em' => now()->subMinute(),
            'conferido_em' => now(),
        ]);

        $this->actingAs($user)->post($this->urlDoDocumento($inscricao, $exigido), ['arquivo' => $this->arquivo('novo.pdf', self::CONTEUDO_PDF."%novo\n")])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao));

        $enviado = InscricaoDocumento::sole();
        $this->assertSame((int) $anterior->id, (int) $enviado->id);
        $this->assertSame('novo.pdf', $enviado->arquivo_nome);
        $this->assertNotSame('inscricoes/antigo.pdf', $enviado->arquivo);
        $this->assertSame(self::CONTEUDO_PDF."%novo\n", Storage::disk('local')->get($enviado->arquivo));
        $this->assertSame([$enviado->arquivo], Storage::disk('local')->allFiles());
        $this->assertSame(SituacaoConferencia::Conferindo, $enviado->situacao);
        $this->assertNotSame('execucao-antiga', $enviado->execucao);
        $this->assertTrue(now()->equalTo($enviado->iniciada_em));
        $this->assertNull($enviado->motivo);
        $this->assertNull($enviado->erro);
        $this->assertNull($enviado->provedor);
        $this->assertNull($enviado->modelo);
        $this->assertNull($enviado->conferido_em);
        $this->assertSame([[(int) $enviado->id, $enviado->execucao]], $this->lancador->chamadas);
    }

    public function test_arquivo_que_nao_e_pdf_nem_imagem_jpg_ou_png_ou_acima_de_10mb_e_recusado(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa);
        $inscricao = $this->rascunho($edital, $user);
        $url = $this->urlDoDocumento($inscricao, $exigido);
        $acimaDoLimite = self::CONTEUDO_PDF.str_repeat('0', 10 * 1024 * 1024);

        $this->actingAs($user);

        $this->post($url, [])->assertSessionHasErrors(['arquivo' => self::ERRO_ARQUIVO]);
        $this->post($url, ['arquivo' => $this->arquivo('rg.pdf', 'isto não é um pdf')])->assertSessionHasErrors(['arquivo' => self::ERRO_ARQUIVO]);
        $this->post($url, ['arquivo' => $this->arquivo('rg.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00")])->assertSessionHasErrors(['arquivo' => self::ERRO_ARQUIVO]);
        $this->post($url, ['arquivo' => $this->arquivo('rg.pdf', $acimaDoLimite)])->assertSessionHasErrors(['arquivo' => self::ERRO_ARQUIVO]);

        $this->assertSame(0, InscricaoDocumento::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_documento_fora_da_lista_da_404(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $categoria = EditalCategoria::factory()->for($edital)->create();
        $inscricao = $this->rascunho($edital, $user);
        $deOutraCategoria = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa, ['todas_categorias' => false]);
        $deOutraCategoria->categorias()->attach($categoria->id);
        $deOutroEdital = $this->documentoExigido(Edital::factory()->create(), ChecagemDocumento::Rigorosa);

        $this->actingAs($user);

        foreach ([$deOutraCategoria->id, $deOutroEdital->id, 999999] as $id) {
            $url = "/portal/inscricoes/{$inscricao->id}/documentos/{$id}";

            $this->post($url, ['arquivo' => $this->arquivo('rg.pdf')])->assertNotFound();
            $this->delete($url)->assertNotFound();
            $this->get("{$url}/arquivo")->assertNotFound();
        }
        $this->post("/portal/inscricoes/{$inscricao->id}/documentos/abc", ['arquivo' => $this->arquivo('rg.pdf')])->assertNotFound();

        $this->assertSame(0, InscricaoDocumento::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_obrigatorio_nao_pode_ser_removido_e_opcional_pode(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $obrigatorio = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa, ['obrigatorio' => true]);
        $opcional = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa, ['obrigatorio' => false]);
        $inscricao = $this->rascunho($edital, $user);
        $doObrigatorio = $this->documentoEnviado($inscricao, $obrigatorio, 'inscricoes/obrigatorio.pdf');
        $doOpcional = $this->documentoEnviado($inscricao, $opcional, 'inscricoes/opcional.pdf');
        Storage::disk('local')->put('inscricoes/obrigatorio.pdf', self::CONTEUDO_PDF);
        Storage::disk('local')->put('inscricoes/opcional.pdf', self::CONTEUDO_PDF);

        $this->actingAs($user);

        $this->delete($this->urlDoDocumento($inscricao, $obrigatorio))
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('erro', InscricaoDocumentoController::ERRO_OBRIGATORIO);
        $this->assertTrue(InscricaoDocumento::whereKey($doObrigatorio->id)->exists());
        $this->assertTrue(Storage::disk('local')->exists('inscricoes/obrigatorio.pdf'));

        $this->delete($this->urlDoDocumento($inscricao, $opcional))
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('sucesso');
        $this->assertFalse(InscricaoDocumento::whereKey($doOpcional->id)->exists());
        $this->assertFalse(Storage::disk('local')->exists('inscricoes/opcional.pdf'));

        // Remover de novo (clique repetido) não é erro.
        $this->delete($this->urlDoDocumento($inscricao, $opcional))->assertRedirect(route('portal.inscricoes.show', $inscricao));
        $this->assertSame(1, InscricaoDocumento::count());
    }

    public function test_dono_baixa_o_arquivo_com_o_nome_original(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa);
        $inscricao = $this->rascunho($edital, $user);
        Storage::disk('local')->put('inscricoes/rg.pdf', self::CONTEUDO_PDF);
        $this->documentoEnviado($inscricao, $exigido, 'inscricoes/rg.pdf', 'RG frente.pdf');

        $resposta = $this->actingAs($user)->get(route('portal.inscricoes.documentos.arquivo', [$inscricao, $exigido]));

        $resposta->assertOk()->assertDownload('RG frente.pdf');
        $this->assertSame(self::CONTEUDO_PDF, $resposta->streamedContent());
    }

    public function test_outro_usuario_nao_baixa_o_arquivo(): void
    {
        $dono = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $outro = User::factory()->proponente()->comPerfil()->create();
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa);
        $inscricao = $this->rascunho($edital, $dono);
        Storage::disk('local')->put('inscricoes/rg.pdf', self::CONTEUDO_PDF);
        $this->documentoEnviado($inscricao, $exigido, 'inscricoes/rg.pdf');

        $this->actingAs($outro)->get(route('portal.inscricoes.documentos.arquivo', [$inscricao, $exigido]))->assertNotFound();
    }

    public function test_so_o_dono_anexa_e_remove(): void
    {
        $dono = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $outro = User::factory()->proponente()->comPerfil()->create();
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa, ['obrigatorio' => false]);
        $inscricao = $this->rascunho($edital, $dono);
        $this->documentoEnviado($inscricao, $exigido, 'inscricoes/rg.pdf');
        Storage::disk('local')->put('inscricoes/rg.pdf', self::CONTEUDO_PDF);
        $url = $this->urlDoDocumento($inscricao, $exigido);

        $this->actingAs($outro);
        $this->post($url, ['arquivo' => $this->arquivo('rg.pdf')])->assertNotFound();
        $this->delete($url)->assertNotFound();

        $this->assertSame(['inscricoes/rg.pdf'], Storage::disk('local')->allFiles());
        $this->assertSame(1, InscricaoDocumento::count());
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_inscricao_finalizada_nao_recebe_nem_perde_documento(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa, ['obrigatorio' => false]);
        $inscricao = $this->rascunho($edital, $user, ['situacao' => SituacaoInscricao::Finalizada]);
        Storage::disk('local')->put('inscricoes/rg.pdf', self::CONTEUDO_PDF);
        $this->documentoEnviado($inscricao, $exigido, 'inscricoes/rg.pdf');
        $url = $this->urlDoDocumento($inscricao, $exigido);

        $this->actingAs($user);
        $this->post($url, ['arquivo' => $this->arquivo('novo.pdf')])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('erro', InscricaoController::ERRO_REABRIR);
        $this->delete($url)
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('erro', InscricaoController::ERRO_REABRIR);

        $this->assertSame(['inscricoes/rg.pdf'], Storage::disk('local')->allFiles());
        $this->assertSame('inscricoes/rg.pdf', InscricaoDocumento::sole()->arquivo);
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_fora_do_periodo_nao_recebe_nem_perde_documento(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $encerrado = $this->editalAberto(['slug' => 'encerrado'], '2026-10-01', '2026-11-09');
        $exigido = $this->documentoExigido($encerrado, ChecagemDocumento::Rigorosa, ['obrigatorio' => false]);
        $inscricao = $this->rascunho($encerrado, $user);
        Storage::disk('local')->put('inscricoes/rg.pdf', self::CONTEUDO_PDF);
        $this->documentoEnviado($inscricao, $exigido, 'inscricoes/rg.pdf');
        $url = $this->urlDoDocumento($inscricao, $exigido);

        $this->actingAs($user);
        $this->post($url, ['arquivo' => $this->arquivo('novo.pdf')])
            ->assertRedirect(route('portal.editais'))
            ->assertSessionHas('aviso', PortalController::AVISO_INSCRICOES_FECHADAS);
        $this->delete($url)
            ->assertRedirect(route('portal.editais'))
            ->assertSessionHas('aviso', PortalController::AVISO_INSCRICOES_FECHADAS);

        $this->assertSame(['inscricoes/rg.pdf'], Storage::disk('local')->allFiles());
        $this->assertSame(1, InscricaoDocumento::count());
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_falha_ao_lancar_deixa_o_documento_nao_conferido_sem_detalhe_tecnico(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF]);
        $edital = $this->editalAberto();
        $exigido = $this->documentoExigido($edital, ChecagemDocumento::Rigorosa);
        $inscricao = $this->rascunho($edital, $user);
        $this->lancador->falha = new RuntimeException('sh: php: not found com segredo-da-chave');

        $this->actingAs($user)->post($this->urlDoDocumento($inscricao, $exigido), ['arquivo' => $this->arquivo('rg.pdf')])
            ->assertRedirect(route('portal.inscricoes.show', $inscricao))
            ->assertSessionHas('erro', InscricaoDocumentoController::ERRO_AO_INICIAR);

        $enviado = InscricaoDocumento::sole();
        $this->assertSame(SituacaoConferencia::NaoConferido, $enviado->situacao);
        $this->assertSame(InscricaoDocumentoController::NAO_CONFERIDO, $enviado->motivo);
        $this->assertNotEmpty($enviado->erro);
        $this->assertStringNotContainsString('segredo', (string) $enviado->erro);
        $this->assertTrue(Storage::disk('local')->exists($enviado->arquivo));
    }

    public function test_lancador_roda_o_comando_de_conferencia_em_segundo_plano(): void
    {
        Process::fake();
        $documento = new InscricaoDocumento(['execucao' => 'abc-123']);
        $documento->id = 42;

        (new LancadorDeConferencia)->lancar($documento);

        Process::assertRan(fn (PendingProcess $processo): bool => str_contains($processo->command, 'inscricoes:conferir 42 '.escapeshellarg('abc-123')));
    }

    private function arquivo(string $nome, string $conteudo = self::CONTEUDO_PDF): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function urlDoDocumento(Inscricao $inscricao, EditalDocumento $documento): string
    {
        return route('portal.inscricoes.documentos.store', [$inscricao, $documento]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function documentoExigido(Edital $edital, ChecagemDocumento $checagem, array $atributos = []): EditalDocumento
    {
        return EditalDocumento::factory()->for($edital)->create(['checagem' => $checagem, ...$atributos]);
    }

    private function documentoEnviado(Inscricao $inscricao, EditalDocumento $exigido, string $arquivo, string $nome = 'documento.pdf'): InscricaoDocumento
    {
        return InscricaoDocumento::factory()->for($inscricao)->for($exigido, 'documento')->create([
            'arquivo' => $arquivo,
            'arquivo_nome' => $nome,
        ]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function editalAberto(array $atributos = [], string $inicio = '2026-11-01', string $fim = '2026-11-15'): Edital
    {
        $edital = Edital::factory()->create([
            'slug' => 'pnab', 'inscricoes_inicio' => $inicio, 'inscricoes_fim' => $fim, ...$atributos,
        ]);
        EditalChamada::factory()->for($edital)->create(['inicio' => $inicio, 'fim' => $fim, 'ordem' => 1]);

        return $edital;
    }

    /**
     * Rascunho da pessoa física do usuário, na primeira chamada do edital.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function rascunho(Edital $edital, User $user, array $atributos = []): Inscricao
    {
        return Inscricao::factory()->for($edital)->for($user)->create([
            'edital_chamada_id' => $edital->chamadas()->first()->id,
            'documento_cadastro' => $user->cpf,
            ...$atributos,
        ]);
    }
}
