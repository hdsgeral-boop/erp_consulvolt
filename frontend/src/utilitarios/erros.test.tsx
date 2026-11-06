import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { ErroApi } from '@/api/tipos';
import { FronteiraDeErros } from '@/componentes/FronteiraDeErros';
import { notificarErro } from './erros';

const { erro, aviso } = vi.hoisted(() => ({ erro: vi.fn(), aviso: vi.fn() }));
vi.mock('antd', async (importOriginal) => {
  const original = await importOriginal<typeof import('antd')>();
  return { ...original, notification: { ...original.notification, error: erro, warning: aviso } };
});

function texto(no: ReactNode): string {
  const { container } = render(<>{no}</>);
  return container.textContent ?? '';
}

describe('notificarErro (camada central de apresentação)', () => {
  beforeEach(() => {
    erro.mockClear();
    aviso.mockClear();
  });

  it('validação e regras de negócio são AVISO; falhas do sistema são ERRO', () => {
    notificarErro(new ErroApi('Os dados enviados são inválidos.', 422, 'VALIDACAO'));
    expect(aviso).toHaveBeenCalledTimes(1);
    expect(erro).not.toHaveBeenCalled();
    notificarErro(new ErroApi('Ocorreu um erro interno.', 500, 'ERRO_INTERNO', undefined, 'E-ABCDEFGH'));
    expect(erro).toHaveBeenCalledTimes(1);
  });

  it('um erro que não é ErroApi nunca mostra o seu message cru', () => {
    notificarErro(new TypeError('Cannot read properties of undefined (reading "x") em /src/secreto.ts'));
    const args = erro.mock.calls[0][0];
    const visivel = texto(args.description);
    expect(visivel).not.toMatch(/Cannot read|secreto/);
    expect(visivel).toContain('erro inesperado');
  });

  it('o 5xx mostra a referência; os outros erros não mostram nenhum código interno', () => {
    notificarErro(new ErroApi('x', 500, 'ERRO_INTERNO', undefined, 'E-7K3QX9PD'));
    expect(texto(erro.mock.calls[0][0].description)).toContain('E-7K3QX9PD');
    notificarErro(new ErroApi('O documento já está anulado.', 422, 'DOC_ANULADO'));
    const t = texto(aviso.mock.calls[0][0].description);
    expect(t).toContain('O documento já está anulado.');
    expect(t).not.toContain('DOC_ANULADO');
  });

  it('o mesmo erro usa a mesma chave (não empilha) e é anunciado como alerta', () => {
    const e = new ErroApi('O documento já está anulado.', 422, 'DOC_ANULADO');
    notificarErro(e);
    notificarErro(e);
    expect(aviso.mock.calls[0][0].key).toBe(aviso.mock.calls[1][0].key);
    expect(aviso.mock.calls[0][0].role).toBe('alert');
  });

  it('pedido cancelado não gera alerta', () => {
    notificarErro(new ErroApi('O pedido foi cancelado.', 0, 'PEDIDO_CANCELADO'));
    expect(aviso).not.toHaveBeenCalled();
    expect(erro).not.toHaveBeenCalled();
  });
});

describe('FronteiraDeErros', () => {
  it('um erro de renderização mostra texto genérico e uma referência, sem a mensagem da excepção', () => {
    const consola = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const Rebenta = (): never => {
      throw new Error('SQLSTATE segredo /var/www/x.php');
    };
    render(
      <FronteiraDeErros>
        <Rebenta />
      </FronteiraDeErros>,
    );
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(document.body.textContent).toMatch(/UI-[A-Z0-9]{8}/);
    expect(document.body.textContent).not.toMatch(/SQLSTATE|segredo|var\/www/);
    consola.mockRestore();
  });
});
