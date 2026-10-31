<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes_email', function (Blueprint $table) {
            $table->id();
            $table->boolean('ativo')->default(false);
            $table->string('host', 255)->default('smtp.gmail.com');
            $table->unsignedInteger('porta')->default(587);
            // Valores de App\Enums\CriptografiaEmail.
            $table->string('criptografia', 3)->default('tls');
            $table->string('usuario', 255)->nullable();
            // Cifrada com a APP_KEY (cast encrypted do model): texto, porque o cifrado é bem maior que a senha.
            $table->text('senha')->nullable();
            $table->string('remetente_email', 255)->nullable();
            $table->string('remetente_nome', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_email');
    }
};
