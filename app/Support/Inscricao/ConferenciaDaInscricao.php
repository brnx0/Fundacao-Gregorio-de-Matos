<?php

namespace App\Support\Inscricao;

use App\Enums\MarcacaoDoItem;
use App\Models\EditalDocumento;
use App\Models\EditalPergunta;
use App\Models\Inscricao;
use App\Models\InscricaoConferencia;
use Illuminate\Support\Collection;

/**
 * O que o gestor confere numa inscrição e como está marcado: os documentos exigidos que foram enviados mais os
 * obrigatórios sem envio (na ordem do edital) e as perguntas visíveis com as respostas salvas. Antes da decisão, o
 * obrigatório não enviado sem marcação gravada vale como problema "Não enviado." (automática); depois dela, só as
 * marcações gravadas contam.
 *
 * @phpstan-type Marcacao array{situacao: MarcacaoDoItem, observacao: ?string, automatica: bool, por: ?string, em: ?string}
 */
final class ConferenciaDaInscricao
{
    public const string NAO_ENVIADO = 'Não enviado.';

    /** @var list<int>|null ids dos documentos exigidos que a inscrição enviou */
    private ?array $idsEnviados = null;

    /** @var Collection<int, EditalDocumento>|null */
    private ?Collection $documentos = null;

    /** @var Collection<int, EditalPergunta>|null */
    private ?Collection $perguntas = null;

    /** @var array{documentos: array<int, Marcacao>, perguntas: array<int, Marcacao>}|null */
    private ?array $marcacoes = null;

    public function __construct(private Inscricao $inscricao) {}

    /**
     * Os documentos exigidos que foram enviados e os obrigatórios sem envio, na ordem do edital; o opcional não
     * enviado fica de fora.
     *
     * @return Collection<int, EditalDocumento>
     */
    public function documentos(): Collection
    {
        return $this->documentos ??= $this->inscricao->documentosExigidos()
            ->filter(fn (EditalDocumento $documento): bool => $documento->obrigatorio || $this->foiEnviado($documento))
            ->values();
    }

    /**
     * As perguntas visíveis com as respostas salvas, na ordem do questionário.
     *
     * @return Collection<int, EditalPergunta>
     */
    public function perguntas(): Collection
    {
        if ($this->perguntas !== null) {
            return $this->perguntas;
        }

        $questionario = new QuestionarioDaInscricao($this->inscricao);

        return $this->perguntas = $questionario->visiveis($questionario->respostasSalvas());
    }

    public function temDocumento(int $id): bool
    {
        return $this->documentos()->contains(fn (EditalDocumento $documento): bool => (int) $documento->id === $id);
    }

    public function temPergunta(int $id): bool
    {
        return $this->perguntas()->contains(fn (EditalPergunta $pergunta): bool => (int) $pergunta->id === $id);
    }

    /**
     * A marcação de cada item conferido, pelo id; item sem marcação fica de fora. `em` em d/m/Y H:i.
     *
     * @return array{documentos: array<int, Marcacao>, perguntas: array<int, Marcacao>}
     */
    public function marcacoes(): array
    {
        if ($this->marcacoes !== null) {
            return $this->marcacoes;
        }

        $gravadas = $this->inscricao->conferencias()->with('user:id,name')->get();
        $porDocumento = $gravadas->whereNotNull('edital_documento_id')->keyBy(fn (InscricaoConferencia $marcacao): int => (int) $marcacao->edital_documento_id);
        $porPergunta = $gravadas->whereNotNull('edital_pergunta_id')->keyBy(fn (InscricaoConferencia $marcacao): int => (int) $marcacao->edital_pergunta_id);
        $semDecisao = $this->inscricao->homologacao === null;
        $marcacoes = ['documentos' => [], 'perguntas' => []];

        foreach ($this->documentos() as $documento) {
            $id = (int) $documento->id;

            if ($porDocumento->has($id)) {
                $marcacoes['documentos'][$id] = self::gravada($porDocumento->get($id));
            } elseif ($semDecisao && ! $this->foiEnviado($documento)) {
                $marcacoes['documentos'][$id] = [
                    'situacao' => MarcacaoDoItem::Problema,
                    'observacao' => self::NAO_ENVIADO,
                    'automatica' => true,
                    'por' => null,
                    'em' => null,
                ];
            }
        }

        foreach ($this->perguntas() as $pergunta) {
            $id = (int) $pergunta->id;

            if ($porPergunta->has($id)) {
                $marcacoes['perguntas'][$id] = self::gravada($porPergunta->get($id));
            }
        }

        return $this->marcacoes = $marcacoes;
    }

