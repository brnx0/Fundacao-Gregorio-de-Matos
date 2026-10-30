<?php

namespace Tests\Feature\Inscricao;

use App\Enums\MarcacaoDoItem;
use App\Models\EditalDocumento;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\Inscricao;
use App\Models\InscricaoResposta;
use App\Models\User;
use App\Support\Inscricao\ConferenciaDaInscricao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class ConferenciaDaInscricaoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    public function test_confere_os_enviados_e_os_obrigatorios_que_faltam(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $rg = $this->documentoEnviado($inscricao, 'RG');
        $portfolio = $this->documentoEnviado($inscricao, 'Portfólio', obrigatorio: false);
        $certidao = $this->documentoExigido($inscricao->edital, 'Certidão');
        $carta = $this->documentoExigido($inscricao->edital, 'Carta de anuência', obrigatorio: false);

        $conferencia = new ConferenciaDaInscricao($inscricao);

        $this->assertSame(
            [(int) $rg->id, (int) $portfolio->id, (int) $certidao->id],
            $conferencia->documentos()->map(fn (EditalDocumento $documento): int => (int) $documento->id)->all(),
        );
        $this->assertTrue($conferencia->temDocumento((int) $certidao->id));
        $this->assertFalse($conferencia->temDocumento((int) $carta->id));
    }

    public function test_obrigatorio_nao_enviado_vem_como_problema_e_pode_ser_marcado_ok(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $certidao = $this->documentoExigido($inscricao->edital, 'Certidão');

        $automatica = (new ConferenciaDaInscricao($inscricao))->marcacoes()['documentos'][(int) $certidao->id];

        $this->assertSame(MarcacaoDoItem::Problema, $automatica['situacao']);
        $this->assertSame(ConferenciaDaInscricao::NAO_ENVIADO, $automatica['observacao']);
        $this->assertTrue($automatica['automatica']);
        $this->assertNull($automatica['por']);

        $this->marcar($inscricao, $certidao, MarcacaoDoItem::Ok);
        $gravada = (new ConferenciaDaInscricao($inscricao))->marcacoes()['documentos'][(int) $certidao->id];

        $this->assertSame(MarcacaoDoItem::Ok, $gravada['situacao']);
        $this->assertFalse($gravada['automatica']);
        $this->assertNotNull($gravada['por']);
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $gravada['em']);
    }

    public function test_pergunta_escondida_pela_condicao_nao_e_conferida(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $secao = EditalQuestionarioSecao::factory()->for($inscricao->edital)->create(['ordem' => 1]);
        $origem = EditalPergunta::factory()->simNao()->for($inscricao->edital)->create(['edital_questionario_secao_id' => $secao->id, 'ordem' => 1]);
        $escondida = EditalPergunta::factory()->condicionadaA($origem, ['sim'])->for($inscricao->edital)->create(['edital_questionario_secao_id' => $secao->id, 'ordem' => 2]);
        InscricaoResposta::factory()->opcoes(['nao'])->create(['inscricao_id' => $inscricao->id, 'edital_pergunta_id' => $origem->id]);

        $conferencia = new ConferenciaDaInscricao($inscricao);

        $this->assertSame([(int) $origem->id], $conferencia->perguntas()->map(fn (EditalPergunta $pergunta): int => (int) $pergunta->id)->all());
        $this->assertTrue($conferencia->temPergunta((int) $origem->id));
        $this->assertFalse($conferencia->temPergunta((int) $escondida->id));
        $this->assertArrayNotHasKey((int) $escondida->id, $conferencia->marcacoes()['perguntas']);
    }

    public function test_tudo_ok_so_com_todos_os_itens_marcados_ok(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $rg = $this->documentoEnviado($inscricao, 'RG');
        $nome = $this->perguntaRespondida($inscricao, 'Nome do projeto');

        $this->assertTrue((new ConferenciaDaInscricao($this->inscricaoFinalizada($this->editalEmHomologacao())))->tudoOk());

        $this->marcar($inscricao, $rg, MarcacaoDoItem::Ok);
        $this->assertFalse((new ConferenciaDaInscricao($inscricao))->tudoOk());

        $this->marcar($inscricao, $nome, MarcacaoDoItem::Ok);
        $this->assertTrue((new ConferenciaDaInscricao($inscricao))->tudoOk());
    }

    public function test_motivo_montado_dos_itens_com_problema(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $rg = $this->documentoEnviado($inscricao, 'RG');
        $certidao = $this->documentoExigido($inscricao->edital, 'Certidão');
        $nome = $this->perguntaRespondida($inscricao, 'Nome do projeto');
        $this->marcar($inscricao, $rg, MarcacaoDoItem::Problema, 'Ilegível.');
        $this->marcar($inscricao, $nome, MarcacaoDoItem::Problema, 'Vazio');

        $conferencia = new ConferenciaDaInscricao($inscricao);

        $this->assertSame("Documentos: RG — Ilegível; Certidão — Não enviado.\nQuestionário: Nome do projeto — Vazio.", $conferencia->motivoSugerido());
        $this->assertSame([
            ['tipo' => 'documento', 'id' => (int) $rg->id, 'nome' => 'RG', 'observacao' => 'Ilegível.'],
            ['tipo' => 'documento', 'id' => (int) $certidao->id, 'nome' => 'Certidão', 'observacao' => ConferenciaDaInscricao::NAO_ENVIADO],
            ['tipo' => 'pergunta', 'id' => (int) $nome->id, 'nome' => 'Nome do projeto', 'observacao' => 'Vazio'],
        ], $conferencia->itensComProblema());
        $this->assertSame([(int) $rg->id, (int) $certidao->id], $conferencia->documentosComProblema()->map(fn (EditalDocumento $documento): int => (int) $documento->id)->all());
    }

    public function test_com_decisao_vale_so_o_que_foi_gravado(): void
    {
        $edital = $this->editalEmHomologacao();
        $inscricao = Inscricao::factory()->for($edital)->naoHomologada()->create([
            'edital_chamada_id' => $edital->chamadas()->first()->id,
        ]);
        $rg = $this->documentoEnviado($inscricao, 'RG');
        $this->marcar($inscricao, $rg, MarcacaoDoItem::Problema, 'Ilegível.');
        $novo = $this->documentoExigido($edital, 'Certidão');

        $conferencia = new ConferenciaDaInscricao($inscricao);

        $this->assertArrayNotHasKey((int) $novo->id, $conferencia->marcacoes()['documentos']);
        $this->assertSame([
            ['tipo' => 'documento', 'id' => (int) $rg->id, 'nome' => 'RG', 'observacao' => 'Ilegível.'],
        ], $conferencia->itensComProblema());
    }

    public function test_grava_os_problemas_automaticos(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $certidao = $this->documentoExigido($inscricao->edital, 'Certidão');
        $gestor = User::factory()->gestor()->create();

        (new ConferenciaDaInscricao($inscricao))->gravarProblemasAutomaticos((int) $gestor->id);

        $gravada = $inscricao->conferencias()->sole();
        $this->assertSame((int) $certidao->id, (int) $gravada->edital_documento_id);
        $this->assertSame(MarcacaoDoItem::Problema, $gravada->situacao);
        $this->assertSame(ConferenciaDaInscricao::NAO_ENVIADO, $gravada->observacao);
        $this->assertSame((int) $gestor->id, (int) $gravada->user_id);
    }
}
