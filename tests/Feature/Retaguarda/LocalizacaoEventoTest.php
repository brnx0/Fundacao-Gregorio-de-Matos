<?php

namespace Tests\Feature\Retaguarda;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalizacaoEventoTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDERECO = 'Teatro Gregório de Mattos, Salvador/BA';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');
    }

    private function localizar(string $endereco = self::ENDERECO): string
    {
        return route('retaguarda.eventos.localizar', ['endereco' => $endereco]);
    }

    public function test_devolve_as_coordenadas_do_endereco_e_se_identifica_ao_nominatim(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => '-12.9758', 'lon' => '-38.5108', 'display_name' => 'Teatro Gregório de Mattos, Praça Castro Alves, Salvador'],
        ])]);

        $this->getJson($this->localizar())
            ->assertOk()
            ->assertExactJson(['encontrado' => true, 'dados' => [
                'latitude' => -12.9758,
                'longitude' => -38.5108,
                'descricao' => 'Teatro Gregório de Mattos, Praça Castro Alves, Salvador',
            ]]);

        Http::assertSent(fn (Request $pedido) => $pedido['q'] === self::ENDERECO
            && $pedido['countrycodes'] === 'br'
            && $pedido->hasHeader('User-Agent', sprintf('%s (%s)', config('app.name'), config('app.url'))));
    }

    public function test_mesmo_endereco_de_novo_vem_do_cache(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '-12.9758', 'lon' => '-38.5108']])]);

        $this->getJson($this->localizar())->assertJsonPath('encontrado', true);
        $this->getJson($this->localizar('  teatro gregório de mattos,   salvador/ba '))->assertJsonPath('encontrado', true);

        Http::assertSentCount(1);
    }

    public function test_endereco_nao_encontrado_ou_falha_de_rede_volta_sem_coordenadas(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
            ->push([])
            ->pushFailedConnection()]);

        $this->getJson($this->localizar('Rua que não existe'))->assertExactJson(['encontrado' => false]);
        $this->getJson($this->localizar('Outro endereço'))->assertExactJson(['encontrado' => false]);
    }

    public function test_endereco_curto_demais_nem_consulta(): void
    {
        Http::fake();

        $this->getJson($this->localizar('ab'))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Informe o endereço ou o local do evento.']);
        Http::assertNothingSent();
    }

    public function test_quem_nao_e_gestor_vai_para_o_login(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create());

        $this->get($this->localizar())->assertRedirect('/retaguarda/login');
        Http::assertNothingSent();
    }
}
