<?php

namespace Tests\Feature;

use App\Enums\NaturezaProponente;
use App\Enums\TipoEdital;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalChamada;
use App\Models\EditalCriterio;
use App\Models\EditalDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EditalEstruturaTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_DAS_CHAMADAS = 'migrations/2026_10_28_172228_create_chamadas_for_existing_editais.php';

    public function test_migration_de_dados_da_a_cada_edital_existente_uma_chamada_com_as_mesmas_datas(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        $aberto = Edital::factory()->create(['inscricoes_inicio' => '2026-11-01', 'inscricoes_fim' => '2026-11-30']);
        $excluido = Edital::factory()->create(['inscricoes_inicio' => '2026-10-01', 'inscricoes_fim' => '2026-10-20']);
        $excluido->delete();

        (require database_path(self::MIGRATION_DAS_CHAMADAS))->up();

        $chamadas = $aberto->chamadas()->get();
        $this->assertCount(1, $chamadas);
        $this->assertSame('Inscrições', $chamadas[0]->nome);
        $this->assertSame('2026-11-01', $chamadas[0]->inicio->toDateString());
        $this->assertSame('2026-11-30', $chamadas[0]->fim->toDateString());
        $this->assertSame(1, EditalChamada::where('edital_id', $excluido->id)->count());
        $this->assertTrue(Edital::naVitrine()->get()->contains($aberto));
        $this->assertSame(1, Edital::contarAbertos());
    }

    public function test_migration_de_dados_nao_duplica_chamada_de_edital_que_ja_tem(): void
    {
        $edital = Edital::factory()->create();
        EditalChamada::factory()->for($edital)->create();

        (require database_path(self::MIGRATION_DAS_CHAMADAS))->up();

        $this->assertSame(1, $edital->chamadas()->count());
    }

    public function test_rascunho_sem_titulo_e_sem_datas_fica_fora_da_vitrine_e_da_contagem(): void
    {
        $rascunho = Edital::factory()->rascunho()->create(['pdf' => 'editais/teste.pdf', 'publicado' => true]);

        $this->assertNull($rascunho->fresh()->titulo);
        $this->assertNull($rascunho->situacao());
        $this->assertFalse($rascunho->estaVisivel());
        $this->assertCount(0, Edital::naVitrine()->get());
        $this->assertCount(0, Edital::visiveis()->get());
        $this->assertSame(0, Edital::contarAbertos());
    }

    public function test_lista_da_retaguarda_mostra_rascunho_sem_periodo_nem_situacao(): void
    {
        $this->actingAs(User::factory()->gestor()->create());
        $rascunho = Edital::factory()->rascunho()->create();

        $this->get('/retaguarda/editais')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('editais.data', 1)
                ->where('editais.data.0.slug', $rascunho->slug)
                ->where('editais.data.0.titulo', null)
                ->where('editais.data.0.periodo', null)
                ->where('editais.data.0.situacao', null));

        $this->get('/retaguarda/editais?situacao=aberto')
            ->assertInertia(fn (Assert $p) => $p->has('editais.data', 0));
    }

    public function test_pendencias_para_publicar_listam_o_que_falta(): void
    {
        $rascunho = Edital::factory()->rascunho()->create();
        EditalChamada::factory()->for($rascunho)->create(['inicio' => '2026-11-01', 'fim' => null]);

        $this->assertSame([
            'Informe o título do edital.',
            'Envie o PDF do edital.',
            'Cadastre ao menos uma chamada com início e fim das inscrições.',
            'Marque ao menos uma natureza que pode se inscrever.',
            'Cadastre ao menos uma categoria.',
        ], $rascunho->pendenciasParaPublicar());
    }

    public function test_pendencias_por_campo_apontam_o_campo_que_resolve_cada_uma(): void
    {
        $rascunho = Edital::factory()->rascunho()->create();

        $this->assertSame([
            'titulo' => 'Informe o título do edital.',
            'pdf' => 'Envie o PDF do edital.',
            'chamadas' => 'Cadastre ao menos uma chamada com início e fim das inscrições.',
            'naturezas' => 'Marque ao menos uma natureza que pode se inscrever.',
            'categorias' => 'Cadastre ao menos uma categoria.',
        ], $rascunho->pendenciasPorCampo());
    }

    public function test_pendencias_para_publicar_vazias_quando_completo(): void
    {
        $edital = Edital::factory()->create(['naturezas' => [NaturezaProponente::PessoaFisica]]);
        EditalChamada::factory()->for($edital)->create();
        EditalCategoria::factory()->for($edital)->create();

        $this->assertSame([], $edital->fresh()->pendenciasParaPublicar());
    }

    public function test_relacoes_vem_ordenadas_por_ordem(): void
    {
        $edital = Edital::factory()->create();
        EditalChamada::factory()->for($edital)->create(['nome' => 'Chamada 2', 'ordem' => 2]);
        EditalChamada::factory()->for($edital)->create(['nome' => 'Chamada 1', 'ordem' => 1]);
        $categoria = EditalCategoria::factory()->for($edital)->create(['nome' => 'B', 'ordem' => 2]);
        EditalCategoria::factory()->for($edital)->create(['nome' => 'A', 'ordem' => 1]);
        $categoria->itens()->createMany([['nome' => 'Item 2', 'ordem' => 2], ['nome' => 'Item 1', 'ordem' => 1]]);
        EditalDocumento::factory()->for($edital)->create(['nome' => 'Doc 2', 'ordem' => 2]);
        EditalDocumento::factory()->for($edital)->create(['nome' => 'Doc 1', 'ordem' => 1]);
        EditalCriterio::factory()->for($edital)->create(['nome' => 'Crit 2', 'ordem' => 2]);
        EditalCriterio::factory()->for($edital)->create(['nome' => 'Crit 1', 'ordem' => 1]);

        $edital = $edital->fresh();

        $this->assertSame(['Chamada 1', 'Chamada 2'], $edital->chamadas->pluck('nome')->all());
        $this->assertSame(['A', 'B'], $edital->categorias->pluck('nome')->all());
        $this->assertSame(['Item 1', 'Item 2'], $edital->categorias[1]->itens->pluck('nome')->all());
        $this->assertSame(['Doc 1', 'Doc 2'], $edital->documentos->pluck('nome')->all());
        $this->assertSame(['Crit 1', 'Crit 2'], $edital->criterios->pluck('nome')->all());
    }

    public function test_pivos_ligam_documentos_e_criterios(): void
    {
        $edital = Edital::factory()->create();
        $categoria = EditalCategoria::factory()->for($edital)->create();
        $documento = EditalDocumento::factory()->for($edital)->create(['todas_categorias' => false]);
        $criterio = EditalCriterio::factory()->for($edital)->create(['todas_categorias' => false]);

        $documento->categorias()->attach($categoria);
        $criterio->categorias()->attach($categoria);

        $this->assertTrue($documento->categorias()->first()->is($categoria));
        $this->assertTrue($criterio->categorias()->first()->is($categoria));
    }

    public function test_casts_dos_campos_novos(): void
    {
        $edital = Edital::factory()->create([
            'tipo' => TipoEdital::Chamamento,
            'naturezas' => [NaturezaProponente::PessoaFisica, NaturezaProponente::Mei],
            'aceita_coletivos' => true,
            'inscricoes_por_proponente' => 2,
            'nota_corte' => 60.5,
            'cotas' => ['negros' => ['ativa' => true, 'percentual' => 25, 'quantidade' => null], 'base' => 'vagas'],
            'pontuacao_extra' => ['grupos' => [['grupo' => 'Mulheres', 'pontos_por_pessoa' => 1, 'maximo' => 3]], 'teto' => 5, 'conta_para_corte' => false],
            'informacoes' => ['vedacoes' => 'Servidores da Fundação.'],
        ])->fresh();

        $this->assertSame(TipoEdital::Chamamento, $edital->tipo);
        $this->assertSame([NaturezaProponente::PessoaFisica, NaturezaProponente::Mei], $edital->naturezas->all());
        $this->assertTrue($edital->aceita_coletivos);
        $this->assertSame(2, $edital->inscricoes_por_proponente);
        $this->assertSame('60.50', $edital->nota_corte);
        $this->assertSame(25, $edital->cotas['negros']['percentual']);
        $this->assertSame('vagas', $edital->cotas['base']);
        $this->assertSame(5, $edital->pontuacao_extra['teto']);
        $this->assertSame('Servidores da Fundação.', $edital->informacoes['vedacoes']);
        $this->assertSame('Chamamento Público', $edital->tipo->rotulo());
    }

    public function test_edital_antigo_sem_campos_novos_tem_naturezas_nulas(): void
    {
        $edital = Edital::factory()->create()->fresh();

        $this->assertNull($edital->naturezas);
        $this->assertNull($edital->cotas);
        $this->assertNull($edital->informacoes);
    }
}
