<?php

namespace App\Console\Commands;

use App\Demo\DemoCompany;
use Illuminate\Console\Command;

/**
 * Cria a empresa de demonstração "Atlântico Construções" (dados fictícios em todas as áreas).
 * Os emails da equipa fictícia são do domínio reservado atlantico-demo.test: nunca recebem emails
 * (ver App\Mail\DropReservedRecipients) e ninguém entra com eles. Para ver a demonstração, dá-se a
 * um utilizador real o perfil de administrador (--admin) e ele muda de empresa no menu.
 */
class CreateDemoCompany extends Command
{
    protected $signature = 'obraconnect:demo
        {--admin=* : Email de um utilizador já existente que fica administrador da empresa de demonstração}
        {--fresh : Apaga a empresa de demonstração que existir e cria-a de novo (com as datas de hoje)}';

    protected $description = 'Cria a empresa de demonstração (Atlântico Construções) com dados fictícios em todas as áreas.';

    public function handle(DemoCompany $demo): int
    {
        $admins = array_values(array_filter((array) $this->option('admin')));
        $existing = DemoCompany::find();

        if ($existing !== null && ! $this->option('fresh')) {
            $added = $demo->attachAdmins($existing, $admins);
            $this->info("A empresa de demonstração já existe (#{$existing->id}). Para a recriar com as datas de hoje: --fresh.");
            $this->reportAdmins($admins, $added);

            return self::SUCCESS;
        }

        if ($existing !== null) {
            $this->line("A apagar a empresa de demonstração #{$existing->id}…");
            $demo->destroy($existing);
        }

        $this->line('A criar a empresa de demonstração…');
        $started = microtime(true);
        $tenant = $demo->build($admins, fn (string $message) => $this->line("  {$message}"));
        $this->info(sprintf('Criada: %s (#%d) em %.1f s.', $tenant->name, $tenant->id, microtime(true) - $started));
        $this->reportAdmins($admins, $demo->attachAdmins($tenant, $admins));

        return self::SUCCESS;
    }

    /** @param  list<string>  $wanted @param  list<string>  $added */
    private function reportAdmins(array $wanted, array $added): void
    {
        foreach ($added as $email) {
            $this->line("  Administrador: {$email}");
        }
        foreach (array_diff($wanted, $added) as $email) {
            $this->warn("  Não existe nenhum utilizador com o email {$email}: não foi associado.");
        }
        if ($wanted === []) {
            $this->warn('  Sem --admin: ninguém real tem acesso à demonstração. Volte a correr com --admin=email.');
        }
    }
}
