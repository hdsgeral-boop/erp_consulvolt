import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Guarda de regressão (ADR-082): nenhum código da aplicação pode voltar a gravar no navegador o token, o identificador do
 * dispositivo, a empresa activa ou dados da sessão. As únicas gravações permitidas em localStorage/sessionStorage são as
 * dos ficheiros de PREFERÊNCIAS DE INTERFACE da lista abaixo (tema, largura do menu, colunas…), e cada nova gravação num
 * ficheiro fora da lista tem de ser decidida aqui, à vista, com a justificação em docs/arquitetura/SESSOES_E_COOKIES.md.
 */
const RAIZ = join(__dirname, '..');

function ficheiros(dir: string, saida: string[] = []): string[] {
  for (const nome of readdirSync(dir)) {
    const p = join(dir, nome);
    if (statSync(p).isDirectory()) ficheiros(p, saida);
    else if (/\.(ts|tsx)$/.test(nome) && !/\.test\.(ts|tsx)$/.test(nome)) saida.push(p);
  }
  return saida;
}

/** Ficheiros que gravam PREFERÊNCIAS DE INTERFACE (sem valor para um atacante). */
const PERMITIDOS = new Set([
  'componentes/formularios/PainelLinhas.tsx',
  'componentes/impressao/preferencias.ts',
  'componentes/tour/TourBoasVindas.tsx',
  'componentes/vistas/preferenciaColunas.ts',
  'componentes/vistas/preferenciaVista.ts',
  'layout/aspecto.ts',
  'layout/avisoLicencaVisto.ts',
  'layout/larguraMenu.ts',
  'layout/modoMenu.ts',
  'layout/posicaoFlutuante.ts',
  'main.tsx',
  'modulos/config/NotificacoesCentro.tsx',
  'modulos/contab/lancamentos/ferramentas.ts',
  'modulos/contab/Rotinas.tsx',
  'modulos/estrutura/Organigrama.tsx',
  'modulos/geral/FluxoProcessos.tsx',
  'modulos/pos/comum/frente.ts',
  'modulos/pos/comum/impressao.ts',
  'modulos/pos/comum/SessaoTerminal.tsx',
  'modulos/pos/FrenteCaixa.tsx',
  'modulos/projectos/separadores/organigrama/QuadroOrganigrama.tsx',
  'modulos/stock/posArmazem/Balcao.tsx',
  'modulos/stock/posArmazem/Senhas.tsx',
  'paginas/publico/RegistarEmpresa.tsx',
  'plataforma/modoMenuPlataforma.ts',
  'sessao/armazenamento.ts', // só converte chaves antigas de interface
]);

const todos = ficheiros(RAIZ).map((f) => ({ rel: relative(RAIZ, f).replace(/\\/g, '/'), texto: readFileSync(f, 'utf8') }));

describe('nada de autenticação no navegador', () => {
  it('só os ficheiros de preferências de interface gravam em localStorage/sessionStorage', () => {
    const intrusos = todos.filter((f) => /\.\s*setItem\s*\(/.test(f.texto) && !PERMITIDOS.has(f.rel)).map((f) => f.rel);
    expect(intrusos).toEqual([]);
  });

  it('ninguém usa IndexedDB, Cache Storage nem Service Workers (nenhuma resposta autenticada fica no navegador)', () => {
    const usam = todos.filter((f) => /\bindexedDB\b|\bcaches\.(open|match)\b|serviceWorker\s*\.\s*register|\bnavigator\.serviceWorker\b/.test(f.texto)).map((f) => f.rel);
    expect(usam).toEqual([]);
  });

  it('nenhuma chave do armazenamento do navegador é de token, dispositivo, empresa activa, e-mail ou permissões', () => {
    const proibido = /["'`]erp\.(dispositivo|empresa|empresas_recentes|token|sessao|utilizador|email|permissoes|menu_cache)["'`.]/;
    const quem = todos.filter((f) => f.rel !== 'sessao/armazenamento.ts' && proibido.test(f.texto)).map((f) => f.rel);
    expect(quem).toEqual([]);
  });

  it('o código que grava no armazenamento nunca recebe o token, o e-mail, o nome nem as permissões', () => {
    const suspeito = /(localStorage|sessionStorage)\s*\.\s*setItem\s*\([^)]*\b(token|palavra_passe|password|email|nome_utilizador|nome_completo|permissoes)\b/i;
    const quem = todos.filter((f) => suspeito.test(f.texto)).map((f) => f.rel);
    expect(quem).toEqual([]);
  });

  it('o cliente HTTP não lê nem escreve o token: só o cookie CSRF (legível por desenho) e nunca o cookie HttpOnly', () => {
    const cliente = todos.find((f) => f.rel === 'api/cliente.ts')!.texto;
    expect(cliente).not.toMatch(/erp_token|erp_dev|Authorization|localStorage|sessionStorage/);
    expect(cliente).toMatch(/withCredentials:\s*true/);
  });

  it('a sessão é reconstruída só de /autenticacao/eu e a empresa activa vai para o servidor', () => {
    const sessao = todos.find((f) => f.rel === 'sessao/SessaoContexto.tsx')!.texto;
    expect(sessao).toContain("'/autenticacao/eu'");
    expect(sessao).toContain("'/autenticacao/empresa-activa'");
    expect(sessao).not.toMatch(/localStorage|sessionStorage|idDispositivo|dispositivo:/);
  });
});
