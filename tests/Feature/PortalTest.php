<?php

namespace Tests\Feature;

use App\Enums\EtapaDocumento;
use App\Enums\NaturezaProponente;
use App\Enums\TipoEdital;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalCategoriaItem;
use App\Models\EditalChamada;
use App\Models\EditalDocumento;
use App\Models\Evento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_lista_vitrine_e_eventos(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        Edital::factory()->create([
            'titulo' => 'PNAB Salvador — Ciclo II',
            'slug' => 'pnab-ii',
            'categoria' => 'Fomento',
            'resumo' => 'Apoio a projetos culturais da cidade.',
            'inscricoes_inicio' => '2026-11-01',
            'inscricoes_fim' => '2026-11-15',
            // Publicado antes de a imagem ser exigida: o carrossel usa as fotos fixas da vitrine.
            'imagem' => null,
        ]);
        Edital::factory()->create(['inscricoes_inicio' => '2026-11-05', 'inscricoes_fim' => '2026-11-30']);
        Edital::factory()->create(['inscricoes_inicio' => '2026-12-01', 'inscricoes_fim' => '2026-12-20']);
        Evento::factory()->create(['titulo' => 'Dois de Julho', 'data_realizacao' => '2027-07-02']);
        Evento::factory()->create(['titulo' => 'Jornada do Patrimônio', 'data_realizacao' => '2026-12-15', 'local' => 'Centro Histórico']);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Home')
            ->where('totalAbertos', 2)
            ->has('editais', 3)
            ->has('editais.0', fn (Assert $e) => $e
                ->where('titulo', 'PNAB Salvador — Ciclo II')
                ->where('resumo', 'Apoio a projetos culturais da cidade.')
                ->where('slug', 'pnab-ii')
                ->where('pdf', route('editais.pdf', 'pnab-ii'))
                ->where('categoria', 'Fomento')
                ->where('situacao', 'aberto')
                ->where('situacaoRotulo', 'Aberto')
                ->where('inscricoesInicio', '01/11/2026')
                ->where('inscricoesFim', '15/11/2026')
                ->where('imagem', null))
            ->where('editais.2.situacao', 'em_breve')
            ->where('editais.2.inscricoesInicio', '01/12/2026')
            ->has('eventos', 2)
            ->has('eventos.0', fn (Assert $e) => $e
                ->where('titulo', 'Jornada do Patrimônio')
                ->where('dia', '15')
                ->where('mes', 'dezembro')
                ->where('ano', '2026')
                ->where('local', 'Centro Histórico')
                ->where('imagem', null)
                ->has('resumo')
                ->has('slug')));
    }

    public function test_home_envia_a_imagem_cadastrada_do_edital(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        Edital::factory()->create(['imagem' => 'editais/capa.jpg', 'inscricoes_inicio' => '2026-11-01', 'inscricoes_fim' => '2026-11-30']);

        $this->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('editais.0.imagem', Storage::disk('public')->url('editais/capa.jpg')));
    }

    public function test_home_envia_todos_os_proximos_eventos_do_mais_proximo_ao_mais_distante(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        foreach ([8, 3, 5, 1, 7, 2, 6, 4] as $mes) {
            Evento::factory()->create(['titulo' => "Evento {$mes}", 'data_realizacao' => sprintf('2027-%02d-10', $mes)]);
        }

        $this->get('/')->assertInertia(fn (Assert $p) => $p
            ->has('eventos', 8)
            ->where('eventos.0.titulo', 'Evento 1')
            ->where('eventos.6.titulo', 'Evento 7')
            ->where('eventos.7.titulo', 'Evento 8'));
    }

    public function test_evento_passado_despublicado_e_excluido_ficam_de_fora(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        Evento::factory()->create(['titulo' => 'Aparece', 'data_realizacao' => '2026-11-10']);
        Evento::factory()->create(['data_realizacao' => '2026-11-09']);
        Evento::factory()->create(['data_realizacao' => '2026-12-01', 'publicado' => false]);
        Evento::factory()->create(['data_realizacao' => '2026-12-02'])->delete();

        $this->get('/')->assertInertia(fn (Assert $p) => $p
            ->has('eventos', 1)
            ->where('eventos.0.titulo', 'Aparece'));
    }

    public function test_home_vazia_nao_quebra(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('totalAbertos', 0)->has('editais', 0)->has('eventos', 0));
    }

    public function test_pagina_do_edital_publicado(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        Edital::factory()->create([
            'titulo' => 'SalCine 2',
            'slug' => 'salcine-2',
            'categoria' => 'Audiovisual',
            'resumo' => 'Apoio ao audiovisual.',
            'inscricoes_inicio' => '2026-11-05',
            'inscricoes_fim' => '2026-11-30',
        ]);

        $this->get('/editais/salcine-2')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Editais/Show')
            ->has('edital', fn (Assert $e) => $e
                ->where('titulo', 'SalCine 2')
                ->where('slug', 'salcine-2')
                ->where('categoria', 'Audiovisual')
                ->where('resumo', 'Apoio ao audiovisual.')
                ->where('situacao', 'aberto')
                ->where('situacaoRotulo', 'Aberto')
                ->where('inscricoesInicio', '05/11/2026')
                ->where('inscricoesFim', '30/11/2026')
                ->where('pdf', route('editais.pdf', 'salcine-2'))
                ->etc()));
    }

    public function test_pagina_do_edital_completo_envia_os_blocos_estruturados(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        $edital = Edital::factory()->create([
            'slug' => 'completo',
            'inscricoes_inicio' => '2026-11-05',
            'inscricoes_fim' => '2026-11-30',
            'numero' => '12/2026',
            'tipo' => TipoEdital::Chamamento,
            'objeto' => 'Apoiar a produção cultural.',
            'naturezas' => [NaturezaProponente::PessoaFisica, NaturezaProponente::Mei],
            'aceita_coletivos' => true,
            'inscricoes_por_proponente' => 2,
            'cotas' => [
                'negros' => ['ativa' => true, 'percentual' => 20, 'quantidade' => null],
                'indigenas' => ['ativa' => false, 'percentual' => 5, 'quantidade' => null],
                'pcd' => ['ativa' => true, 'percentual' => null, 'quantidade' => 3],
            ],
            'informacoes' => [
                'vedacoes' => "Primeira linha\nSegunda linha",
                'contatos' => '',
                'base_legal' => '  ',
                'anexos' => 'Anexo I',
            ],
        ]);
        EditalChamada::factory()->for($edital)->create(['nome' => 'Chamada 1', 'inicio' => '2026-11-01', 'fim' => '2026-11-08', 'ordem' => 1]);
        EditalChamada::factory()->for($edital)->create(['nome' => 'Chamada 2', 'inicio' => '2026-11-05', 'fim' => '2026-11-30', 'ordem' => 2]);
        EditalChamada::factory()->for($edital)->create(['nome' => 'Chamada 3', 'inicio' => '2026-12-01', 'fim' => '2026-12-10', 'ordem' => 3]);
        $categoria = EditalCategoria::factory()->for($edital)->create(['nome' => 'Apoio', 'valor_centavos' => 1234567, 'vagas' => 4]);
        EditalCategoriaItem::factory()->for($categoria, 'categoria')->create(['nome' => 'Casarão', 'valor_centavos' => 50000, 'vagas' => 1, 'suplentes' => 2]);
        $outra = EditalCategoria::factory()->for($edital)->create(['nome' => 'Outra', 'ordem' => 2]);
        $porCategoria = EditalDocumento::factory()->for($edital)->create([
            'nome' => 'Portfólio', 'obrigatorio' => false, 'todas_categorias' => false,
            'naturezas' => [NaturezaProponente::Mei], 'ordem' => 1,
        ]);
        $porCategoria->categorias()->attach($outra->id);
        EditalDocumento::factory()->for($edital)->create(['nome' => 'Comprovante de cota', 'etapa' => EtapaDocumento::ValidacaoCotas, 'ordem' => 2]);
        EditalDocumento::factory()->for($edital)->create(['nome' => 'Laudo', 'etapa' => EtapaDocumento::Habilitacao, 'ordem' => 3]);

        $this->get('/editais/completo')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('edital.numero', '12/2026')
            ->where('edital.tipoRotulo', TipoEdital::Chamamento->rotulo())
            ->where('edital.objeto', 'Apoiar a produção cultural.')
            ->has('edital.chamadas', 3)
            ->where('edital.chamadas.0.nome', 'Chamada 1')
            ->where('edital.chamadas.0.situacao', 'encerrado')
            ->where('edital.chamadas.1.situacao', 'aberto')
            ->where('edital.chamadas.1.inicio', '05/11/2026')
            ->where('edital.chamadas.2.situacao', 'em_breve')
            ->where('edital.naturezas', [NaturezaProponente::PessoaFisica->rotulo(), NaturezaProponente::Mei->rotulo()])
            ->where('edital.aceitaColetivos', true)
            ->where('edital.inscricoesPorProponente', 2)
            ->has('edital.categorias', 2)
            ->where('edital.categorias.0.nome', 'Apoio')
            ->where('edital.categorias.0.valor', 'R$ 12.345,67')
            ->where('edital.categorias.0.vagas', 4)
            ->where('edital.categorias.0.itens.0.nome', 'Casarão')
            ->where('edital.categorias.0.itens.0.valor', 'R$ 500,00')
            ->where('edital.categorias.0.itens.0.suplentes', 2)
            ->has('edital.cotas', 2)
            ->where('edital.cotas.0', ['modalidade' => 'Pessoas negras', 'percentual' => 20, 'quantidade' => null, 'base' => null])
            ->where('edital.cotas.1', ['modalidade' => 'Pessoas com deficiência', 'percentual' => null, 'quantidade' => 3, 'base' => null])
            ->has('edital.documentosInscricao', 1)
            ->where('edital.documentosInscricao.0.nome', 'Portfólio')
            ->where('edital.documentosInscricao.0.obrigatorio', false)
            ->where('edital.documentosInscricao.0.naturezas', [NaturezaProponente::Mei->rotulo()])
            ->where('edital.documentosInscricao.0.categorias', ['Outra'])
            ->where('edital.documentosInscricao.0.modeloUrl', null)
            ->has('edital.informacoes', 2)
            ->where('edital.informacoes.0', ['chave' => 'vedacoes', 'titulo' => 'Vedações', 'texto' => "Primeira linha\nSegunda linha"])
            ->where('edital.informacoes.1.chave', 'anexos'));
    }

    public function test_cotas_sobre_os_recursos_informam_a_base(): void
    {
        Edital::factory()->create([
            'slug' => 'cotas-em-recursos',
            'cotas' => [
                'negros' => ['ativa' => true, 'percentual' => 25, 'quantidade' => 50000],
                'base' => 'recursos',
            ],
        ]);

        $this->get('/editais/cotas-em-recursos')->assertInertia(fn (Assert $p) => $p
            ->where('edital.cotas', [['modalidade' => 'Pessoas negras', 'percentual' => 25, 'quantidade' => 50000, 'base' => 'recursos']]));
    }

    public function test_documento_com_modelo_envia_o_link_de_download(): void
    {
        $edital = Edital::factory()->create(['slug' => 'com-modelo']);
        $documento = EditalDocumento::factory()->for($edital)->create(['modelo' => 'x/modelo.docx', 'modelo_nome' => 'modelo.docx']);

        $this->get('/editais/com-modelo')->assertInertia(fn (Assert $p) => $p
            ->where('edital.documentosInscricao.0.modeloUrl', route('editais.documentos.modelo', [$edital, $documento])));
    }

    public function test_edital_antigo_sem_blocos_abre_sem_secoes_vazias(): void
    {
        Edital::factory()->create(['slug' => 'antigo']);

        $this->get('/editais/antigo')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('edital.numero', null)
            ->where('edital.tipoRotulo', null)
            ->where('edital.objeto', null)
            ->where('edital.chamadas', [])
            ->where('edital.naturezas', [])
            ->where('edital.categorias', [])
            ->where('edital.cotas', [])
            ->where('edital.documentosInscricao', [])
            ->where('edital.informacoes', []));
    }

    public function test_baixa_o_pdf_do_edital(): void
    {
        Storage::fake('local')->put('editais/teste.pdf', '%PDF-1.4');
        Edital::factory()->create(['slug' => 'com-pdf', 'pdf' => 'editais/teste.pdf']);

        $this->get('/editais/com-pdf/pdf')
            ->assertOk()
            ->assertDownload('edital-com-pdf.pdf')
            ->assertStreamedContent('%PDF-1.4');
    }

    public function test_edital_sem_pdf_da_404_na_pagina(): void
    {
        Edital::factory()->semPdf()->create(['slug' => 'sem-pdf-pagina']);

        $this->get('/editais/sem-pdf-pagina')->assertNotFound();
    }

    public function test_pdf_ausente_no_disco_da_404(): void
    {
        Storage::fake('local');
        Edital::factory()->create(['slug' => 'arquivo-sumiu', 'pdf' => 'editais/inexistente.pdf']);

        $this->get('/editais/arquivo-sumiu/pdf')->assertNotFound();
    }

    public function test_edital_sem_pdf_nao_aparece_no_portal(): void
    {
        Edital::factory()->semPdf()->create(['slug' => 'sem-pdf']);

        $this->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('totalAbertos', 0)->has('editais', 0));
        $this->get('/editais/sem-pdf/pdf')->assertNotFound();
    }

    public function test_edital_excluido_da_404_na_pagina_e_no_pdf(): void
    {
        Storage::fake('local')->put('editais/teste.pdf', '%PDF-1.4');
        Edital::factory()->create(['slug' => 'excluido'])->delete();

        $this->get('/editais/excluido')->assertNotFound();
        $this->get('/editais/excluido/pdf')->assertNotFound();
    }

    public function test_pdf_de_edital_despublicado_da_404(): void
    {
        Storage::fake('local')->put('editais/teste.pdf', '%PDF-1.4');
        Edital::factory()->create(['slug' => 'oculto-pdf', 'publicado' => false]);

        $this->get('/editais/oculto-pdf/pdf')->assertNotFound();
    }

    public function test_edital_despublicado_da_404(): void
    {
        Edital::factory()->create(['slug' => 'oculto', 'publicado' => false]);

        $this->get('/editais/oculto')->assertNotFound();
    }

    public function test_paginas_institucionais_abrem(): void
    {
        foreach ([
            '/a-fgm' => 'Institucional/AFgm',
            '/duvidas' => 'Institucional/Duvidas',
            '/privacidade' => 'Institucional/Privacidade',
            '/termos' => 'Institucional/Termos',
        ] as $url => $componente) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($componente));
        }
    }
}
