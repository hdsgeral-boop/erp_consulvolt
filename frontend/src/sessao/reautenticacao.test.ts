import { AxiosError, type InternalAxiosRequestConfig } from 'axios';
import { enviar, http } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { metodoDoErro, pedirReautenticacao, registarOuvinteReautenticacao } from './reautenticacao';

/** Contrato com o backend (ExigirMfaRecente): 403 MFA_REQUERIDO abre o pedido e, confirmado, repete a operação uma vez. */
function falhaMfa(config: InternalAxiosRequestConfig, metodo = 'palavra_passe'): never {
  throw new AxiosError('403', 'ERR_BAD_REQUEST', config, null, {
    status: 403, statusText: 'Forbidden', headers: {}, config,
    data: { sucesso: false, mensagem: 'Esta operação exige uma confirmação de identidade recente.', codigo: 'MFA_REQUERIDO', erros: { metodo } },
  });
}

describe('step-up (MFA_REQUERIDO)', () => {
  let chamadas: string[];
  let desligar: () => void = () => undefined;

  beforeEach(() => {
    chamadas = [];
  });
  afterEach(() => desligar());

  it('pede a confirmação, repete o pedido original uma vez e devolve o resultado', async () => {
    let falhou = false;
    http.defaults.adapter = async (config) => {
      chamadas.push(`${config.method} ${config.url}`);
      if (config.url === '/sistema/utilizadores' && !falhou) {
        falhou = true;
        return falhaMfa(config, 'codigo_mfa');
      }
      return { data: { sucesso: true, dados: { id: 7 }, mensagem: 'ok', metadados: {} }, status: 200, statusText: 'OK', headers: {}, config };
    };
    const pedidos: string[] = [];
    desligar = registarOuvinteReautenticacao(({ metodo, resolver }) => {
      pedidos.push(metodo);
      resolver(true);
    });

    const r = await enviar<{ id: number }>('post', '/sistema/utilizadores', { nome: 'x' });

    expect(r.dados.id).toBe(7);
    expect(pedidos).toEqual(['codigo_mfa']);
    expect(chamadas).toEqual(['post /sistema/utilizadores', 'post /sistema/utilizadores']);
  });

  it('se o utilizador cancela, o erro chega ao chamador com o código e não há repetição', async () => {
    http.defaults.adapter = async (config) => {
      chamadas.push(config.url ?? '');
      return falhaMfa(config);
    };
    desligar = registarOuvinteReautenticacao(({ resolver }) => resolver(false));

    await expect(enviar('post', '/sistema/perfis', {})).rejects.toMatchObject({ estado: 403, codigo: 'MFA_REQUERIDO' });
    expect(chamadas).toHaveLength(1);
  });

  it('não entra em ciclo: um segundo MFA_REQUERIDO depois da confirmação é um erro normal', async () => {
    http.defaults.adapter = async (config) => {
      chamadas.push(config.url ?? '');
      return falhaMfa(config);
    };
    desligar = registarOuvinteReautenticacao(({ resolver }) => resolver(true));

    await expect(enviar('post', '/sistema/perfis', {})).rejects.toBeInstanceOf(ErroApi);
    expect(chamadas).toHaveLength(2);
  });

  it('vários pedidos em simultâneo partilham um só pedido de confirmação', async () => {
    const abertos: Array<(c: boolean) => void> = [];
    desligar = registarOuvinteReautenticacao(({ resolver }) => abertos.push(resolver));
    const a = pedirReautenticacao('palavra_passe');
    const b = pedirReautenticacao('palavra_passe');
    expect(abertos).toHaveLength(1);
    abertos[0](true);
    await expect(Promise.all([a, b])).resolves.toEqual([true, true]);
  });

  it('sem interface registada não fica à espera: devolve falso', async () => {
    await expect(pedirReautenticacao('palavra_passe')).resolves.toBe(false);
  });

  it('o método vem do servidor; na dúvida usa a palavra-passe', () => {
    expect(metodoDoErro({ metodo: 'codigo_mfa' })).toBe('codigo_mfa');
    expect(metodoDoErro({ metodo: 'outro' })).toBe('palavra_passe');
    expect(metodoDoErro(undefined)).toBe('palavra_passe');
  });
});
