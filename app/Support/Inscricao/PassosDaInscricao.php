<?php

namespace App\Support\Inscricao;

use App\Models\EditalDocumento;
use App\Models\EditalQuestionarioSecao;
use App\Models\Inscricao;

/**
 * Os passos da inscrição, na ordem: escolhas, uma seção do questionário por passo (só as que têm pergunta visível),
 * documentos e revisão. Um passo só abre com todos os anteriores completos.
 *
 * @phpstan-type Passo array{id: string, rotulo: string, completo: bool}
 */
final class PassosDaInscricao
{
    public const string ESCOLHAS = 'escolhas';

    public const string DOCUMENTOS = 'documentos';

    public const string REVISAO = 'revisao';

    public static function idDaSecao(int $id): string
    {
        return "secao-{$id}";
    }

    /**
     * @return list<Passo>
     */
    public static function para(Inscricao $inscricao, QuestionarioDaInscricao $questionario): array
    {
        $respostas = $questionario->respostasSalvas();

        // Mesma regra das escolhas (SalvarInscricaoRequest): a categoria só é exigida quando alguma aceita a natureza.
        $passos = [[
            'id' => self::ESCOLHAS,
            'rotulo' => 'Escolhas',
            'completo' => $inscricao->edital_categoria_id !== null
                || RegrasDaInscricao::categorias($inscricao->edital, $inscricao->natureza)->isEmpty(),
        ]];

        foreach ($questionario->secoesVisiveis($respostas) as $secao) {
            /** @var EditalQuestionarioSecao $secao */
            $passos[] = [
                'id' => self::idDaSecao((int) $secao->id),
                'rotulo' => $secao->titulo,
                'completo' => $questionario->faltando($respostas, (int) $secao->id) === [],
            ];
        }

        $enviados = $inscricao->documentos()->pluck('edital_documento_id')->map(fn (mixed $id): int => (int) $id)->all();
        $passos[] = [
            'id' => self::DOCUMENTOS,
            'rotulo' => 'Documentos',
            'completo' => $inscricao->documentosExigidos()
                ->filter(fn (EditalDocumento $documento): bool => $documento->obrigatorio && ! in_array((int) $documento->id, $enviados, true))
                ->isEmpty(),
        ];

        $passos[] = ['id' => self::REVISAO, 'rotulo' => 'Revisão e envio', 'completo' => false];

        return $passos;
    }

    /**
     * O passo a abrir: o pedido, se existe e todos os anteriores estão completos; senão o primeiro incompleto.
     *
     * @param  list<Passo>  $passos
     */
    public static function atual(array $passos, ?string $pedido): string
    {
        foreach ($passos as $passo) {
            if ($passo['id'] === $pedido) {
                return $pedido;
            }

            if (! $passo['completo']) {
                return $passo['id'];
            }
        }

        return $passos[array_key_last($passos)]['id'];
    }

    /**
     * O passo depois deste; o último continua nele. Se o passo não está na lista, o que abriria sem pedido.
     *
     * @param  list<Passo>  $passos
     */
    public static function seguinte(array $passos, string $id): string
    {
        foreach ($passos as $indice => $passo) {
            if ($passo['id'] === $id) {
                return $passos[$indice + 1]['id'] ?? $id;
            }
        }

        return self::atual($passos, null);
    }
}
