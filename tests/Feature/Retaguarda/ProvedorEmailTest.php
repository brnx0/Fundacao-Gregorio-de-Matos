<?php

namespace Tests\Feature\Retaguarda;

use App\Mail\EmailDeTeste;
use App\Models\ConfiguracaoEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProvedorEmailTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/retaguarda/email';

    private const string SENHA = 'abcd efgh ijkl mnop';

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gestor = User::factory()->gestor()->create(['email' => 'gestor@fgm.salvador.ba.gov.br']);
        $this->actingAs($this->gestor);
    }

    /** Carrega o envio de e-mail de novo, como na primeira mensagem de uma requisição. */
    private function carregarEnvio(): void
    {
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        $this->app->make('mail.manager');
    }

    /** @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        return [
            'ativo' => true,
            'host' => 'smtp.gmail.com',
            'porta' => 587,
            'criptografia' => 'tls',
            'usuario' => 'fgm.envios@gmail.com',
            'senha' => self::SENHA,
            'remetente_email' => 'fgm.envios@gmail.com',
            'remetente_nome' => 'Fundação Gregório de Mattos',
            ...$extra,
        ];
    }

    public function test_tela_vem_com_o_gmail_preenchido_antes_da_primeira_gravacao(): void
    {
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/ProvedorEmail')
            ->where('configuracao', [
                'ativo' => false,
                'host' => 'smtp.gmail.com',
                'porta' => 587,
                'criptografia' => 'tls',
                'usuario' => '',
                'remetente_email' => '',
                'remetente_nome' => '',
            ])
            ->where('senhaGuardada', false)
            ->where('emailDoTeste', 'gestor@fgm.salvador.ba.gov.br')
            ->where('criptografias.0.valor', 'tls')
            ->where('criptografias.1.valor', 'ssl'));
    }

    public function test_salva_numa_linha_so_com_a_senha_cifrada_que_nunca_volta_para_a_tela(): void
    {
        $this->put(self::URL, $this->dados())
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::URL)
            ->assertSessionHas('sucesso', 'Configuração de e-mail salva.');

        $this->put(self::URL, $this->dados(['remetente_nome' => 'FGM']))->assertSessionHasNoErrors();

        $configuracao = ConfiguracaoEmail::sole();
        $this->assertSame('FGM', $configuracao->remetente_nome);
        $this->assertSame(self::SENHA, $configuracao->senhaGuardada());
        $this->assertStringNotContainsString(self::SENHA, (string) DB::table('configuracoes_email')->value('senha'));

        $resposta = $this->get(self::URL);
        $resposta->assertInertia(fn (Assert $p) => $p->where('senhaGuardada', true)->missing('configuracao.senha'));
        $this->assertStringNotContainsString(self::SENHA, $resposta->getContent());
    }

    public function test_senha_em_branco_mantem_a_guardada(): void
    {
        $this->put(self::URL, $this->dados());

        $this->put(self::URL, $this->dados(['senha' => '', 'porta' => 465, 'criptografia' => 'ssl']))->assertSessionHasNoErrors();

        $configuracao = ConfiguracaoEmail::sole();
        $this->assertSame(self::SENHA, $configuracao->senhaGuardada());
        $this->assertSame(465, $configuracao->porta);
    }

    public function test_senha_e_obrigatoria_enquanto_nenhuma_esta_guardada(): void
    {
        $this->put(self::URL, $this->dados(['senha' => '']))->assertSessionHasErrors(['senha' => 'Informe a senha de app.']);

        $this->assertSame(0, ConfiguracaoEmail::count());
    }

    public function test_campos_obrigatorios_e_validos(): void
    {
        $this->put(self::URL, [
            'ativo' => 'talvez',
            'host' => 'smtp gmail',
            'porta' => 70000,
            'criptografia' => 'starttls',
            'usuario' => '',
            'senha' => self::SENHA,
            'remetente_email' => 'sem-arroba',
            'remetente_nome' => str_repeat('ç', 70),
        ])->assertSessionHasErrors([
            'ativo',
            'host' => 'Digite um endereço de servidor válido, como smtp.gmail.com.',
            'porta' => 'Informe uma porta de 1 a 65535.',
            'criptografia' => 'Selecione uma opção da lista.',
            'usuario' => 'Este campo é obrigatório.',
            'remetente_email' => 'Digite um e-mail válido.',
            'remetente_nome',
        ]);

        $this->assertSame(0, ConfiguracaoEmail::count());
    }

    public function test_envio_ativado_vale_para_todo_email_do_sistema(): void
    {
        $this->put(self::URL, $this->dados(['criptografia' => 'ssl', 'porta' => 465]));

        $this->carregarEnvio();

        $this->assertSame(ConfiguracaoEmail::MAILER, config('mail.default'));
        $this->assertSame(['address' => 'fgm.envios@gmail.com', 'name' => 'Fundação Gregório de Mattos'], config('mail.from'));
        $this->assertSame([
            'transport' => 'smtp',
            'scheme' => 'smtps',
            'host' => 'smtp.gmail.com',
            'port' => 465,
            'username' => 'fgm.envios@gmail.com',
            'password' => self::SENHA,
            'timeout' => 15,
        ], config('mail.mailers.'.ConfiguracaoEmail::MAILER));
    }

    public function test_envio_desligado_deixa_o_env_valer(): void
    {
        $padrao = config('mail.default');
        $this->put(self::URL, $this->dados(['ativo' => false]));

        $this->carregarEnvio();

        $this->assertSame($padrao, config('mail.default'));
    }

    public function test_email_de_teste_vai_para_o_gestor_pela_configuracao_salva_mesmo_desligada(): void
    {
        $this->put(self::URL, $this->dados(['ativo' => false]));
        Mail::fake();

        $this->post(self::URL.'/teste')
            ->assertRedirect(self::URL)
            ->assertSessionHas('sucesso', 'E-mail de teste enviado para gestor@fgm.salvador.ba.gov.br. Confira a caixa de entrada e o spam.');

        Mail::assertSent(EmailDeTeste::class, fn (EmailDeTeste $email): bool => $email->hasTo('gestor@fgm.salvador.ba.gov.br')
            && $email->mailer === ConfiguracaoEmail::MAILER);
    }

    public function test_email_de_teste_pede_a_configuracao_salva_antes(): void
    {
        Mail::fake();

        $this->post(self::URL.'/teste')->assertSessionHas('erro', 'Salve a configuração antes de enviar o e-mail de teste.');

        Mail::assertNothingSent();
    }

    public function test_falha_de_conexao_no_teste_vira_mensagem_sem_a_senha(): void
    {
        // Porta 1 do próprio container: a conexão é recusada na hora.
        $this->put(self::URL, $this->dados(['host' => '127.0.0.1', 'porta' => 1]));

        $resposta = $this->post(self::URL.'/teste');

        $resposta->assertSessionHas('erro', 'Não foi possível conectar a 127.0.0.1 na porta 1. Confira o servidor, a porta e a criptografia.');
        $this->assertStringNotContainsString(self::SENHA, (string) session('erro'));
    }

    public function test_quem_nao_e_gestor_vai_para_o_login_da_retaguarda(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(self::URL)->assertRedirect('/retaguarda/login');
        $this->put(self::URL, $this->dados())->assertRedirect('/retaguarda/login');
        $this->post(self::URL.'/teste')->assertRedirect('/retaguarda/login');

        $this->assertSame(0, ConfiguracaoEmail::count());
    }

    public function test_menu_de_parametrizacao_tem_email(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/MenuRetaguarda.vue'));

        $this->assertStringContainsString(route('retaguarda.email.edit', absolute: false), $menu);
        $this->assertStringContainsString("'E-mail'", $menu);
    }
}
