import { beforeEach, describe, expect, it, vi } from 'vitest';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obter(...a), enviar: (...a: unknown[]) => enviar(...a) }));

import { gravarPreferencias, guardarConfigServidor, lerPreferencias, PREFERENCIAS_PADRAO, sincronizarPreferencias, temPreferenciasLocais } from './impressao';

const EFECTIVA = { formato: 'A4', largura: 58, automatico: false, consulta: 'DIRECTO', rodape: 'Do servidor' };

beforeEach(() => {
  window.localStorage.clear();
  obter.mockReset();
  enviar.mockReset();
});

describe('configuração de impressão no servidor (F7)', () => {
  it('a configuração do servidor passa a cópia local (a leitura síncrona devolve-a)', async () => {
    obter.mockResolvedValue({ guardada: true, empresa: EFECTIVA, terminal: null, terminal_pos_id: null, efectiva: EFECTIVA, padrao: PREFERENCIAS_PADRAO });
    const r = await sincronizarPreferencias(5);
    expect(r.origem).toBe('SERVIDOR');
    expect(lerPreferencias(5)).toMatchObject({ formato: 'A4', largura: 58, automatico: false, rodape: 'Do servidor' });
  });

  it('a sobreposição do terminal do posto também actualiza a cópia, e o pedido leva o terminal', async () => {
    obter.mockResolvedValue({ guardada: true, empresa: EFECTIVA, terminal: { largura: 80 }, terminal_pos_id: 7, efectiva: { ...EFECTIVA, largura: 80 }, padrao: PREFERENCIAS_PADRAO });
    await sincronizarPreferencias(5, 7);
    expect(obter).toHaveBeenCalledWith('/pos/impressao/config', { terminal_pos_id: 7 });
    expect(lerPreferencias(5).largura).toBe(80);
  });

  it('sem configuração no servidor mantém a local e avisa que há algo por guardar', async () => {
    obter.mockResolvedValue({ guardada: false, empresa: null, terminal: null, terminal_pos_id: null, efectiva: PREFERENCIAS_PADRAO, padrao: PREFERENCIAS_PADRAO });
    expect(await sincronizarPreferencias(5)).toMatchObject({ origem: 'LOCAL', porGuardar: false });
    gravarPreferencias(5, { ...PREFERENCIAS_PADRAO, formato: 'A4' });
    expect(temPreferenciasLocais(5)).toBe(true);
    expect(await sincronizarPreferencias(5)).toMatchObject({ origem: 'LOCAL', porGuardar: true });
    expect(lerPreferencias(5).formato).toBe('A4');
  });

  it('offline ou sem permissão: não mexe na cópia local nem lança erro', async () => {
    gravarPreferencias(5, { ...PREFERENCIAS_PADRAO, largura: 58 });
    obter.mockRejectedValue(new Error('rede'));
    expect(await sincronizarPreferencias(5)).toMatchObject({ origem: 'LOCAL', porGuardar: false, config: null });
    expect(lerPreferencias(5).largura).toBe(58);
  });

  it('guarda no servidor com PUT, levando o terminal só quando há sobreposição', async () => {
    enviar.mockResolvedValue({ dados: {}, mensagem: '' });
    await guardarConfigServidor({ formato: 'A4' });
    expect(enviar).toHaveBeenLastCalledWith('put', '/pos/impressao/config', { formato: 'A4' });
    await guardarConfigServidor({ largura: 58 }, 3);
    expect(enviar).toHaveBeenLastCalledWith('put', '/pos/impressao/config', { largura: 58, terminal_pos_id: 3 });
  });
});
