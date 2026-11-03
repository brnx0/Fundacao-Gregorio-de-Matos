<?php

namespace App\Http\Controllers;

use App\Models\Edital;
use App\Models\Evento;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Home', [
            'totalAbertos' => Edital::contarAbertos(),
            // Com o resumo (CLOB), que é a descrição do card; a vitrine tem poucos editais.
            'editais' => Edital::naVitrine()->paraListagem()->addSelect('resumo')->get()->map(fn (Edital $edital) => [
                'titulo' => $edital->titulo,
                'resumo' => $edital->resumo,
                'slug' => $edital->slug,
                'pdf' => $edital->pdfUrl(),
                'categoria' => $edital->categoria,
                'situacao' => $edital->situacao()->value,
                'situacaoRotulo' => $edital->situacao()->rotulo(),
                'inscricoesInicio' => $edital->inscricoes_inicio->format('d/m/Y'),
                'inscricoesFim' => $edital->inscricoes_fim->format('d/m/Y'),
                // Imagem enviada na retaguarda; sem ela, o carrossel usa as fotos fixas da vitrine.
                'imagem' => $edital->imagemUrl(),
            ])->all(),
            // Sem a descrição (CLOB): a home só mostra o card. Vão todos os próximos eventos; a home pagina de 6 em 6.
            'eventos' => Evento::proximos()
                ->select(['id', 'titulo', 'slug', 'resumo', 'data_realizacao', 'local', 'imagem'])
                ->get()
                ->map(fn (Evento $evento) => [
                    'titulo' => $evento->titulo,
                    'slug' => $evento->slug,
                    'resumo' => $evento->resumo,
                    // "agosto" e "2026": a home mostra "AGO / 2026"; o dia completa a data no modal do evento.
                    'dia' => $evento->data_realizacao->format('j'),
                    'mes' => $evento->data_realizacao->locale('pt_BR')->translatedFormat('F'),
                    'ano' => $evento->data_realizacao->format('Y'),
                    'local' => $evento->local,
                    'imagem' => $evento->imagemUrl(),
                ])->all(),
        ]);
    }
}
