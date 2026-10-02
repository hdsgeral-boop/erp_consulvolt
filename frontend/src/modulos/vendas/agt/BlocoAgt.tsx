import { Alert, Card, Descriptions, List, Space, Tag, Timeline, Typography } from 'antd';
import { COLUNAS_DESCRICOES } from '@/componentes/responsivo';
import { formatarDataHora } from '@/utilitarios/formatacao';
import type { DocumentoVenda } from '../api';
import { INFO_ESTADO_AGT, estadoAgtDoDocumento, normalizarMensagensAgt, orientacaoAgt, type MensagemAgt } from './estadoAgt';

function ListaMensagens({ titulo, itens, tipo }: { titulo: string; itens: MensagemAgt[]; tipo: 'danger' | 'warning' }) {
  if (!itens.length) return null;
  return (
    <List
      size="small"
      style={{ marginTop: 12 }}
      header={<Typography.Text strong type={tipo}>{titulo} ({itens.length})</Typography.Text>}
      bordered
      dataSource={itens}
      renderItem={(m) => (
        <List.Item>
          <Space size={8} align="start" wrap={false}>
            {m.codigo && <Tag color={tipo === 'danger' ? 'red' : 'gold'} style={{ fontFamily: 'monospace' }}>{m.codigo}</Tag>}
            <span style={{ wordBreak: 'break-word' }}>
              {m.mensagem}
              {m.documento && <Typography.Text type="secondary"> ({m.documento})</Typography.Text>}
            </span>
          </Space>
        </List.Item>
      )}
    />
  );
}

/**
 * Bloco «Facturação electrónica (AGT)» do detalhe do documento de venda (A-04): estado, série/número, selagem, última
 * resposta da AGT (pedido, datas, correcção) e, legíveis, os erros e avisos do validador local e os erros devolvidos
 * pela AGT, com a orientação para os corrigir.
 */
export function BlocoAgt({ documento }: { documento: Pick<DocumentoVenda, 'faturacao_eletronica'> }) {
  const fe = documento.faturacao_eletronica;
  const estado = estadoAgtDoDocumento(fe);
  if (!fe || !estado) return null;
  const info = INFO_ESTADO_AGT[estado];
  const erros = normalizarMensagensAgt(fe.erros);
  const avisos = normalizarMensagensAgt(fe.avisos);
  const errosAgt = normalizarMensagensAgt(fe.erros_agt);
  const envio = fe.envio_detalhe ?? null;
  const orientacao = orientacaoAgt(estado);
  const historico = [...(envio?.historico ?? [])].reverse();

  return (
    <Card title="Facturação electrónica (AGT)" style={{ marginBottom: 16 }} extra={<Tag color={info.cor}>{info.rotulo}</Tag>}>
      <Descriptions size="small" column={COLUNAS_DESCRICOES}>
        <Descriptions.Item label="Série / n.º">{fe.serie ?? '—'} / {fe.numero ?? '—'}</Descriptions.Item>
        <Descriptions.Item label="Selado em">{formatarDataHora(fe.selado_em ?? null)}</Descriptions.Item>
        <Descriptions.Item label="Validação local">{fe.estado === 'PRONTO' ? 'Cumpre as regras da AGT' : `${erros.length} erro(s) a corrigir`}</Descriptions.Item>
        {envio?.request_id && <Descriptions.Item label="Pedido AGT"><Typography.Text copyable code>{envio.request_id}</Typography.Text></Descriptions.Item>}
        {envio?.enviado_em && <Descriptions.Item label="Enviado em">{formatarDataHora(envio.enviado_em)}</Descriptions.Item>}
        {envio?.validado_em && <Descriptions.Item label="Validado em">{formatarDataHora(envio.validado_em)}</Descriptions.Item>}
        {envio?.ultima_consulta && <Descriptions.Item label="Última consulta">{formatarDataHora(envio.ultima_consulta)}</Descriptions.Item>}
        {estado === 'ENVIADO' && envio?.proxima_consulta && <Descriptions.Item label="Próxima consulta">{formatarDataHora(envio.proxima_consulta)}</Descriptions.Item>}
        {!!envio?.tentativas && <Descriptions.Item label="Tentativas de envio">{envio.tentativas}</Descriptions.Item>}
        {envio?.correccao && <Descriptions.Item label="Tipo de envio">Correcção (C)</Descriptions.Item>}
      </Descriptions>
      {orientacao && <Alert style={{ marginTop: 12 }} type={estado === 'ENVIADO' ? 'info' : 'warning'} showIcon message={orientacao} />}
      <ListaMensagens titulo="Erros devolvidos pela AGT" itens={errosAgt} tipo="danger" />
      <ListaMensagens titulo="Erros do validador local" itens={erros} tipo="danger" />
      <ListaMensagens titulo="Avisos" itens={avisos} tipo="warning" />
      {historico.length > 0 && (
        <div style={{ marginTop: 16 }}>
          <Typography.Text strong>Últimas respostas</Typography.Text>
          <Timeline
            style={{ marginTop: 12, marginBottom: -24 }}
            items={historico.map((h) => ({ children: <><Typography.Text type="secondary">{formatarDataHora(h.em)}</Typography.Text> · <strong>{h.accao}</strong>: {h.resultado}</> }))}
          />
        </div>
      )}
    </Card>
  );
}
