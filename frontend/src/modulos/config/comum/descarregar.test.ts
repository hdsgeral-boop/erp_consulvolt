import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { definirAoExpirar, descarregar, http } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { eFolhaExcel } from './regras';

/** `descarregar` (src/api/cliente.ts): downloads binários com o mesmo tratamento de erros dos outros pedidos. */
describe('descarregar ficheiros binários', () => {
  const resposta = (status: number, corpo: Blob, cabecalhos: Record<string, string> = {}) =>
    ({ status, data: corpo, headers: cabecalhos, statusText: '', config: {} }) as never;
  let expirou: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    expirou = vi.fn();
    definirAoExpirar(expirou);
    URL.createObjectURL = vi.fn(() => 'blob:x');
    URL.revokeObjectURL = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('descarrega com o nome do Content-Disposition e devolve os cabeçalhos', async () => {
    const get = vi.spyOn(http, 'get').mockResolvedValue(
      resposta(200, new Blob(['<xml/>']), { 'content-disposition': 'attachment; filename="SAFT_AO_1.xml"', 'X-Saft-Documentos': '3' }),
    );
    const clique = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    const r = await descarregar('/vendas/saft', { inicio: '2026-01-01', fim: '', vazio: null }, 'x.xml');
    expect(r).toEqual({ nome: 'SAFT_AO_1.xml', cabecalhos: { 'content-disposition': 'attachment; filename="SAFT_AO_1.xml"', 'x-saft-documentos': '3' } });
    expect(get.mock.calls[0][1]).toMatchObject({ params: { inicio: '2026-01-01' }, responseType: 'blob' });
    expect(clique).toHaveBeenCalledOnce();
    expect(expirou).not.toHaveBeenCalled();
  });

  it('lê a mensagem e o código do envelope JSON quando o servidor responde erro', async () => {
    vi.spyOn(http, 'get').mockResolvedValue(
      resposta(422, new Blob([JSON.stringify({ sucesso: false, mensagem: 'Não há documentos fiscais nem recibos no período.', codigo: 'SEM_DOCUMENTOS' })])),
    );
    const erro = await descarregar('/vendas/saft', undefined, 'x.xml').catch((e: unknown) => e);
    expect(erro).toBeInstanceOf(ErroApi);
    expect(erro).toMatchObject({ message: 'Não há documentos fiscais nem recibos no período.', estado: 422, codigo: 'SEM_DOCUMENTOS' });
    expect(expirou).not.toHaveBeenCalled();
  });

  it('termina a sessão com 401 e tolera corpos que não são JSON', async () => {
    vi.spyOn(http, 'get').mockResolvedValue(resposta(401, new Blob(['<html>'])));
    const erro = await descarregar('/sistema/copias/exportar', undefined, 'c.json').catch((e: unknown) => e);
    expect(expirou).toHaveBeenCalledOnce();
    expect(erro).toMatchObject({ estado: 401, message: 'Não foi possível obter o ficheiro (401).' });
  });
});

describe('importações de ficheiros', () => {
  it('só as folhas Excel seguem para o servidor', () => {
    expect(eFolhaExcel('Template_plano_contas.xlsx')).toBe(true);
    expect(eFolhaExcel('cambios.XLS')).toBe(true);
    expect(eFolhaExcel('cambios.csv')).toBe(false);
    expect(eFolhaExcel('dados.txt')).toBe(false);
  });
});
