import { describe, expect, it } from 'vitest';
import type { ModuloMenu } from '@/api/tipos';
import { MAX_ROTULO_FAVORITO, nomeOriginalFavorito, normalizarRotulo, renomearNaLista, resolverFavoritos } from './favoritos';

const MENU: ModuloMenu[] = [{ id: 'config', nome: 'Configurações', ecras: [{ id: 'config_geral', nome: 'Geral', vistas: [], pai: null }] }];

describe('favoritos — rótulo próprio (renomear favorito, Versão Actual)', () => {
  it('mostra o rótulo próprio e guarda o nome original do ecrã', () => {
    const [f] = resolverFavoritos([{ modulo: 'config', ecra: 'config_geral', rotulo: 'Cópias' }], MENU);
    expect(f).toMatchObject({ nome: 'Cópias', rotulo: 'Cópias', rota: '/m/config/config_geral' });
    expect(nomeOriginalFavorito('config', 'config_geral', MENU)).toBe('Geral / Backup');
    expect(resolverFavoritos([{ modulo: 'config', ecra: 'config_geral' }], MENU)[0].nome).toBe('Geral / Backup');
  });

  it('normaliza: espaços, tamanho máximo; vazio ou igual ao nome do ecrã retira o rótulo', () => {
    expect(normalizarRotulo('  a   b ')).toBe('a b');
    expect(normalizarRotulo('x'.repeat(200))).toHaveLength(MAX_ROTULO_FAVORITO);
    expect(normalizarRotulo('   ')).toBeUndefined();
    expect(normalizarRotulo('Geral / Backup', 'Geral / Backup')).toBeUndefined();
  });

  it('renomeia só o favorito indicado e mantém a ordem', () => {
    const lista = [{ modulo: 'a', ecra: '1' }, { modulo: 'b', ecra: '2', rotulo: 'Antigo' }];
    expect(renomearNaLista(lista, 'a', '1', 'Novo')).toEqual([{ modulo: 'a', ecra: '1', rotulo: 'Novo' }, { modulo: 'b', ecra: '2', rotulo: 'Antigo' }]);
    expect(renomearNaLista(lista, 'b', '2', '')).toEqual([{ modulo: 'a', ecra: '1' }, { modulo: 'b', ecra: '2' }]);
  });
});
