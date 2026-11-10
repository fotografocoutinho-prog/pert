<!DOCTYPE html>
<html lang="pt-PT" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#c2410c">
    <title>ObraConnect — gestão de obras, fiscalização e custos</title>
    <meta name="description" content="Diário de obra, fiscalização, orçamentos, autos, compras, planeamento e previsões de custo numa só plataforma para construtoras e empreiteiros. {{ $trialDays }} dias grátis, sem cartão.">
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="ObraConnect — {{ $tagline }}">
    <meta property="og:description" content="{{ $summary }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:locale" content="pt_PT">
    <link rel="icon" href="{{ asset('pwa/icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('pwa/icon-192.png') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-slate-900 antialiased">
<a href="#conteudo" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow">Saltar para o conteúdo</a>

{{-- Cabeçalho --}}
<header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
        <a href="{{ url('/apresentacao') }}" class="inline-flex items-center gap-2 font-bold tracking-tight" aria-label="ObraConnect">
            <svg viewBox="0 0 32 32" class="h-8 w-8" aria-hidden="true"><rect width="32" height="32" rx="7" fill="#c2410c"/><path d="M7 24V13l9-6 9 6v11h-6v-7h-6v7z" fill="#fff"/></svg>
            <span class="text-lg">Obra<span class="text-brand-700">Connect</span></span>
        </a>
        <nav class="hidden items-center gap-6 text-sm text-slate-600 md:flex" aria-label="Secções">
            <a href="#funcionalidades" class="hover:text-slate-900">Funcionalidades</a>
            <a href="#perfis" class="hover:text-slate-900">Para quem</a>
            <a href="#planos" class="hover:text-slate-900">Planos e preços</a>
            <a href="#perguntas" class="hover:text-slate-900">Perguntas</a>
        </nav>
        <div class="flex items-center gap-2">
            <a href="{{ url('/entrar') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Entrar</a>
            <a href="{{ url('/registar') }}" class="rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800">Experimentar grátis</a>
        </div>
    </div>
</header>

