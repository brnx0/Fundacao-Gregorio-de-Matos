<?php

namespace Tests\Feature;

use App\Enums\AvatarVLibras;
use App\Models\ConfiguracaoVLibras;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfiguracaoVLibrasCacheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Como nos stores reais (file, redis, database): valores serializados e, por cache.serializable_classes,
     * nenhum objeto pode voltar do cache. O array dos testes, por padrão, guarda o valor sem serializar.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.array.serialize' => true]);
        Cache::purge('array');
    }

    public function test_configuracao_e_lida_do_banco_uma_vez_so(): void
    {
        ConfiguracaoVLibras::factory()->create(['avatar' => AvatarVLibras::Guga]);
        ConfiguracaoVLibras::atual();

        DB::enableQueryLog();
        $configuracao = ConfiguracaoVLibras::atual();

        $this->assertSame(AvatarVLibras::Guga, $configuracao->avatar);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_padrao_sem_linha_salva_tambem_fica_em_cache(): void
    {
        ConfiguracaoVLibras::atual();

        DB::enableQueryLog();
        $configuracao = ConfiguracaoVLibras::atual();

        $this->assertFalse($configuracao->exists);
        $this->assertSame(AvatarVLibras::Icaro, $configuracao->avatar);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_salvar_na_retaguarda_atualiza_o_portal_na_hora(): void
    {
        ConfiguracaoVLibras::factory()->create(['avatar' => AvatarVLibras::Icaro]);
        $this->get('/')->assertSee("avatar: 'icaro'", false);

        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda')
            ->put('/retaguarda/vlibras', ['ativo' => true, 'lado' => 'R', 'avatar' => 'hosana'])
            ->assertSessionHasNoErrors();

        $this->get('/')->assertSee("avatar: 'hosana'", false);
        $this->assertSame(1, ConfiguracaoVLibras::query()->count());
    }

    public function test_primeira_gravacao_sobre_o_padrao_em_cache_cria_a_linha(): void
    {
        ConfiguracaoVLibras::atual();

        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda')
            ->put('/retaguarda/vlibras', ['ativo' => false, 'lado' => 'L', 'avatar' => 'guga'])
            ->assertSessionHasNoErrors();

        $this->assertFalse(ConfiguracaoVLibras::atual()->ativo);
        $this->assertSame(1, ConfiguracaoVLibras::query()->count());
    }
}
