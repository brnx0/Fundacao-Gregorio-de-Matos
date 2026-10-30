<?php

namespace Tests\Feature\Questionario;

use App\Enums\TipoPergunta;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\Inscricao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstruturaDoQuestionarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_edital_sem_perguntas_nao_tem_pendencia_do_questionario(): void
    {
        $edital = Edital::factory()->create();

        $this->assertSame([], $edital->pendenciasDoQuestionario());
    }

    public function test_pergunta_sem_vinculo_entra_na_pendencia_com_a_contagem(): void
    {
        $edital = Edital::factory()->create();
        EditalPergunta::factory()->semVinculo()->create(['edital_id' => $edital->id]);

        $this->assertSame(['Vincule às categorias 1 pergunta do questionário.'], $edital->pendenciasDoQuestionario());

        EditalPergunta::factory()->count(2)->semVinculo()->create(['edital_id' => $edital->id]);
        EditalPergunta::factory()->create(['edital_id' => $edital->id]);

        $this->assertSame(['Vincule às categorias 3 perguntas do questionário.'], $edital->pendenciasDoQuestionario());
        $this->assertContains('Vincule às categorias 3 perguntas do questionário.', $edital->pendenciasParaPublicar());
    }

    public function test_pergunta_ligada_so_a_categoria_apagada_fica_sem_vinculo(): void
    {
        $edital = Edital::factory()->create();
        $categoria = EditalCategoria::factory()->create(['edital_id' => $edital->id]);
        $pergunta = EditalPergunta::factory()->semVinculo()->create(['edital_id' => $edital->id]);
        $pergunta->categorias()->attach($categoria);

        $this->assertFalse($pergunta->semVinculo());
        $this->assertSame([], $edital->pendenciasDoQuestionario());

        $categoria->delete();

        $this->assertTrue($pergunta->fresh()->semVinculo());
        $this->assertSame(['Vincule às categorias 1 pergunta do questionário.'], $edital->pendenciasDoQuestionario());
    }

    public function test_trava_com_inscricao_ja_finalizada_mesmo_reaberta(): void
    {
        $edital = Edital::factory()->create();
        Inscricao::factory()->create(['edital_id' => $edital->id]);

        $this->assertFalse($edital->questionarioTravado());

        Inscricao::factory()->finalizada()->create(['edital_id' => $edital->id, 'finalizada_em' => null]);

        $this->assertTrue($edital->questionarioTravado());
    }

    public function test_apagar_a_secao_apaga_as_perguntas_e_solta_a_condicao_de_outra_secao(): void
    {
        $edital = Edital::factory()->create();
        $secaoA = EditalQuestionarioSecao::factory()->create(['edital_id' => $edital->id]);
        $secaoB = EditalQuestionarioSecao::factory()->create(['edital_id' => $edital->id]);
        $origem = EditalPergunta::factory()->simNao()->create([
            'edital_id' => $edital->id, 'edital_questionario_secao_id' => $secaoA->id,
        ]);
        $dependente = EditalPergunta::factory()->condicionadaA($origem, ['sim'])->create([
            'edital_id' => $edital->id, 'edital_questionario_secao_id' => $secaoB->id,
        ]);

        $secaoA->delete();

        $this->assertDatabaseMissing('edital_perguntas', ['id' => $origem->id]);
        $this->assertNull($dependente->fresh()->condicao_pergunta_id);
    }

    public function test_vale_para_a_categoria(): void
    {
        $edital = Edital::factory()->create();
        $categoria = EditalCategoria::factory()->create(['edital_id' => $edital->id]);
        $outra = EditalCategoria::factory()->create(['edital_id' => $edital->id]);

        $todas = EditalPergunta::factory()->create(['edital_id' => $edital->id]);
        $this->assertTrue($todas->valePara(null));
        $this->assertTrue($todas->valePara($categoria->id));

        $algumas = EditalPergunta::factory()->semVinculo()->create(['edital_id' => $edital->id]);
        $algumas->categorias()->attach($categoria);
        $this->assertTrue($algumas->valePara($categoria->id));
        $this->assertFalse($algumas->valePara($outra->id));
        $this->assertFalse($algumas->valePara(null));

        $semVinculo = EditalPergunta::factory()->semVinculo()->create(['edital_id' => $edital->id]);
        $this->assertFalse($semVinculo->valePara($categoria->id));
    }

    public function test_chaves_das_opcoes_por_tipo(): void
    {
        $this->assertSame(['sim', 'nao'], EditalPergunta::factory()->simNao()->make()->chavesDasOpcoes());
        $this->assertSame(['declaro'], EditalPergunta::factory()->declaracao()->make()->chavesDasOpcoes());
        $this->assertSame([], EditalPergunta::factory()->make()->chavesDasOpcoes());

        $escolha = EditalPergunta::factory()->escolha(TipoPergunta::MultiplaEscolha, ['A', 'B'], true)->make();
        $this->assertSame(['o1', 'o2', 'o3'], $escolha->chavesDasOpcoes());
        $this->assertTrue($escolha->opcoes[2]['outro']);

        $this->assertSame(255, EditalPergunta::factory()->make()->limiteDeCaracteres());
        $this->assertSame(50, EditalPergunta::factory()->make(['limite_caracteres' => 50])->limiteDeCaracteres());
    }
}
