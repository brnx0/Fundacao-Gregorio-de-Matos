<?php

namespace Tests\Feature\Retaguarda;

use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalCriterio;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/** Depois da primeira nota, critérios e categorias salvos ficam travados no edital (só nome e descrição mudam). */
class EditalCriteriosTravadosTest extends TestCase
{
    use RefreshDatabase;

    private Edital $edital;

    private EditalCategoria $teatro;

    private EditalCategoria $danca;

    private EditalCriterio $merito;

    private EditalCriterio $relevancia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->gestor()->create());

        $this->edital = Edital::factory()->create();
        $this->teatro = EditalCategoria::factory()->for($this->edital)->create(['nome' => 'Teatro', 'ordem' => 1]);
        $this->danca = EditalCategoria::factory()->for($this->edital)->create(['nome' => 'Dança', 'ordem' => 2]);
        $this->merito = EditalCriterio::factory()->for($this->edital)->create([
            'nome' => 'Mérito', 'peso' => '2', 'pontos_maximos' => '50', 'todas_categorias' => false, 'ordem' => 1,
        ]);
        $this->merito->categorias()->attach($this->teatro->id);
        $this->relevancia = EditalCriterio::factory()->for($this->edital)->create([
            'nome' => 'Relevância', 'peso' => null, 'pontos_maximos' => '10', 'todas_categorias' => true, 'ordem' => 2,
        ]);
    }

    /** Nota de rascunho numa inscrição do edital: a avaliação começou. */
    private function darNota(Edital $edital): void
    {
        $inscricao = Inscricao::factory()->finalizada()->create(['edital_id' => $edital->id]);

        InscricaoAvaliacao::factory()->create(['inscricao_id' => $inscricao->id])->notas()->create([
            'edital_criterio_id' => $this->merito->id, 'nota' => 8, 'peso' => 2, 'pontos_maximos' => 50,
        ]);
    }

    /**
     * As listas como estão gravadas, no formato do formulário (categorias dos critérios por posição).
     *
     * @return array<string, mixed>
     */
    private function envio(): array
    {
        $categorias = $this->edital->categorias()->orderBy('ordem')->get();
        $posicaoDaCategoria = $categorias->values()->mapWithKeys(fn (EditalCategoria $categoria, int $posicao): array => [(int) $categoria->id => $posicao])->all();

        return [
            'com_listas' => '1',
            'titulo' => $this->edital->titulo,
            'categorias' => $categorias->map(fn (EditalCategoria $categoria): array => ['id' => (int) $categoria->id, 'nome' => $categoria->nome])->all(),
            'criterios' => $this->edital->criterios()->orderBy('ordem')->with('categorias')->get()->map(fn (EditalCriterio $criterio): array => [
                'id' => (int) $criterio->id,
                'nome' => $criterio->nome,
                'descricao' => $criterio->descricao,
                'peso' => $criterio->peso,
                'pontos_maximos' => $criterio->pontos_maximos,
                'todas_categorias' => $criterio->todas_categorias ? '1' : '0',
                'categorias' => $criterio->categorias->map(fn (EditalCategoria $categoria): int => $posicaoDaCategoria[(int) $categoria->id])->all(),
            ])->all(),
        ];
    }

    private function assertCriteriosIntactos(): void
    {
        $this->assertSame(2, $this->edital->criterios()->count());
        $this->assertSame('2.00', $this->merito->refresh()->peso);
        $this->assertSame('50.00', $this->merito->pontos_maximos);
        $this->assertFalse($this->merito->todas_categorias);
        $this->assertSame([(int) $this->teatro->id], $this->merito->categorias()->pluck('edital_categorias.id')->map(fn (mixed $id): int => (int) $id)->all());
        $this->assertTrue($this->relevancia->refresh()->todas_categorias);
    }

    public function test_com_nota_nao_remove_criterio(): void
    {
        $this->darNota($this->edital);
        $dados = $this->envio();
        array_pop($dados['criterios']);

        $this->put("/retaguarda/editais/{$this->edital->slug}", $dados)
            ->assertSessionHasErrors(['criterios' => Edital::AVISO_CRITERIOS_TRAVADOS]);

        $this->assertCriteriosIntactos();
    }

    public function test_com_nota_nao_acrescenta_criterio(): void
    {
        $this->darNota($this->edital);
        $dados = $this->envio();
        $dados['criterios'][] = ['nome' => 'Novo', 'pontos_maximos' => '5', 'todas_categorias' => '1'];

        $this->put("/retaguarda/editais/{$this->edital->slug}", $dados)
            ->assertSessionHasErrors(['criterios' => Edital::AVISO_CRITERIOS_TRAVADOS]);

        $this->assertCriteriosIntactos();
    }

    public function test_com_nota_nao_muda_peso_pontos_nem_categorias(): void
    {
        $this->darNota($this->edital);

        $alteracoes = [
            'peso' => fn (array &$dados) => $dados['criterios'][0]['peso'] = '3',
            'peso vazio' => fn (array &$dados) => $dados['criterios'][0]['peso'] = '',
            'pontos máximos' => fn (array &$dados) => $dados['criterios'][1]['pontos_maximos'] = '10.01',
            'todas as categorias' => fn (array &$dados) => $dados['criterios'][0]['todas_categorias'] = '1',
            'outra categoria' => fn (array &$dados) => $dados['criterios'][0]['categorias'] = [1],
            'categoria nova' => function (array &$dados): void {
                $dados['categorias'][] = ['nome' => 'Circo'];
                $dados['criterios'][0]['categorias'] = [0, 2];
            },
            'id de categoria repetido' => function (array &$dados): void {
                $dados['categorias'][] = ['id' => (int) $this->teatro->id, 'nome' => 'Circo'];
                $dados['criterios'][0]['categorias'] = [2];
            },
        ];

        foreach ($alteracoes as $alteracao => $alterar) {
            $dados = $this->envio();
            $alterar($dados);

            $resposta = $this->put("/retaguarda/editais/{$this->edital->slug}", $dados);

            try {
                $resposta->assertSessionHasErrors(['criterios' => Edital::AVISO_CRITERIOS_TRAVADOS]);
            } catch (AssertionFailedError $falha) {
                $this->fail("Alteração aceita: {$alteracao}. {$falha->getMessage()}");
            }
            $this->assertCriteriosIntactos();
        }

        $this->assertSame(['Teatro', 'Dança'], $this->edital->categorias()->orderBy('ordem')->pluck('nome')->all());
    }

    public function test_com_nota_edita_nome_e_descricao(): void
    {
        $this->darNota($this->edital);
        $dados = $this->envio();
        $dados['criterios'][0]['nome'] = 'Mérito artístico';
        $dados['criterios'][0]['descricao'] = 'Qualidade da proposta.';
        $dados['criterios'][0]['peso'] = '2';
        $dados['criterios'][1]['pontos_maximos'] = '10';
        $dados['categorias'][] = ['nome' => 'Circo'];

        $this->put("/retaguarda/editais/{$this->edital->slug}", $dados)->assertSessionHasNoErrors();

        $this->assertSame('Mérito artístico', $this->merito->refresh()->nome);
        $this->assertSame('Qualidade da proposta.', $this->merito->descricao);
        $this->assertCriteriosIntactos();
        $this->assertSame(3, $this->edital->categorias()->count());
    }

    public function test_com_nota_preenche_pontuacao_maxima_em_branco(): void
    {
        $this->relevancia->update(['pontos_maximos' => null]);
        $this->darNota($this->edital);
        $dados = $this->envio();
        $dados['criterios'][1]['pontos_maximos'] = '10';

        $this->put("/retaguarda/editais/{$this->edital->slug}", $dados)->assertSessionHasNoErrors();

        $this->assertSame('10.00', $this->relevancia->refresh()->pontos_maximos);
        $this->assertCriteriosIntactos();
    }

    public function test_com_nota_nao_remove_categoria(): void
    {
        $this->darNota($this->edital);
        $dados = $this->envio();
        array_pop($dados['categorias']);

        $this->put("/retaguarda/editais/{$this->edital->slug}", $dados)
            ->assertSessionHasErrors(['categorias' => Edital::AVISO_CATEGORIAS_TRAVADAS])
            ->assertSessionDoesntHaveErrors('criterios');

        $this->assertSame(2, $this->edital->categorias()->count());
    }

    public function test_sem_nota_edita_criterios_normalmente(): void
    {
        $dados = $this->envio();
        $dados['criterios'][0]['peso'] = '3';
        $dados['criterios'][0]['categorias'] = [0];
        array_pop($dados['criterios']);
        array_pop($dados['categorias']);
        $dados['criterios'][] = ['nome' => 'Novo', 'pontos_maximos' => '5', 'todas_categorias' => '1'];

        $this->put("/retaguarda/editais/{$this->edital->slug}", $dados)->assertSessionHasNoErrors();

        $this->assertSame('3.00', $this->merito->refresh()->peso);
        $this->assertSame(['Mérito', 'Novo'], $this->edital->criterios()->orderBy('ordem')->pluck('nome')->all());
        $this->assertSame(1, $this->edital->categorias()->count());
    }

    public function test_nota_durante_o_salvamento_trava(): void
    {
        $dados = $this->envio();
        array_pop($dados['criterios']);

        $ativo = true;
        Edital::saving(function (Edital $salvando) use (&$ativo): void {
            if ($ativo && $salvando->exists) {
                $ativo = false;
                $this->darNota($salvando);
            }
        });

        $this->put("/retaguarda/editais/{$this->edital->slug}", $dados)
            ->assertSessionHasErrors(['criterios' => Edital::AVISO_CRITERIOS_TRAVADOS]);

        $this->assertFalse($ativo);
        $this->assertCriteriosIntactos();
    }

    public function test_tela_de_edicao_avisa_a_trava_dos_criterios(): void
    {
        $this->get("/retaguarda/editais/{$this->edital->slug}/edit")
            ->assertInertia(fn (Assert $p) => $p
                ->where('criteriosTravados', false)
                ->where('avisoCriteriosTravados', Edital::AVISO_CRITERIOS_TRAVADOS)
                ->where('avisoCategoriasTravadas', Edital::AVISO_CATEGORIAS_TRAVADAS));

        $this->darNota($this->edital);

        $this->get("/retaguarda/editais/{$this->edital->slug}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('criteriosTravados', true));
    }
}
