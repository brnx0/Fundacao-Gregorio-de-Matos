<?php

namespace Tests\Feature\Inscricao;

use App\Enums\DecisaoRecurso;
use App\Enums\MarcacaoDoItem;
use App\Enums\SituacaoHomologacao;
use App\Models\EditalPergunta;
use App\Models\Inscricao;
use App\Models\InscricaoConferencia;
use App\Models\InscricaoRecurso;
use App\Models\InscricaoRecursoDocumento;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DadosDaHomologacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_decisao_e_gravada_com_quem_e_quando(): void
    {
        $inscricao = Inscricao::factory()->naoHomologada()->create();

        $inscricao = $inscricao->fresh();

        $this->assertSame(SituacaoHomologacao::NaoHomologada, $inscricao->homologacao);
        $this->assertSame('Documentos: RG — ilegível.', $inscricao->homologacao_motivo);
        $this->assertInstanceOf(User::class, $inscricao->homologador);
        $this->assertNotNull($inscricao->homologada_em);
    }

    public function test_marcacoes_de_documento_e_de_pergunta_convivem_e_a_repetida_e_recusada(): void
    {
        $inscricao = Inscricao::factory()->finalizada()->create();
        $primeira = EditalPergunta::factory()->create();
        $segunda = EditalPergunta::factory()->create();

        InscricaoConferencia::factory()->daPergunta($primeira)->problema()->create(['inscricao_id' => $inscricao->id]);
        InscricaoConferencia::factory()->daPergunta($segunda)->problema()->create(['inscricao_id' => $inscricao->id]);
        InscricaoConferencia::factory()->create(['inscricao_id' => $inscricao->id]);

        $this->assertSame(3, $inscricao->conferencias()->count());
        $this->assertSame(MarcacaoDoItem::Problema, $inscricao->conferencias()->where('edital_pergunta_id', $primeira->id)->first()->situacao);

        $this->expectException(UniqueConstraintViolationException::class);

        InscricaoConferencia::factory()->daPergunta($primeira)->create(['inscricao_id' => $inscricao->id]);
    }

    public function test_recurso_e_um_por_inscricao(): void
    {
        $inscricao = Inscricao::factory()->emRecurso()->create();
        InscricaoRecurso::factory()->create(['inscricao_id' => $inscricao->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        InscricaoRecurso::factory()->create(['inscricao_id' => $inscricao->id]);
    }

    public function test_apagar_a_inscricao_apaga_marcacoes_e_recurso(): void
    {
        $recurso = InscricaoRecurso::factory()->create();
        $inscricao = $recurso->inscricao;
        $marcacao = InscricaoConferencia::factory()->problema()->create(['inscricao_id' => $inscricao->id]);
        $arquivo = InscricaoRecursoDocumento::factory()->create(['inscricao_recurso_id' => $recurso->id]);

        $inscricao->delete();

        $this->assertModelMissing($marcacao);
        $this->assertModelMissing($recurso);
        $this->assertModelMissing($arquivo);
    }

    public function test_motivo_longo_vai_inteiro(): void
    {
        $motivo = str_repeat('ç', 4000);

        $inscricao = Inscricao::factory()->naoHomologada($motivo)->create();

        $this->assertSame($motivo, $inscricao->fresh()->homologacao_motivo);
    }

    public function test_decisao_do_recurso_e_gravada(): void
    {
        $recurso = InscricaoRecurso::factory()->create(['decisao' => DecisaoRecurso::Aceito]);

        $this->assertSame(DecisaoRecurso::Aceito, $recurso->fresh()->decisao);
    }

    public function test_rotulos_da_situacao(): void
    {
        $this->assertSame('Homologada', SituacaoHomologacao::Homologada->rotulo());
        $this->assertSame('Não homologada', SituacaoHomologacao::NaoHomologada->rotulo());
        $this->assertSame('Em recurso', SituacaoHomologacao::EmRecurso->rotulo());
        $this->assertSame('Recurso negado', SituacaoHomologacao::RecursoNegado->rotulo());

        $this->assertSame('Homologada', SituacaoHomologacao::Homologada->rotuloNoPortal());
        $this->assertSame('Não homologada', SituacaoHomologacao::NaoHomologada->rotuloNoPortal());
        $this->assertSame('Recurso em análise', SituacaoHomologacao::EmRecurso->rotuloNoPortal());
        $this->assertSame('Não homologada (recurso negado)', SituacaoHomologacao::RecursoNegado->rotuloNoPortal());
    }
}
