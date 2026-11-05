import { http, obter, obterAutomatico } from './cliente';

/** Contrato com o backend (PedidoAutomatico): só os GET de polling levam `X-Erp-Automatico: 1`, para não renovarem a inactividade. */
describe('obterAutomatico', () => {
  const pedidos: Record<string, string | undefined>[] = [];
  beforeEach(() => {
    pedidos.length = 0;
    http.defaults.adapter = async (config) => {
      pedidos.push({ metodo: config.method, automatico: config.headers.get('X-Erp-Automatico') as string | undefined });
      return { data: { dados: 1, mensagem: '', metadados: {} }, status: 200, statusText: 'OK', headers: {}, config };
    };
  });

  it('envia X-Erp-Automatico: 1 num GET automático', async () => {
    await obterAutomatico('/sistema/notificacoes');
    expect(pedidos[0]).toEqual({ metodo: 'get', automatico: '1' });
  });

  it('um GET normal (acção do utilizador) não leva a marca', async () => {
    await obter('/sistema/notificacoes');
    expect(pedidos[0].automatico).toBeUndefined();
  });

  it('mantém os cabeçalhos extra passados pelo chamador', async () => {
    await obterAutomatico('/x', undefined, { headers: { 'X-Teste': 'a' } });
    expect(pedidos[0].automatico).toBe('1');
  });
});
