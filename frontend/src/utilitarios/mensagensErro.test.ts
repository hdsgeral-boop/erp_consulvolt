import { AxiosError, type InternalAxiosRequestConfig } from 'axios';
import { http, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import {
  categoriaDoErro,
  detalhesDoErro,
  gravidadeDoErro,
  mensagemGenerica,
  mensagemParaUtilizador,
  MENSAGEM_INESPERADA,
  MENSAGEM_REDE,
  MENSAGEM_SEM_INTERNET,
  MENSAGEM_TIMEOUT,
  normalizarFalhaSemResposta,
  normalizarRespostaDeErro,
  parecePorTecnico,
  referenciaDoErro,
} from './mensagensErro';

/** Faz o cliente HTTP falhar como o axios falharia: com resposta (estado + corpo) ou sem ela (rede/timeout). */
function falhar(estado: number, corpo: unknown, codigoAxios?: string): void {
  http.defaults.adapter = async (config: InternalAxiosRequestConfig) => {
    const resposta = estado ? { data: corpo, status: estado, statusText: '', headers: {}, config } : undefined;
    throw new AxiosError(estado ? `Request failed with status code ${estado}` : 'Network Error', codigoAxios, config, null, resposta);
  };
}

async function erroDe(estado: number, corpo: unknown, codigoAxios?: string): Promise<ErroApi> {
  falhar(estado, corpo, codigoAxios);
  try {
    await obter('/x');
  } catch (e) {
    return e as ErroApi;
  }
  throw new Error('devia ter falhado');
}

const envelope = (mensagem: string, codigo: string, extra: Record<string, unknown> = {}) => ({ sucesso: false, mensagem, codigo, dados: null, metadados: {}, ...extra });

describe('mapeamento por categoria (estado HTTP + código)', () => {
  it('401 → sessão terminou (texto próprio, não o do servidor)', async () => {
    const e = await erroDe(401, envelope('Sessão inválida ou expirada. Inicie sessão novamente.', 'NAO_AUTENTICADO'));
    expect(e.message).toBe('A sua sessão terminou. Entre novamente.');
    expect(categoriaDoErro(e)).toBe('sessao');
  });

  it('401 com código de negócio (credenciais) mantém a mensagem do servidor', async () => {
    const e = await erroDe(401, envelope('Credenciais inválidas.', 'CREDENCIAIS_INVALIDAS'));
    expect(e.message).toBe('Credenciais inválidas.');
  });

  it('403 → sem permissão; 404 → inexistente; 405; 419; 413', async () => {
    expect(categoriaDoErro(await erroDe(403, envelope('x', 'SEM_PERMISSAO')))).toBe('permissao');
    expect((await erroDe(403, envelope('x', 'SEM_PERMISSAO'))).message).toBe(mensagemGenerica(403));
    expect(categoriaDoErro(await erroDe(404, envelope('x', 'NAO_ENCONTRADO')))).toBe('inexistente');
    expect(categoriaDoErro(await erroDe(419, envelope('CSRF token mismatch.', 'SESSAO_CSRF_EXPIRADA')))).toBe('csrf');
    expect((await erroDe(419, envelope('CSRF token mismatch.', 'SESSAO_CSRF_EXPIRADA'))).message).toBe(mensagemGenerica(419));
    expect(categoriaDoErro(await erroDe(413, envelope('x', 'PEDIDO_DEMASIADO_GRANDE')))).toBe('grande');
  });

  it('422 de validação e regras de negócio mostram a mensagem do servidor e os detalhes por campo', async () => {
    const v = await erroDe(422, envelope('Os dados enviados são inválidos.', 'VALIDACAO', { erros: { nif: ['O NIF é obrigatório.'] } }));
    expect(categoriaDoErro(v)).toBe('validacao');
    expect(gravidadeDoErro(v)).toBe('warning');
    expect(detalhesDoErro(v)).toEqual(['O NIF é obrigatório.']);
    const n = await erroDe(422, envelope('O documento já está anulado.', 'DOC_ANULADO'));
    expect(n.message).toBe('O documento já está anulado.');
    expect(categoriaDoErro(n)).toBe('negocio');
  });

  it('409/422 de duplicado: diz que já existe, sem dados de terceiros', async () => {
    const e = await erroDe(422, envelope('Já existe uma empresa activa com este NIF.', 'NIF_DUPLICADO'));
    expect(e.message).toBe('Já existe uma empresa activa com este NIF.');
    expect(categoriaDoErro(await erroDe(409, envelope('x', 'CONFLITO')))).toBe('conflito');
  });

  it('429 usa o tempo de espera do servidor', async () => {
    const e = await erroDe(429, envelope('Demasiadas tentativas. Aguarde 42 segundos e tente novamente.', 'LIMITE_PEDIDOS', { erros: { espera_segundos: 42 } }));
    expect(e.message).toContain('42 segundos');
    expect(categoriaDoErro(e)).toBe('limite');
  });

  it('licença e MFA mantêm o código e a mensagem própria', async () => {
    const l = await erroDe(403, envelope('A licença expirou: a empresa está em modo de leitura.', 'LICENCA_EXPIRADA'));
    expect(categoriaDoErro(l)).toBe('licenca');
    expect(l.message).toContain('licença');
    const m = normalizarRespostaDeErro(403, envelope('Esta operação exige uma confirmação de identidade recente.', 'MFA_REQUERIDO'));
    expect(m.codigo).toBe('MFA_REQUERIDO');
    expect(m.mensagem).toBe('Esta operação exige uma confirmação de identidade recente.');
    expect(categoriaDoErro(new ErroApi(m.mensagem, 403, m.codigo))).toBe('mfa');
  });
});

describe('sem vazamento de detalhes do backend', () => {
  const tecnicos = [
    'SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "uq_empresas_nif"',
    'Call to undefined method App\\Services\\X::y() in /var/www/html/app/X.php on line 12',
    'Illuminate\\Database\\QueryException: select * from "empresas"',
    'Undefined array key "x"',
    'cURL error 6: Could not resolve host: api.exemplo.com',
    'redis connection refused 10.0.0.5:6379',
    'TypeError: x is not a function at foo (http://localhost/a.js:1:2)',
  ];

  it.each(tecnicos)('texto técnico de um código de negócio é substituído pela frase da categoria: %s', async (texto) => {
    const e = await erroDe(422, envelope(texto, 'CODIGO_QUALQUER'));
    expect(parecePorTecnico(texto)).toBe(true);
    expect(e.message).toBe(mensagemGenerica(422));
    expect(e.message).not.toContain('SQL');
  });

  it('um 500 nunca mostra a mensagem do servidor, mesmo com código de negócio', async () => {
    const e = await erroDe(500, envelope('SQLSTATE[HY000] segredo /var/www/x.php', 'ERRO_INTERNO', { referencia: 'E-7K3QX9PD' }));
    expect(e.message).toBe(mensagemGenerica(500));
    expect(e.message).not.toMatch(/SQLSTATE|var\/www/);
  });

  it('HTML de erro do proxy (502/503/504/404 do nginx) vira a frase da categoria, sem nomes de servidor nem versões', async () => {
    const html = '<html><head><title>502 Bad Gateway</title></head><body><center>nginx/1.24.0 (Ubuntu)</center></body></html>';
    for (const estado of [502, 503, 504]) {
      const e = await erroDe(estado, html);
      expect(e.message).toBe('Serviço temporariamente indisponível. Tente novamente dentro de instantes.');
      expect(categoriaDoErro(e)).toBe('indisponivel');
    }
    expect((await erroDe(404, html)).message).not.toMatch(/nginx|Ubuntu/);
  });

  it('um Error do navegador (TypeError) nunca é mostrado cru', () => {
    expect(mensagemParaUtilizador(new TypeError("Cannot read properties of undefined (reading 'x')"))).toBe(MENSAGEM_INESPERADA);
    expect(mensagemParaUtilizador('SQLSTATE texto')).toBe(MENSAGEM_INESPERADA);
    expect(mensagemParaUtilizador({ message: 'segredo' })).toBe(MENSAGEM_INESPERADA);
  });

  it('detalhes por campo com aspecto técnico são descartados', () => {
    const e = new ErroApi('x', 422, 'VALIDACAO', { a: ['Campo obrigatório.', 'SQLSTATE[22001] value too long for column "nome"'] });
    expect(detalhesDoErro(e)).toEqual(['Campo obrigatório.']);
  });

  it('a referência só é aceite no formato esperado e só nos 5xx', async () => {
    expect(normalizarRespostaDeErro(500, envelope('x', 'ERRO_INTERNO', { referencia: 'E-7K3QX9PD' })).referencia).toBe('E-7K3QX9PD');
    expect(normalizarRespostaDeErro(500, envelope('x', 'ERRO_INTERNO', { referencia: '<script>' })).referencia).toBeUndefined();
    expect(normalizarRespostaDeErro(422, envelope('x', 'VALIDACAO', { referencia: 'E-7K3QX9PD' })).referencia).toBeUndefined();
  });
});

describe('falha de rede ≠ erro do servidor', () => {
  it('sem resposta: mensagem de rede, estado 0, categoria rede (nunca «erro interno»)', async () => {
    const e = await erroDe(0, undefined, 'ERR_NETWORK');
    expect(e.estado).toBe(0);
    expect(categoriaDoErro(e)).toBe('rede');
    expect(e.message).toBe(MENSAGEM_REDE);
    expect(e.message).not.toMatch(/interno|permiss|sessão/i);
  });

  it('timeout: diz que o servidor não respondeu a tempo e que a operação pode ter ficado registada', async () => {
    const e = await erroDe(0, undefined, 'ECONNABORTED');
    expect(categoriaDoErro(e)).toBe('timeout');
    expect(e.message).toBe(MENSAGEM_TIMEOUT);
  });

  it('pedido cancelado não é uma falha', async () => {
    const e = await erroDe(0, undefined, 'ERR_CANCELED');
    expect(categoriaDoErro(e)).toBe('cancelado');
    expect(gravidadeDoErro(e)).toBe('info');
  });

  it('sem internet no navegador', () => {
    expect(normalizarFalhaSemResposta('ERR_NETWORK', false).mensagem).toBe(MENSAGEM_SEM_INTERNET);
  });
});

describe('5xx com referência', () => {
  it('traz a referência e a gravidade «erro»', async () => {
    const e = await erroDe(500, envelope('Ocorreu um erro interno.', 'ERRO_INTERNO', { referencia: 'E-7K3QX9PD' }));
    expect(referenciaDoErro(e)).toBe('E-7K3QX9PD');
    expect(categoriaDoErro(e)).toBe('interno');
    expect(gravidadeDoErro(e)).toBe('error');
  });

  it('com contexto: «Não foi possível gravar a factura. <categoria>»', async () => {
    const e = await erroDe(503, envelope('x', 'INDISPONIVEL', { referencia: 'E-7K3QX9PD' }));
    expect(mensagemParaUtilizador(e, 'gravar a factura')).toBe('Não foi possível gravar a factura. Serviço temporariamente indisponível. Tente novamente dentro de instantes.');
  });
});
