<?php

namespace App\Http\Controllers;

use App\Marketing\BrochurePdf;
use App\Marketing\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Página pública de apresentação do ObraConnect (o que faz, para quem, planos e preços do backoffice)
 * e a mesma apresentação em PDF, para enviar a clientes.
 */
class LandingController extends Controller
{
    /** Na raiz: visitantes veem a apresentação; quem já tem sessão vai direto para a aplicação. */
    public function home(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->to(url('/app'));
        }

        return $this->page();
    }

    public function show(): Response
    {
        return $this->page();
    }

    public function brochure(BrochurePdf $pdf): Response
    {
        return response($pdf->render(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="ObraConnect-apresentacao.pdf"',
        ]);
    }

    private function page(): Response
    {
        return response()->view('landing', self::data());
    }

    /** @return array<string, mixed> */
    public static function data(): array
    {
        return [
            'tagline' => Catalog::TAGLINE,
            'summary' => Catalog::SUMMARY,
            'benefits' => Catalog::benefits(),
            'modules' => Catalog::modules(),
            'roles' => Catalog::roles(),
            'security' => Catalog::security(),
            'steps' => Catalog::steps(),
            'faq' => Catalog::faq(),
            'plans' => Catalog::plans(),
            'trialDays' => Catalog::trialDays(),
            'trialPlan' => Catalog::trialPlanName(),
            'contactEmail' => config('obraconnect.sales.contact_email'),
            'money' => fn (?int $c) => Catalog::money($c),
        ];
    }
}
