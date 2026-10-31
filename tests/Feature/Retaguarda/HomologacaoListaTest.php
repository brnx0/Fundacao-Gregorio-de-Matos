<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\SituacaoInscricao;
use App\Models\EditalChamada;
use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class HomologacaoListaTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private const string CPF_DA_MARIA = '52998224725';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->gestor()->create());
    }

    private function maria(string $nome = 'MARIA PROPONENTE'): User
    {
        return User::factory()->proponente()->comPerfil()->create(['cpf' => self::CPF_DA_MARIA, 'name' => $nome]);
    }

    public function test_lista_os_editais_publicados_com_finalizadas_e_as_contagens_por_situacao(): void
    {
        $this->travelTo('2026-12-05 10:00:00');
        $edital = $this->editalEmHomologacao();
        $this->inscricaoFinalizada($edital);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'em_recurso']);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'homologada']);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'nao_homologada']);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'recurso_negado']);
        Inscricao::factory()->for($edital)->for(User::factory()->proponente()->create())->create(['situacao' => SituacaoInscricao::Rascunho]);

        $naoPublicado = $this->editalEmHomologacao();
        $naoPublicado->update(['publicado' => false]);
        $this->inscricaoFinalizada($naoPublicado);
        $soRascunho = $this->editalEmHomologacao();
        Inscricao::factory()->for($soRascunho)->for(User::factory()->proponente()->create())->create(['situacao' => SituacaoInscricao::Rascunho]);

        $this->get('/retaguarda/homologacao')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Homologacao/Index')
            ->missing('inscricoes')
            ->has('editais.data', 1)
            ->where('editais.data.0.id', $edital->id)
            ->where('editais.data.0.periodos', ['01/12/2026 a 10/12/2026'])
            ->where('editais.data.0.emHomologacao', true)
            ->where('editais.data.0.finalizadas', 5)
            ->where('editais.data.0.semDecisao', 1)
            ->where('editais.data.0.emRecurso', 1)
            ->where('editais.data.0.homologadas', 1)
            ->where('editais.data.0.naoHomologadas', 2));
    }

    public function test_periodos_trazem_o_nome_da_chamada_quando_ha_mais_de_uma(): void
    {
        $this->travelTo('2027-01-20 10:00:00');
        $edital = $this->editalEmHomologacao(['nome' => 'Primeira']);
        EditalChamada::factory()->for($edital)->create([
            'nome' => 'Segunda', 'inicio' => '2026-11-01', 'fim' => '2026-11-30',
            'homologacao_inicio' => null, 'homologacao_fim' => null, 'ordem' => 2,
        ]);
        $this->inscricaoFinalizada($edital);

        $this->get('/retaguarda/homologacao')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('editais.data.0.periodos', ['01/12/2026 a 10/12/2026 (Primeira)', 'Não cadastrado (Segunda)'])
            ->where('editais.data.0.emHomologacao', false));
    }

    public function test_busca_editais_pelo_titulo(): void
    {
        $cinema = $this->editalEmHomologacao();
        $cinema->update(['titulo' => 'Edital de Cinema 1000']);
        $this->inscricaoFinalizada($cinema);
        $danca = $this->editalEmHomologacao();
        $danca->update(['titulo' => 'Edital de Dança']);
        $this->inscricaoFinalizada($danca);

        $ids = fn (string $consulta): array => collect($this->get('/retaguarda/homologacao'.$consulta)->assertOk()->viewData('page')['props']['editais']['data'])->pluck('id')->all();

        $this->assertSame([$cinema->id, $danca->id], $ids(''));
        $this->assertSame([$cinema->id], $ids('?busca=CINEMA'));
        $this->assertSame([], $ids('?busca=100%25'));
    }

    public function test_edital_lista_so_as_inscricoes_dele_com_cpf_mascarado(): void
    {
        $edital = $this->editalEmHomologacao();
        $inscricao = $this->inscricaoFinalizada($edital, $this->maria());
        Inscricao::factory()->for($edital)->for(User::factory()->proponente()->create())->create(['situacao' => SituacaoInscricao::Rascunho]);
        $this->inscricaoFinalizada($this->editalEmHomologacao());

        $this->get("/retaguarda/homologacao/editais/{$edital->slug}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Homologacao/Edital')
            ->where('edital.id', $edital->id)
            ->where('edital.titulo', $edital->titulo)
            ->where('edital.periodos', ['01/12/2026 a 10/12/2026'])
            ->has('inscricoes.data', 1)
            ->where('inscricoes.data.0.id', $inscricao->id)
            ->where('inscricoes.data.0.documento', '***.982.247-**')
            ->where('inscricoes.data.0.proponente', 'MARIA PROPONENTE')
            ->where('inscricoes.data.0.situacaoRotulo', 'Sem decisão')
            ->where('inscricoes.data.0.homologacao', null)
            ->has('situacoes', 5));
    }

    public function test_edital_nao_publicado_ou_excluido_nao_abre(): void
    {
        $naoPublicado = $this->editalEmHomologacao();
        $naoPublicado->update(['publicado' => false]);
        $excluido = $this->editalEmHomologacao();
        $excluido->delete();

        $this->get("/retaguarda/homologacao/editais/{$naoPublicado->slug}")->assertNotFound();
        $this->get("/retaguarda/homologacao/editais/{$excluido->slug}")->assertNotFound();
    }

    public function test_em_recurso_primeiro_depois_sem_decisao_mais_antigas_depois_decididas(): void
    {
        $edital = $this->editalEmHomologacao();
        $decidida = $this->inscricaoFinalizada($edital);
        $decidida->update(['homologacao' => 'homologada', 'finalizada_em' => now()->subDays(5)]);
        $nova = $this->inscricaoFinalizada($edital);
        $nova->update(['finalizada_em' => now()->subDay()]);
        $antiga = $this->inscricaoFinalizada($edital);
        $antiga->update(['finalizada_em' => now()->subDays(3)]);
        $recurso = $this->inscricaoFinalizada($edital);
        $recurso->update(['homologacao' => 'em_recurso', 'finalizada_em' => now()]);

        $this->get("/retaguarda/homologacao/editais/{$edital->slug}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('inscricoes.data', fn ($linhas) => collect($linhas)->pluck('id')->all() === [$recurso->id, $antiga->id, $nova->id, $decidida->id]));
    }

    public function test_filtra_as_inscricoes_do_edital_por_situacao_e_busca(): void
    {
        $edital = $this->editalEmHomologacao();
        $maria = $this->inscricaoFinalizada($edital, $this->maria());
        $joao = $this->inscricaoFinalizada($edital, User::factory()->proponente()->comPerfil()->create(['name' => 'JOAO']));
        $joao->update(['homologacao' => 'homologada']);
        $this->inscricaoFinalizada($this->editalEmHomologacao(), User::factory()->proponente()->comPerfil()->create(['name' => 'MARIA DE OUTRO EDITAL']));

        $ids = fn (string $consulta): array => collect($this->get("/retaguarda/homologacao/editais/{$edital->slug}".$consulta)->assertOk()->viewData('page')['props']['inscricoes']['data'])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$maria->id, $joao->id], $ids(''));
        $this->assertSame([$maria->id], $ids('?situacao=sem_decisao'));
        $this->assertSame([$joao->id], $ids('?situacao=homologada'));
        $this->assertSame([$maria->id], $ids('?busca=maria'));
        $this->assertSame([$joao->id], $ids('?busca='.$joao->numero));
        $this->assertSame([], $ids('?busca=100%25'));
    }

    public function test_situacao_desconhecida_e_ignorada(): void
    {
        $edital = $this->editalEmHomologacao();
        $this->inscricaoFinalizada($edital);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'homologada']);

        $this->get("/retaguarda/homologacao/editais/{$edital->slug}?situacao=xyz")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('filtros.situacao', '')
            ->has('inscricoes.data', 2));
    }

    public function test_conferencia_traz_o_edital_para_voltar_a_lista_dele(): void
    {
        $edital = $this->editalEmHomologacao();
        $inscricao = $this->inscricaoFinalizada($edital);

        $this->get("/retaguarda/homologacao/{$inscricao->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('inscricao.editalSlug', $edital->slug));

        $edital->update(['publicado' => false]);

        $this->get("/retaguarda/homologacao/{$inscricao->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('inscricao.editalSlug', null));
    }

    public function test_edital_sem_chamada_tem_periodo_nao_cadastrado(): void
    {
        $edital = $this->editalEmHomologacao();
        $this->inscricaoFinalizada($edital);
        $edital->chamadas()->delete();

        $this->get('/retaguarda/homologacao')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('editais.data.0.periodos', ['Não cadastrado'])
            ->where('editais.data.0.emHomologacao', false));
        $this->get("/retaguarda/homologacao/editais/{$edital->slug}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('edital.periodos', ['Não cadastrado']));
    }

    public function test_quem_nao_e_gestor_vai_para_o_login_da_retaguarda(): void
    {
        $edital = $this->editalEmHomologacao();

        Auth::logout();
        $this->get('/retaguarda/homologacao')->assertRedirect('/retaguarda/login');
        $this->get("/retaguarda/homologacao/editais/{$edital->slug}")->assertRedirect('/retaguarda/login');

        $this->actingAs($this->maria());
        $this->get('/retaguarda/homologacao')->assertRedirect('/retaguarda/login');
        $this->get("/retaguarda/homologacao/editais/{$edital->slug}")->assertRedirect('/retaguarda/login');
    }

    public function test_menu_tem_o_grupo_inscricoes_com_homologacao(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString(route('retaguarda.homologacao.index', absolute: false), $menu);
        $this->assertStringContainsString("'Inscrições'", $menu);
        $this->assertStringContainsString("'Homologação'", $menu);
    }
}
