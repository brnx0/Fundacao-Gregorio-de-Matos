<?php

namespace App\Support\Avaliacao;

use App\Models\Avaliador;
use Illuminate\Http\Request;
use LogicException;

/**
 * Avaliador que entrou pelo link pessoal. A sessão guarda o id e o hash do link (nunca o código): link novo ou
 * avaliador desativado deixam de conferir na requisição seguinte. Não usa a autenticação de usuários (o avaliador
 * não tem conta), e convive com o proponente logado na mesma sessão.
 */
final class AvaliadorDaSessao
{
    public const string SESSAO_AVALIADOR = 'avaliacao.avaliador';

    public const string SESSAO_ACESSO = 'avaliacao.acesso';

    /** Onde GarantirAvaliador deixa o avaliador conferido, para os controllers e as props compartilhadas. */
    private const string ATRIBUTO = 'avaliacao.avaliador';

    public static function entrar(Request $request, Avaliador $avaliador): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSAO_AVALIADOR, (int) $avaliador->id);
        $request->session()->put(self::SESSAO_ACESSO, $avaliador->acesso_hash);
    }

    /** Tira só as chaves do avaliador; o resto da sessão (proponente logado, por exemplo) fica. */
    public static function sair(Request $request): void
    {
        $request->session()->forget([self::SESSAO_AVALIADOR, self::SESSAO_ACESSO]);
    }

    /** Avaliador da sessão, se ainda ativo e com o mesmo link; guarda-o na requisição. */
    public static function conferir(Request $request): ?Avaliador
    {
        $id = $request->session()->get(self::SESSAO_AVALIADOR);
        $hash = $request->session()->get(self::SESSAO_ACESSO);

        if (! is_numeric($id) || ! is_string($hash) || $hash === '') {
            return null;
        }

        $avaliador = Avaliador::query()->where('ativo', true)->find((int) $id);

        if ($avaliador === null || ! is_string($avaliador->acesso_hash) || ! hash_equals($avaliador->acesso_hash, $hash)) {
            return null;
        }

        $request->attributes->set(self::ATRIBUTO, $avaliador);

        return $avaliador;
    }

    /** Avaliador conferido nesta requisição; só nas rotas sob o middleware `avaliador`. */
    public static function atual(Request $request): Avaliador
    {
        return self::daRequisicao($request) ?? throw new LogicException('Rota sem o middleware avaliador.');
    }

    /** Avaliador conferido nesta requisição, ou null fora das rotas do avaliador. */
    public static function daRequisicao(Request $request): ?Avaliador
    {
        $avaliador = $request->attributes->get(self::ATRIBUTO);

        return $avaliador instanceof Avaliador ? $avaliador : null;
    }
}
