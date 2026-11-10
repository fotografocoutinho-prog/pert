<?php

namespace App\Marketing;

use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Conteúdo comercial do ObraConnect (página pública e apresentação em PDF). Só descreve o que a
 * plataforma faz hoje: nada de funcionalidades por fazer (IA, faturação certificada, pagamentos online).
 */
final class Catalog
{
    public const TAGLINE = 'A obra, a fiscalização e os custos no mesmo sítio.';

    public const SUMMARY = 'O ObraConnect junta o diário de obra, a fiscalização, os orçamentos, os autos, as compras, o planeamento e as previsões de custo e de prazo numa única plataforma, para construtoras, empreiteiros e equipas de fiscalização. Funciona no computador e no telemóvel — o diário e as fotografias até sem rede no estaleiro.';

    /** @return list<array{title: string, text: string}> */
    public static function benefits(): array
    {
        return [
            ['title' => 'Menos deslocações à obra', 'text' => 'Inspeções com listas de verificação, fotografias e evidências obrigatórias: aprova ou devolve à distância, com tudo registado.'],
            ['title' => 'Custos controlados antes de derraparem', 'text' => 'Previsão do custo no fim e da margem, explicada em frases simples, e aviso automático quando a obra se aproxima do teto de custo ou do prazo.'],
            ['title' => 'Autos e pré-faturas sem folhas de Excel', 'text' => 'Do orçamento às medições, dos autos às pré-faturas, prontas a passar para o seu programa de faturação certificado.'],
            ['title' => 'Diário de obra sempre em dia', 'text' => 'O encarregado regista no telemóvel o dia, a mão de obra, os equipamentos e as fotografias, mesmo sem rede; sincroniza quando voltar a haver.'],
            ['title' => 'Faturas de fornecedores lidas sozinhas', 'text' => 'Envie o PDF ou a fotografia: o ObraConnect lê o fornecedor, o número, o ATCUD e os valores, e propõe a encomenda a que corresponde.'],
            ['title' => 'Tudo fica registado', 'text' => 'Quem fez o quê e quando, num registo de auditoria que não se altera nem se apaga. Os documentos guardam todas as versões.'],
        ];
    }

    /** @return list<array{title: string, items: list<string>}> */
    public static function modules(): array
    {
        return [
            ['title' => 'Obra no terreno', 'items' => [
                'Diário de obra por dia: meteorologia, mão de obra, equipamentos, materiais, ocorrências e visitas',
                'Fotografias com o original intacto, ligadas ao local e ao elemento da obra',
                'Modo sem rede no telemóvel para o diário e as fotografias',
                'Estrutura da obra: edifício, piso, zona e elementos, com o histórico de cada um',
                'Documentos por pastas, com versões, especialidades e registo de quem descarregou',
            ]],
            ['title' => 'Fiscalização e qualidade', 'items' => [
                'Listas de verificação configuráveis pela empresa',
                'Inspeções com pedido, agendamento, execução e apreciação; evidências obrigatórias',
                'Aprovações por uma, duas ou três pessoas e pontos de paragem',
                'Ocorrências e não conformidades com responsável, prazo e aviso automático quando o prazo passa',
                'Relatório da inspeção em PDF, com um registo encadeado que mostra se algo foi mexido',
            ]],
            ['title' => 'Orçamento, medições e autos', 'items' => [
                'Orçamentos com capítulos, artigos e preços compostos; biblioteca de preços da empresa',
                'Importação e exportação do orçamento em Excel',
                'Medições e autos do dono de obra e de subempreiteiros, com retenções',
                'Contratos de subempreitada com o que já foi autado e o que falta',
            ]],
            ['title' => 'Custos e compras', 'items' => [
                'Custo previsto, comprometido e realizado por obra, com teto de custo',
                'Encomendas a fornecedores: aprovar, receber e faturar',
                'Painel financeiro com todas as obras',
                'Faturas e guias de fornecedores lidas automaticamente (QR e ATCUD) e lançadas nas compras ou nos custos',
            ]],
            ['title' => 'Previsões e alertas', 'items' => [
                'Custo previsto no fim da obra e margem, com as razões explicadas',
                'Fim previsto da obra pelo caminho crítico, a partir do avanço real',
                'Evolução semana a semana e alertas por email: teto de custo, atrasos, medições, pontos de paragem, faturas por rever',
            ]],
            ['title' => 'Planeamento e recursos', 'items' => [
                'Planeamento em Gantt com dependências e caminho crítico (CPM e PERT)',
                'Calendários de trabalho com os feriados nacionais e paragens da empresa',
                'Trabalhadores e registo de horas com aprovação; folha de horas',
                'Equipamentos com código QR, utilização por obra e manutenções',
            ]],
            ['title' => 'Pré-faturação', 'items' => [
                'Pré-faturas a partir dos autos, com número interno, IVA e motivos de isenção',
                'Exportação em Excel, CSV e JSON e PDF para o programa de faturação certificado que a empresa já usa',
                'Documentos internos, sem valor fiscal: a fatura oficial continua a sair do programa certificado',
            ]],
            ['title' => 'Subempreiteiros e fornecedores', 'items' => [
                'Fichas de fornecedores e subempreiteiros com documentos e validades (seguros, alvarás, declarações)',
                'Portal do subempreiteiro: vê os seus contratos, propõe os autos e envia documentos',
                'As pessoas do subempreiteiro não contam como utilizadores do plano',
            ]],
            ['title' => 'Ligação a outros programas', 'items' => [
                'API para ler obras, custos, previsões, autos e pré-faturas e para enviar faturas de fornecedores',
                'Webhooks assinados: aviso imediato quando um auto é aprovado, uma pré-fatura fica pronta, uma fatura é lida ou abre um alerta',
                'Nos planos Business e Enterprise',
            ]],
        ];
    }

