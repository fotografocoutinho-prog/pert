# P. API pública e webhooks (v0.21.0)

Para ligar o ObraConnect a outros programas da empresa: faturação certificada, contabilidade, ERP, BI. Faz parte dos planos **Business** e **Enterprise** (funcionalidade `integrations`). Gere-se em **Integrações (API)**, com a permissão `integrations.manage` (o administrador tem-na).

## Chaves da API

- Uma chave por programa, com nome, **âmbitos** e validade opcional. Formato `oc_` + 40 caracteres.
- A chave só aparece **uma vez**, quando é criada. Guarda-se o início (`prefix`, 12 caracteres, para a encontrar) e o SHA-256 (para a confirmar); a chave em claro não fica em lado nenhum.
- Revogar é imediato. Máximo de 20 chaves ativas por empresa.
- Fica registado o último uso (data e IP) e, na auditoria, quem criou e revogou cada chave.
- Uma chave só vê os dados da sua empresa. Se a empresa estiver **só leitura**, só os pedidos de leitura passam (423 nos outros); **suspensa**, nada passa (423). Se o plano deixar de incluir integrações, as chaves deixam de funcionar (402) até voltar a incluir.

| Âmbito | Dá acesso a |
| --- | --- |
| `projects:read` | Obras, custos, previsões e autos |
| `invoicing:read` | Pré-faturas (sem valor fiscal), no formato da exportação JSON |
| `supplier-documents:read` | Faturas e guias de fornecedores |
| `supplier-documents:write` | Enviar faturas e guias de fornecedores (entram como pelo ecrã e são lidas pelo DocIntel) |

## Pedidos

Endereço: `https://<instalação>/api/public/v1`. Autenticação: `Authorization: Bearer oc_…` (ou `X-API-Key: oc_…`). Sem sessão nem cookies. Limite: 60 pedidos por minuto por chave (`PUBLIC_API_RATE_PER_MINUTE`) e o limite geral por IP; acima disso, 429.

| Método e caminho | Âmbito | Resposta |
| --- | --- | --- |
| `GET /ping` | — | Empresa, nome da chave, âmbitos e versão |
| `GET /projects?status=&per_page=` | `projects:read` | Obras (paginado, até 100 por página): id, código, nome, estado, cliente, localidade, datas, valor da adjudicação, teto de custo |
| `GET /projects/{id ou código}` | `projects:read` | A obra + `costs` (adjudicado, autado, realizado, comprometido, teto) + `forecast` (custo no fim, margem, fim previsto, atraso, razões) |
| `GET /projects/{id ou código}/certificates` | `projects:read` | Autos submetidos, aprovados (sem rascunhos), do dono de obra e de subempreiteiros, com as linhas do período |
| `GET /invoice-drafts?status=` | `invoicing:read` | Pré-faturas (até 500), com o id e os campos da exportação JSON |
| `POST /supplier-documents` (multipart: `file`, `project_code` opcional) | `supplier-documents:write` | 201 com o documento; 409 se o ficheiro já tinha sido enviado |
| `GET /supplier-documents/{id}` | `supplier-documents:read` | O documento (estado, fornecedor, número, ATCUD, totais, motivos de revisão) |

Valores sempre em **cêntimos**; datas ISO 8601. O formato é estável: podem aparecer campos novos, os existentes não mudam. Erros no formato habitual `{ "message": "…", "code": "…" }` (401 `API_KEY_INVALID`, 402 `PLAN_FEATURE_REQUIRED`, 403 `API_SCOPE_MISSING`, 404 `NOT_FOUND`, 422, 423, 429).

```bash
curl -H "Authorization: Bearer oc_…" https://www.coda.pt/obraconnect/api/public/v1/projects
curl -H "Authorization: Bearer oc_…" -F file=@fatura.pdf -F project_code=OB-2026-001 \
     https://www.coda.pt/obraconnect/api/public/v1/supplier-documents
```

## Webhooks

Um webhook é um endereço do outro programa que recebe um `POST` com JSON quando acontece um evento. Até 10 por empresa, cada um com os eventos que quer.

