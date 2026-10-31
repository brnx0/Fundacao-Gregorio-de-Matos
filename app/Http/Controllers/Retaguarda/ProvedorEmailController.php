<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\CriptografiaEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retaguarda\SalvarProvedorEmailRequest;
use App\Mail\EmailDeTeste;
use App\Models\ConfiguracaoEmail;
use App\Support\FalhaNoEnvioDeEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Parametrização: servidor SMTP dos e-mails do sistema (confirmação de cadastro, redefinição de senha, convites), com
 * o e-mail de teste.
 */
class ProvedorEmailController extends Controller
{
    public function edit(Request $request): Response
    {
        $configuracao = ConfiguracaoEmail::atual();

        return Inertia::render('Retaguarda/ProvedorEmail', [
            // A senha nunca vem para a tela: só se há uma guardada.
            'configuracao' => [
                'ativo' => $configuracao->ativo,
                'host' => $configuracao->host,
                'porta' => $configuracao->porta,
                'criptografia' => $configuracao->criptografia->value,
                'usuario' => $configuracao->usuario ?? '',
                'remetente_email' => $configuracao->remetente_email ?? '',
                'remetente_nome' => $configuracao->remetente_nome ?? '',
            ],
            'senhaGuardada' => $configuracao->senhaGuardada() !== null,
            'criptografias' => array_map(
                fn (CriptografiaEmail $criptografia): array => ['valor' => $criptografia->value, 'rotulo' => $criptografia->rotulo()],
                CriptografiaEmail::cases(),
            ),
            'emailDoTeste' => $request->user()->email,
        ]);
    }

    public function update(SalvarProvedorEmailRequest $request): RedirectResponse
    {
        ConfiguracaoEmail::atual()->fill($request->dados())->save();

        return redirect()->route('retaguarda.email.edit')->with('sucesso', 'Configuração de e-mail salva.');
    }

    /** Envia para quem pediu, pela configuração salva, mesmo com o envio desligado. */
    public function teste(Request $request): RedirectResponse
    {
        $configuracao = ConfiguracaoEmail::atual();

        if (! $configuracao->completa()) {
            return redirect()->route('retaguarda.email.edit')->with('erro', 'Salve a configuração antes de enviar o e-mail de teste.');
        }

        ConfiguracaoEmail::aplicar();
        $destino = $request->user()->email;

        try {
            Mail::mailer(ConfiguracaoEmail::MAILER)
                ->to($destino)
                ->send((new EmailDeTeste)->from($configuracao->remetente_email, $configuracao->remetente_nome));
        } catch (Throwable $falha) {
            // Só o tipo e o código: o texto do servidor pode trazer o usuário da conta.
            Log::warning('E-mail de teste: o envio falhou.', ['falha' => $falha::class, 'codigo' => $falha->getCode()]);

            return redirect()->route('retaguarda.email.edit')
                ->with('erro', FalhaNoEnvioDeEmail::mensagem($falha, $configuracao->host, $configuracao->porta));
        }

        return redirect()->route('retaguarda.email.edit')
            ->with('sucesso', "E-mail de teste enviado para {$destino}. Confira a caixa de entrada e o spam.");
    }
}
