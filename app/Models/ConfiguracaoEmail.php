<?php

namespace App\Models;

use App\Enums\CriptografiaEmail;
use Database\Factories\ConfiguracaoEmailFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Servidor SMTP dos e-mails do sistema (Retaguarda > Parametrização > E-mail). Uma linha só, criada na primeira
 * gravação. A senha fica cifrada com a APP_KEY e nunca vai para o navegador. Ativa e completa, vale no lugar do .env
 * (ver aplicar()); desligada, o .env continua valendo, mas o e-mail de teste já usa o que está salvo.
 */
#[Fillable(['ativo', 'host', 'porta', 'criptografia', 'usuario', 'senha', 'remetente_email', 'remetente_nome'])]
#[Hidden(['senha'])]
class ConfiguracaoEmail extends Model
{
    /** @use HasFactory<ConfiguracaoEmailFactory> */
    use HasFactory;

    /** Nome do mailer montado com esta configuração (config mail.mailers). */
    public const string MAILER = 'configurado';

    private const string CHAVE_CACHE = 'configuracao-email';

    protected $table = 'configuracoes_email';

    /**
     * Mesmos padrões do banco, para valerem antes da primeira gravação (ver atual()).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ativo' => false,
        'host' => 'smtp.gmail.com',
        'porta' => 587,
        'criptografia' => 'tls',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'porta' => 'integer',
            'criptografia' => CriptografiaEmail::class,
            'senha' => 'encrypted',
        ];
    }

    /**
     * A configuração salva ou, enquanto ninguém salvou, uma nova com os padrões. Fica em cache e o cache é limpo
     * quando ela é salva ou apagada. O cache guarda só as colunas da linha (a senha, cifrada), como ConfiguracaoVLibras.
     */
    public static function atual(): self
    {
        /** @var array<string, mixed> $colunas */
        $colunas = Cache::rememberForever(
            self::CHAVE_CACHE,
            fn (): array => self::query()->orderBy('id')->first()?->getAttributes() ?? [],
        );

        return $colunas === [] ? new self : (new self)->newFromBuilder($colunas);
    }

    /**
     * Monta o mailer desta configuração e, ativa e completa, faz dele o padrão do sistema, com o remetente salvo.
     * Chamado quando o envio de e-mail é carregado (AppServiceProvider) e antes do e-mail de teste.
     */
    public static function aplicar(): void
    {
        $configuracao = self::atual();

        if (! $configuracao->completa()) {
            return;
        }

        config(['mail.mailers.'.self::MAILER => $configuracao->mailer()]);

        if ($configuracao->ativo) {
            config([
                'mail.default' => self::MAILER,
                'mail.from' => ['address' => $configuracao->remetente_email, 'name' => $configuracao->remetente_nome],
            ]);
        }
    }

    /**
     * A senha decifrada, ou null sem senha. Também null quando ela não decifra (APP_KEY trocada sem
     * APP_PREVIOUS_KEYS): a tela pede outra, em vez de quebrar.
     */
    public function senhaGuardada(): ?string
    {
        try {
            return $this->senha;
        } catch (DecryptException) {
            return null;
        }
    }

    /** Tem o que o envio precisa: servidor, porta, usuário, senha e remetente. */
    public function completa(): bool
    {
        return $this->exists
            && filled($this->host)
            && $this->porta > 0
            && filled($this->usuario)
            && $this->senhaGuardada() !== null
            && filled($this->remetente_email);
    }

    /**
     * Configuração do mailer SMTP no formato de config/mail.php.
     *
     * @return array{transport: string, scheme: string, host: string, port: int, username: string, password: string, timeout: int}
     */
    private function mailer(): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $this->criptografia->scheme(),
            'host' => $this->host,
            'port' => $this->porta,
            'username' => $this->usuario,
            'password' => (string) $this->senhaGuardada(),
            // Sem resposta do servidor, desiste em 15 s em vez de prender a requisição.
            'timeout' => 15,
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CHAVE_CACHE));
        static::deleted(fn () => Cache::forget(self::CHAVE_CACHE));
    }
}