| Evento | Quando | `data` |
| --- | --- | --- |
| `certificate.approved` | Um auto é aprovado | O auto (como em `/certificates`, com a obra) |
| `certificate.proposed` | Um subempreiteiro propõe um auto no portal | O auto |
| `invoice_draft.ready` | Uma pré-fatura fica pronta a faturar | A pré-fatura (como em `/invoice-drafts`) |
| `supplier_document.read` | O DocIntel acaba de ler uma fatura ou guia (com ou sem erro: `read_error`) | O documento |
| `supplier_document.posted` | Uma fatura ou guia é lançada | O documento + `posted` (o que foi lançado) |
| `alert.opened` | Abre um alerta automático (previsões, medições, pontos de paragem, faturas por rever) | O alerta (tipo, nível, obra, endereço na aplicação) |

Corpo:

```json
{ "id": "uuid do evento", "event": "certificate.approved", "created_at": "2026-11-21T10:00:00+00:00",
  "company": { "id": 7, "name": "…" }, "data": { … } }
```

Cabeçalhos: `X-ObraConnect-Event`, `X-ObraConnect-Delivery` (= `id`; igual nas repetições, para ignorar duplicados), `X-ObraConnect-Timestamp` (segundos) e `X-ObraConnect-Signature` = `sha256=` + HMAC-SHA256 de `"{timestamp}.{corpo}"` com o **segredo** do webhook (`whsec_…`, mostrado uma vez ao criar ou ao trocar).

Verificação (PHP):

```php
$body = file_get_contents('php://input');
$ts = $_SERVER['HTTP_X_OBRACONNECT_TIMESTAMP'] ?? '';
$expected = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, getenv('OBRACONNECT_WEBHOOK_SECRET'));
if (! hash_equals($expected, $_SERVER['HTTP_X_OBRACONNECT_SIGNATURE'] ?? '') || abs(time() - (int) $ts) > 300) {
    http_response_code(400); exit;
}
```

Entrega:

- Sai pela fila, depois de a ação terminar (em cPanel, até 1 minuto depois). Uma falha no envio nunca estraga a ação que gerou o evento.
- Conta como entregue uma resposta **2xx** em menos de 10 segundos. Os redirecionamentos não se seguem.
- Sem isso, repete-se ao fim de 1 min, 5 min, 30 min, 2 h e 6 h; à 6.ª falha desiste. **Dez eventos falhados seguidos desligam o webhook** (fica o motivo à vista); voltar a ligá-lo recomeça a contagem.
- Em **Envios** vê-se cada envio (estado, tentativas, resposta, conteúdo) e pode **reenviar-se** (mesmo id de evento). **Enviar teste** manda um evento `ping` já, sem repetições.
- O registo dos envios terminados apaga-se ao fim de 30 dias (`obraconnect:prune-webhooks`, de hora a hora).

## Segurança

- Só endereços **https** e **públicos**: nada de `localhost`, `.local`, `.internal`, redes privadas, CGNAT, endereços reservados ou de metadados (169.254.x). Confirma-se ao gravar e outra vez a cada envio, e a ligação vai para o IP confirmado (sem nova consulta ao DNS), para o ObraConnect não poder ser usado para chegar a serviços internos do servidor. Endereços com utilizador e palavra-passe não são aceites.
- `WEBHOOKS_ALLOW_HTTP` e `WEBHOOKS_BLOCK_PRIVATE_NETWORKS=false` existem só para testes locais; nunca em produção.
- O segredo do webhook fica encriptado na base de dados (`APP_KEY`); a chave da API, só como hash.

## Tabelas

`api_keys` (tenant_id, name, prefix, key_hash, scopes, last_used_at/ip, expires_at, revoked_at), `webhook_endpoints` (url, description, secret encriptado, events, active, consecutive_failures, disabled_reason, last_delivery_at), `webhook_deliveries` (endpoint, event, event_id, payload, status pending/delivered/failed, attempts, response_status, response_body, error, delivered_at, next_attempt_at). Todas com `tenant_id` e chaves compostas como as outras tabelas da empresa.
