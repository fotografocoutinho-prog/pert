# Plano de implementação comercial

Este documento organiza o lançamento. Não inclui estimativas de mercado nem preços finais: os valores dos planos no sistema são **exemplos** e devem ser definidos no backoffice depois de validados com clientes.

## Etapas

| Etapa | Objetivo | Condição para avançar |
| --- | --- | --- |
| 1. Piloto fechado | 2 a 3 empresas de construção usam as fases 1–3 gratuitamente | Fase 3 concluída; contrato de piloto e acordo de tratamento de dados assinados |
| 2. Validação | Medir uso real: diários por semana, inspeções remotas, deslocações evitadas | Pelo menos 1 empresa usa semanalmente durante 2 meses |
| 3. Preços | Definir preços com base no valor medido e nos custos (alojamento, suporte, IA) | Decisão do cliente **[DECIDIR]** |
| 4. Lançamento | Registo público com trial de 30 dias | Checklist de segurança completa, revisão jurídica, faturação definida |
| 5. Escala | Pagamentos self-service, integrações ERP, migração para VPS | Volume que justifique (ver `09-migracao-vps.md`) |

## O que tem de existir antes de cobrar

- [ ] Termos de utilização, política de privacidade e acordo de tratamento de dados revistos por jurista.
- [ ] Processo de faturação das subscrições com software certificado pela AT (a plataforma não emite faturas).
- [ ] Contacto de suporte, horário e tempos de resposta definidos.
- [ ] Backups automáticos fora do servidor e teste de restauro feito.
- [ ] Domínio, email transacional com SPF/DKIM/DMARC, certificado SSL.
- [ ] Página pública de preços e de funcionalidades (fora desta aplicação ou a acrescentar).

## Planos (estrutura já configurável)

| Plano | Limites de exemplo no sistema | Funcionalidades |
| --- | --- | --- |
| Start | 5 utilizadores, 3 obras, 5 GB | diário, documentos |
| Professional | 25 utilizadores, 15 obras, 50 GB | + fiscalização, custos, relatórios avançados |
| Business | 100 utilizadores, 60 obras, 200 GB | + IA, integrações, automações |
| Enterprise | sob consulta | + base de dados dedicada, integrações dedicadas, suporte prioritário |

Tudo isto é editável em **Plataforma → Planos e preços** sem alterar código.

## Decisões pendentes

Repetidas de `01-analise-funcional.md`, secção 6: faturação, gateway de pagamentos, fornecedor de IA, retenção após cancelamento, domínio e subdomínios.
