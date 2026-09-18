# Medições — pilar e vigas

Utilitário autónomo (sem dependências, Node 20+) para medir betão e cofragem de um
pilar e das vigas que nele descarregam, quando as vigas têm alturas diferentes.
Não faz parte da plataforma de signage; vive aqui só por conveniência.

```
node scripts/medicoes/pilar-vigas.mjs            # exemplo
node scripts/medicoes/pilar-vigas.mjs --ajuda    # opções
node --test scripts/medicoes/pilar-vigas.test.mjs
```

## Convenções

- O pilar leva a altura toda (pé-direito estrutural).
- As vigas medem-se em vão livre, entre faces dos pilares. Assim nada é contado duas
  vezes no nó.
- A cofragem do pilar mede-se face a face. Em cada face desconta-se a altura da viga
  que aí chega, porque nessa zona quem cofra é a viga. Com 4 vigas diferentes, as 4
  faces dão alturas diferentes.
- A cofragem da viga é o fundo mais as faces laterais visíveis, `(b + 2·(h − e_laje)) × L`.
  Com `ladosComLaje: 1` uma das faces fica à vista em toda a altura (viga de bordadura).
- `--convencao nervura` (por omissão) mede a viga abaixo da laje e a laje em toda a
  área. `--convencao total` mede a viga com a altura toda e a laje entre faces das
  vigas. Sem laje indicada, as duas coincidem.

O relatório inclui sempre a verificação `perímetro × altura − descontos`, que tem de
fechar com a soma das faces.

## Orientação

Em planta, `a` é a dimensão do pilar na direção nascente-poente e `b` na direção
norte-sul. Por isso as faces nascente e poente têm largura `b`, e as faces norte e sul
têm largura `a`.

## Exemplo

Pilar 0,30 × 0,40, pé-direito 4,00, com V1 0,40×0,50 a nascente, V2 0,30×0,25 a norte,
V3 0,40×0,35 a poente e V4 0,30×0,40 a sul:

| Face | Viga | Largura | Altura a cofrar | Área |
| --- | --- | --- | --- | --- |
| sul | V4 | 0,30 | 4,00 − 0,40 = 3,60 | 1,080 |
| nascente | V1 | 0,40 | 4,00 − 0,50 = 3,50 | 1,400 |
| norte | V2 | 0,30 | 4,00 − 0,25 = 3,75 | 1,125 |
| poente | V3 | 0,40 | 4,00 − 0,35 = 3,65 | 1,460 |

Betão 0,480 m³, cofragem 5,065 m². Verificação: 1,40 × 4,00 = 5,600 − 0,535 = 5,065.
