# Túnel da Cloudflare (domínio no ar a partir de qualquer máquina com Docker)

O `cloudflared` liga **para fora** à Cloudflare: não abre portas nem precisa de IP fixo. A Cloudflare faz o HTTPS e
encaminha `meuerp.ao` e `*.meuerp.ao` para o Nginx do Compose (`web:80`).

## Uma vez

1. Na Cloudflare (plano Free): *Add a site* `meuerp.ao` e trocar os nameservers no registrador. Esperar o estado **Active**.
2. `cloudflared tunnel login` (abre o navegador; escolha o domínio). Grava `~/.cloudflared/cert.pem`.
3. `cloudflared tunnel create meuerp` → devolve o ID e grava `~/.cloudflared/<ID>.json`.
4. DNS (um registo por nome; o `*` cobre todas as empresas):
   ```
   cloudflared tunnel route dns meuerp meuerp.ao
   cloudflared tunnel route dns meuerp "*.meuerp.ao"
   ```
5. Copie `config.yml.exemplo` para `config.yml`, ponha o ID e copie o `<ID>.json` para esta pasta (não vai para o Git).
6. No `backend/.env`: `DOMINIO_BASE=meuerp.ao` e `DOMINIO_PLATAFORMA=gestao-admin`; depois `docker compose up -d`.

## Ligar e desligar

```
docker compose --profile tunel up -d      # liga o túnel
docker compose --profile tunel stop cloudflared
```

## Verificar

`ferramentas/operacao/verificar_saas.sh` (credenciais por variáveis de ambiente) ou abrir `https://gestao-admin.meuerp.ao`.

## Limites

A máquina tem de ficar ligada e com internet; serve para testes e demonstração. Para clientes reais, mover o Docker para
uma VPS: o túnel e esta configuração continuam iguais.
