<?php

namespace Tests\Feature;

use App\Models\Plan;
use Tests\TestCase;

class LandingTest extends TestCase
{
    public function test_visitors_see_what_it_does_and_the_public_plans_from_the_backoffice(): void
    {
        Plan::query()->where('code', 'start')->update(['price_monthly_cents' => 5900, 'price_yearly_cents' => 59000]);
        Plan::query()->create(['code' => 'oculto', 'name' => 'Plano Oculto', 'price_monthly_cents' => 100, 'currency' => 'EUR', 'trial_days' => 0,
            'limits' => [], 'features' => [], 'is_public' => false, 'is_active' => true, 'sort_order' => 5]);

        $html = $this->get('/')->assertOk()
            ->assertSee('A obra, a fiscalização e os custos no mesmo sítio.')
            ->assertSee('Experimentar 30 dias grátis')
            ->assertSee('Tudo o que inclui')
            ->assertSee('Diário de obra por dia')
            ->assertSee('Portal do subempreiteiro')
            ->assertSee('Perguntas frequentes')
            ->assertSee('59 €')
            ->assertSee('590 €/ano')
            ->assertSee('Sob consulta')
            ->assertSee('Business')
            ->assertDontSee('Plano Oculto')
            ->getContent();

        // Nada do que ainda não existe é anunciado, e a página é para os motores de busca.
        $this->assertDoesNotMatchRegularExpression('/intelig[eê]ncia artificial/i', $html);
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
    }

    public function test_the_contact_email_appears_only_when_configured(): void
    {
        config(['obraconnect.sales.contact_email' => 'vendas@exemplo.pt']);

        $this->get('/apresentacao')->assertOk()->assertSee('mailto:vendas@exemplo.pt', false)->assertSee('Pedir uma demonstração');
    }

    public function test_signed_in_people_go_straight_to_the_app_but_can_still_open_the_presentation(): void
    {
        [$owner] = $this->createCompany();

        $this->actingAs($owner)->get('/')->assertRedirect(url('/app'));
        $this->get('/apresentacao')->assertOk()->assertSee('Planos e preços');
        $this->get('/entrar')->assertOk()->assertSee('id="app"', false);
    }

    public function test_the_presentation_is_also_a_pdf(): void
    {
        $response = $this->get('/apresentacao.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(10000, strlen($response->getContent()));
    }
}
