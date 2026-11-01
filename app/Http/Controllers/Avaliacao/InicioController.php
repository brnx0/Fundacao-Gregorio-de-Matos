<?php

namespace App\Http\Controllers\Avaliacao;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Início da área do avaliador. O nome vem das props compartilhadas (HandleInertiaRequests). */
class InicioController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Avaliacao/Inicio');
    }
}
