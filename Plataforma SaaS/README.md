# Plataforma SaaS — MEU ERP

Esta pasta reúne a documentação, a avaliação e o plano de correções da plataforma SaaS.
A plataforma está **dentro do ERP**, em Configurações → Plataforma, e usa o mesmo frontend e a mesma API.

## Conteúdo da pasta

| Arquivo | O que é |
| --- | --- |
| [01_AVALIACAO.md](01_AVALIACAO.md) | Avaliação de prontidão e análise da área Configurações → Plataforma SaaS |
| [02_PLANO_DE_CORRECOES.md](02_PLANO_DE_CORRECOES.md) | Plano por fases (P0 a P3) com os critérios para avançar |
| [03_ESTADO_ATUAL.md](03_ESTADO_ATUAL.md) | O que já foi corrigido, o que está pendente e como está o ambiente |

## Documentos existentes (ficam onde estão)

- [Ativar o SaaS (papel `erp_app` e RLS)](../docs/arquitetura/SAAS_ATIVACAO.md)
- [Pagamentos, gateways e notificações](../docs/arquitetura/SAAS_PAGAMENTOS_NOTIFICACOES.md)
- [Comparação com a v5 do SaaS](../docs/arquitetura/COMPARACAO_V5_SAAS.md)
- [Deploy automático para a AWS](../docs/DEPLOY_AWS.md)

## Onde está o código

| Parte | Caminho |
| --- | --- |
| Telas da plataforma | `frontend/src/modulos/config/Plataforma*.tsx` e `Licenca.tsx` |
| Registo dos ecrãs | `frontend/src/modulos/config/ecras.ts` |
| Rotas da API da plataforma | `backend/routes/api/plataforma.php` |
| Rotas de licença e cobrança | `backend/routes/api/saas_licenca.php` |
| Barreira da área da plataforma | `backend/app/Http/Middleware/ExigirPlataforma.php` |
| Resolução do tenant por domínio | `backend/app/Support/Tenancy/DominioPedido.php` e `ResolverTenantAtivo.php` |
| Criação de clientes | `backend/app/Services/Plataforma/ServicoClientes.php` |
| Licenças e pagamentos | `backend/app/Services/Sistema/ServicoLicenca.php`, `backend/app/Services/Pagamentos/` |

## Endereços

- Plataforma (administração): `https://gestao-admin.meuerp.ao`
- Cada empresa cliente: `https://<slug>.meuerp.ao`
- O prefixo da plataforma vem de `DOMINIO_PLATAFORMA` e o domínio base de `DOMINIO_BASE`, ambos no `.env`.

Senhas e credenciais não ficam neste repositório.
