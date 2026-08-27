# Guia de SEO — 1000viagens

O que já está feito no código, o que tem de fazer fora dele e por que ordem.
Escrito para ser lido por quem gere a agência, não por programadores.

---

## 1. O que o código já faz por si

| Feito | Onde | Porque conta para o Google |
|---|---|---|
| Título e descrição orientados a pesquisa | `public/index.html` | São o que aparece nos resultados e decidem se clicam |
| Conteúdo entregue já em HTML | `cpanel/api/_render.php` | O Google lê a página sem depender de JavaScript |
| Uma página por destino | `/viagens/<destino>/` | Capta "viagens para X", "férias em X", "quanto custa X" |
| Índice de destinos | `/viagens/` | Distribui autoridade pelas páginas de destino |
| Dados estruturados (TravelAgency, FAQPage, TouristTrip, BreadcrumbList, ItemList) | páginas geradas e `_render.php` | Dá direito a resultados enriquecidos: perguntas, preço, migalhas |
| Mapa do site e robots.txt | `public/sitemap.xml`, `public/robots.txt` | Diz ao Google o que indexar (e que o backoffice não é para indexar) |
| Endereços canónicos e Open Graph | cabeçalho de cada página | Evita conteúdo duplicado e melhora as partilhas |
| Imagem de partilha 1200×630 | `assets/img/og.png` | Partilhas com imagem têm muito mais cliques |
| Compressão, cache e HTTPS | `.htaccess` | Velocidade é fator de posicionamento, sobretudo no telemóvel |
| Ligações internas entre destinos | secção "Outros destinos" | Ajuda o Google a descobrir e a hierarquizar as páginas |
| Tipos de letra do sistema, zero scripts externos | todo o site | Páginas rápidas e sem problemas de RGPD |

**O que o código não pode fazer:** garantir a primeira posição. O Google decide
por conteúdo, reputação do domínio e concorrência. O que está feito coloca o site
tecnicamente ao nível dos melhores; o resto ganha-se com os passos 3 a 6.

---

## 2. Termos que o site trabalha

Termos em português de Portugal, que é o que os seus clientes escrevem.

**Página inicial** — intenção comercial genérica
`orçamento de viagens` · `viagens à medida` · `pacotes de férias` ·
`viagens organizadas` · `travel booking` · `viagem sem compromisso`

**Páginas de destino** — a maior fatia do tráfego, com intenção clara
`viagens para as maldivas` · `férias nas ilhas gregas` · `quanto custa ir à lapónia` ·
`escapadinha a marraquexe` · `cruzeiro no mediterrâneo preços` · `city break nova iorque`

**Termos de época** (a trabalhar em texto e destaques ao longo do ano)
`férias de verão` · `passagem de ano` · `carnaval` · `páscoa` ·
`black friday viagens` · `viagens de última hora`

**Termos por tipo de viajante**
`viagens em família` · `lua de mel` · `viagens para seniores` ·
`viagens a dois` · `viagens de grupo` · `viagens com crianças`

Onde acrescentar mais termos, sem esforço técnico:
* **Backoffice → Conteúdos do site → Perguntas frequentes.** Cada pergunta nova é
  uma hipótese de aparecer no Google. Escreva as perguntas como os clientes as fazem
  ("posso pagar a prestações?", "e se eu ficar doente antes da viagem?").
* **`content/destinos.json`.** É onde vive o texto das páginas de destino. Quanto
  mais concreto e útil (preços reais, meses, tempo de voo, documentos), melhor
  posiciona — e mais confiança dá.

---

## 3. Primeiro dia: dizer ao Google que existe

1. **Google Search Console** — <https://search.google.com/search-console>
   Adicione o domínio, confirme a propriedade (o cPanel permite fazê-lo por
   registo DNS TXT) e submeta `sitemap.xml`.
2. **Perfil de Empresa do Google** — <https://business.google.com>
   Para uma agência, é o que mais tráfego local traz. Preencha tudo: morada,
   horário, telefone, fotografias reais, serviços. Peça avaliações aos clientes.
3. **Bing Webmaster Tools** — importa a configuração do Search Console em dois
   cliques e traz algum tráfego extra.

---

## 4. Primeiro mês: dar-lhe razões para o mostrar

* **Fotografias reais.** Substitua as ilustrações por fotografias suas nos
  destinos (Backoffice → Conteúdos do site). Fotografia própria distingue-o dos
  sites que usam as mesmas imagens de banco.
* **Testemunhos com nome e viagem.** Já estão no site; troque os de exemplo por
  reais, com autorização.
* **Uma página de destino nova por semana.** Cada uma é uma porta de entrada.
  Comece pelos destinos que mais vende — são os que mais orçamentos convertem.
* **Ligações de fora (backlinks).** As que valem para uma agência portuguesa:
  associações do setor, RNAVT, câmaras e regiões de turismo, parceiros
  (hotéis, operadores), imprensa local, blogues de viagens.
* **Redes sociais ligadas ao site.** Preencha-as no backoffice: entram nos dados
  estruturados e ajudam o Google a perceber que a marca é a mesma.

---

## 5. Ao longo do tempo

* **Responda a perguntas.** Cada dúvida que receber por telefone é uma pergunta
  frequente à espera de ser escrita.
* **Atualize os preços "desde".** Um preço desatualizado numa página de destino
  custa credibilidade e cliques.
* **Veja o Search Console uma vez por mês:** que termos já trazem visitas, quais
  estão na segunda página (são os mais fáceis de subir) e que páginas ninguém vê.
* **Não compre ligações.** É o caminho mais rápido para uma penalização.

---

## 6. Verificações técnicas depois de publicar

```
https://oseudominio.pt/robots.txt      deve mostrar o endereço do sitemap
https://oseudominio.pt/sitemap.xml     deve listar todas as páginas
https://oseudominio.pt/viagens/        deve abrir o índice de destinos
```

Ferramentas úteis (todas gratuitas):

* **PageSpeed Insights** — <https://pagespeed.web.dev> — velocidade e experiência.
* **Teste de resultados enriquecidos** — <https://search.google.com/test/rich-results> —
  confirma que as perguntas frequentes e as migalhas são reconhecidas.
* **Inspeção de URL** no Search Console — para pedir a indexação de páginas novas.

---

## 7. Quando acrescentar destinos

As páginas de destino são geradas a partir das definições. Depois de acrescentar
um destino no backoffice:

```bash
npm run build:seo        # gera a página nova, atualiza o mapa do site
npm run build:cpanel     # prepara o pacote atualizado para carregar
```

Se quiser texto próprio para esse destino (recomendado — o texto genérico
posiciona bem menos), acrescente um bloco em `content/destinos.json` a copiar
um dos existentes.
