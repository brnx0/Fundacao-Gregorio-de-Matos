<?php

namespace App\Support\Painel;

use App\Enums\TipoCadastro;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Filtros do Painel da retaguarda, já saneados: valor que não se reconhece vira "sem filtro", como nas listas.
 * O período é de dias civis (fuso da aplicação), e "até" inclui o dia inteiro.
 */
final readonly class FiltrosDoPainel
{
    public function __construct(
        public ?TipoCadastro $publico = null,
        public ?string $territorio = null,
        public ?int $editalId = null,
        public ?CarbonImmutable $de = null,
        public ?CarbonImmutable $ate = null,
    ) {}

    /**
     * @param  list<int>  $editais  Ids que o filtro de edital aceita.
     * @param  list<string>  $territorios  Códigos que o filtro de território aceita.
     */
    public static function daRequisicao(Request $request, array $editais, array $territorios): self
    {
        $territorio = (string) $request->query('territorio', '');
        $edital = (string) $request->query('edital', '');
        $de = self::dia($request->query('de'));
        $ate = self::dia($request->query('ate'));

        if ($de !== null && $ate !== null && $de->greaterThan($ate)) {
            [$de, $ate] = [$ate, $de];
        }

        return new self(
            publico: TipoCadastro::tryFrom((string) $request->query('publico', '')),
            territorio: in_array($territorio, $territorios, true) ? $territorio : null,
            editalId: ctype_digit($edital) && in_array((int) $edital, $editais, true) ? (int) $edital : null,
            de: $de,
            ate: $ate,
        );
    }

    /** Início do período (meia-noite do "de"), para colunas com hora. */
    public function inicio(): ?string
    {
        return $this->de?->toDateTimeString();
    }

    /** Fim aberto do período (meia-noite do dia seguinte ao "até"), para colunas com hora. */
    public function fim(): ?string
    {
        return $this->ate?->addDay()->toDateTimeString();
    }

    /** Último dia que as séries mostram: o "até" do filtro ou hoje. */
    public function ultimoDia(): CarbonImmutable
    {
        return $this->ate ?? CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /** Chave do cache: muda com qualquer filtro. */
    public function chave(): string
    {
        return sha1((string) json_encode($this->paraTela()));
    }

    /**
     * Filtros como a tela usa: '' é "sem filtro".
     *
     * @return array{publico: string, territorio: string, edital: string, de: string, ate: string}
     */
    public function paraTela(): array
    {
        return [
            'publico' => $this->publico?->value ?? '',
            'territorio' => $this->territorio ?? '',
            'edital' => $this->editalId === null ? '' : (string) $this->editalId,
            'de' => $this->de?->toDateString() ?? '',
            'ate' => $this->ate?->toDateString() ?? '',
        ];
    }

    /** Dia no formato do campo de data (aaaa-mm-dd); qualquer outra coisa é "sem data". */
    private static function dia(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }

        try {
            $dia = CarbonImmutable::createFromFormat('!Y-m-d', $valor, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        // 2026-02-31 "vira" 03/03: data que não existe é descartada.
        return $dia !== null && $dia->toDateString() === $valor ? $dia : null;
    }
}
