<?php

namespace Database\Seeders;

use App\Models\Edital;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class EditalSeeder extends Seeder
{
    public function run(): void
    {
        $hoje = now()->startOfDay();

        $modelo = file_get_contents(__DIR__.'/arquivos/edital-modelo.pdf');

        $editais = [
            [
                'titulo' => 'PNAB Salvador — Ciclo II',
                'slug' => 'pnab-salvador-ciclo-ii',
                'resumo' => 'Fomento a projetos culturais com a Política Nacional Aldir Blanc.',
                'categoria' => 'Fomento',
                'inscricoes_inicio' => $hoje->copy()->subDays(10)->toDateString(),
                'inscricoes_fim' => $hoje->copy()->addDays(20)->toDateString(),
            ],
            [
                'titulo' => 'SalCine 2',
                'slug' => 'salcine-2',
                'resumo' => 'Apoio à produção audiovisual soteropolitana, de curtas a séries.',
                'categoria' => 'Audiovisual',
                'inscricoes_inicio' => $hoje->copy()->subDays(5)->toDateString(),
                'inscricoes_fim' => $hoje->copy()->addDays(30)->toDateString(),
            ],
            [
                'titulo' => 'Edital Pontos de Cultura',
                'slug' => 'edital-pontos-de-cultura',
                'resumo' => 'Reconhecimento e apoio a iniciativas culturais da rede municipal.',
                'categoria' => 'Rede municipal',
                'inscricoes_inicio' => $hoje->copy()->addDays(15)->toDateString(),
                'inscricoes_fim' => $hoje->copy()->addDays(60)->toDateString(),
            ],
            [
                'titulo' => 'Lei Paulo Gustavo',
                'slug' => 'lei-paulo-gustavo',
                'resumo' => 'Fomento ao setor cultural com recursos da Lei Complementar 195/2022.',
                'categoria' => 'Fomento',
                'inscricoes_inicio' => $hoje->copy()->subDays(90)->toDateString(),
                'inscricoes_fim' => $hoje->copy()->subDays(30)->toDateString(),
            ],
        ];

        foreach ($editais as $dados) {
            $edital = Edital::withTrashed()->firstOrNew(['slug' => $dados['slug']]);

            if (! $edital->exists) {
                $edital->fill($dados + ['imagem' => null, 'publicado' => true]);
            }

            // Nunca sobrescreve um PDF enviado pela retaguarda.
            if ($edital->pdf === null) {
                $edital->pdf = "editais/{$dados['slug']}.pdf";
                Storage::disk('local')->put($edital->pdf, $modelo);
            }

            $edital->save();
        }
    }
}
