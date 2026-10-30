<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\SituacaoInscricao;
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

    public function test_lista_so_finalizadas_de_editais_publicados_com_cpf_mascarado(): void
    {
        $edital = $this->editalEmHomologacao();
        $inscricao = $this->inscricaoFinalizada($edital, $this->maria());

        Inscricao::factory()->for($edital)->for(User::factory()->proponente()->create())->create(['situacao' => SituacaoInscricao::Rascunho]);
        $naoPublicado = $this->editalEmHomologacao();
        $naoPublicado->update(['publicado' => false]);
        $this->inscricaoFinalizada($naoPublicado);

        $this->get('/retaguarda/homologacao')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Homologacao/Index')
            ->has('inscricoes.data', 1)
            ->where('inscricoes.data.0.id', $inscricao->id)
            ->where('inscricoes.data.0.documento', '***.982.247-**')
            ->where('inscricoes.data.0.proponente', 'MARIA PROPONENTE')
            ->where('inscricoes.data.0.situacaoRotulo', 'Sem decisão')
            ->where('inscricoes.data.0.homologacao', null)
            ->has('editais', 1)
            ->has('situacoes', 5));
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

        $this->get('/retaguarda/homologacao')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('inscricoes.data', fn ($linhas) => collect($linhas)->pluck('id')->all() === [$recurso->id, $antiga->id, $nova->id, $decidida->id]));
    }

    public function test_filtra_por_edital_situacao_e_busca(): void
    {
        $edital = $this->editalEmHomologacao();
        $outro = $this->editalEmHomologacao();
        $maria = $this->inscricaoFinalizada($edital, $this->maria());
        $joao = $this->inscricaoFinalizada($outro, User::factory()->proponente()->comPerfil()->create(['name' => 'JOAO']));
        $joao->update(['homologacao' => 'homologada']);

        $ids = fn (string $consulta): array => collect($this->get('/retaguarda/homologacao'.$consulta)->assertOk()->viewData('page')['props']['inscricoes']['data'])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$maria->id, $joao->id], $ids(''));
        $this->assertSame([$maria->id], $ids('?situacao=sem_decisao'));
        $this->assertSame([$joao->id], $ids('?situacao=homologada'));
        $this->assertSame([$joao->id], $ids('?edital='.$outro->id));
        $this->assertSame([$maria->id], $ids('?busca=maria'));
        $this->assertSame([$joao->id], $ids('?busca='.$joao->numero));
        $this->assertSame([], $ids('?busca=100%25'));
    }

    public function test_situacao_desconhecida_e_ignorada(): void
    {
        $edital = $this->editalEmHomologacao();
        $this->inscricaoFinalizada($edital);
        $this->inscricaoFinalizada($edital)->update(['homologacao' => 'homologada']);

        $this->get('/retaguarda/homologacao?situacao=xyz')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('inscricoes.data', 2));
    }

    public function test_quem_nao_e_gestor_vai_para_o_login_da_retaguarda(): void
    {
        Auth::logout();
        $this->get('/retaguarda/homologacao')->assertRedirect('/retaguarda/login');

        $this->actingAs($this->maria());
        $this->get('/retaguarda/homologacao')->assertRedirect('/retaguarda/login');
    }

    public function test_menu_tem_o_grupo_inscricoes_com_homologacao(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString(route('retaguarda.homologacao.index', absolute: false), $menu);
        $this->assertStringContainsString("'Inscrições'", $menu);
        $this->assertStringContainsString("'Homologação'", $menu);
    }
}