<main id="conteudo">
    {{-- Abertura --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-brand-50 to-white">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-14 sm:px-6 lg:grid-cols-2 lg:py-20">
            <div>
                <p class="mb-3 inline-flex items-center rounded-full bg-white px-3 py-1 text-xs font-medium text-brand-800 ring-1 ring-brand-200">Para construtoras, empreiteiros e fiscalização</p>
                <h1 class="text-4xl font-extrabold leading-tight tracking-tight text-slate-900 sm:text-5xl">{{ $tagline }}</h1>
                <p class="mt-5 text-lg leading-relaxed text-slate-600">{{ $summary }}</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ url('/registar') }}" class="rounded-xl bg-brand-700 px-6 py-3 text-center text-base font-semibold text-white shadow hover:bg-brand-800">Experimentar {{ $trialDays }} dias grátis</a>
                    <a href="#planos" class="rounded-xl bg-white px-6 py-3 text-center text-base font-semibold text-slate-800 ring-1 ring-slate-300 hover:bg-slate-50">Ver planos e preços</a>
                </div>
                <p class="mt-4 text-sm text-slate-500">Sem cartão · sem instalar nada · no computador e no telemóvel</p>
            </div>

            {{-- Ilustração (valores de exemplo) --}}
            <div class="relative" aria-hidden="true">
                <div class="rounded-2xl bg-white p-5 shadow-xl ring-1 ring-slate-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Obra · exemplo</p>
                            <p class="font-semibold">Moradia em Braga</p>
                        </div>
                        <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">1 alerta</span>
                    </div>
                    <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Custo previsto no fim</p><p class="mt-1 text-lg font-bold">147 300 €</p></div>
                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Margem prevista</p><p class="mt-1 text-lg font-bold text-green-700">18,6 %</p></div>
                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Fim previsto</p><p class="mt-1 text-lg font-bold">12/03</p></div>
                    </div>
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <p class="font-medium">Custo a subir esta semana</p>
                        <p class="text-amber-800">As compras de cofragem passaram o previsto no orçamento.</p>
                    </div>
                    <ul class="mt-4 space-y-2 text-sm">
                        <li class="flex items-center justify-between"><span>Diário de hoje · 6 trabalhadores, 14 fotografias</span><span class="text-green-700">Submetido</span></li>
                        <li class="flex items-center justify-between"><span>Inspeção · betonagem da laje do piso 1</span><span class="text-brand-700">Ponto de paragem</span></li>
                        <li class="flex items-center justify-between"><span>Auto n.º 4 · subempreiteiro de eletricidade</span><span class="text-slate-500">Por aprovar</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- O que muda --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 class="text-3xl font-bold tracking-tight">O que muda no dia a dia</h2>
        <p class="mt-2 max-w-2xl text-slate-600">Menos papel, menos telefonemas e menos surpresas no fim da obra.</p>
        <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($benefits as $b)
                <div class="rounded-2xl border border-slate-200 p-6">
                    <h3 class="font-semibold">{{ $b['title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $b['text'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Funcionalidades --}}
    <section id="funcionalidades" class="scroll-mt-20 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <h2 class="text-3xl font-bold tracking-tight">Tudo o que inclui</h2>
            <p class="mt-2 max-w-2xl text-slate-600">Da primeira medição à última pré-fatura, sem saltar de programa em programa.</p>
            <div class="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($modules as $m)
                    <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                        <h3 class="font-semibold text-brand-800">{{ $m['title'] }}</h3>
                        <ul class="mt-3 space-y-2 text-sm text-slate-700">
                            @foreach ($m['items'] as $item)
                                <li class="flex gap-2"><span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-600"></span><span>{{ $item }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Para quem --}}
    <section id="perfis" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6">
        <h2 class="text-3xl font-bold tracking-tight">Útil para cada pessoa da equipa</h2>
        <p class="mt-2 max-w-2xl text-slate-600">Cada pessoa vê o que precisa, com o seu perfil. Os perfis vêm prontos e a empresa ajusta-os.</p>
        <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($roles as $r)
                <div class="rounded-2xl border border-slate-200 p-6">
                    <h3 class="font-semibold">{{ $r['role'] }}</h3>
                    <ul class="mt-3 space-y-1.5 text-sm text-slate-600">
                        @foreach ($r['items'] as $item)
                            <li class="flex gap-2"><span class="text-brand-700" aria-hidden="true">✓</span><span>{{ $item }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Como começa --}}
    <section class="bg-slate-900 text-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <h2 class="text-3xl font-bold tracking-tight">Começa hoje, em três passos</h2>
            <ol class="mt-8 grid gap-6 md:grid-cols-3">
                @foreach ($steps as $i => $s)
                    <li class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-sm font-bold">{{ $i + 1 }}</span>
                        <h3 class="mt-4 font-semibold">{{ $s['title'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-300">{{ $s['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Segurança --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <div class="grid gap-10 lg:grid-cols-3">
            <div>
                <h2 class="text-3xl font-bold tracking-tight">Os dados da sua empresa, protegidos</h2>
                <p class="mt-3 text-slate-600">Feito para empresas que não podem perder o histórico de uma obra.</p>
            </div>
            <ul class="grid gap-3 sm:grid-cols-2 lg:col-span-2">
                @foreach ($security as $item)
                    <li class="flex gap-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-700"><span class="text-brand-700" aria-hidden="true">●</span><span>{{ $item }}</span></li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Planos --}}
    <section id="planos" class="scroll-mt-20 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <h2 class="text-3xl font-bold tracking-tight">Planos e preços</h2>
            <p class="mt-2 max-w-2xl text-slate-600">
                {{ $trialDays }} dias grátis{{ $trialPlan ? ' no plano '.$trialPlan : '' }}, sem cartão; durante esse tempo pode mudar para o plano que quiser.
                Faturação mensal ou anual. Valores sem IVA.
            </p>
            @php($cols = [1 => 'xl:grid-cols-1', 2 => 'xl:grid-cols-2', 3 => 'xl:grid-cols-3'][count($plans)] ?? 'xl:grid-cols-4')
            <div class="mt-8 grid gap-5 md:grid-cols-2 {{ $cols }}">
                @foreach ($plans as $p)
                    <div class="flex flex-col rounded-2xl bg-white p-6 shadow-sm {{ $p['highlight'] ? 'ring-2 ring-brand-600' : 'ring-1 ring-slate-200' }}">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-lg font-bold">{{ $p['name'] }}</h3>
                            @if ($p['highlight'])
                                <span class="rounded-full bg-brand-100 px-2.5 py-0.5 text-xs font-medium text-brand-800">Período experimental</span>
                            @endif
                        </div>
                        @if ($p['contact'])
                            <p class="mt-4 text-3xl font-extrabold">Sob consulta</p>
                            <p class="mt-1 text-sm text-slate-500">À medida da empresa</p>
                        @else
                            <p class="mt-4 text-3xl font-extrabold">{{ $money($p['monthly_cents']) }}<span class="text-base font-normal text-slate-500">/mês</span></p>
                            @if ($p['yearly_cents'] !== null)
                                <p class="mt-1 text-sm text-slate-500">
                                    ou {{ $money($p['yearly_cents']) }}/ano
                                    @if ($p['yearly_saving_cents'])
                                        <span class="text-green-700">(poupa {{ $money($p['yearly_saving_cents']) }})</span>
                                    @endif
                                </p>
                            @endif
                        @endif
                        <dl class="mt-5 space-y-1.5 border-t border-slate-100 pt-4 text-sm">
                            @foreach ($p['limits'] as $l)
                                <div class="flex justify-between gap-2"><dt class="text-slate-500">{{ $l['label'] }}</dt><dd class="font-medium">{{ $l['value'] }}</dd></div>
                            @endforeach
                        </dl>
                        <ul class="mt-4 flex-1 space-y-1.5 text-sm text-slate-700">
                            @foreach ($p['features'] as $f)
                                <li class="flex gap-2"><span class="text-brand-700" aria-hidden="true">✓</span><span>{{ $f }}</span></li>
                            @endforeach
                        </ul>
                        @if ($p['contact'])
                            @if ($contactEmail)
                                <a href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('ObraConnect '.$p['name'].' — pedido de proposta') }}" class="mt-6 rounded-xl bg-white px-4 py-2.5 text-center text-sm font-semibold text-slate-800 ring-1 ring-slate-300 hover:bg-slate-50">Pedir uma proposta</a>
                            @endif
                        @else
                            <a href="{{ url('/registar') }}" class="mt-6 rounded-xl px-4 py-2.5 text-center text-sm font-semibold {{ $p['highlight'] ? 'bg-brand-700 text-white hover:bg-brand-800' : 'bg-white text-slate-800 ring-1 ring-slate-300 hover:bg-slate-50' }}">Experimentar grátis</a>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="mt-6 text-sm text-slate-500">Os subempreiteiros com acesso ao portal não contam como utilizadores. Mais utilizadores, obras ou armazenamento? Mude de plano na própria aplicação, a qualquer momento.</p>
        </div>
    </section>

    {{-- Perguntas --}}
    <section id="perguntas" class="mx-auto max-w-3xl scroll-mt-20 px-4 py-16 sm:px-6">
        <h2 class="text-3xl font-bold tracking-tight">Perguntas frequentes</h2>
        <div class="mt-8 divide-y divide-slate-200 rounded-2xl border border-slate-200">
            @foreach ($faq as $f)
                <details class="group p-5">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
                        {{ $f['q'] }}
                        <span class="text-slate-400 transition group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>
                    <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $f['a'] }}</p>
                </details>
            @endforeach
        </div>
    </section>

    {{-- Chamada final --}}
    <section class="bg-brand-700 text-white">
        <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 px-4 py-14 sm:px-6 md:flex-row md:items-center">
            <div>
                <h2 class="text-2xl font-bold sm:text-3xl">Experimente com a sua próxima obra.</h2>
                <p class="mt-2 text-brand-100">{{ $trialDays }} dias grátis, sem cartão. Se não servir, exporta os dados e sai.</p>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row">
                <a href="{{ url('/registar') }}" class="rounded-xl bg-white px-6 py-3 text-center font-semibold text-brand-800 hover:bg-brand-50">Criar a empresa</a>
                @if ($contactEmail)
                    <a href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('ObraConnect — pedido de demonstração') }}" class="rounded-xl px-6 py-3 text-center font-semibold text-white ring-1 ring-white/60 hover:bg-white/10">Pedir uma demonstração</a>
                @endif
            </div>
        </div>
    </section>
</main>

<footer class="border-t border-slate-200">
    <div class="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-8 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <p>© {{ now()->year }} ObraConnect</p>
        <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Rodapé">
            <a href="{{ url('/apresentacao.pdf') }}" class="hover:text-slate-800">Apresentação em PDF</a>
            <a href="{{ url('/entrar') }}" class="hover:text-slate-800">Entrar</a>
            <a href="{{ url('/registar') }}" class="hover:text-slate-800">Criar conta</a>
            @if ($contactEmail)
                <a href="mailto:{{ $contactEmail }}" class="hover:text-slate-800">{{ $contactEmail }}</a>
            @endif
        </nav>
    </div>
</footer>
</body>
</html>
