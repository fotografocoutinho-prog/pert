<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePlatformStaff;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\InitializeRequestContext;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Tenancy\MissingTenantContextException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Context;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([InitializeRequestContext::class, ForceHttps::class]);
        $middleware->append(SecurityHeaders::class);

        // Sessão por cookie para a SPA no mesmo domínio (Sanctum).
        $middleware->statefulApi();
        $middleware->throttleApi();

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform' => EnsurePlatformStaff::class,
            'account.active' => EnsureAccountActive::class,
        ]);

        // O contexto da empresa tem de existir antes do route model binding,
        // porque os modelos de empresa falham fechado sem contexto.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenant::class);

        // A SPA volta ao endereço pedido depois do login (só caminhos relativos).
        $middleware->redirectGuestsTo(fn (Request $request) => '/entrar?redirect='.urlencode($request->getRequestUri()));
        $middleware->redirectUsersTo('/app');

        $middleware->encryptCookies(except: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Erro de programação: regista com detalhe, responde sem expor informação.
        $exceptions->render(function (MissingTenantContextException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Erro interno.',
                    'code' => 'TENANT_CONTEXT_MISSING',
                    'request_id' => Context::get('request_id'),
                ], 500);
            }

            return null;
        });

        $exceptions->context(fn () => array_filter(['request_id' => Context::get('request_id')]));
    })->create();
