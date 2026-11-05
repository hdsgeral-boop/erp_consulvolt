import { aplicarAjudas } from './AjudasAcessibilidade';

describe('AjudasAcessibilidade', () => {
  it('dá id ao main, nome às selecções sem rótulo e às th vazias; respeita o que já existe', () => {
    document.body.innerHTML = `
      <main></main>
      <div class="ant-form-item"><label>Estado</label><div class="ant-select"><input role="combobox" id="a" /></div></div>
      <div class="ant-select"><span class="ant-select-placeholder">Todas as contas</span><input role="combobox" id="b" /></div>
      <div class="ant-select"><input role="combobox" id="c" aria-label="Já tem" /></div>
      <table><tr><th></th><th>Nome</th></tr><tr class="erp-linha-filtros"><th></th></tr></table>`;
    aplicarAjudas();
    expect(document.querySelector('main')!.id).toBe('conteudo-principal');
    expect(document.getElementById('a')!.getAttribute('aria-label')).toBe('Estado');
    expect(document.getElementById('b')!.getAttribute('aria-label')).toBe('Todas as contas');
    expect(document.getElementById('c')!.getAttribute('aria-label')).toBe('Já tem');
    const ths = document.querySelectorAll('th');
    expect(ths[0].getAttribute('aria-label')).toBe('Acções');
    expect(ths[1].getAttribute('aria-label')).toBeNull();
    expect(ths[2].getAttribute('aria-label')).toBe('Filtro da coluna');
  });
});
