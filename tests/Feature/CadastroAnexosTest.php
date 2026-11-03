<?php

namespace Tests\Feature;

use App\Enums\AnexoCadastro;
use App\Enums\ProvedorIa;
use App\Enums\SituacaoConferencia;
use App\Http\Controllers\CadastroAnexoController;
use App\Http\Controllers\InscricaoDocumentoController;
use App\Http\Middleware\BloquearGestorNoCadastro;
use App\Http\Middleware\ExigirPessoaFisica;
use App\Http\Requests\EnviarAnexoRequest;
use App\Models\ConfiguracaoAnexo;
use App\Models\ConfiguracaoIa;
use App\Models\PessoaFisica;
use App\Models\PessoaFisicaAnexo;
use App\Models\User;
use App\Support\DocumentoIa\LancadorDeConferenciaDoAnexo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class CadastroAnexosTest extends TestCase
{
    use RefreshDatabase;

    private const string CONTEUDO_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** Só o começo de cada imagem: é por ele que o tipo do arquivo é reconhecido. */
    private const string CONTEUDO_JPG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    private const string CONTEUDO_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00";

    private const string CONTEUDO_GIF = "GIF89a\x01\x00\x01\x00\x00\x00\x00";

    /** Lançador que só registra as conferências pedidas (a conferência em si é testada em ConferenciaAnexoTest). */
    private LancadorDeConferenciaDoAnexo $lancador;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));

        // Validação por IA ligada (a chave da Integração com IA); cada campo continua dispensado até o teste pedir.
        ConfiguracaoIa::factory()->create();

        $this->lancador = new class extends LancadorDeConferenciaDoAnexo
        {
            /** @var list<array{int, string}> */
            public array $chamadas = [];

            public ?RuntimeException $falha = null;

            public function lancar(PessoaFisicaAnexo $anexo): void
            {
                if ($this->falha !== null) {
                    throw $this->falha;
                }

                $this->chamadas[] = [(int) $anexo->getKey(), (string) $anexo->execucao];
            }
        };
        $this->app->instance(LancadorDeConferenciaDoAnexo::class, $this->lancador);
    }

    public function test_etapa_exige_login(): void
    {
        $this->get('/cadastro/anexos')->assertRedirect('/');
        $this->post('/cadastro/anexos/documento_cpf', ['arquivo' => $this->arquivo('cpf.pdf')])->assertRedirect('/');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_etapa_bloqueia_usuario_da_retaguarda(): void
    {
        $gestor = User::factory()->gestor()->create();

        $this->actingAs($gestor)->get('/cadastro/anexos')
            ->assertRedirect('/')
            ->assertSessionHas('erro', BloquearGestorNoCadastro::ERRO);
    }

    public function test_etapa_sem_meus_dados_salvos_volta_para_o_cadastro(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->get('/cadastro/anexos')
            ->assertRedirect(route('cadastro'))
            ->assertSessionHas('aviso', ExigirPessoaFisica::AVISO);
        $this->post('/cadastro/anexos/documento_cpf', ['arquivo' => $this->arquivo('cpf.pdf')])
            ->assertRedirect(route('cadastro'));

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, PessoaFisicaAnexo::count());
    }

    public function test_etapa_traz_os_sete_anexos_na_ordem_e_as_pendencias_do_cpf_e_do_rg(): void
    {
        $user = $this->pessoaNosAnexos();

        $this->actingAs($user)->get('/cadastro/anexos')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Cadastro/Anexos')
                ->where('anexos', fn ($anexos): bool => collect($anexos)->pluck('campo')->all() === [
                    'foto_perfil', 'documento_cpf', 'documento_rg', 'documento_passaporte',
                    'documento_crnm', 'documento_outros', 'comprovante_residencia',
                ])
                ->where('anexos.0', [
                    'campo' => 'foto_perfil', 'rotulo' => 'Foto de perfil', 'obrigatorio' => false,
                    'somenteImagem' => true, 'conferidoPelaIa' => false, 'enviado' => null,
                ])
                ->where('anexos.1', [
                    'campo' => 'documento_cpf', 'rotulo' => 'Cópia do CPF', 'obrigatorio' => true,
                    'somenteImagem' => false, 'conferidoPelaIa' => false, 'enviado' => null,
                ])
                ->where('anexos.2.obrigatorio', true)
                ->where('anexos.6.obrigatorio', false)
                ->where('pendencias', [
                    ['campo' => 'documento_cpf', 'texto' => 'Anexe "Cópia do CPF".'],
                    ['campo' => 'documento_rg', 'texto' => 'Anexe "Cópia do RG".'],
                ])
                ->where('podeContinuar', false));
    }

    public function test_anexo_enviado_traz_nome_url_e_imagem_sem_o_caminho_no_disco_nem_o_erro(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->anexo($user, AnexoCadastro::FotoPerfil, "cadastros/{$user->id}/foto.png", ['arquivo_nome' => 'eu.png']);
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf", [
            'arquivo_nome' => 'cpf.pdf',
            'situacao' => SituacaoConferencia::Reprovado,
            'motivo' => 'O arquivo é de outra pessoa.',
            'erro' => 'detalhe técnico',
        ]);

        $resposta = $this->actingAs($user)->get('/cadastro/anexos');

        $resposta->assertInertia(fn (Assert $p) => $p
            ->where('anexos.0.enviado', [
                'nome' => 'eu.png',
                'url' => route('cadastro.anexos.arquivo', ['campo' => 'foto_perfil', 'v' => substr(md5("cadastros/{$user->id}/foto.png"), 0, 8)]),
                'imagem' => true,
                'situacao' => 'enviado',
                'motivo' => null,
            ])
            ->where('anexos.1.enviado', [
                'nome' => 'cpf.pdf',
                'url' => route('cadastro.anexos.arquivo', ['campo' => 'documento_cpf', 'v' => substr(md5("cadastros/{$user->id}/cpf.pdf"), 0, 8)]),
                'imagem' => false,
                'situacao' => 'reprovado',
                'motivo' => 'O arquivo é de outra pessoa.',
            ]));
        $this->assertStringNotContainsString("cadastros/{$user->id}/", $resposta->getContent());
        $this->assertStringNotContainsString('detalhe técnico', $resposta->getContent());
    }

    public function test_conferido_pela_ia_segue_a_parametrizacao_do_campo_e_a_chave_geral(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoRg);

        $this->actingAs($user)->get('/cadastro/anexos')->assertInertia(fn (Assert $p) => $p
            ->where('anexos.1.conferidoPelaIa', false)
            ->where('anexos.2.conferidoPelaIa', true));

        ConfiguracaoIa::query()->update(['ativo' => false]);

        $this->get('/cadastro/anexos')->assertInertia(fn (Assert $p) => $p->where('anexos.2.conferidoPelaIa', false));
    }

    public function test_anexa_pdf_e_lanca_a_conferencia_quando_a_ia_confere_o_campo(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoRg);

        $this->actingAs($user)->post($this->url(AnexoCadastro::DocumentoRg), ['arquivo' => $this->arquivo('rg.pdf')])
            ->assertRedirect(route('cadastro.anexos'))
            ->assertSessionHas('sucesso', 'Arquivo enviado.');

        $enviado = PessoaFisicaAnexo::sole();
        $this->assertSame((int) $user->pessoaFisica->id, (int) $enviado->pessoa_fisica_id);
        $this->assertSame(AnexoCadastro::DocumentoRg, $enviado->campo);
        $this->assertSame('rg.pdf', $enviado->arquivo_nome);
        $this->assertStringStartsWith("cadastros/{$user->id}/", $enviado->arquivo);
        $this->assertSame(self::CONTEUDO_PDF, Storage::disk('local')->get($enviado->arquivo));
        $this->assertSame(SituacaoConferencia::Conferindo, $enviado->situacao);
        $this->assertNotEmpty($enviado->execucao);
        $this->assertTrue(now()->equalTo($enviado->iniciada_em));
        $this->assertSame([[(int) $enviado->id, $enviado->execucao]], $this->lancador->chamadas);
    }

    public function test_anexo_com_a_conferencia_dispensada_fica_enviado_sem_lancar(): void
    {
        $user = $this->pessoaNosAnexos();

        $this->actingAs($user)->post($this->url(AnexoCadastro::DocumentoCpf), ['arquivo' => $this->arquivo('cpf.pdf')])
            ->assertRedirect(route('cadastro.anexos'))
            ->assertSessionHas('sucesso', 'Arquivo enviado.');

        $enviado = PessoaFisicaAnexo::sole();
        $this->assertSame(SituacaoConferencia::Enviado, $enviado->situacao);
        $this->assertNull($enviado->execucao);
        $this->assertNull($enviado->iniciada_em);
        $this->assertTrue(Storage::disk('local')->exists($enviado->arquivo));
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_com_a_validacao_por_ia_desligada_o_anexo_fica_enviado_sem_lancar(): void
    {
        ConfiguracaoIa::query()->update(['ativo' => false]);
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoCpf);

        $this->actingAs($user)->post($this->url(AnexoCadastro::DocumentoCpf), ['arquivo' => $this->arquivo('cpf.pdf')])
            ->assertRedirect(route('cadastro.anexos'));

        $enviado = PessoaFisicaAnexo::sole();
        $this->assertSame(SituacaoConferencia::Enviado, $enviado->situacao);
        $this->assertNull($enviado->execucao);
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_foto_de_perfil_aceita_so_imagem_jpg_ou_png(): void
    {
        $user = $this->pessoaNosAnexos();
        $url = $this->url(AnexoCadastro::FotoPerfil);
        $this->actingAs($user);

        $this->post($url, ['arquivo' => $this->arquivo('eu.gif', self::CONTEUDO_GIF)])
            ->assertSessionHasErrors(['arquivo' => EnviarAnexoRequest::ERRO_IMAGEM]);
        $this->post($url, ['arquivo' => $this->arquivo('eu.pdf')])
            ->assertSessionHasErrors(['arquivo' => EnviarAnexoRequest::ERRO_IMAGEM]);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->post($url, ['arquivo' => $this->arquivo('eu.jpg', self::CONTEUDO_JPG)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('cadastro.anexos'));
        $this->assertSame('eu.jpg', PessoaFisicaAnexo::sole()->arquivo_nome);

        $this->post($url, ['arquivo' => $this->arquivo('eu.png', self::CONTEUDO_PNG)])->assertSessionHasNoErrors();
        $this->assertSame('eu.png', PessoaFisicaAnexo::sole()->arquivo_nome);
    }

    public function test_documento_aceita_imagem_jpg_ou_png(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->actingAs($user);

        $this->post($this->url(AnexoCadastro::DocumentoCpf), ['arquivo' => $this->arquivo('cpf.jpg', self::CONTEUDO_JPG)])
            ->assertSessionHasNoErrors();
        $this->post($this->url(AnexoCadastro::DocumentoRg), ['arquivo' => $this->arquivo('rg.png', self::CONTEUDO_PNG)])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PessoaFisicaAnexo::count());
    }

    public function test_documento_recusa_o_que_nao_e_pdf_jpg_ou_png_de_ate_2mb(): void
    {
        $user = $this->pessoaNosAnexos();
        $url = $this->url(AnexoCadastro::DocumentoCpf);
        $this->actingAs($user);

        $this->post($url, [])->assertSessionHasErrors(['arquivo' => EnviarAnexoRequest::ERRO_DOCUMENTO]);
        // A extensão diz PDF, o conteúdo não.
        $this->post($url, ['arquivo' => $this->arquivo('cpf.pdf', 'isto não é um pdf')])->assertSessionHasErrors(['arquivo' => EnviarAnexoRequest::ERRO_DOCUMENTO]);
        $this->post($url, ['arquivo' => $this->arquivo('cpf.gif', self::CONTEUDO_GIF)])->assertSessionHasErrors(['arquivo' => EnviarAnexoRequest::ERRO_DOCUMENTO]);
        $this->post($url, ['arquivo' => $this->arquivo('cpf.docx', "PK\x03\x04")])->assertSessionHasErrors(['arquivo' => EnviarAnexoRequest::ERRO_DOCUMENTO]);
        $this->post($url, ['arquivo' => $this->arquivo('cpf.pdf', self::CONTEUDO_PDF.str_repeat('0', 2 * 1024 * 1024))])
            ->assertSessionHasErrors(['arquivo' => EnviarAnexoRequest::ERRO_DOCUMENTO]);

        $this->assertSame(0, PessoaFisicaAnexo::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_anexar_de_novo_substitui_o_arquivo_e_reconfere(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoRg);
        $anterior = $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/antigo.pdf", [
            'situacao' => SituacaoConferencia::Reprovado,
            'motivo' => 'O arquivo está ilegível.',
            'erro' => 'detalhe técnico',
            'provedor' => ProvedorIa::Gemini,
            'modelo' => 'gemini-2.5-pro',
            'execucao' => 'execucao-antiga',
            'iniciada_em' => now()->subMinute(),
            'conferido_em' => now(),
        ]);

        $this->actingAs($user)->post($this->url(AnexoCadastro::DocumentoRg), ['arquivo' => $this->arquivo('novo.pdf', self::CONTEUDO_PDF."%novo\n")])
            ->assertRedirect(route('cadastro.anexos'));

        $enviado = PessoaFisicaAnexo::sole();
        $this->assertSame((int) $anterior->id, (int) $enviado->id);
        $this->assertSame('novo.pdf', $enviado->arquivo_nome);
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

    public function test_campo_fora_da_lista_da_404(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->actingAs($user);

        $this->post('/cadastro/anexos/rg', ['arquivo' => $this->arquivo('rg.pdf')])->assertNotFound();
        $this->delete('/cadastro/anexos/rg')->assertNotFound();
        $this->get('/cadastro/anexos/rg/arquivo')->assertNotFound();
        $this->post('/cadastro/anexos/rg/conferir')->assertNotFound();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_falha_ao_lancar_deixa_o_anexo_nao_conferido_sem_detalhe_tecnico(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoRg);
        $this->lancador->falha = new RuntimeException('sh: php: not found com segredo-da-chave');

        $this->actingAs($user)->post($this->url(AnexoCadastro::DocumentoRg), ['arquivo' => $this->arquivo('rg.pdf')])
            ->assertRedirect(route('cadastro.anexos'))
            ->assertSessionHas('erro', CadastroAnexoController::ERRO_AO_INICIAR);

        $enviado = PessoaFisicaAnexo::sole();
        $this->assertSame(SituacaoConferencia::NaoConferido, $enviado->situacao);
        $this->assertSame(InscricaoDocumentoController::NAO_CONFERIDO, $enviado->motivo);
        $this->assertNotEmpty($enviado->erro);
        $this->assertStringNotContainsString('segredo', (string) $enviado->erro);
        $this->assertTrue(Storage::disk('local')->exists($enviado->arquivo));
    }

    public function test_qualquer_anexo_pode_ser_removido_e_sem_cpf_a_etapa_volta_a_pedir_o_documento(): void
    {
        $user = $this->pessoaNosAnexos();
        $cpf = $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf", ['situacao' => SituacaoConferencia::Aprovado]);
        $comprovante = $this->anexo($user, AnexoCadastro::ComprovanteResidencia, "cadastros/{$user->id}/comprovante.pdf");
        $this->actingAs($user);

        $this->delete('/cadastro/anexos/documento_cpf')
            ->assertRedirect(route('cadastro.anexos'))
            ->assertSessionHas('sucesso', 'Arquivo removido.');
        $this->assertFalse(PessoaFisicaAnexo::whereKey($cpf->id)->exists());
        $this->assertFalse(Storage::disk('local')->exists($cpf->arquivo));
        $this->assertContains(
            ['campo' => 'documento_cpf', 'texto' => 'Anexe "Cópia do CPF".'],
            $user->pessoaFisica->pendenciasDosAnexos(),
        );

        $this->delete('/cadastro/anexos/comprovante_residencia')
            ->assertRedirect(route('cadastro.anexos'))
            ->assertSessionHas('sucesso', 'Arquivo removido.');
        $this->assertFalse(PessoaFisicaAnexo::whereKey($comprovante->id)->exists());
        $this->assertFalse(Storage::disk('local')->exists($comprovante->arquivo));

        // Remover de novo (clique repetido) não é erro.
        $this->delete('/cadastro/anexos/comprovante_residencia')->assertRedirect(route('cadastro.anexos'));
        $this->assertSame(0, PessoaFisicaAnexo::count());
    }

    public function test_dono_abre_o_arquivo_no_navegador_com_o_nome_original(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf", ['arquivo_nome' => 'RG frente.pdf']);

        $resposta = $this->actingAs($user)->get(route('cadastro.anexos.arquivo', 'documento_rg'));

        $resposta->assertOk()->assertHeader('Content-Disposition', 'inline; filename="RG frente.pdf"');
        $this->assertSame(self::CONTEUDO_PDF, $resposta->streamedContent());
    }

    public function test_quem_nao_enviou_o_arquivo_nao_abre_o_de_outra_pessoa(): void
    {
        $dono = $this->pessoaNosAnexos();
        $this->anexo($dono, AnexoCadastro::DocumentoRg, "cadastros/{$dono->id}/rg.pdf");

        $this->actingAs($this->pessoaNosAnexos())->get(route('cadastro.anexos.arquivo', 'documento_rg'))->assertNotFound();
    }

    public function test_conferir_de_novo_so_para_recusado_ou_nao_conferido(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoRg);
        $url = route('cadastro.anexos.conferir', 'documento_rg');
        $this->actingAs($user);

        // Nada enviado, ainda conferindo ou já aprovado: não há o que refazer.
        $this->post($url)->assertSessionHas('aviso', InscricaoDocumentoController::AVISO_CONFERIR_DE_NOVO);
        foreach (['conferindo', 'aprovado'] as $estado) {
            $anexo = PessoaFisicaAnexo::factory()->for($user->pessoaFisica)->campo(AnexoCadastro::DocumentoRg)->{$estado}()->create(['execucao' => 'execucao-atual']);

            $this->post($url)
                ->assertRedirect(route('cadastro.anexos'))
                ->assertSessionHas('aviso', InscricaoDocumentoController::AVISO_CONFERIR_DE_NOVO);

            $this->assertSame('execucao-atual', $anexo->fresh()->execucao);
            $anexo->delete();
        }
        $this->assertSame([], $this->lancador->chamadas);

        $paradoHaMuito = ['situacao' => SituacaoConferencia::Conferindo, 'iniciada_em' => now()->subMinutes(6)];
        foreach ([['situacao' => SituacaoConferencia::Reprovado], ['situacao' => SituacaoConferencia::NaoConferido], $paradoHaMuito] as $atributos) {
            $anexo = $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf", [
                'motivo' => 'Antigo.',
                'erro' => 'Detalhe antigo.',
                'provedor' => ProvedorIa::Gemini,
                'modelo' => 'gemini-2.5-pro',
                'execucao' => 'execucao-atual',
                'iniciada_em' => now()->subHour(),
                'conferido_em' => now()->subHour(),
                ...$atributos,
            ]);

            $this->post($url)
                ->assertRedirect(route('cadastro.anexos'))
                ->assertSessionHas('sucesso', 'Conferência iniciada.');

            $anexo->refresh();
            $this->assertSame(SituacaoConferencia::Conferindo, $anexo->situacao);
            $this->assertNotSame('execucao-atual', $anexo->execucao);
            $this->assertTrue(now()->equalTo($anexo->iniciada_em));
            $this->assertNull($anexo->motivo);
            $this->assertNull($anexo->erro);
            $this->assertNull($anexo->provedor);
            $this->assertNull($anexo->conferido_em);
            $this->assertSame([(int) $anexo->id, $anexo->execucao], $this->lancador->chamadas[array_key_last($this->lancador->chamadas)]);
            $anexo->delete();
        }
        $this->assertCount(3, $this->lancador->chamadas);
    }

    public function test_enviado_sem_conferencia_e_conferido_quando_o_campo_passa_a_validar_com_ia(): void
    {
        $user = $this->pessoaNosAnexos();
        $anexo = $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf");
        $url = route('cadastro.anexos.conferir', 'documento_rg');
        $this->actingAs($user);

        $this->post($url)->assertSessionHas('aviso', InscricaoDocumentoController::AVISO_CONFERIR_DE_NOVO);
        $this->assertSame(SituacaoConferencia::Enviado, $anexo->fresh()->situacao);

        $this->validarComIa(AnexoCadastro::DocumentoRg);

        $this->post($url)->assertSessionHas('sucesso', 'Conferência iniciada.');
        $this->assertSame(SituacaoConferencia::Conferindo, $anexo->refresh()->situacao);
        $this->assertSame([[(int) $anexo->id, $anexo->execucao]], $this->lancador->chamadas);
    }

    public function test_conferir_de_novo_com_a_conferencia_dispensada_volta_a_enviado_sem_lancar(): void
    {
        $user = $this->pessoaNosAnexos();
        $anexo = $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf", [
            'situacao' => SituacaoConferencia::Reprovado,
            'motivo' => 'Antigo.',
            'erro' => 'Detalhe antigo.',
            'execucao' => 'execucao-atual',
            'conferido_em' => now()->subHour(),
        ]);

        $this->actingAs($user)->post(route('cadastro.anexos.conferir', 'documento_rg'))
            ->assertRedirect(route('cadastro.anexos'))
            ->assertSessionHas('aviso', InscricaoDocumentoController::AVISO_SEM_CONFERENCIA);

        $anexo->refresh();
        $this->assertSame(SituacaoConferencia::Enviado, $anexo->situacao);
        $this->assertNull($anexo->motivo);
        $this->assertNull($anexo->erro);
        $this->assertNull($anexo->execucao);
        $this->assertNull($anexo->conferido_em);
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_situacao_traz_os_enviados_as_pendencias_e_se_pode_continuar(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoCpf, AnexoCadastro::ComprovanteResidencia);
        $this->anexo($user, AnexoCadastro::ComprovanteResidencia, "cadastros/{$user->id}/comprovante.pdf", [
            'situacao' => SituacaoConferencia::Conferindo,
            'execucao' => 'outra',
            'iniciada_em' => now(),
        ]);
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf", [
            'situacao' => SituacaoConferencia::Reprovado,
            'motivo' => 'O arquivo está ilegível.',
            'erro' => 'detalhe técnico',
        ]);

        $this->getJson('/cadastro/anexos/situacao')->assertUnauthorized();

        $this->actingAs($user)->getJson('/cadastro/anexos/situacao')
            ->assertOk()
            ->assertExactJson([
                'anexos' => [
                    ['campo' => 'documento_cpf', 'situacao' => 'reprovado', 'motivo' => 'O arquivo está ilegível.'],
                    ['campo' => 'comprovante_residencia', 'situacao' => 'conferindo', 'motivo' => null],
                ],
                'pendencias' => [
                    ['campo' => 'documento_cpf', 'texto' => 'Envie outro arquivo para "Cópia do CPF": o atual foi recusado na conferência.'],
                    ['campo' => 'documento_rg', 'texto' => 'Anexe "Cópia do RG".'],
                    ['campo' => 'comprovante_residencia', 'texto' => 'Aguarde a conferência de "Comprovante de residência".'],
                ],
                'podeContinuar' => false,
            ]);
    }

    public function test_conferindo_parado_ha_mais_de_5_minutos_vem_nao_conferido_e_nao_impede_continuar(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoRg);
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf");
        $rg = $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf", [
            'situacao' => SituacaoConferencia::Conferindo,
            'execucao' => 'execucao-atual',
            'iniciada_em' => now()->subMinutes(5),
        ]);
        $this->actingAs($user);

        // Exatamente cinco minutos ainda é conferência em andamento.
        $this->getJson('/cadastro/anexos/situacao')
            ->assertJsonPath('anexos.1.situacao', 'conferindo')
            ->assertJsonPath('podeContinuar', false);

        $rg->update(['iniciada_em' => now()->subMinutes(5)->subSecond()]);

        $this->getJson('/cadastro/anexos/situacao')
            ->assertJsonPath('anexos.1.situacao', 'nao_conferido')
            ->assertJsonPath('anexos.1.motivo', InscricaoDocumentoController::NAO_CONFERIDO)
            ->assertJsonPath('pendencias', [])
            ->assertJsonPath('podeContinuar', true);
    }

    public function test_continuar_sem_pendencias_conclui_a_etapa_e_segue_para_a_pessoa_juridica(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf");
        $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf");

        $this->actingAs($user)->post('/cadastro/anexos/concluir')
            ->assertRedirect(route('cadastro.organizacao'))
            ->assertSessionHas('sucesso', 'Anexos salvos. Você também pode cadastrar uma Pessoa Jurídica.');

        $this->assertTrue(now()->equalTo($user->fresh()->profile_completed_at));
    }

    public function test_continuar_sem_cpf_ou_rg_fica_nos_anexos(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf");

        $this->assertContinuarFicaNosAnexos($user);
    }

    public function test_continuar_com_anexo_recusado_fica_nos_anexos(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoRg);
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf");
        $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf", ['situacao' => SituacaoConferencia::Reprovado, 'motivo' => 'Ilegível.']);

        $this->assertContinuarFicaNosAnexos($user);
    }

    public function test_continuar_com_anexo_conferindo_fica_nos_anexos(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::FotoPerfil);
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf");
        $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf");
        $this->anexo($user, AnexoCadastro::FotoPerfil, "cadastros/{$user->id}/foto.png", [
            'situacao' => SituacaoConferencia::Conferindo,
            'execucao' => 'execucao-atual',
            'iniciada_em' => now()->subMinute(),
        ]);

        $this->assertContinuarFicaNosAnexos($user);
    }

    public function test_continuar_com_anexo_nao_conferido_segue(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->validarComIa(AnexoCadastro::DocumentoRg);
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf");
        $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf", ['situacao' => SituacaoConferencia::NaoConferido]);

        $this->actingAs($user)->post('/cadastro/anexos/concluir')->assertRedirect(route('cadastro.organizacao'));

        $this->assertNotNull($user->fresh()->profile_completed_at);
    }

    public function test_recusado_em_campo_que_depois_foi_dispensado_nao_impede_continuar(): void
    {
        $user = $this->pessoaNosAnexos();
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf");
        $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf", ['situacao' => SituacaoConferencia::Reprovado, 'motivo' => 'Ilegível.']);

        $this->actingAs($user)->post('/cadastro/anexos/concluir')->assertRedirect(route('cadastro.organizacao'));

        $this->assertNotNull($user->fresh()->profile_completed_at);
    }

    public function test_continuar_com_o_cadastro_finalizado_fica_nos_anexos_e_mantem_a_data_do_cadastro(): void
    {
        $user = User::factory()->proponente()->comPerfil()->create(['profile_completed_at' => '2026-01-15 09:00:00']);
        $this->anexo($user, AnexoCadastro::DocumentoCpf, "cadastros/{$user->id}/cpf.pdf");
        $this->anexo($user, AnexoCadastro::DocumentoRg, "cadastros/{$user->id}/rg.pdf");

        $this->actingAs($user)->post('/cadastro/anexos/concluir')
            ->assertRedirect(route('cadastro.anexos'))
            ->assertSessionHas('sucesso', 'Anexos salvos.');

        $this->assertSame('2026-01-15 09:00:00', $user->fresh()->profile_completed_at->format('Y-m-d H:i:s'));
    }

    public function test_lancador_roda_o_comando_de_conferencia_em_segundo_plano(): void
    {
        Process::fake();
        $anexo = new PessoaFisicaAnexo(['execucao' => 'abc-123']);
        $anexo->id = 42;

        (new LancadorDeConferenciaDoAnexo)->lancar($anexo);

        Process::assertRan(fn (PendingProcess $processo): bool => str_contains($processo->command, 'cadastros:conferir 42 '.escapeshellarg('abc-123')));
    }

    private function assertContinuarFicaNosAnexos(User $user): void
    {
        $this->actingAs($user)->post('/cadastro/anexos/concluir')
            ->assertRedirect(route('cadastro.anexos'))
            ->assertSessionHas('erro', CadastroAnexoController::ERRO_PENDENCIAS);

        $this->assertNull($user->fresh()->profile_completed_at);
    }

    /** Quem salvou Meus dados e ainda não concluiu a etapa Anexos. */
    private function pessoaNosAnexos(): User
    {
        $user = User::factory()->create();
        PessoaFisica::factory()->for($user)->create();

        return $user;
    }

    /** Põe os campos em "Validar com IA" (a migration os cria todos dispensados). */
    private function validarComIa(AnexoCadastro ...$campos): void
    {
        ConfiguracaoAnexo::query()
            ->whereIn('campo', array_map(fn (AnexoCadastro $campo): string => $campo->value, $campos))
            ->update(['validar_ia' => true, 'instrucao_ia' => 'Confira se é o documento da pessoa.']);
    }

    /**
     * Anexo do usuário com o arquivo no disco (um PDF, ou uma imagem PNG para a foto). Sem atributos, só enviado.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function anexo(User $user, AnexoCadastro $campo, string $caminho, array $atributos = []): PessoaFisicaAnexo
    {
        Storage::disk('local')->put($caminho, $campo->somenteImagem() ? self::CONTEUDO_PNG : self::CONTEUDO_PDF);

        return PessoaFisicaAnexo::factory()->for($user->pessoaFisica)->campo($campo)->create([
            'arquivo' => $caminho,
            ...$atributos,
        ]);
    }

    private function arquivo(string $nome, string $conteudo = self::CONTEUDO_PDF): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function url(AnexoCadastro $campo): string
    {
        return route('cadastro.anexos.store', $campo->value);
    }
}
