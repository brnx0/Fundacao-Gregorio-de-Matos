<?php

namespace Tests\Feature;

use App\Models\Evento;
use Database\Seeders\EventoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_marca_o_pin_de_todos_os_eventos(): void
    {
        $this->seed(EventoSeeder::class);

        $capoeira = Evento::where('slug', 'capoeira-viva')->sole();
        $this->assertSame(-12.9773792, $capoeira->latitude);
        $this->assertSame(-38.5137469, $capoeira->longitude);
        $this->assertSame('Ladeira da Barroquinha, Centro Histórico, Salvador/BA', $capoeira->endereco);

        // Nenhum evento sem mapa: o slug de um evento novo precisa de entrada nas localizações.
        $this->assertSame([], Evento::withTrashed()->get()->reject->temLocalizacao()->pluck('slug')->all());
    }

    public function test_nao_sobrescreve_o_pin_nem_o_endereco_marcados_na_retaguarda(): void
    {
        Evento::factory()->create([
            'slug' => 'capoeira-viva',
            'endereco' => 'Endereço da retaguarda',
            'latitude' => -12.9,
            'longitude' => -38.4,
        ]);
        Evento::factory()->create(['slug' => 'dois-de-julho', 'endereco' => 'Campo Grande, Salvador/BA']);

        $this->seed(EventoSeeder::class);

        $capoeira = Evento::where('slug', 'capoeira-viva')->sole();
        $this->assertSame('Endereço da retaguarda', $capoeira->endereco);
        $this->assertSame(-12.9, $capoeira->latitude);

        // Sem pin ainda: ganha as coordenadas, mas o endereço já preenchido fica.
        $doisDeJulho = Evento::where('slug', 'dois-de-julho')->sole();
        $this->assertSame('Campo Grande, Salvador/BA', $doisDeJulho->endereco);
        $this->assertSame(-12.9554615, $doisDeJulho->latitude);
    }
}
