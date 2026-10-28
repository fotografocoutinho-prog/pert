<?php

use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

/*
| Instalação sem SSH (cPanel). Sem sessão nem CSRF porque as tabelas podem ainda
| não existir; a proteção é o SETUP_TOKEN do .env. Desativa-se sozinha quando
| existe um super administrador.
*/
Route::withoutMiddleware(['web'])->group(function () {
    Route::get('instalar', [SetupController::class, 'show'])->name('setup.show');
    Route::post('instalar', [SetupController::class, 'store'])->name('setup.store');
});

// SPA: todas as outras rotas GET servem a aplicação Vue.
Route::view('/entrar', 'app')->name('login');
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|auth/|sanctum/|up$|instalar).*$')
    ->name('spa');
