<?php

namespace Tests\Support;

use App\Enums\MarcacaoDoItem;
use App\Enums\SituacaoConferencia;
use App\Enums\TipoPergunta;
use App\Models\Edital;
use App\Models\EditalChamada;
use App\Models\EditalDocumento;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\Inscricao;
use App\Models\InscricaoConferencia;
use App\Models\InscricaoDocumento;
use App\Models\InscricaoResposta;
use App\Models\User;

/** Edital com período de homologação, inscrição finalizada e os itens que o gestor confere e marca. */
trait CenarioDaHomologacao
{
    /**
     * Edital publicado, com PDF, e uma chamada: inscrição de 01 a 30/11/2026 e homologação de 01 a 10/12/2026.
     *
     * @param  array<string, mixed>  $chamada  Atributos da chamada a trocar.
     */
    protected function editalEmHomologacao(array $chamada = []): Edital
    {
        $edital = Edital::factory()->create([
            'slug' => 'homologacao-'.fake()->unique()->numberBetween(1, 999999),
            'inscricoes_inicio' => '2026-11-01',
            'inscricoes_fim' => '2026-11-30',
            'publicado' => true,
            'pdf' => 'editais/teste.pdf',
        ]);

        EditalChamada::factory()->for($edital)->create([
            'inicio' => '2026-11-01',
            'fim' => '2026-11-30',
            'homologacao_inicio' => '2026-12-01',
            'homologacao_fim' => '2026-12-10',
            'ordem' => 1,
            ...$chamada,
        ]);

        return $edital;
    }

    /** Inscrição finalizada na chamada do edital, de um proponente com perfil (criado quando não vem o dono). */
    protected function inscricaoFinalizada(Edital $edital, ?User $dono = null): Inscricao
    {
        $dono ??= User::factory()->proponente()->comPerfil()->create();

        return Inscricao::factory()->for($edital)->for($dono)->finalizada()->create([
            'edital_chamada_id' => $edital->chamadas()->first()->id,
            'documento_cadastro' => $dono->cpf,
        ]);
    }

    /** Documento exigido pelo edital, no fim da lista. */
    protected function documentoExigido(Edital $edital, string $nome, bool $obrigatorio = true): EditalDocumento
    {
        return EditalDocumento::factory()->for($edital)->create([
            'nome' => $nome,
            'obrigatorio' => $obrigatorio,
            'ordem' => EditalDocumento::query()->where('edital_id', $edital->id)->count() + 1,
        ]);
    }

    /** Documento exigido pelo edital da inscrição, já enviado por ela. */
    protected function documentoEnviado(Inscricao $inscricao, string $nome, bool $obrigatorio = true, string $arquivo = 'inscricoes/original.pdf'): EditalDocumento
    {
        $exigido = $this->documentoExigido($inscricao->edital, $nome, $obrigatorio);

        InscricaoDocumento::factory()->create([
            'inscricao_id' => $inscricao->id,
            'edital_documento_id' => $exigido->id,
            'arquivo' => $arquivo,
            'arquivo_nome' => basename($arquivo),
            'situacao' => SituacaoConferencia::Enviado,
        ]);

        return $exigido;
    }

    /** Pergunta de texto curto para todas as categorias, numa seção nova do edital da inscrição, já respondida. */
    protected function perguntaRespondida(Inscricao $inscricao, string $enunciado, string $texto = 'Resposta'): EditalPergunta
    {
        $edital = $inscricao->edital;
        $ordem = EditalQuestionarioSecao::query()->where('edital_id', $edital->id)->count() + 1;
        $secao = EditalQuestionarioSecao::factory()->for($edital)->create(['ordem' => $ordem]);
        $pergunta = EditalPergunta::factory()->create([
            'edital_id' => $edital->id,
            'edital_questionario_secao_id' => $secao->id,
            'enunciado' => $enunciado,
            'tipo' => TipoPergunta::TextoCurto,
            'todas_categorias' => true,
        ]);

        InscricaoResposta::factory()->create([
            'inscricao_id' => $inscricao->id,
            'edital_pergunta_id' => $pergunta->id,
            'texto' => $texto,
        ]);

        return $pergunta;
    }

    /** Marcação do gestor sobre o documento ou a pergunta da inscrição. */
    protected function marcar(Inscricao $inscricao, EditalDocumento|EditalPergunta $item, MarcacaoDoItem $marcacao, ?string $observacao = null): InscricaoConferencia
    {
        return InscricaoConferencia::factory()->create([
            'inscricao_id' => $inscricao->id,
            'edital_documento_id' => $item instanceof EditalDocumento ? $item->id : null,
            'edital_pergunta_id' => $item instanceof EditalPergunta ? $item->id : null,
            'situacao' => $marcacao,
            'observacao' => $observacao,
        ]);
    }
}
