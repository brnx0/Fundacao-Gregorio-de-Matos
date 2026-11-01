<?php

namespace App\Models;

use Database\Factories\AvaliadorFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Avaliador cadastrado na retaguarda (Cadastro). Não se exclui, só se desativa. CPF com os 11 dígitos, sem pontuação.
 */
class Avaliador extends Model
{
    /** @use HasFactory<AvaliadorFactory> */
    use HasFactory;

    protected $table = 'avaliadores';

    protected $fillable = ['nome', 'data_nascimento', 'cpf', 'email', 'ativo'];

    /** O link de acesso (hash e texto cifrado) nunca é serializado nem preenchido em massa. */
    protected $hidden = ['acesso_hash', 'acesso_cifrado'];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'ativo' => 'boolean',
        ];
    }

    /** @return HasMany<EditalAvaliador, $this> */
    public function vinculos(): HasMany
    {
        return $this->hasMany(EditalAvaliador::class);
    }

    /** @return HasMany<InscricaoAvaliacao, $this> */
    public function avaliacoes(): HasMany
    {
        return $this->hasMany(InscricaoAvaliacao::class);
    }

    /**
     * Gera um link pessoal novo, que derruba o anterior (e as sessões abertas com ele). Grava só o hash, para achar
     * o avaliador, e o texto cifrado, para reenviar o link por e-mail; devolve o código cru.
     */
    public function novoAcesso(): string
    {
        $codigo = Str::random(48);

        $this->forceFill([
            'acesso_hash' => hash('sha256', $codigo),
            'acesso_cifrado' => Crypt::encryptString($codigo),
        ])->save();

        return $codigo;
    }

    /** Endereço completo do link pessoal (vai no e-mail). Null sem link gerado ou se o texto cifrado não abrir. */
    public function linkDeAcesso(): ?string
    {
        if ($this->acesso_cifrado === null) {
            return null;
        }

        try {
            $codigo = Crypt::decryptString($this->acesso_cifrado);
        } catch (DecryptException) {
            return null;
        }

        return route('avaliacao.acesso', ['codigo' => $codigo]);
    }

    /** Avaliador ativo dono deste código de link. */
    public static function porCodigo(string $codigo): ?self
    {
        return self::query()
            ->where('ativo', true)
            ->where('acesso_hash', hash('sha256', $codigo))
            ->first();
    }
}
