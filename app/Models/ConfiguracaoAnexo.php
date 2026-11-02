<?php

namespace App\Models;

use App\Enums\AnexoCadastro;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Como a IA confere cada anexo do cadastro (Retaguarda > Parametrização > Anexos do cadastro): uma linha por campo,
 * criada na migration. instrucao_ia é o que a IA verifica; fica guardada mesmo com a validação dispensada.
 */
#[Fillable(['campo', 'validar_ia', 'instrucao_ia'])]
class ConfiguracaoAnexo extends Model
{
    protected $table = 'configuracoes_anexos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'validar_ia' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'campo' => AnexoCadastro::class,
            'validar_ia' => 'boolean',
        ];
    }

    /** A configuração do campo ou, se a linha sumiu, uma nova sem conferência. */
    public static function doCampo(AnexoCadastro $campo): self
    {
        return self::query()->where('campo', $campo->value)->first() ?? new self(['campo' => $campo]);
    }

    /**
     * As configurações de todos os campos, por valor do campo (as que faltarem vêm sem conferência).
     *
     * @return array<string, self>
     */
    public static function porCampo(): array
    {
        /** @var Collection<int, self> $salvas */
        $salvas = self::query()->get()->keyBy(fn (self $configuracao): string => $configuracao->campo->value);

        $configuracoes = [];

        foreach (AnexoCadastro::cases() as $campo) {
            $configuracoes[$campo->value] = $salvas->get($campo->value) ?? new self(['campo' => $campo]);
        }

        return $configuracoes;
    }

    /** Se a IA confere o arquivo deste campo agora: só com a validação por IA ligada na Integração com IA. */
    public function confereNoCadastro(bool $validacaoLigada): bool
    {
        return $validacaoLigada && $this->validar_ia;
    }
}
