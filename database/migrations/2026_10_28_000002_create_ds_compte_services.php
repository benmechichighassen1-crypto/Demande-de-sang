<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Un agent peut être rattaché à plusieurs services de soin. Reprise de
 * l'ancienne valeur unique ds_comptes.service_code (colonne gardée, plus utilisée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ds_compte_services', function (Blueprint $table) {
            $table->string('user_name', 50);
            $table->string('service_code', 20);
            $table->primary(['user_name', 'service_code']);
        });

        foreach (DB::table('ds_comptes')->whereNotNull('service_code')->where('service_code', '<>', '')->get(['user_name', 'service_code']) as $c) {
            DB::table('ds_compte_services')->insert(['user_name' => $c->user_name, 'service_code' => $c->service_code]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ds_compte_services');
    }
};
