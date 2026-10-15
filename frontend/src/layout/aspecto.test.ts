import { aplicarTema, CLASSE_TELA_NOCTURNA, chaveLocalAspecto, lerAspectoLocal, normalizarAspecto } from './aspecto';

describe('aspecto da interface (tela nocturna e aspecto do menu)', () => {
  afterEach(() => {
    localStorage.clear();
    aplicarTema('claro');
  });

  it('normaliza valores desconhecidos para o padrão', () => {
    expect(normalizarAspecto(null)).toEqual({ tema: 'claro', menu: 'completo' });
    expect(normalizarAspecto({ tema: 'escuro', menu: 'nomes' })).toEqual({ tema: 'escuro', menu: 'nomes' });
    expect(normalizarAspecto({ tema: '<script>', menu: 'outro' })).toEqual({ tema: 'claro', menu: 'completo' });
  });

  it('lê a cópia do navegador por utilizador (aplicada antes da primeira pintura)', () => {
    localStorage.setItem(chaveLocalAspecto(7), JSON.stringify({ tema: 'escuro', menu: 'icones' }));
    expect(lerAspectoLocal(7)).toEqual({ tema: 'escuro', menu: 'icones' });
    expect(lerAspectoLocal(8)).toEqual({ tema: 'claro', menu: 'completo' });
    localStorage.setItem(chaveLocalAspecto(9), '{mau json');
    expect(lerAspectoLocal(9)).toEqual({ tema: 'claro', menu: 'completo' });
  });

  it('aplica e retira a classe da tela nocturna no elemento raiz', () => {
    aplicarTema('escuro');
    expect(document.documentElement).toHaveClass(CLASSE_TELA_NOCTURNA);
    expect(document.documentElement.dataset.tema).toBe('escuro');
    aplicarTema('claro');
    expect(document.documentElement).not.toHaveClass(CLASSE_TELA_NOCTURNA);
  });
});
