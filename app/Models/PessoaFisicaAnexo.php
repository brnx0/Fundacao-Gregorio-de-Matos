<?php

namespace App\Models;

use App\Enums\AnexoCadastro;
use App\Enums\ProvedorIa;
use App\Enums\SituacaoConferencia;
use App\Models\Concerns\ConferidoPelaIa;
use Carbon\CarbonInterface;
use Database\Factories\PessoaFisicaAnexoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo enviado na etapa Anexos do cadastro, com o resultado da conferência por IA (como InscricaoDocumento).
 *
 * execucao: identifica a conferência em curso; só o processo lançado com ela pode concluí-la.
 * provedor e modelo: os da Integração com IA que conferiram (ou, antes da resposta, os previstos).
 */
#[Fillable([
    'pessoa_fisica_id', 'campo', 'arquivo', 'arquivo_nome', 'situacao', 'motivo', 'erro',
    'provedor', 'modelo', 'execucao', 'iniciada_em', 'conferido_em',
])]
class PessoaFisicaAnexo extends Model
{
    use ConferidoPelaIa;

    /** @use HasFactory<PessoaFisicaAnexoFactory> */
    use HasFactory;

    /** Disco dos anexos do cadastro (privado: só sai por rota autenticada). */
    public const string DISCO = 'local';

    protected $table = 'pessoa_fisica_anexos';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'campo' => AnexoCadastro::class,
            'situacao' => SituacaoConferencia::class,
            'provedor' => ProvedorIa::class,
            'iniciada_em' => 'datetime',
            'conferido_em' => 'datetime',
        ];
    }

    /** Pasta dos anexos de um usuário no disco (a mesma de antes da etapa Anexos). */
    public static function pasta(User $user): string
    {
        return "cadastros/{$user->getKey()}";
    }

    /** @return BelongsTo<PessoaFisica, $this> */
    public function pessoaFisica(): BelongsTo
    {
        return $this->belongsTo(PessoaFisica::class);
    }

    /** Arquivo de imagem (mostrado em miniatura): pelo nome guardado; GIF só nas fotos de antes da etapa Anexos. */
    public function ehImagem(): bool
    {
        return in_array(strtolower(pathinfo($this->arquivo, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'], true);
    }

    /**
     * O resultado da IA para a equipe na retaguarda; nulo enquanto o arquivo só foi recebido, sem conferência.
     *
     * @return array{situacao: string, rotulo: string, motivo: string|null}|null
     */
    public function parecerDaIa(?CarbonInterface $agora = null): ?array
    {
        $situacao = $this->situacaoExibida($agora);

        if ($situacao === SituacaoConferencia::Enviado) {
            return null;
        }

        return [
            'situacao' => $situacao->value,
            'rotulo' => $situacao->rotulo(),
            'motivo' => $situacao === SituacaoConferencia::Reprovado ? $this->motivo : null,
        ];
    }
}
