<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\SituacaoConvite;
use App\Http\Controllers\Retaguarda\AvaliadoresDaAvaliacaoController;
use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\EditalCategoria;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\User;
use App\Support\Avaliacao\LancadorDeConvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

/** Retaguarda: os editais da avaliação e a aba Avaliadores (incluir, categorias, convite, novo link, tirar). */
class AvaliacaoAvaliadoresTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    /** Lançador falso: registra cada envio (avaliador e execução) em vez de abrir o processo. */
    private LancadorDeConvite $lancador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lancador = new class extends LancadorDeConvite
        {
            /** @var list<array{avaliador: int, execucao: string}> */
            public array $chamadas = [];

            public function lancar(Avaliador $avaliador, string $execucao): void
            {
                $this->chamadas[] = ['avaliador' => (int) $avaliador->getKey(), 'execucao' => $execucao];
            }
        };
        $this->app->instance(LancadorDeConvite::class, $this->lancador);

        $this->actingAs(User::factory()->gestor()->create());
    }

    /** Edital publicado com avaliação de 15 a 20/12/2026 na chamada. */
    private function editalEmAvaliacao(): Edital
    {
        return $this->editalEmHomologacao(['validacao_inicio' => '2026-12-15', 'validacao_fim' => '2026-12-20']);
    }

    private function inscricaoHomologada(Edital $edital): Inscricao
    {
        $inscricao = $this->inscricaoFinalizada($edital);
        $inscricao->update(['homologacao' => 'homologada']);

        return $inscricao;
    }

    private function vinculo(Edital $edital, ?Avaliador $avaliador = null): EditalAvaliador
    {
        return EditalAvaliador::factory()->create([
            'edital_id' => $edital->id,
            'avaliador_id' => ($avaliador ?? Avaliador::factory()->create())->id,
        ]);
    }

    private function atribuir(Inscricao $inscricao, Avaliador $avaliador, bool $concluida = false): InscricaoAvaliacao
    {
        $fabrica = InscricaoAvaliacao::factory();

        return ($concluida ? $fabrica->concluida() : $fabrica)->create([
            'inscricao_id' => $inscricao->id,
            'avaliador_id' => $avaliador->id,
        ]);
    }

    private function aba(Edital $edital): string
    {
        return "/retaguarda/avaliacao/editais/{$edital->slug}/avaliadores";
    }

    public function test_lista_editais_com_homologadas_e_contagens(): void
    {
        $edital = $this->editalEmAvaliacao();
        $edital->update(['titulo' => 'Edital de Cinema']);
        $avaliador = Avaliador::factory()->create();
        $this->inscricaoHomologada($edital);
        $this->atribuir($this->inscricaoHomologada($edital), $avaliador);
        $this->atribuir($this->inscricaoHomologada($edital), $avaliador, concluida: true);
        $this->inscricaoFinalizada($edital);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'nao_homologada']);

        $soFinalizada = $this->editalEmAvaliacao();
        $this->inscricaoFinalizada($soFinalizada);
        $naoPublicado = $this->editalEmAvaliacao();
        $this->inscricaoHomologada($naoPublicado);
        $naoPublicado->update(['publicado' => false]);
        $danca = $this->editalEmAvaliacao();
        $danca->update(['titulo' => 'Edital de Dança']);
        $this->inscricaoHomologada($danca);

        $this->get('/retaguarda/avaliacao?busca=CINEMA')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Avaliacao/Index')
            ->where('filtros.busca', 'CINEMA')
            ->has('editais.data', 1)
            ->where('editais.data.0.id', $edital->id)
            ->where('editais.data.0.slug', $edital->slug)
            ->where('editais.data.0.periodos', ['15/12/2026 a 20/12/2026'])
            ->where('editais.data.0.homologadas', 3)
            ->where('editais.data.0.semAvaliador', 1)
            ->where('editais.data.0.concluidas', 1));

        $ids = collect($this->get('/retaguarda/avaliacao')->assertOk()->viewData('page')['props']['editais']['data'])->pluck('id')->all();
        $this->assertSame([$edital->id, $danca->id], $ids);
    }

    public function test_aba_traz_avaliadores_convite_disponiveis_e_pendencias(): void
    {
        $this->travelTo('2026-12-16 10:00:00');
        $edital = $this->editalEmAvaliacao();
        $teatro = EditalCategoria::factory()->for($edital)->create(['nome' => 'Teatro', 'ordem' => 1]);
        $ana = Avaliador::factory()->create(['nome' => 'Ana', 'email' => 'ana@example.com']);
        $bruno = Avaliador::factory()->inativo()->create(['nome' => 'Bruno']);
        $carla = Avaliador::factory()->create(['nome' => 'Carla', 'cpf' => '52998224725']);
        Avaliador::factory()->inativo()->create(['nome' => 'Davi']);
        $vinculoDaAna = $this->vinculo($edital, $ana);
        $vinculoDaAna->categorias()->attach($teatro->id);
        $vinculoDaAna->update([
            'convite_situacao' => SituacaoConvite::NaFila,
            'convite_iniciado_em' => now()->subMinutes(16),
            'convite_execucao' => (string) Str::uuid(),
        ]);
        $vinculoDoBruno = $this->vinculo($edital, $bruno);
        $vinculoDoBruno->update(['convite_situacao' => SituacaoConvite::Enviado, 'convite_enviado_em' => now()->subDay()]);
        $this->atribuir($this->inscricaoHomologada($edital), $bruno);
        $this->atribuir($this->inscricaoHomologada($edital), $bruno, concluida: true);
        $this->atribuir($this->inscricaoHomologada($this->editalEmAvaliacao()), $bruno);

        $this->get($this->aba($edital))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Avaliacao/Avaliadores')
            ->where('edital', [
                'slug' => $edital->slug,
                'titulo' => $edital->titulo,
                'temCategorias' => true,
                'periodos' => ['15/12/2026 a 20/12/2026'],
            ])
            ->has('avaliadores', 2)
            ->where('avaliadores.0.id', $vinculoDaAna->id)
            ->where('avaliadores.0.nome', 'Ana')
            ->where('avaliadores.0.email', 'ana@example.com')
            ->where('avaliadores.0.ativo', true)
            ->where('avaliadores.0.categorias', [$teatro->id])
            ->where('avaliadores.0.convite', [
                'situacao' => 'falhou',
                'rotulo' => 'Falhou',
                'enviadoEm' => null,
                'erro' => EditalAvaliador::ERRO_SEM_TERMINO,
            ])
            ->where('avaliadores.0.inscricoes', 0)
            ->where('avaliadores.0.naoConcluidas', 0)
            ->where('avaliadores.1.nome', 'Bruno')
            ->where('avaliadores.1.ativo', false)
            ->where('avaliadores.1.convite.situacao', 'enviado')
            ->where('avaliadores.1.convite.enviadoEm', '15/12/2026 10:00')
            ->where('avaliadores.1.inscricoes', 2)
            ->where('avaliadores.1.naoConcluidas', 1)
            ->where('disponiveis', [['id' => $carla->id, 'nome' => 'Carla', 'cpf' => '***.982.247-**']])
            ->where('categorias', [['id' => $teatro->id, 'nome' => 'Teatro']])
            ->where('pendencias', [Edital::PENDENCIA_SEM_CRITERIO]));
    }

    public function test_inclui_avaliadores_ativos_e_manda_o_convite(): void
    {
        $edital = $this->editalEmAvaliacao();
        $ana = Avaliador::factory()->create();
        $bruno = Avaliador::factory()->create();

        $this->post($this->aba($edital), ['avaliadores' => [$ana->id, $bruno->id]])
            ->assertRedirect()
            ->assertSessionHas('sucesso', '2 avaliadores incluídos. O convite está sendo enviado.');

        foreach ([$ana, $bruno] as $avaliador) {
            $vinculo = EditalAvaliador::query()->where('edital_id', $edital->id)->where('avaliador_id', $avaliador->id)->firstOrFail();
            $this->assertSame(SituacaoConvite::NaFila, $vinculo->convite_situacao);
            $this->assertContains(['avaliador' => (int) $avaliador->id, 'execucao' => $vinculo->convite_execucao], $this->lancador->chamadas);
            $this->assertNotNull($avaliador->fresh()->linkDeAcesso());
        }
        $this->assertCount(2, $this->lancador->chamadas);

        $carla = Avaliador::factory()->create();
        $this->post($this->aba($edital), ['avaliadores' => [$carla->id]])
            ->assertSessionHas('sucesso', '1 avaliador incluído. O convite está sendo enviado.');
    }

    public function test_nao_inclui_inativo_nem_repetido(): void
    {
        $edital = $this->editalEmAvaliacao();
        $inativo = Avaliador::factory()->inativo()->create();
        $repetido = $this->vinculo($edital)->avaliador;

        $this->post($this->aba($edital), ['avaliadores' => [$inativo->id]])->assertSessionHasErrors('avaliadores.0');
        $this->post($this->aba($edital), ['avaliadores' => [$repetido->id]])->assertSessionHasErrors('avaliadores.0');
        $this->post($this->aba($edital), ['avaliadores' => []])->assertSessionHasErrors('avaliadores');

        $this->assertSame(1, EditalAvaliador::query()->where('edital_id', $edital->id)->count());
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_define_categorias_so_do_edital(): void
    {
        $edital = $this->editalEmAvaliacao();
        $teatro = EditalCategoria::factory()->for($edital)->create(['ordem' => 1]);
        $danca = EditalCategoria::factory()->for($edital)->create(['ordem' => 2]);
        $deOutro = EditalCategoria::factory()->create();
        $vinculo = $this->vinculo($edital);
        $categorias = fn (): array => $vinculo->categorias()->pluck('edital_categorias.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $this->put($this->aba($edital)."/{$vinculo->id}/categorias", ['categorias' => [$teatro->id, $danca->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame([(int) $teatro->id, (int) $danca->id], $categorias());

        $this->put($this->aba($edital)."/{$vinculo->id}/categorias", ['categorias' => [$teatro->id, $deOutro->id]])
            ->assertSessionHasErrors(['categorias' => AvaliadoresDaAvaliacaoController::ERRO_CATEGORIAS]);
        $this->assertSame([(int) $teatro->id, (int) $danca->id], $categorias());

        $this->put($this->aba($edital)."/{$vinculo->id}/categorias", ['categorias' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $categorias());
    }

    public function test_nao_tira_avaliador_com_inscricoes(): void
    {
        $edital = $this->editalEmAvaliacao();
        $vinculo = $this->vinculo($edital);
        $this->atribuir($this->inscricaoHomologada($edital), $vinculo->avaliador, concluida: true);

        $this->delete($this->aba($edital)."/{$vinculo->id}")
            ->assertRedirect()
            ->assertSessionHas('erro', AvaliadoresDaAvaliacaoController::ERRO_TEM_INSCRICOES);

        $this->assertModelExists($vinculo);
    }

    public function test_tira_avaliador_sem_inscricoes(): void
    {
        $edital = $this->editalEmAvaliacao();
        $categoria = EditalCategoria::factory()->for($edital)->create();
        $vinculo = $this->vinculo($edital);
        $vinculo->categorias()->attach($categoria->id);
        $this->atribuir($this->inscricaoHomologada($this->editalEmAvaliacao()), $vinculo->avaliador);

        $this->delete($this->aba($edital)."/{$vinculo->id}")
            ->assertRedirect()
            ->assertSessionHas('sucesso');

        $this->assertModelMissing($vinculo);
        $this->assertModelExists($vinculo->avaliador);
    }

    public function test_reenvia_convite(): void
    {
        $edital = $this->editalEmAvaliacao();
        $vinculo = $this->vinculo($edital);
        $vinculo->update(['convite_situacao' => SituacaoConvite::Falhou, 'convite_erro' => 'Falhou antes.']);

        $this->post($this->aba($edital)."/{$vinculo->id}/convite")
            ->assertRedirect()
            ->assertSessionHas('sucesso');

        $vinculo->refresh();
        $this->assertSame(SituacaoConvite::NaFila, $vinculo->convite_situacao);
        $this->assertNull($vinculo->convite_erro);
        $this->assertSame([['avaliador' => (int) $vinculo->avaliador_id, 'execucao' => $vinculo->convite_execucao]], $this->lancador->chamadas);
    }

    public function test_avaliador_desativado_nao_recebe_convite_nem_link(): void
    {
        $edital = $this->editalEmAvaliacao();
        $vinculo = $this->vinculo($edital, Avaliador::factory()->inativo()->create());

        $this->post($this->aba($edital)."/{$vinculo->id}/convite")->assertSessionHas('erro', AvaliadoresDaAvaliacaoController::ERRO_INATIVO);
        $this->post($this->aba($edital)."/{$vinculo->id}/novo-link")->assertSessionHas('erro', AvaliadoresDaAvaliacaoController::ERRO_INATIVO);

        $this->assertSame([], $this->lancador->chamadas);
        $this->assertNull($vinculo->avaliador->fresh()->linkDeAcesso());
    }

    public function test_novo_link_troca_o_acesso_e_reenvia_para_todos_os_editais(): void
    {
        $edital = $this->editalEmAvaliacao();
        $avaliador = Avaliador::factory()->create(['email' => 'ana@example.com']);
        $antigo = $avaliador->novoAcesso();
        $vinculo = $this->vinculo($edital, $avaliador);
        $outro = $this->vinculo($this->editalEmAvaliacao(), $avaliador);
        $deOutroAvaliador = $this->vinculo($edital);

        $this->post($this->aba($edital)."/{$vinculo->id}/novo-link")
            ->assertRedirect()
            ->assertSessionHas('sucesso', 'Link novo gerado e enviado para ana@example.com. O link antigo deixou de valer.');

        $this->assertNull(Avaliador::porCodigo($antigo));
        $this->assertNotNull($avaliador->fresh()->linkDeAcesso());
        $vinculo->refresh();
        $outro->refresh();
        $this->assertSame(SituacaoConvite::NaFila, $vinculo->convite_situacao);
        $this->assertSame(SituacaoConvite::NaFila, $outro->convite_situacao);
        $this->assertSame($vinculo->convite_execucao, $outro->convite_execucao);
        $this->assertNull($deOutroAvaliador->fresh()->convite_situacao);
        $this->assertSame([['avaliador' => (int) $avaliador->id, 'execucao' => $vinculo->convite_execucao]], $this->lancador->chamadas);
    }

    public function test_vinculo_de_outro_edital_da_404(): void
    {
        $edital = $this->editalEmAvaliacao();
        $categoria = EditalCategoria::factory()->for($edital)->create();
        $deOutro = $this->vinculo($this->editalEmAvaliacao());
        $base = $this->aba($edital)."/{$deOutro->id}";

        $this->put("{$base}/categorias", ['categorias' => [$categoria->id]])->assertNotFound();
        $this->delete($base)->assertNotFound();
        $this->post("{$base}/convite")->assertNotFound();
        $this->post("{$base}/novo-link")->assertNotFound();

        $this->assertModelExists($deOutro);
        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_edital_nao_publicado_da_404(): void
    {
        $edital = $this->editalEmAvaliacao();
        $vinculo = $this->vinculo($edital);
        $edital->update(['publicado' => false]);
        $avaliador = Avaliador::factory()->create();

        $this->get($this->aba($edital))->assertNotFound();
        $this->post($this->aba($edital), ['avaliadores' => [$avaliador->id]])->assertNotFound();
        $this->post($this->aba($edital)."/{$vinculo->id}/convite")->assertNotFound();

        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_props_sem_codigo_do_link(): void
    {
        $edital = $this->editalEmAvaliacao();
        $avaliador = Avaliador::factory()->create();
        $codigo = $avaliador->novoAcesso();
        $this->vinculo($edital, $avaliador);
        $disponivel = Avaliador::factory()->create();
        $codigoDoDisponivel = $disponivel->novoAcesso();

        $props = json_encode($this->get($this->aba($edital))->assertOk()->viewData('page')['props']);

        $this->assertStringNotContainsString($codigo, $props);
        $this->assertStringNotContainsString($codigoDoDisponivel, $props);
        $this->assertStringNotContainsString('avaliacao\/acesso', $props);
        $this->assertStringNotContainsString('/avaliacao/acesso/', $props);
    }

    public function test_quem_nao_e_gestor_vai_para_o_login(): void
    {
        $edital = $this->editalEmAvaliacao();
        $vinculo = $this->vinculo($edital);

        Auth::logout();
        $this->get('/retaguarda/avaliacao')->assertRedirect('/retaguarda/login');
        $this->get($this->aba($edital))->assertRedirect('/retaguarda/login');

        $this->actingAs(User::factory()->proponente()->create());
        $this->get('/retaguarda/avaliacao')->assertRedirect('/retaguarda/login');
        $this->get($this->aba($edital))->assertRedirect('/retaguarda/login');
        $this->post($this->aba($edital)."/{$vinculo->id}/novo-link")->assertRedirect('/retaguarda/login');

        $this->assertSame([], $this->lancador->chamadas);
    }

    public function test_menu_tem_avaliacao(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString("'".route('retaguarda.avaliacao.index', absolute: false)."'", $menu);
        $this->assertStringContainsString("'Avaliação'", $menu);
        $this->assertStringContainsString('retaguarda.avaliacao.index', $menu);
    }
}