    /** @return list<array{role: string, items: list<string>}> */
    public static function roles(): array
    {
        return [
            ['role' => 'Gerência e direção', 'items' => ['Margem e fim previsto de todas as obras num só painel', 'Alertas antes de a obra derrapar', 'Perfis e permissões para cada pessoa']],
            ['role' => 'Diretor de obra e diretor técnico', 'items' => ['Diário, fotografias e inspeções da obra sem ir lá todos os dias', 'Autos, compras e custos ao dia', 'Planeamento com o caminho crítico']],
            ['role' => 'Encarregado', 'items' => ['Diário e fotografias no telemóvel, mesmo sem rede', 'Horas da equipa e equipamentos usados', 'Ocorrências com fotografia na hora']],
            ['role' => 'Fiscalização, segurança e qualidade', 'items' => ['Listas de verificação e evidências obrigatórias', 'Pontos de paragem e não conformidades com prazo', 'Relatórios em PDF prontos a enviar']],
            ['role' => 'Medição, orçamentação e administrativo', 'items' => ['Orçamentos importados do Excel e biblioteca de preços', 'Medições, autos e pré-faturas', 'Faturas de fornecedores lidas sozinhas']],
            ['role' => 'Subempreiteiros e dono de obra', 'items' => ['Portal próprio para propor autos e enviar documentos', 'Perfil de dono de obra só com o que lhe diz respeito', 'Sem custo para o subempreiteiro']],
        ];
    }

    /** @return list<string> */
    public static function security(): array
    {
        return [
            'Cada empresa só vê os seus dados; o isolamento é verificado em testes automáticos a cada versão',
            'Autenticação de dois fatores, que a empresa pode tornar obrigatória',
            'Perfis prontos a usar (diretor de obra, encarregado, fiscal, medidor, administrativo…) e editáveis',
            'Registo de auditoria que não se altera nem se apaga',
            'Exportação completa dos dados da empresa a qualquer momento',
            'O suporte técnico só entra quando a empresa autoriza, por tempo limitado e só para consulta',
            'Cópia de segurança diária da base de dados e dos ficheiros',
            'Ligação sempre cifrada (HTTPS)',
        ];
    }

    /** @return list<array{title: string, text: string}> */
    public static function steps(): array
    {
        return [
            ['title' => 'Crie a empresa', 'text' => 'Em dois minutos, sem cartão. O período experimental começa logo e, durante esse tempo, pode mudar de plano à vontade.'],
            ['title' => 'Traga a equipa e a primeira obra', 'text' => 'Convide as pessoas por email, escolha o perfil de cada uma e importe o orçamento do Excel.'],
            ['title' => 'Comece no estaleiro', 'text' => 'Diário, fotografias e inspeções no telemóvel; custos, autos e previsões no escritório.'],
        ];
    }

