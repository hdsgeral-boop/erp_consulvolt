import { Alert, Button, Descriptions, Drawer, Skeleton, Space, Table, Tabs, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { larguraGaveta } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, obterFichaFracao, type HistoricoEstado, type Listas } from './api';
import { ModalEstado } from './ModaisFracao';
import { ESTADOS_FRACAO, formatarArea, formatarKz, rotuloDe } from './logica';

export function EtiquetaEstado({ estado }: { estado: keyof typeof ESTADOS_FRACAO }) {
  const e = ESTADOS_FRACAO[estado];
  return <Tag style={{ color: e.cor, background: e.fundo, borderColor: e.cor }}>{e.rotulo}</Tag>;
}

const dataHora = (iso: string) => new Date(iso).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });

/** Ficha da fracção (parte do cadastro): dados, ligação ao Activos e histórico de estados. Contratos, rendas e obras chegam nas ondas seguintes. */
export function FichaFracao({ id, listas, podeMudarEstado, aoFechar }: { id: number | null; listas: Listas | undefined; podeMudarEstado: boolean; aoFechar: () => void }) {
  const q = useQuery({ queryKey: CHAVES.fichaFracao(id ?? 0), queryFn: () => obterFichaFracao(id as number), enabled: id !== null });
  const [mudar, setMudar] = useState(false);
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar a ficha da fracção'); }, [q.error]);
  const f = q.data;
  return (
    <Drawer title={f ? `Fracção ${f.designacao}` : 'Fracção'} open={id !== null} onClose={aoFechar} size={larguraGaveta(720)} destroyOnHidden
      extra={f && podeMudarEstado && <Button type="primary" onClick={() => setMudar(true)}>Mudar estado</Button>}>
      {!f ? <Skeleton active /> : (
        <>
          <Space wrap style={{ marginBottom: 12 }}>
            <EtiquetaEstado estado={f.estado} />
            {f.estado_desde && <span>desde {f.estado_desde}</span>}
            {f.estado_motivo && <span>· {f.estado_motivo}</span>}
          </Space>
          <Tabs items={[
            { key: 'resumo', label: 'Resumo', children: (
              <>
                <Descriptions bordered size="small" column={{ xs: 1, md: 2 }}>
                  <Descriptions.Item label="Imóvel">{f.imovel ? `${f.imovel.codigo} · ${f.imovel.nome}` : '—'}</Descriptions.Item>
                  <Descriptions.Item label="Código">{f.codigo}</Descriptions.Item>
                  <Descriptions.Item label="Natureza">{rotuloDe(listas?.naturezas, f.natureza)}</Descriptions.Item>
                  <Descriptions.Item label="Tipologia">{rotuloDe(listas?.tipologias, f.tipologia)}</Descriptions.Item>
                  <Descriptions.Item label="Finalidade">{rotuloDe(listas?.finalidades, f.finalidade)}</Descriptions.Item>
                  <Descriptions.Item label="Mobília">{rotuloDe(listas?.mobilia, f.mobilada)}</Descriptions.Item>
                  <Descriptions.Item label="Bloco / piso / número">{[f.bloco, f.andar, f.numero].filter(Boolean).join(' · ') || '—'}</Descriptions.Item>
                  <Descriptions.Item label="Área bruta / útil">{formatarArea(f.area_bruta)} / {formatarArea(f.area_util)}</Descriptions.Item>
                  <Descriptions.Item label="Quartos / casas de banho">{f.quartos ?? '—'} / {f.casas_banho ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Artigo matricial">{f.matriz_predial ?? (f.imovel?.matriz_predial ? `${f.imovel.matriz_predial} (do imóvel)` : '—')}</Descriptions.Item>
                  <Descriptions.Item label="Renda de referência">{formatarKz(f.renda_referencia)}</Descriptions.Item>
                  <Descriptions.Item label="Preço de venda de referência">{formatarKz(f.venda_referencia)}</Descriptions.Item>
                  <Descriptions.Item label="Valor patrimonial">{formatarKz(f.valor_patrimonial)}</Descriptions.Item>
                  <Descriptions.Item label="Porta no Activos">{f.porta ? `${f.porta.nome ?? 'Porta'} ${f.porta.numero ?? ''}`.trim() : 'Sem ligação'}</Descriptions.Item>
                  {f.notas && <Descriptions.Item label="Notas" span={2}>{f.notas}</Descriptions.Item>}
                </Descriptions>
                <Alert style={{ marginTop: 12 }} type="info" showIcon title="Contratos, rendas, obras e inventário desta fracção ficam disponíveis nas ondas seguintes do módulo." />
              </>
            ) },
            { key: 'historico', label: `Histórico de estados (${f.historico.length})`, children: (
              <Table<HistoricoEstado> rowKey="id" size="small" pagination={false} dataSource={f.historico} scroll={{ x: 'max-content' }}
                columns={[
                  { title: 'Quando', dataIndex: 'em', render: (v: string) => dataHora(v) },
                  { title: 'De', dataIndex: 'estado_de', render: (v: HistoricoEstado['estado_de']) => (v ? <EtiquetaEstado estado={v} /> : '—') },
                  { title: 'Para', dataIndex: 'estado_para', render: (v: HistoricoEstado['estado_para']) => <EtiquetaEstado estado={v} /> },
                  { title: 'Motivo', dataIndex: 'motivo', render: (v: string | null) => v ?? '—' },
                ]} />
            ) },
          ]} />
          <ModalEstado fracao={mudar ? f : null} listas={listas} aoFechar={() => setMudar(false)} />
        </>
      )}
    </Drawer>
  );
}
