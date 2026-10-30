<?php

namespace App\Models;

use App\Enums\TipoCadastro;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['cpf', 'name', 'email', 'email_verified', 'phone', 'password', 'ativo'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * Mesmo padrão do banco, para que um usuário recém-criado (ainda não relido) já responda ehGestor().
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ativo' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'password' => 'hashed',
            'ativo' => 'boolean',
            'profile_completed_at' => 'datetime',
            'tipos_cadastro' => AsEnumCollection::of(TipoCadastro::class),
            'last_login_at' => 'datetime',
        ];
    }

    /** @return Attribute<?string, ?string> */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: function (?string $valor): ?string {
                $normalizado = mb_strtolower(trim((string) $valor));

                return $normalizado === '' ? null : $normalizado;
            },
        );
    }

    /** A pessoa já escolheu, na última etapa do cadastro, ser proponente, patrocinadora ou as duas coisas. */
    public function cadastroFinalizado(): bool
    {
        return $this->tipos_cadastro?->isNotEmpty() ?? false;
    }

    public function temTipoCadastro(TipoCadastro $tipo): bool
    {
        return $this->tipos_cadastro?->contains($tipo) ?? false;
    }

    /** Tela em que o portal abre: a do primeiro tipo escolhido (com os dois, a do proponente). */
    public function rotaInicialDoPortal(): string
    {
        return $this->tipos_cadastro->first()->rotaInicial();
    }

    /** O cadastro da pessoa física tem o perfil que os editais pedem. */
    public function perfilCompleto(): bool
    {
        return $this->pessoaFisica?->perfilCompleto() ?? false;
    }

    /** @return HasOne<PessoaFisica, $this> */
    public function pessoaFisica(): HasOne
    {
        return $this->hasOne(PessoaFisica::class);
    }

    /** @return Builder<Organizacao> */
    public function organizacoes(): Builder
    {
        return Organizacao::daPessoa($this);
    }

    /** @return BelongsToMany<Role, $this> */
    public function papeis(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function temPapel(string $slug): bool
    {
        return $this->papeis()->where('slug', $slug)->exists();
    }

    public function ehGestor(): bool
    {
        return $this->ativo && $this->papeis()->exists();
    }

    /** Pessoas do portal: enviaram a etapa "Seus dados" e não são usuárias da retaguarda. */
    public function scopeDoPortal(Builder $query): void
    {
        $query->whereHas('pessoaFisica')->whereDoesntHave('papeis');
    }
}
