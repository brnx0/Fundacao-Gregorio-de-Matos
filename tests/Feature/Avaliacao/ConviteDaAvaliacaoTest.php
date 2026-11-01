<?php

namespace Tests\Feature\Avaliacao;

use App\Console\Commands\EnviarConviteCommand;
use App\Enums\SituacaoConvite;
use App\Mail\ConviteDaAvaliacao;
use App\Models\Avaliador;
use App\Models\ConfiguracaoEmail;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Support\Avaliacao\Convites;
use App\Support\Avaliacao\LancadorDeConvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Convite do avaliador por e-mail: o comando roda na hora nos testes, no lugar do processo em segundo plano. */
class ConviteDaAvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(LancadorDeConvite::class, new class extends LancadorDeConvite
        {
            public function lancar(Avaliador $avaliador, string $execucao): void
            {
                Artisan::call('avaliacao:enviar-convite', ['avaliador' => $avaliador->getKey(), 'execucao' => $execucao]);
            }
        });
    }

    /** Recarrega o envio de e-mail depois de salvar a configuração, como na primeira mensagem de uma requisição. */
    private function carregarEnvio(): void
    {
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        $this->app->make('mail.manager');
    }

    private function vinculo(Avaliador $avaliador, string $titulo, ?string $inicio = '2026-12-15', ?string $fim = '2026-12-20'): EditalAvaliador
    {
        $edital = Edital::factory()->create(['titulo' => $titulo]);
        $edital->chamadas()->create(['nome' => 'Chamada única', 'validacao_inicio' => $inicio, 'validacao_fim' => $fim, 'ordem' => 1]);

        return EditalAvaliador::factory()->create(['edital_id' => $edital->id, 'avaliador_id' => $avaliador->id]);
    }

    public function test_convite_vai_com_o_link_e_os_editais(): void
    {
        Mail::fake();
        $avaliador = Avaliador::factory()->create(['email' => 'ana@example.com']);
        $avaliador->novoAcesso();
        $primeiro = $this->vinculo($avaliador, 'Edital Um');
        $segundo = $this->vinculo($avaliador, 'Edital Dois', null, null);

        Convites::enviar($avaliador, collect([$primeiro, $segundo]));

        Mail::assertSent(ConviteDaAvaliacao::class, 1);
        Mail::assertSent(ConviteDaAvaliacao::class, function (ConviteDaAvaliacao $email) use ($avaliador): bool {
            $email->assertSeeInText('/avaliacao/acesso/');
            $email->assertSeeInText('Edital Um');
            $email->assertSeeInText('15/12/2026 a 20/12/2026');
            $email->assertSeeInText('Edital Dois');
            $email->assertSeeInText('Ao abrir, confirme o seu CPF.');
            $email->assertSeeInText('Este link é pessoal: não o encaminhe.');

            return $email->hasTo('ana@example.com')
                && $email->hasSubject('Convite para avaliar inscrições · Portal da FGM')
                && str_contains($email->link, '/avaliacao/acesso/')
                && $email->nome === $avaliador->nome;
        });

        foreach ([$primeiro, $segundo] as $vinculo) {
            $vinculo->refresh();
            $this->assertSame(SituacaoConvite::Enviado, $vinculo->convite_situacao);
            $this->assertNotNull($vinculo->convite_enviado_em);
            $this->assertNull($vinculo->convite_erro);
        }
    }

    public function test_cria_o_acesso_quando_falta(): void
    {
        Mail::fake();
        $avaliador = Avaliador::factory()->create();
        $this->assertNull($avaliador->linkDeAcesso());
        $vinculo = $this->vinculo($avaliador, 'Edital Um');

        Convites::enviar($avaliador, collect([$vinculo]));

        $this->assertNotNull($avaliador->fresh()->linkDeAcesso());
        Mail::assertSent(ConviteDaAvaliacao::class, 1);
        $this->assertSame(SituacaoConvite::Enviado, $vinculo->fresh()->convite_situacao);
    }

    public function test_falha_no_envio_marca_falhou_sem_vazar(): void
    {
        $senha = 'senha-secreta-do-smtp';
        ConfiguracaoEmail::factory()->create(['host' => '127.0.0.1', 'porta' => 1, 'senha' => $senha]);
        $this->carregarEnvio();
        $avaliador = Avaliador::factory()->create();
        $codigo = $avaliador->novoAcesso();
        $vinculo = $this->vinculo($avaliador, 'Edital Um');

        Convites::enviar($avaliador, collect([$vinculo]));

        $vinculo->refresh();
        $this->assertSame(SituacaoConvite::Falhou, $vinculo->convite_situacao);
        $this->assertSame('Não foi possível conectar a 127.0.0.1 na porta 1. Confira o servidor, a porta e a criptografia.', $vinculo->convite_erro);
        $this->assertStringNotContainsString($senha, (string) $vinculo->convite_erro);
        $this->assertStringNotContainsString($codigo, (string) $vinculo->convite_erro);
        $this->assertNull($vinculo->convite_enviado_em);
    }

    public function test_falha_ao_iniciar_marca_falhou(): void
    {
        $this->app->instance(LancadorDeConvite::class, new class extends LancadorDeConvite
        {
            public function lancar(Avaliador $avaliador, string $execucao): void
            {
                throw new RuntimeException('sem processo');
            }
        });
        $avaliador = Avaliador::factory()->create();
        $vinculo = $this->vinculo($avaliador, 'Edital Um');

        Convites::enviar($avaliador, collect([$vinculo]));

        $vinculo->refresh();
        $this->assertSame(SituacaoConvite::Falhou, $vinculo->convite_situacao);
        $this->assertSame(EditalAvaliador::ERRO_AO_INICIAR, $vinculo->convite_erro);
    }

    public function test_na_fila_ha_mais_de_15_minutos_aparece_como_falhou(): void
    {
        $avaliador = Avaliador::factory()->create();
        $vinculo = $this->vinculo($avaliador, 'Edital Um');
        $vinculo->update([
            'convite_situacao' => SituacaoConvite::NaFila,
            'convite_iniciado_em' => now()->subMinutes(16),
            'convite_execucao' => (string) Str::uuid(),
        ]);

        $this->assertSame(SituacaoConvite::Falhou, $vinculo->situacaoDoConvite());
        $this->assertSame(EditalAvaliador::ERRO_SEM_TERMINO, $vinculo->erroDoConvite());

        $vinculo->update(['convite_iniciado_em' => now()->subMinutes(14)]);

        $this->assertSame(SituacaoConvite::NaFila, $vinculo->situacaoDoConvite());
        $this->assertNull($vinculo->erroDoConvite());
    }

    public function test_execucao_antiga_e_ignorada(): void
    {
        Mail::fake();
        $avaliador = Avaliador::factory()->create();
        $avaliador->novoAcesso();
        $vinculo = $this->vinculo($avaliador, 'Edital Um');
        $vinculo->update([
            'convite_situacao' => SituacaoConvite::NaFila,
            'convite_iniciado_em' => now(),
            'convite_execucao' => 'execucao-nova',
        ]);

        Artisan::call('avaliacao:enviar-convite', ['avaliador' => $avaliador->id, 'execucao' => 'execucao-antiga']);

        Mail::assertNothingSent();
        $vinculo->refresh();
        $this->assertSame(SituacaoConvite::NaFila, $vinculo->convite_situacao);
        $this->assertNull($vinculo->convite_enviado_em);
    }

    public function test_avaliador_desativado_na_fila_nao_recebe(): void
    {
        Mail::fake();
        $avaliador = Avaliador::factory()->create();
        $avaliador->novoAcesso();
        $vinculo = $this->vinculo($avaliador, 'Edital Um');
        $vinculo->update([
            'convite_situacao' => SituacaoConvite::NaFila,
            'convite_iniciado_em' => now(),
            'convite_execucao' => 'execucao-atual',
        ]);
        $avaliador->update(['ativo' => false]);

        Artisan::call('avaliacao:enviar-convite', ['avaliador' => $avaliador->id, 'execucao' => 'execucao-atual']);

        Mail::assertNothingSent();
        $vinculo->refresh();
        $this->assertSame(SituacaoConvite::Falhou, $vinculo->convite_situacao);
        $this->assertSame(EnviarConviteCommand::DESATIVADO, $vinculo->convite_erro);
        $this->assertNull($vinculo->convite_enviado_em);
    }
}
