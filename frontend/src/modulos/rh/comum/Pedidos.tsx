import { Steps } from 'antd';
import { formatarData, formatarDataHora } from '@/utilitarios/formatacao';
import { ROTULOS_ESTADO, type DocumentoEmitido, type PedidoPortal } from '../api';

/** Texto curto do pedido (datas das férias/ausência, documento pedido, n.º de dependentes). */
export function resumoPedido(p: Pick<PedidoPortal, 'tipo' | 'dados'>): string {
  const d = (p.dados ?? {}) as Record<string, unknown>;
  const datas = d.data_inicio ? `${formatarData(String(d.data_inicio))} a ${formatarData(String(d.data_fim ?? d.data_inicio))}` : '';
  switch (p.tipo) {
    case 'FERIAS':
      return [datas, d.dias ? `${d.dias} dia(s) úteis` : ''].filter(Boolean).join(' · ');
    case 'AUSENCIA':
      return [String(d.tipo_nome ?? d.tipo ?? ''), datas].filter(Boolean).join(' · ');
    case 'DOCUMENTO':
      return [String(d.documento_nome ?? d.documento ?? ''), d.finalidade ? `para ${String(d.finalidade)}` : ''].filter(Boolean).join(' ');
    case 'AGREGADO':
      {
        const lista = Array.isArray(d.depois) ? d.depois : Array.isArray(d.dependentes) ? d.dependentes : [];
        return `${lista.length} dependente(s) propostos`;
      }
    default:
      return '';
  }
}

/** Circuito de aprovação (chefia → RH) com quem decidiu e a nota. */
export function EtapasPedido({ pedido }: { pedido: PedidoPortal }) {
  const etapas = pedido.etapas ?? [];
  const estado = (e: string) => (e === 'APROVADO' || e === 'DISPENSADA' ? 'finish' : e === 'RECUSADO' ? 'error' : e === 'PENDENTE' ? 'process' : 'wait');
  return (
    <Steps size="small" style={{ maxWidth: 640 }} items={etapas.map((e) => ({
      title: e.nivel === 'CHEFIA' ? `Chefia${e.aprovador_nome ? ` (${e.aprovador_nome})` : ''}` : 'Recursos Humanos',
      status: estado(e.estado),
      description: [ROTULOS_ESTADO[e.estado] ?? e.estado, e.por ? `por ${e.por}` : '', e.em ? formatarDataHora(e.em) : '', e.nota ? `«${e.nota}»` : ''].filter(Boolean).join(' · '),
    }))} />
  );
}

/** Declaração emitida, formatada para impressão (impressao.css). */
export function DocumentoImpresso({ documento, empresa }: { documento: DocumentoEmitido; empresa?: string | null }) {
  return (
    <div className="rh-documento">
      {/* No documento impresso o nome (e o logótipo) da empresa já vêm no cabeçalho comum. */}
      <div className="imp-so-ecra" style={{ fontWeight: 600 }}>{empresa ?? ''}</div>
      <div style={{ textAlign: 'right', color: '#595959' }}>N.º {documento.numero}</div>
      <h2>{documento.titulo}</h2>
      <p>{documento.texto}</p>
      <p style={{ marginTop: 32 }}>{documento.local ? `${documento.local}, ` : ''}{formatarData(documento.data)}</p>
      <div style={{ marginTop: 48, textAlign: 'center' }}>
        <div style={{ borderTop: '1px solid #595959', width: 280, margin: '0 auto', paddingTop: 4 }}>{documento.assinante}</div>
        {documento.cargo_assinante && <div style={{ color: '#595959' }}>{documento.cargo_assinante}</div>}
      </div>
    </div>
  );
}