    /** Todo documento e pergunta conferidos marcados como Ok; sem itens, também. */
    public function tudoOk(): bool
    {
        $marcacoes = $this->marcacoes();

        return $this->documentos()->every(fn (EditalDocumento $documento): bool => ($marcacoes['documentos'][(int) $documento->id]['situacao'] ?? null) === MarcacaoDoItem::Ok)
            && $this->perguntas()->every(fn (EditalPergunta $pergunta): bool => ($marcacoes['perguntas'][(int) $pergunta->id]['situacao'] ?? null) === MarcacaoDoItem::Ok);
    }

    /**
     * Os itens marcados com problema: os documentos e depois as perguntas, na ordem.
     *
     * @return list<array{tipo: 'documento'|'pergunta', id: int, nome: string, observacao: ?string}>
     */
    public function itensComProblema(): array
    {
        $marcacoes = $this->marcacoes();
        $itens = [];

        foreach ($this->documentosComProblema() as $documento) {
            $itens[] = [
                'tipo' => 'documento',
                'id' => (int) $documento->id,
                'nome' => $documento->nome,
                'observacao' => $marcacoes['documentos'][(int) $documento->id]['observacao'],
            ];
        }

        foreach ($this->perguntas() as $pergunta) {
            $marcacao = $marcacoes['perguntas'][(int) $pergunta->id] ?? null;

            if ($marcacao !== null && $marcacao['situacao'] === MarcacaoDoItem::Problema) {
                $itens[] = [
                    'tipo' => 'pergunta',
                    'id' => (int) $pergunta->id,
                    'nome' => $pergunta->enunciado,
                    'observacao' => $marcacao['observacao'],
                ];
            }
        }

        return $itens;
    }

    /**
     * Os documentos conferidos marcados com problema, na ordem do edital.
     *
     * @return Collection<int, EditalDocumento>
     */
    public function documentosComProblema(): Collection
    {
        $marcacoes = $this->marcacoes()['documentos'];

        return $this->documentos()
            ->filter(fn (EditalDocumento $documento): bool => ($marcacoes[(int) $documento->id]['situacao'] ?? null) === MarcacaoDoItem::Problema)
            ->values();
    }

    /**
     * O motivo da não homologação montado dos itens com problema, um grupo por linha ("Documentos: …" e
     * "Questionário: …"): cada item com a observação sem o ponto final, separados por "; ", e o grupo termina com um
     * ponto. Vazio sem problema.
     */
    public function motivoSugerido(): string
    {
        $grupos = ['documento' => [], 'pergunta' => []];

        foreach ($this->itensComProblema() as $item) {
            $grupos[$item['tipo']][] = $item['observacao'] === null
                ? $item['nome']
                : $item['nome'].' — '.rtrim($item['observacao'], '.');
        }

        $linhas = [];

        if ($grupos['documento'] !== []) {
            $linhas[] = 'Documentos: '.implode('; ', $grupos['documento']).'.';
        }

        if ($grupos['pergunta'] !== []) {
            $linhas[] = 'Questionário: '.implode('; ', $grupos['pergunta']).'.';
        }

        return implode("\n", $linhas);
    }

    /** Grava como marcação do usuário as automáticas (obrigatório não enviado), para valerem depois da decisão. */
    public function gravarProblemasAutomaticos(int $userId): void
    {
        foreach ($this->marcacoes()['documentos'] as $documentoId => $marcacao) {
            if ($marcacao['automatica']) {
                $this->inscricao->conferencias()->create([
                    'edital_documento_id' => $documentoId,
                    'situacao' => $marcacao['situacao'],
                    'observacao' => $marcacao['observacao'],
                    'user_id' => $userId,
                ]);
            }
        }

        $this->marcacoes = null;
    }

    private function foiEnviado(EditalDocumento $documento): bool
    {
        $this->idsEnviados ??= $this->inscricao->documentos()->pluck('edital_documento_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return in_array((int) $documento->id, $this->idsEnviados, true);
    }

    /**
     * @return Marcacao
     */
    private static function gravada(InscricaoConferencia $marcacao): array
    {
        return [
            'situacao' => $marcacao->situacao,
            'observacao' => $marcacao->observacao,
            'automatica' => false,
            'por' => $marcacao->user?->name,
            'em' => $marcacao->updated_at?->format('d/m/Y H:i'),
        ];
    }
}
