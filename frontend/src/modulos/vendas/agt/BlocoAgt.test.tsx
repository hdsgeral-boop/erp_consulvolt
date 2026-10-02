import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { BlocoAgt } from './BlocoAgt';

vi.setConfig({ testTimeout: 60_000 });

describe('BlocoAgt (A-04)', () => {
  it('mostra o estado, a última resposta e os erros/avisos legíveis', () => {
    render(
      <BlocoAgt
        documento={{
          faturacao_eletronica: {
            serie: 'A2026', numero: 7, estado: 'PRONTO', regime: true, envio: 'INVALIDO', selado_em: '2026-10-01T10:00:00Z',
            erros: [], avisos: ['W01: linha isenta sem motivo de isenção'],
            erros_agt: [{ codigo: 'E40', mensagem: 'NIF do cliente inválido', documentNo: 'FT A2026/7' }],
            envio_detalhe: { request_id: 'REQ-123', enviado_em: '2026-10-01T10:05:00Z', validado_em: null, ultima_consulta: '2026-10-01T10:06:00Z', proxima_consulta: null, correccao: true, tentativas: 2,
              historico: [{ em: '2026-10-01T10:06:00Z', accao: 'consultar', resultado: 'I (1 erro)' }] },
          },
        }}
      />,
    );
    expect(screen.getByText('Facturação electrónica (AGT)')).toBeInTheDocument();
    expect(screen.getByText('Inválido')).toBeInTheDocument();
    expect(screen.getByText('REQ-123')).toBeInTheDocument();
    expect(screen.getByText('E40')).toBeInTheDocument();
    expect(screen.getByText(/NIF do cliente inválido/)).toBeInTheDocument();
    expect(screen.getByText('W01')).toBeInTheDocument();
    expect(screen.getByText('Correcção (C)')).toBeInTheDocument();
    expect(screen.getByText(/Revalidar AGT/)).toBeInTheDocument();
  });

  it('não aparece fora do regime de facturação electrónica', () => {
    const { container } = render(<BlocoAgt documento={{ faturacao_eletronica: { serie: null, numero: null, estado: 'PRONTO', regime: false, erros: [], avisos: [] } }} />);
    expect(container).toBeEmptyDOMElement();
  });
});
