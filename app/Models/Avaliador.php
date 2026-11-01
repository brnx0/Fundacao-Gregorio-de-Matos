<?php

namespace App\Models;

use Database\Factories\AvaliadorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
