<?php

namespace App\Support\Inscricao;

use App\Enums\ModalidadeCota;
use App\Enums\NaturezaProponente;
use App\Enums\SituacaoInscricao;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalChamada;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\Inscricao;
use App\Models\User;
use App\Support\Cnpj;
use App\Support\Cpf;
use Illuminate\Support\Collection;

/** Dados que as telas da inscrição recebem: o edital, o que o proponente escolhe (cadastro, categoria e cotas) e o questionário. */
final class PropsDaInscricao
{
    /**
     * @return array{slug: string, titulo: string, categoria: string, periodo: ?string, pdf: ?string}
     */
    public static function edital(Edital $edital): array
    {
        return [
            'slug' => $edital->slug,
            'titulo' => $edital->titulo,
            'categoria' => $edital->categoria,
            // Sem chamadas com datas (a equipe pode tê-las apagado num edital publicado), o edital fica sem período.
            'periodo' => $edital->inscricoes_inicio && $edital->inscricoes_fim
                ? $edital->inscricoes_inicio->format('d/m/Y').' a '.$edital->inscricoes_fim->format('d/m/Y')
                : null,
            'pdf' => $edital->pdfUrl(),
        ];
    }

    /**
     * Inscrições do usuário no edital, da mais antiga para a mais nova.
     *
     * @return list<array{id: int, numero: ?string, situacao: string, situacaoRotulo: string, cadastro: string, categoria: ?string}>
     */
    public static function minhasInscricoes(User $user, Edital $edital): array
    {
        return Inscricao::query()
            ->where('edital_id', $edital->id)
            ->where('user_id', $user->id)
            ->with(['organizacao', 'categoria'])
            ->orderBy('id')
            ->get()
            ->map(fn (Inscricao $inscricao): array => [
                'id' => (int) $inscricao->id,
                'numero' => $inscricao->numero,
                'situacao' => $inscricao->situacao->value,
                'situacaoRotulo' => $inscricao->situacao->rotulo(),
                'cadastro' => self::nomeDoCadastro($inscricao, $user),
                'categoria' => $inscricao->categoria?->nome,
            ])
            ->all();
    }

    /** Valor com que o formulário escolhe o cadastro: `pessoa_fisica` ou `organizacao:{id}`. */
    public static function chaveDoCadastro(?int $organizacaoId): string
    {
        return $organizacaoId === null ? 'pessoa_fisica' : "organizacao:{$organizacaoId}";
    }

    /**
     * As escolhas possíveis do usuário neste edital.
     *
     * `limiteAtingido` só é verdadeiro quando nenhuma chamada aceita mais inscrição do cadastro (a chamada
     * depende da categoria, que ainda não foi escolhida); `inscricaoDeOutro` é a situação da inscrição do
     * mesmo CPF/CNPJ feita por outro usuário. Em `categorias`, `naturezas` vazio vale para todas.
     *
     * @return array{
     *     cadastros: list<array{chave: string, nome: string, documento: string, natureza: string, naturezaRotulo: string, aceito: bool, limiteAtingido: bool, inscricaoDeOutro: ?string}>,
     *     categorias: list<array{id: int, nome: string, naturezas: list<string>}>,
     *     cotas: list<array{valor: string, rotulo: string}>,
     *     vedaNegroIndigena: bool,
     * }
     */
    public static function opcoes(User $user, Edital $edital): array
    {
        $cadastros = RegrasDaInscricao::cadastros($user, $edital);
        $deOutros = self::situacaoDasInscricoesDeOutros($user, $edital, array_column($cadastros, 'documento'));

        return [
            'cadastros' => array_map(fn (array $cadastro): array => [
                'chave' => self::chaveDoCadastro($cadastro['id']),
                'nome' => $cadastro['nome'],
                'documento' => $cadastro['tipo'] === 'pessoa_fisica'
                    ? Cpf::formatar($cadastro['documento'])
                    : Cnpj::formatar($cadastro['documento']),
                'natureza' => $cadastro['natureza']->value,
                'naturezaRotulo' => $cadastro['natureza']->rotulo(),
                'aceito' => $cadastro['aceito'],
                'limiteAtingido' => self::limiteAtingido($edital, $cadastro['documento']),
                'inscricaoDeOutro' => $deOutros[$cadastro['documento']] ?? null,
            ], $cadastros),
            'categorias' => $edital->categorias()->get()
                ->map(fn (EditalCategoria $categoria): array => [
                    'id' => (int) $categoria->id,
                    'nome' => $categoria->nome,
                    'naturezas' => $categoria->naturezas?->map(fn (NaturezaProponente $natureza): string => $natureza->value)->values()->all() ?? [],
                ])
                ->all(),
            'cotas' => array_map(
                fn (ModalidadeCota $cota): array => ['valor' => $cota->value, 'rotulo' => $cota->rotulo()],
                RegrasDaInscricao::cotasOferecidas($edital),
            ),
            'vedaNegroIndigena' => ($edital->cotas['veda_negro_indigena'] ?? null) === true,
        ];
    }

