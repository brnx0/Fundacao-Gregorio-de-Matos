<?php

namespace Tests\Feature\Retaguarda;

use App\Models\Evento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventosTest extends TestCase
{
    use RefreshDatabase;

    /** PNG de 1×1 pixel (o container não tem GD para UploadedFile::fake()->image()). */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');
    }

    private function imagem(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('capa.png', base64_decode(self::PNG));
    }

    /**
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    private function dados(array $alteracoes = []): array
    {
        return [
            'titulo' => 'Capoeira Viva',
            'resumo' => 'Rodas e oficinas de capoeira.',
            'data_realizacao' => '2026-09-06',
            'local' => 'Barroquinha',
            'publicado' => '0',
            ...$alteracoes,
        ];
    }

    public function test_lista_com_busca_e_filtro(): void
    {
        Evento::factory()->create(['titulo' => 'Capoeira Viva', 'data_realizacao' => '2026-09-06']);
        Evento::factory()->create(['titulo' => 'Dois de Julho', 'data_realizacao' => '2026-07-02', 'publicado' => false]);
        Evento::factory()->create(['titulo' => 'Capoeira excluída'])->delete();

        $this->get('/retaguarda/eventos')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Retaguarda/Eventos/Index')
                ->has('eventos.data', 2)
                ->where('eventos.data.0.titulo', 'Capoeira Viva')
                ->where('eventos.data.0.data', '06/09/2026')
                ->where('eventos.per_page', 15));

        $this->get('/retaguarda/eventos?busca=CAPOEIRA')
            ->assertInertia(fn (Assert $p) => $p->has('eventos.data', 1)->where('filtros.busca', 'CAPOEIRA'));

        $this->get('/retaguarda/eventos?busca=%25')
            ->assertInertia(fn (Assert $p) => $p->has('eventos.data', 0));

        $this->get('/retaguarda/eventos?publicado=nao')
            ->assertInertia(fn (Assert $p) => $p->has('eventos.data', 1)->where('eventos.data.0.titulo', 'Dois de Julho'));

        $this->get('/retaguarda/eventos?publicado=sim')
            ->assertInertia(fn (Assert $p) => $p->has('eventos.data', 1)->where('eventos.data.0.titulo', 'Capoeira Viva'));
    }

    public function test_lista_nao_le_a_descricao(): void
    {
        Evento::factory()->create(['descricao' => '<p>Longa</p>']);
        DB::enableQueryLog();

        $this->get('/retaguarda/eventos')->assertOk();

        // A contagem da paginação (count(*)) não lê colunas.
        $consultas = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => stripos($sql, 'eventos') !== false && stripos($sql, 'count(*)') === false);
        $this->assertNotEmpty($consultas);
        foreach ($consultas as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('descricao', $sql);
            // Sem o comentário de dica do Oracle (/*+ FIRST_ROWS(n) */), nenhum "*": colunas explícitas.
            $this->assertStringNotContainsString('*', preg_replace('#/\*.*?\*/#', '', $sql));
        }
    }

    public function test_cria_evento_com_imagem(): void
    {
        $this->post('/retaguarda/eventos', $this->dados(['publicado' => '1', 'imagem' => $this->imagem()]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/retaguarda/eventos')
            ->assertSessionHas('sucesso', 'Evento salvo.');

        $evento = Evento::sole();
        $this->assertSame('capoeira-viva', $evento->slug);
        $this->assertTrue($evento->publicado);
        $this->assertSame('2026-09-06', $evento->data_realizacao->toDateString());
        $this->assertStringStartsWith('eventos/', $evento->imagem);
        Storage::disk('public')->assertExists($evento->imagem);
    }

    public function test_valida_campos_obrigatorios(): void
    {
        $this->post('/retaguarda/eventos', ['publicado' => '0'])
            ->assertSessionHasErrors(['titulo', 'resumo', 'data_realizacao']);

        $this->assertSame(0, Evento::count());
    }

    public function test_slug_unico_mesmo_com_excluido(): void
    {
        Evento::factory()->create(['titulo' => 'Capoeira Viva', 'slug' => 'capoeira-viva'])->delete();

        $this->post('/retaguarda/eventos', $this->dados())->assertSessionHasNoErrors();
        $this->post('/retaguarda/eventos', $this->dados())->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['capoeira-viva-2', 'capoeira-viva-3'], Evento::pluck('slug')->all());
    }

    public function test_editar_sem_reenviar_mantem_imagem(): void
    {
        $evento = Evento::factory()->create(['slug' => 'capoeira-viva', 'imagem' => 'eventos/atual.png']);
        Storage::disk('public')->put('eventos/atual.png', 'png');

        $this->put('/retaguarda/eventos/capoeira-viva', $this->dados(['titulo' => 'Título novo']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/retaguarda/eventos')
            ->assertSessionHas('sucesso', 'Evento salvo.');

        $evento->refresh();
        $this->assertSame('Título novo', $evento->titulo);
        $this->assertSame('capoeira-viva', $evento->slug);
        $this->assertSame('eventos/atual.png', $evento->imagem);
        Storage::disk('public')->assertExists('eventos/atual.png');
    }

    public function test_trocar_imagem_apaga_a_antiga(): void
    {
        $evento = Evento::factory()->create(['imagem' => 'eventos/antiga.png']);
        Storage::disk('public')->put('eventos/antiga.png', 'png');

        $this->put("/retaguarda/eventos/{$evento->slug}", $this->dados(['imagem' => $this->imagem()]))
            ->assertSessionHasNoErrors();

        $evento->refresh();
        $this->assertNotSame('eventos/antiga.png', $evento->imagem);
        Storage::disk('public')->assertMissing('eventos/antiga.png');
        Storage::disk('public')->assertExists($evento->imagem);
    }

    public function test_remover_imagem(): void
    {
        $evento = Evento::factory()->create(['imagem' => 'eventos/capa.png']);
        Storage::disk('public')->put('eventos/capa.png', 'png');

        $this->put("/retaguarda/eventos/{$evento->slug}", $this->dados(['remover_imagem' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertNull($evento->refresh()->imagem);
        Storage::disk('public')->assertMissing('eventos/capa.png');
    }

    public function test_exclui_logicamente(): void
    {
        $evento = Evento::factory()->create(['imagem' => 'eventos/fica.png']);
        Storage::disk('public')->put('eventos/fica.png', 'png');

        $this->delete("/retaguarda/eventos/{$evento->slug}")
            ->assertRedirect('/retaguarda/eventos')
            ->assertSessionHas('sucesso', 'Evento excluído.');

        $this->assertSoftDeleted($evento);
        Storage::disk('public')->assertExists('eventos/fica.png');
    }

    public function test_alterna_publicacao(): void
    {
        $evento = Evento::factory()->create(['publicado' => false]);

        $this->patch("/retaguarda/eventos/{$evento->slug}/publicacao")->assertSessionHas('sucesso', 'Evento publicado.');
        $this->assertTrue($evento->refresh()->publicado);

        $this->patch("/retaguarda/eventos/{$evento->slug}/publicacao")->assertSessionHas('sucesso', 'Evento despublicado.');
        $this->assertFalse($evento->refresh()->publicado);
    }

    public function test_evento_criado_aparece_na_home(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        $this->post('/retaguarda/eventos', $this->dados(['titulo' => 'Novo na home', 'publicado' => '1', 'data_realizacao' => '2026-12-05']))
            ->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('eventos.0.titulo', 'Novo na home'));
    }

    public function test_descricao_e_gravada_sem_script(): void
    {
        $this->post('/retaguarda/eventos', $this->dados([
            'descricao' => '<h2>Programação</h2><p onclick="alert(1)">Rodas às 18h<script>alert(1)</script></p>',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('<h2>Programação</h2><p>Rodas às 18h</p>', Evento::sole()->descricao);
    }

    public function test_edicao_traz_e_atualiza_descricao(): void
    {
        $evento = Evento::factory()->create(['descricao' => '<p>Antiga</p>']);

        $this->get("/retaguarda/eventos/{$evento->slug}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('evento.descricao', '<p>Antiga</p>'));

        $this->put("/retaguarda/eventos/{$evento->slug}", $this->dados(['descricao' => '<p><a href="javascript:alert(1)">Nova</a></p>']))
            ->assertSessionHasNoErrors();
        $this->assertSame('<p>Nova</p>', $evento->refresh()->descricao);

        $this->put("/retaguarda/eventos/{$evento->slug}", $this->dados(['descricao' => '<p></p>']))
            ->assertSessionHasNoErrors();
        $this->assertNull($evento->refresh()->descricao);
    }

    public function test_descricao_longa_demais_e_recusada(): void
    {
        $this->post('/retaguarda/eventos', $this->dados(['descricao' => str_repeat('a', 200001)]))
            ->assertSessionHasErrors(['descricao' => 'O campo descrição não pode ter mais de 200000 caracteres.']);
    }

    public function test_envia_imagem_do_conteudo(): void
    {
        $resposta = $this->postJson('/retaguarda/eventos/conteudo/imagem', ['imagem' => $this->imagem()])
            ->assertOk();

        $url = $resposta->json('url');
        $this->assertMatchesRegularExpression('#^/storage/eventos/conteudo/[A-Za-z0-9]+\.png$#', $url);
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
    }

    public function test_imagem_do_conteudo_so_aceita_imagem(): void
    {
        $this->postJson('/retaguarda/eventos/conteudo/imagem', ['imagem' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('imagem');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_proponente_e_visitante_nao_enviam_imagem_do_conteudo(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/retaguarda/eventos/conteudo/imagem', ['imagem' => $this->imagem()])->assertRedirect('/retaguarda/login');

        auth()->logout();
        $this->post('/retaguarda/eventos/conteudo/imagem', ['imagem' => $this->imagem()])->assertRedirect('/retaguarda/login');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_envio_acima_do_limite_do_php_volta_com_aviso_geral(): void
    {
        $this->call('POST', '/retaguarda/eventos', server: [
            'CONTENT_LENGTH' => (string) (1024 ** 3),
            'HTTP_X_INERTIA' => 'true',
            'HTTP_REFERER' => url('/retaguarda/eventos/create'),
        ])
            ->assertRedirect('/retaguarda/eventos/create')
            ->assertSessionHas('erro', 'Os arquivos enviados passam do tamanho máximo permitido.')
            ->assertSessionDoesntHaveErrors();
    }

    public function test_salva_e_edita_horario_endereco_e_pin_do_mapa(): void
    {
        $this->post('/retaguarda/eventos', $this->dados([
            'hora_inicio' => '19:00',
            'hora_fim' => '22:30',
            'endereco' => 'Ladeira da Barroquinha, 2, Salvador/BA',
            'latitude' => '-12.9747',
            'longitude' => '-38.5102',
        ]))->assertRedirect('/retaguarda/eventos');

        $evento = Evento::sole();
        $this->assertSame('19:00', $evento->hora_inicio);
        $this->assertSame('22:30', $evento->hora_fim);
        $this->assertSame('Ladeira da Barroquinha, 2, Salvador/BA', $evento->endereco);
        $this->assertSame(-12.9747, $evento->latitude);
        $this->assertSame(-38.5102, $evento->longitude);

        $this->get("/retaguarda/eventos/{$evento->slug}/edit")->assertInertia(fn (Assert $p) => $p
            ->where('evento.hora_inicio', '19:00')
            ->where('evento.endereco', 'Ladeira da Barroquinha, 2, Salvador/BA')
            ->where('evento.latitude', -12.9747));

        // "Remover marcação" e horários apagados: os campos voltam a ficar vazios.
        $this->post("/retaguarda/eventos/{$evento->slug}", $this->dados([
            '_method' => 'put', 'hora_inicio' => '', 'hora_fim' => '', 'latitude' => '', 'longitude' => '',
        ]))->assertRedirect('/retaguarda/eventos');

        $evento->refresh();
        $this->assertNull($evento->hora_inicio);
        $this->assertNull($evento->latitude);
        $this->assertNull($evento->longitude);
    }

    /** @param array<string, string> $dados */
    #[DataProvider('horariosEPinsInvalidos')]
    public function test_recusa_horario_e_pin_invalidos(array $dados, string $campo, string $mensagem): void
    {
        $this->post('/retaguarda/eventos', $this->dados($dados))->assertSessionHasErrors([$campo => $mensagem]);

        $this->assertSame(0, Evento::count());
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function horariosEPinsInvalidos(): array
    {
        return [
            'hora fora do formato' => [['hora_inicio' => '7h'], 'hora_inicio', 'Informe o horário de início no formato HH:MM.'],
            'término antes do início' => [['hora_inicio' => '20:00', 'hora_fim' => '19:00'], 'hora_fim', 'O horário de término deve ser depois do horário de início.'],
            'término sem início' => [['hora_fim' => '19:00'], 'hora_inicio', 'Informe o horário de início para colocar o de término.'],
            'latitude sem longitude' => [['latitude' => '-12.97'], 'longitude', 'Marque o local no mapa de novo: faltou uma das coordenadas.'],
            'coordenada fora do mapa' => [['latitude' => '-120', 'longitude' => '-38.5'], 'latitude', 'A marcação está fora do mapa. Marque o local de novo.'],
        ];
    }

    public function test_proponente_nao_acessa(): void
    {
        $evento = Evento::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get('/retaguarda/eventos')->assertRedirect('/retaguarda/login');
        $this->post('/retaguarda/eventos', $this->dados())->assertRedirect('/retaguarda/login');
        $this->delete("/retaguarda/eventos/{$evento->slug}")->assertRedirect('/retaguarda/login');

        $this->assertSame(1, Evento::count());
    }
}