    /** @return list<array{q: string, a: string}> */
    public static function faq(): array
    {
        $trial = self::trialDays();

        return [
            ['q' => 'Preciso de instalar alguma coisa?', 'a' => 'Não. Funciona no browser do computador e do telemóvel, e pode ser adicionado ao ecrã inicial do telemóvel como uma aplicação. O diário e as fotografias funcionam sem rede e sincronizam depois.'],
            ['q' => 'Como funciona o período experimental?', 'a' => "São {$trial} dias sem custos e sem cartão. Durante esse tempo pode mudar de plano à vontade; no fim pede a ativação na própria aplicação."],
            ['q' => 'Como se paga?', 'a' => 'Mensal ou anual. Ao pedir a ativação recebe a fatura por email com as indicações de pagamento; o período pago conta a partir da confirmação do pagamento. Valores sem IVA.'],
            ['q' => 'Posso mudar de plano depois?', 'a' => 'Sim, na própria aplicação. Subir de plano é imediato (paga só a diferença até ao fim do período); descer fica para o fim do período já pago.'],
            ['q' => 'O ObraConnect emite faturas?', 'a' => 'Prepara pré-faturas a partir dos autos e exporta-as para o programa de faturação certificado que a empresa já usa. A fatura oficial continua a sair desse programa.'],
            ['q' => 'Os meus dados são meus?', 'a' => 'Sim. A empresa pode exportar todos os dados a qualquer momento, num ficheiro completo. O suporte só os consulta com autorização da empresa e tudo fica registado.'],
            ['q' => 'Os subempreiteiros pagam?', 'a' => 'Não. O portal do subempreiteiro está incluído e as pessoas dos subempreiteiros não contam como utilizadores do plano.'],
            ['q' => 'Posso trabalhar com várias empresas?', 'a' => 'Sim. A mesma conta pode pertencer a várias empresas e muda de uma para a outra num clique.'],
        ];
    }

    /** Funcionalidades de plano já existentes (as que estão só no papel não se anunciam). */
    public const FEATURE_LABELS = [
        'diary' => 'Diário de obra, fotografias e modo sem rede',
        'documents' => 'Documentos com versões',
        'inspections' => 'Fiscalização: inspeções, pontos de paragem e não conformidades',
        'costs' => 'Orçamentos, medições, autos, compras e custos',
        'advanced_reports' => 'Previsões de custo e prazo e alertas automáticos',
        'integrations' => 'API e webhooks para ligar outros programas',
    ];

    /**
     * Planos públicos e ativos, com o que o site mostra.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function plans(): Collection
    {
        return Plan::query()->where('is_public', true)->where('is_active', true)->orderBy('sort_order')->get()->map(function (Plan $p): array {
            $limits = $p->limits ?? [];
            $features = array_values(array_filter(array_map(fn ($f) => self::FEATURE_LABELS[$f] ?? null, $p->features ?? [])));
            $monthly = $p->price_monthly_cents;
            $yearly = $p->price_yearly_cents;

            return [
                'code' => $p->code,
                'name' => $p->name,
                'monthly_cents' => $monthly,
                'yearly_cents' => $yearly,
                'yearly_saving_cents' => $monthly !== null && $yearly !== null ? max(0, $monthly * 12 - $yearly) : null,
                'limits' => [
                    ['label' => 'Utilizadores', 'value' => self::limit($limits['max_users'] ?? null)],
                    ['label' => 'Obras ativas', 'value' => self::limit($limits['max_active_projects'] ?? null)],
                    ['label' => 'Armazenamento', 'value' => isset($limits['storage_mb']) && $limits['storage_mb'] !== null ? round($limits['storage_mb'] / 1024).' GB' : 'ilimitado'],
                ],
                'features' => $features,
                'contact' => $monthly === null && $yearly === null,
                'highlight' => $p->code === config('obraconnect.trial.plan_code'),
            ];
        })->values();
    }

    public static function trialDays(): int
    {
        $plan = Plan::query()->where('code', config('obraconnect.trial.plan_code'))->first();

        return (int) ($plan?->trial_days ?: config('obraconnect.trial.fallback_days', 30));
    }

    public static function trialPlanName(): ?string
    {
        return Plan::query()->where('code', config('obraconnect.trial.plan_code'))->value('name');
    }

    public static function money(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }

        return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', abs($cents) >= 1_000_000 ? ' ' : '').' €';
    }

    private static function limit(mixed $v): string
    {
        return $v === null ? 'ilimitado' : (string) (int) $v;
    }
}