    /**
     * As seções do questionário que valem para a inscrição, cada uma com todas as perguntas que valem (visíveis ou não).
     *
     * @return list<array{id: int, titulo: string, descricao: ?string, perguntas: list<array<string, mixed>>}>
     */
    public static function secoes(QuestionarioDaInscricao $questionario): array
    {
        return $questionario->secoesQueValem()
            ->map(fn (EditalQuestionarioSecao $secao): array => [
                'id' => (int) $secao->id,
                'titulo' => $secao->titulo,
                'descricao' => $secao->descricao,
                'perguntas' => $secao->perguntas->map(fn (EditalPergunta $pergunta): array => self::pergunta($pergunta))->all(),
            ])
            ->all();
    }

    /**
     * A pergunta como a tela usa. Sim/não e declaração vão sem opções: a tela usa as chaves fixas.
     *
     * @return array{id: int, enunciado: string, ajuda: ?string, tipo: string, obrigatoria: bool, limite: ?int, opcoes: list<array{chave: string, texto: string, outro: bool}>, condicao: array{pergunta: int, opcoes: list<string>}|null}
     */
    public static function pergunta(EditalPergunta $pergunta): array
    {
        return [
            'id' => (int) $pergunta->id,
            'enunciado' => $pergunta->enunciado,
            'ajuda' => $pergunta->ajuda,
            'tipo' => $pergunta->tipo->value,
            'obrigatoria' => $pergunta->obrigatoria,
            'limite' => $pergunta->limiteDeCaracteres(),
            'opcoes' => $pergunta->tipo->temOpcoes()
                ? array_values(array_map(fn (array $opcao): array => [
                    'chave' => (string) $opcao['chave'],
                    'texto' => (string) $opcao['texto'],
                    'outro' => (bool) ($opcao['outro'] ?? false),
                ], $pergunta->opcoes ?? []))
                : [],
            'condicao' => $pergunta->condicao_pergunta_id === null ? null : [
                'pergunta' => (int) $pergunta->condicao_pergunta_id,
                'opcoes' => array_values($pergunta->condicao_opcoes ?? []),
            ],
        ];
    }

    /** Nome do cadastro com que a inscrição foi feita: a organização ou, sem ela, o próprio usuário. */
    public static function nomeDoCadastro(Inscricao $inscricao, User $user): string
    {
        return $inscricao->organizacao?->nome_fantasia ?? $inscricao->organizacao?->razao_social ?? $user->name;
    }

    /**
     * Situação (`rascunho` ou `finalizada`) das inscrições de outros usuários no edital, por CPF/CNPJ;
     * a finalizada pesa mais que o rascunho.
     *
     * @param  list<string>  $documentos
     * @return array<string, string>
     */
    private static function situacaoDasInscricoesDeOutros(User $user, Edital $edital, array $documentos): array
    {
        return Inscricao::query()
            ->where('edital_id', $edital->id)
            ->where('user_id', '!=', $user->id)
            ->whereIn('documento_cadastro', $documentos)
            ->get(['documento_cadastro', 'situacao'])
            ->groupBy('documento_cadastro')
            ->map(fn (Collection $inscricoes): string => $inscricoes->contains(fn (Inscricao $inscricao): bool => $inscricao->situacao === SituacaoInscricao::Finalizada)
                ? SituacaoInscricao::Finalizada->value
                : SituacaoInscricao::Rascunho->value)
            ->all();
    }

    private static function limiteAtingido(Edital $edital, string $documentoCadastro): bool
    {
        $chamadas = $edital->limite_por_chamada ? $edital->chamadas()->get() : new Collection;

        if ($chamadas->isEmpty()) {
            return RegrasDaInscricao::limiteAtingido($edital, null, $documentoCadastro);
        }

        return $chamadas->every(fn (EditalChamada $chamada): bool => RegrasDaInscricao::limiteAtingido($edital, $chamada, $documentoCadastro));
    }
}
