<?php

namespace App\Support\EditalIa;

use App\Enums\AlcanceCota;
use App\Enums\BaseCota;
use App\Enums\EtapaDocumento;
use App\Enums\ModalidadeCota;
use App\Enums\NaturezaProponente;
use App\Enums\TipoEdital;
use App\Models\Edital;

/**
 * Formato da resposta pedido ao Gemini (responseSchema, subconjunto OpenAPI: type, properties, items, enum,
 * nullable, required, description). Datas em texto AAAA-MM-DD; valores em reais como número.
 * Referências entre listas vão pelo nome (categoria do documento e do critério, chamada da categoria).
 */
final class EsquemaDaResposta
{
    use MontaEsquema;

    /** Blocos com nota de confiabilidade, na ordem de exibição: chave => rótulo. */
    public const array BLOCOS = [
        'identificacao' => 'Identificação',
        'inscricao' => 'Períodos',
        'participacao' => 'Participação',
        'categorias' => 'Categorias',
        'cotas' => 'Cotas',
        'documentos' => 'Documentos',
        'avaliacao' => 'Avaliação',
        'informacoes' => 'Informações',
    ];

    /** Tipos de ponto de atenção: valor => rótulo. */
    public const array TIPOS_ATENCAO = [
        'nao_encontrado' => 'Não encontrado',
        'divergencia' => 'Divergência no edital',
        'incerta' => 'Leitura incerta',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function esquema(): array
    {
        $naturezas = self::lista(self::enum(NaturezaProponente::class), 'Naturezas que podem se inscrever.');
        $nomesDeCategorias = self::lista(self::texto(), 'Nomes das categorias, exatamente como na lista de categorias; vazia = todas.');

        return self::objeto([
            'identificacao' => self::objeto([
                'numero' => self::texto('Número do edital, ex.: 007/2026.'),
                'tipo' => self::enum(TipoEdital::class, 'edital ou chamamento (chamamento público).'),
                'titulo' => self::texto(),
                'objeto' => self::texto('Objeto do edital, em texto curto.'),
                'resumo' => self::texto('Apresentação curta do edital para a vitrine, em até 3 frases.'),
                'categoria' => self::texto('Área cultural do edital, ex.: Audiovisual, Música, Artes cênicas.'),
                'dom_data' => self::data('Data de publicação no Diário Oficial do Município.'),
                'dom_numero' => self::texto('Número da edição do Diário Oficial do Município.'),
            ]),
            'inscricao' => self::objeto([
                'chamadas' => self::lista(self::objeto([
                    'nome' => self::texto('Ex.: Chamada 1. Edital sem chamadas tem uma só, com o nome Chamada única.'),
                    'inicio' => self::data('Início das inscrições.'),
                    'fim' => self::data('Fim das inscrições.'),
                    'homologacao_inicio' => self::data('Início da homologação; vazio se o edital só der prazo relativo.'),
                    'homologacao_fim' => self::data('Fim da homologação; vazio se o edital só der prazo relativo.'),
                    'validacao_inicio' => self::data('Início da avaliação dos projetos; vazio se o edital só der prazo relativo.'),
                    'validacao_fim' => self::data('Fim da avaliação dos projetos; vazio se o edital só der prazo relativo.'),
                ], ['nome'])),
                'forma_inscricao_original' => self::texto('Como a inscrição é feita no edital original (ex.: link do formulário).'),
            ], ['chamadas']),
            'participacao' => self::objeto([
                'naturezas' => $naturezas,
                'aceita_coletivos' => self::booleano('Aceita coletivos sem CNPJ representados por um proponente.'),
                'inscricoes_por_proponente' => self::inteiro('Número máximo de inscrições por proponente.'),
                'limite_por_chamada' => self::booleano('O limite de inscrições vale por chamada.'),
            ], ['naturezas']),
            'categorias' => self::lista(self::objeto([
                'nome' => self::texto(),
                'descricao' => self::texto(),
                'valor' => self::numero('Valor por proposta, em reais; vazio quando o edital usa faixa ou valor variável.'),
                'vagas' => self::inteiro(),
                'naturezas' => self::lista(self::enum(NaturezaProponente::class), 'Só quando a categoria restringe as naturezas do edital.'),
                'chamada' => self::texto('Nome da chamada da categoria, quando houver mais de uma.'),
                'itens' => self::lista(self::objeto([
                    'nome' => self::texto(),
                    'valor' => self::numero(),
                    'vagas' => self::inteiro(),
                    'suplentes' => self::inteiro(),
                ], ['nome']), 'Itens que o proponente escolhe dentro da categoria (ex.: bens, espaços).'),
            ], ['nome'])),
            'cotas' => self::objeto([
                ...array_combine(
                    array_map(fn (ModalidadeCota $modalidade): string => $modalidade->value, ModalidadeCota::cases()),
                    array_map(fn (ModalidadeCota $modalidade): array => self::objeto([
                        'ativa' => self::booleano("O edital reserva vagas ou recursos para {$modalidade->rotulo()}."),
                        'percentual' => self::numero(),
                        'quantidade' => self::numero('Vagas ou valor em reais, conforme a base.'),
                    ], ['ativa']), ModalidadeCota::cases()),
                ),
                'base' => self::enum(BaseCota::class, 'A reserva é calculada sobre vagas ou recursos.'),
                'alcance' => self::enum(AlcanceCota::class, 'A reserva vale para todo o edital, por categoria ou por chamada.'),
                'veda_negro_indigena' => self::booleano('É vedado concorrer às cotas de negros e indígenas ao mesmo tempo.'),
                'heteroidentificacao' => self::booleano('Exige banca de heteroidentificação.'),
            ]),
            'documentos' => self::lista(self::objeto([
                'nome' => self::texto(),
                'orientacao' => self::texto('Orientação curta sobre o documento.'),
                'etapa' => self::enum(EtapaDocumento::class, 'inscricao, validacao_cotas ou habilitacao.'),
                'obrigatorio' => self::booleano(),
                'naturezas' => self::lista(self::enum(NaturezaProponente::class), 'Naturezas a que se aplica; vazia = todas.'),
                'categorias' => $nomesDeCategorias,
                'modalidade_cota' => self::enum(ModalidadeCota::class, 'Só nos documentos da validação de cotas.'),
            ], ['nome', 'etapa'])),
            'avaliacao' => self::objeto([
                'nota_maxima' => self::inteiro(),
                'nota_corte' => self::numero(),
                'criterios' => self::lista(self::objeto([
                    'nome' => self::texto(),
                    'descricao' => self::texto(),
                    'peso' => self::numero(),
                    'pontos_maximos' => self::numero(),
                    'categorias' => $nomesDeCategorias,
                ], ['nome'])),
                'pontuacao_extra' => self::objeto([
                    'grupos' => self::lista(self::objeto([
                        'grupo' => self::texto('Ex.: mulheres, pessoas negras, LGBTQIAPN+.'),
                        'pontos_por_pessoa' => self::numero(),
                        'maximo' => self::numero('Máximo de pontos do grupo.'),
                    ], ['grupo'])),
                    'teto' => self::numero('Máximo de pontos extras no total.'),
                    'conta_para_corte' => self::booleano('A pontuação extra conta para atingir a nota de corte.'),
                ]),
            ], ['criterios']),
            'informacoes' => self::objeto(array_map(
                fn (string $rotulo): array => self::texto("Resumo fiel da seção \"{$rotulo}\" do edital."),
                Edital::SECOES_INFORMATIVAS,
            )),
            'confiabilidade' => self::objeto(array_map(
                fn (): array => self::objeto([
                    'nota' => self::inteiro('De 0 a 100.', false),
                    'justificativa' => self::texto(null, false),
                ], ['nota', 'justificativa']),
                self::BLOCOS,
            ), array_keys(self::BLOCOS)),
            'atencao' => self::lista(self::objeto([
                'bloco' => self::enumDe(array_keys(self::BLOCOS), null, false),
                'tipo' => self::enumDe(array_keys(self::TIPOS_ATENCAO), 'nao_encontrado, divergencia (o edital se contradiz) ou incerta (leitura incerta).', false),
                'texto' => self::texto(null, false),
                'itens' => self::lista(self::texto(null, false), 'Itens, subitens ou anexos do edital citados, ex.: item 5.1.'),
            ], ['bloco', 'tipo', 'texto', 'itens'])),
        ], ['identificacao', 'inscricao', 'participacao', 'categorias', 'cotas', 'documentos', 'avaliacao', 'informacoes', 'confiabilidade', 'atencao']);
    }
}
