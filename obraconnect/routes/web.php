<?php

use App\Http\Controllers\LandingController;
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

// Apresentação pública (o que faz, para quem, planos e preços) e a mesma em PDF.
Route::get('/', [LandingController::class, 'home'])->name('landing');
Route::get('/apresentacao', [LandingController::class, 'show'])->name('landing.show');
Route::get('/apresentacao.pdf', [LandingController::class, 'brochure'])->middleware('throttle:30,1')->name('landing.pdf');

// SPA: todas as outras rotas GET servem a aplicação Vue.
Route::view('/entrar', 'app')->name('login');
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|auth/|sanctum/|up$|instalar).*$')
    ->name('spa');
