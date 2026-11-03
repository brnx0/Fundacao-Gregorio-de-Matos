<?php

namespace Tests\Feature\Avaliacao;

use App\Http\Controllers\Avaliacao\InscricoesDoAvaliadorController;
use App\Http\Requests\Avaliacao\SalvarNotasRequest;
use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalCriterio;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\InscricaoAvaliacaoNota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

/**
 * Área do avaliador: as inscrições dele, a inscrição só para leitura, as notas dos critérios e a conclusão. Inscrição
 * que não é dele dá 404 em tudo.
 */
class AvaliacaoDaInscricaoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private Edital $edital;

    private EditalCriterio $merito;

    private EditalCriterio $viabilidade;

    private Avaliador $ana;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-12-16 10:00', 'America/Bahia'));
        $this->edital = $this->editalEmHomologacao(['validacao_inicio' => '2026-12-15', 'validacao_fim' => '2026-12-20']);
        $this->merito = EditalCriterio::factory()->for($this->edital)->create([
            'nome' => 'Mérito cultural', 'peso' => 2, 'pontos_maximos' => 10, 'ordem' => 1,
        ]);
        $this->viabilidade = EditalCriterio::factory()->for($this->edital)->create([
            'nome' => 'Viabilidade', 'peso' => null, 'pontos_maximos' => 5, 'ordem' => 2,
        ]);
        $this->ana = Avaliador::factory()->create(['nome' => 'Ana']);
    }

    private function homologada(?Edital $edital = null, ?string $numero = null): Inscricao
    {
        $inscricao = $this->inscricaoFinalizada($edital ?? $this->edital);
        $inscricao->update(['homologacao' => 'homologada', ...($numero === null ? [] : ['numero' => $numero])]);

        return $inscricao;
    }

    private function atribuir(Inscricao $inscricao, Avaliador $avaliador): InscricaoAvaliacao
    {
        return InscricaoAvaliacao::factory()->create(['inscricao_id' => $inscricao->id, 'avaliador_id' => $avaliador->id]);
    }

    private function entrar(Avaliador $avaliador): void
    {
        $codigo = $avaliador->novoAcesso();

        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => $avaliador->cpf])->assertRedirect('/avaliacao');
    }

    /** Inscrição homologada da Ana, com ela já dentro da área. */
    private function inscricaoDaAna(): Inscricao
    {
        $inscricao = $this->homologada();
        $this->atribuir($inscricao, $this->ana);
        $this->entrar($this->ana);

        return $inscricao;
    }

    /**
     * @param  array<int|string, string|null>  $notas
     * @return array{notas: array<int|string, string|null>, observacao: ?string}
     */
    private function dados(array $notas, ?string $observacao = null): array
    {
        return ['notas' => $notas, 'observacao' => $observacao];
    }

    /** @return array<int, string> nota gravada pelo id do critério */
    private function notasGravadas(Inscricao $inscricao): array
    {
        return InscricaoAvaliacaoNota::query()
            ->whereHas('avaliacao', fn ($q) => $q->where('inscricao_id', $inscricao->id))
            ->get()
            ->mapWithKeys(fn (InscricaoAvaliacaoNota $nota): array => [(int) $nota->edital_criterio_id => $nota->nota])
            ->all();
    }

    public function test_inicio_mostra_so_as_inscricoes_dele(): void
    {
        $segunda = $this->homologada(numero: 'INS-0002');
        $primeira = $this->homologada(numero: 'INS-0001');
        $this->atribuir($segunda, $this->ana)->update(['concluida_em' => now(), 'nota_final' => '7.5']);
        $this->atribuir($primeira, $this->ana);
        $this->atribuir($this->homologada(numero: 'INS-0003'), Avaliador::factory()->create());
        // Finalizada sem homologação e edital apagado: não aparecem.
        $naoHomologada = $this->inscricaoFinalizada($this->edital);
        $this->atribuir($naoHomologada, $this->ana);
        $outroEdital = $this->editalEmHomologacao();
        $this->atribuir($this->homologada($outroEdital), $this->ana);
        $outroEdital->delete();
        $this->entrar($this->ana);

        $this->get('/avaliacao')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Avaliacao/Inicio')
            ->has('editais', 1)
            ->where('editais.0.titulo', $this->edital->titulo)
            ->where('editais.0.periodos', ['15/12/2026 a 20/12/2026'])
            ->has('editais.0.inscricoes', 2)
            ->where('editais.0.inscricoes.0.id', $primeira->id)
            ->where('editais.0.inscricoes.0.numero', 'INS-0001')
            ->where('editais.0.inscricoes.0.proponente', $primeira->user->name)
            ->where('editais.0.inscricoes.0.situacao', 'nao_iniciada')
            ->where('editais.0.inscricoes.0.situacaoRotulo', 'Não iniciada')
            ->where('editais.0.inscricoes.0.notaFinal', null)
            ->where('editais.0.inscricoes.1.id', $segunda->id)
            ->where('editais.0.inscricoes.1.situacaoRotulo', 'Concluída')
            ->where('editais.0.inscricoes.1.notaFinal', '7,50'));
    }

    public function test_inscricao_de_outro_avaliador_da_404(): void
    {
        $daAna = $this->homologada();
        $this->atribuir($daAna, $this->ana);
        $doBruno = $this->homologada();
        $this->atribuir($doBruno, Avaliador::factory()->create());
        $rgDoBruno = $this->documentoEnviado($doBruno, 'RG', arquivo: 'inscricoes/2/rg.pdf');
        Storage::disk('local')->put('inscricoes/2/rg.pdf', 'rg-do-bruno');
        $semAvaliador = $this->homologada();
        $this->entrar($this->ana);

        foreach ([$doBruno, $semAvaliador] as $inscricao) {
            $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertNotFound();
            $this->get("/avaliacao/inscricoes/{$inscricao->id}/documentos/{$rgDoBruno->id}/arquivo")->assertNotFound();
            $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '8']))->assertNotFound();
            $this->post("/avaliacao/inscricoes/{$inscricao->id}/conclusao", $this->dados([$this->merito->id => '8', $this->viabilidade->id => '4']))->assertNotFound();
        }

        // O documento da inscrição do Bruno pela inscrição da Ana: não é dela.
        $this->get("/avaliacao/inscricoes/{$daAna->id}/documentos/{$rgDoBruno->id}/arquivo")->assertNotFound();
        $this->assertSame(0, InscricaoAvaliacaoNota::query()->count());
        $this->assertNull(InscricaoAvaliacao::query()->where('inscricao_id', $doBruno->id)->value('concluida_em'));
    }

    public function test_abre_o_arquivo_da_propria_inscricao(): void
    {
        $inscricao = $this->inscricaoDaAna();
        $rg = $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/1/rg.pdf');
        Storage::disk('local')->put('inscricoes/1/rg.pdf', 'rg-original');
        $naoEnviado = $this->documentoExigido($this->edital, 'Comprovante');

        $resposta = $this->get("/avaliacao/inscricoes/{$inscricao->id}/documentos/{$rg->id}/arquivo");

        $resposta->assertOk()->assertStreamedContent('rg-original');
        $this->assertStringStartsWith('inline; filename=', $resposta->headers->get('Content-Disposition'));
        $this->get("/avaliacao/inscricoes/{$inscricao->id}/documentos/{$naoEnviado->id}/arquivo")->assertNotFound();
    }

    public function test_so_a_area_do_avaliador_abre_o_arquivo_num_quadro_da_propria_pagina(): void
    {
        $inscricao = $this->inscricaoDaAna();
        $rg = $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/1/rg.pdf');
        Storage::disk('local')->put('inscricoes/1/rg.pdf', 'rg-original');

        $arquivo = $this->get("/avaliacao/inscricoes/{$inscricao->id}/documentos/{$rg->id}/arquivo")->assertOk();
        $arquivo->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString("frame-ancestors 'self'", $arquivo->headers->get('Content-Security-Policy'));

        $pagina = $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertOk();
        $pagina->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-src 'self' ", $pagina->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $pagina->headers->get('Content-Security-Policy'));

        // Fora da área do avaliador, nada muda: nenhum quadro do próprio site.
        $this->assertStringNotContainsString("frame-src 'self'", $this->get('/')->headers->get('Content-Security-Policy'));
    }

    public function test_tipo_do_arquivo_sai_da_extensao_guardada_e_nao_do_nome_enviado(): void
    {
        $inscricao = $this->inscricaoDaAna();
        $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/1/rg.pdf');
        $this->documentoEnviado($inscricao, 'Foto', arquivo: 'inscricoes/1/foto.PNG');

        $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertInertia(fn (Assert $p) => $p
            ->where('documentos.0.enviado.tipo', 'pdf')
            ->where('documentos.1.enviado.tipo', 'imagem'));
    }

    public function test_props_sem_caminho_de_arquivo(): void
    {
        $inscricao = $this->inscricaoDaAna();
        $rg = $this->documentoEnviado($inscricao, 'RG', arquivo: 'inscricoes/guardado/rg.pdf');
        $pergunta = $this->perguntaRespondida($inscricao, 'Resumo do projeto', 'Um projeto');

        $resposta = $this->get("/avaliacao/inscricoes/{$inscricao->id}");

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Avaliacao/Inscricao')
            ->where('inscricao.id', $inscricao->id)
            ->where('inscricao.numero', $inscricao->numero)
            ->where('inscricao.edital', $this->edital->titulo)
            ->has('documentos', 1)
            ->where('documentos.0.nome', 'RG')
            ->where('documentos.0.enviado.nome', 'rg.pdf')
            ->where('documentos.0.enviado.url', route('avaliacao.inscricoes.arquivo', [$inscricao, $rg]))
            ->missing('documentos.0.ia')
            ->missing('documentos.0.marcacao')
            ->where('perguntasVisiveis', [$pergunta->id])
            ->where("respostas.{$pergunta->id}.texto", 'Um projeto')
            ->has('criterios', 2)
            ->where('criterios.0.id', $this->merito->id)
            ->where('criterios.0.nome', 'Mérito cultural')
            ->where('criterios.0.peso', '2,00')
            ->where('criterios.0.pontosMaximos', '10,00')
            ->where('criterios.1.peso', '1,00')
            ->where('notas', [])
            ->where('observacao', null)
            ->where('situacaoRotulo', 'Não iniciada')
            ->where('notaFinal', null)
            ->where('podeAvaliar', true)
            ->where('aviso', null));

        // O caminho no disco nunca vai para a tela (a URL da rota do avaliador tem "inscricoes/", o disco não aparece).
        $props = json_encode($resposta->viewData('page')['props'], JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('inscricoes/guardado', $props);
        $this->assertStringNotContainsString('inscricoes/', str_replace(url('/avaliacao/inscricoes/'), '', $props));
    }

    public function test_salva_rascunho_no_periodo_com_copia_de_peso_e_pontos(): void
    {
        $inscricao = $this->inscricaoDaAna();

        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados(
            [$this->merito->id => '8', $this->viabilidade->id => ''],
            'Projeto consistente.',
        ))->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('sucesso', InscricoesDoAvaliadorController::SUCESSO_RASCUNHO);

        $nota = InscricaoAvaliacaoNota::query()->sole();
        $this->assertSame((int) $this->merito->id, (int) $nota->edital_criterio_id);
        $this->assertSame('8.00', $nota->nota);
        $this->assertSame('2.00', $nota->peso);
        $this->assertSame('10.00', $nota->pontos_maximos);
        $avaliacao = InscricaoAvaliacao::query()->sole();
        $this->assertSame('Projeto consistente.', $avaliacao->observacao);
        $this->assertNull($avaliacao->concluida_em);

        // Deixar em branco apaga; o critério sem peso grava peso 1.
        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados(
            [$this->merito->id => null, $this->viabilidade->id => '4'],
        ))->assertSessionHasNoErrors();

        $nota = InscricaoAvaliacaoNota::query()->sole();
        $this->assertSame((int) $this->viabilidade->id, (int) $nota->edital_criterio_id);
        $this->assertSame('1.00', $nota->peso);
        $this->assertSame('5.00', $nota->pontos_maximos);
        $this->assertNull($avaliacao->fresh()->observacao);

        $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertInertia(fn (Assert $p) => $p
            ->where('notas', [(string) $this->viabilidade->id => '4,00'])
            ->where('situacaoRotulo', 'Em andamento'));
    }

    public function test_fora_do_periodo_so_consulta(): void
    {
        $inscricao = $this->inscricaoDaAna();

        foreach (['2026-12-14 23:00', '2026-12-21 08:00'] as $momento) {
            $this->travelTo(Carbon::parse($momento, 'America/Bahia'));

            $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('podeAvaliar', false)
                ->where('aviso', InscricoesDoAvaliadorController::ERRO_FORA_DO_PERIODO));
            $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '8']))
                ->assertSessionHas('erro', InscricoesDoAvaliadorController::ERRO_FORA_DO_PERIODO);
            $this->post("/avaliacao/inscricoes/{$inscricao->id}/conclusao", $this->dados([$this->merito->id => '8', $this->viabilidade->id => '4']))
                ->assertSessionHas('erro', InscricoesDoAvaliadorController::ERRO_FORA_DO_PERIODO);
        }

        $this->assertSame([], $this->notasGravadas($inscricao));

        $inscricao->chamada->update(['validacao_inicio' => null, 'validacao_fim' => null]);
        $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertInertia(fn (Assert $p) => $p
            ->where('podeAvaliar', false)
            ->where('aviso', InscricoesDoAvaliadorController::AVISO_SEM_PERIODO));
    }

    public function test_nota_acima_do_maximo_e_recusada(): void
    {
        $inscricao = $this->inscricaoDaAna();

        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '10.01', $this->viabilidade->id => '-1']))
            ->assertSessionHasErrors([
                "notas.{$this->merito->id}" => 'A nota de "Mérito cultural" vai de 0 a 10,00.',
                "notas.{$this->viabilidade->id}" => SalvarNotasRequest::ERRO_FORMATO,
            ]);

        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '10', $this->viabilidade->id => 'abc']))
            ->assertSessionHasErrors(["notas.{$this->viabilidade->id}" => SalvarNotasRequest::ERRO_FORMATO]);

        $this->assertSame([], $this->notasGravadas($inscricao));
    }

    public function test_aceita_virgula_e_duas_casas(): void
    {
        $inscricao = $this->inscricaoDaAna();

        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '7,255']))
            ->assertSessionHasErrors(["notas.{$this->merito->id}" => SalvarNotasRequest::ERRO_FORMATO]);

        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '7,25', $this->viabilidade->id => '4.5']))
            ->assertSessionHasNoErrors();

        $this->assertSame([(int) $this->merito->id => '7.25', (int) $this->viabilidade->id => '4.50'], $this->notasGravadas($inscricao));
    }

    public function test_criterio_que_nao_vale_e_recusado(): void
    {
        $outraCategoria = EditalCategoria::factory()->create(['edital_id' => $this->edital->id]);
        $daOutraCategoria = EditalCriterio::factory()->for($this->edital)->create(['todas_categorias' => false, 'ordem' => 3]);
        $daOutraCategoria->categorias()->attach($outraCategoria->id);
        $deOutroEdital = EditalCriterio::factory()->create();
        $inscricao = $this->inscricaoDaAna();

        foreach ([$daOutraCategoria->id, $deOutroEdital->id, 'abc'] as $criterio) {
            $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '8', $criterio => '1']))
                ->assertSessionHasErrors(['notas' => SalvarNotasRequest::ERRO_CRITERIO]);
        }

        $this->assertSame([], $this->notasGravadas($inscricao));
    }

    public function test_concluir_exige_todas_as_notas(): void
    {
        $inscricao = $this->inscricaoDaAna();

        $this->post("/avaliacao/inscricoes/{$inscricao->id}/conclusao", $this->dados([$this->merito->id => '8', $this->viabilidade->id => '']))
            ->assertSessionHas('erro', InscricoesDoAvaliadorController::ERRO_FALTAM_NOTAS);

        $avaliacao = InscricaoAvaliacao::query()->sole();
        $this->assertNull($avaliacao->concluida_em);
        $this->assertNull($avaliacao->nota_final);
        // A nota enviada fica como rascunho.
        $this->assertSame([(int) $this->merito->id => '8.00'], $this->notasGravadas($inscricao));
    }

    public function test_criterio_sem_pontuacao_maxima_nao_recebe_nota(): void
    {
        $this->viabilidade->update(['pontos_maximos' => null]);
        $inscricao = $this->inscricaoDaAna();

        $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertInertia(fn (Assert $p) => $p
            ->where('criterios.1.id', (int) $this->viabilidade->id)
            ->where('criterios.1.pontosMaximos', null));

        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '8', $this->viabilidade->id => '0']))
            ->assertSessionHasErrors(["notas.{$this->viabilidade->id}" => SalvarNotasRequest::ERRO_SEM_PONTUACAO]);

        $this->assertSame([], $this->notasGravadas($inscricao));
    }

    public function test_concluir_grava_nota_final_e_trava(): void
    {
        $inscricao = $this->inscricaoDaAna();

        $this->post("/avaliacao/inscricoes/{$inscricao->id}/conclusao", $this->dados(
            [$this->merito->id => '8,5', $this->viabilidade->id => '4'],
            'Bom projeto.',
        ))->assertRedirect("/avaliacao/inscricoes/{$inscricao->id}")
            ->assertSessionHas('sucesso', InscricoesDoAvaliadorController::SUCESSO_CONCLUSAO);

        $avaliacao = InscricaoAvaliacao::query()->sole();
        $this->assertSame('2026-12-16', $avaliacao->concluida_em->setTimezone('America/Bahia')->toDateString());
        $this->assertSame('21.00', $avaliacao->nota_final);
        $this->assertSame('Bom projeto.', $avaliacao->observacao);

        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '1', $this->viabilidade->id => '1']))
            ->assertSessionHas('erro', InscricoesDoAvaliadorController::ERRO_CONCLUIDA);
        $this->post("/avaliacao/inscricoes/{$inscricao->id}/conclusao", $this->dados([$this->merito->id => '1', $this->viabilidade->id => '1']))
            ->assertSessionHas('erro', InscricoesDoAvaliadorController::ERRO_CONCLUIDA);

        $this->assertSame([(int) $this->merito->id => '8.50', (int) $this->viabilidade->id => '4.00'], $this->notasGravadas($inscricao));
        $this->assertSame('21.00', $avaliacao->fresh()->nota_final);
        $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertInertia(fn (Assert $p) => $p
            ->where('podeAvaliar', false)
            ->where('aviso', InscricoesDoAvaliadorController::ERRO_CONCLUIDA)
            ->where('situacaoRotulo', 'Concluída')
            ->where('notaFinal', '21,00'));
    }

    public function test_observacao_longa_e_recusada(): void
    {
        $inscricao = $this->inscricaoDaAna();

        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([], str_repeat('a', 4001)))
            ->assertSessionHasErrors('observacao');
        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([], str_repeat('ç', 2500)))
            ->assertSessionHasErrors('observacao');
        $this->assertNull(InscricaoAvaliacao::query()->sole()->observacao);

        // "\r\n" conta como uma quebra só: 2000 linhas de "a\r\n" ficam em 4000 caracteres.
        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([], str_repeat("a\r\n", 2000)))
            ->assertSessionHasNoErrors();
        $this->assertSame(str_repeat("a\n", 1999).'a', InscricaoAvaliacao::query()->sole()->observacao);
    }

    public function test_inscricao_transferida_sai_do_avaliador_antigo(): void
    {
        $inscricao = $this->inscricaoDaAna();
        $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertOk();

        $bruno = Avaliador::factory()->create();
        InscricaoAvaliacao::query()->where('inscricao_id', $inscricao->id)->update(['avaliador_id' => $bruno->id]);

        $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertNotFound();
        $this->put("/avaliacao/inscricoes/{$inscricao->id}/notas", $this->dados([$this->merito->id => '8']))->assertNotFound();
        $this->post("/avaliacao/inscricoes/{$inscricao->id}/conclusao", $this->dados([$this->merito->id => '8', $this->viabilidade->id => '4']))->assertNotFound();
        $this->get('/avaliacao')->assertInertia(fn (Assert $p) => $p->where('editais', []));
        $this->assertSame([], $this->notasGravadas($inscricao));
    }

    public function test_nota_final_nao_muda_se_o_criterio_mudar_depois(): void
    {
        $inscricao = $this->inscricaoDaAna();
        $this->post("/avaliacao/inscricoes/{$inscricao->id}/conclusao", $this->dados([$this->merito->id => '8', $this->viabilidade->id => '4']))
            ->assertSessionHas('sucesso');

        EditalCriterio::query()->whereKey($this->merito->id)->update(['peso' => 5, 'pontos_maximos' => 50]);

        $this->assertSame('20.00', InscricaoAvaliacao::query()->sole()->nota_final);
        $this->get("/avaliacao/inscricoes/{$inscricao->id}")->assertInertia(fn (Assert $p) => $p
            ->where('criterios.0.peso', '2,00')
            ->where('criterios.0.pontosMaximos', '10,00')
            ->where('notaFinal', '20,00'));
    }
}
