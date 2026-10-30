<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pessoas_fisicas', function (Blueprint $table) {
            // Dia civil, gravado à meia-noite (ver UsaDiaCivil); cadastros antigos ficam sem ela até a pessoa completar.
            $table->date('data_nascimento')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pessoas_fisicas', function (Blueprint $table) {
            $table->dropColumn('data_nascimento');
        });
    }
};
