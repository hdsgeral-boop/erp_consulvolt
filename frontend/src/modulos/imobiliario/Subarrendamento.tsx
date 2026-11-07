import { Alert, Button, Card, Col, DatePicker, Modal, Row, Space, Statistic, Table, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { TabelaComModos } from '@/componentes/vistas';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES_CT, obterCadeia, obterMargem, type LinhaMargem } from './apiContratos';
import { formatarKz } from './logica';
import { COR_CONTRATO } from './logicaContratos';
import './imobiliario.css';

const n = (v: string | number) => Number(v);

/**
 * Gestão Imobiliária › Subarrendamento e margem (ecrã imo_sub, só leitura): o que a empresa factura aos subarrendatários menos o que deve pagar
 * aos senhorios, por fracção, no intervalo escolhido. As rendas são mensalizadas (uma renda tomada anual não distorce a margem) e compara-se o
 * facturado, não o que já entrou ou saiu de caixa. Ver: imo_sub_view. Debitar um encargo ao inquilino faz-se nas Rendas (imo_sub_debitar).
 */
export default function Subarrendamento() {
  const [de, setDe] = useState(dayjs().subtract(11, 'month'));
  const [ate, setAte] = useState(dayjs());
  const [fracao, setFracao] = useState<LinhaMargem | null>(null);
  const [mesAMes, setMesAMes] = useState<LinhaMargem | null>(null);
  const valido = !de.isAfter(ate, 'month');
  const q = useQuery({ queryKey: CHAVES_CT.margem(de.format('YYYY-MM'), ate.format('YYYY-MM')), queryFn: () => obterMargem(de.format('YYYY-MM'), ate.format('YYYY-MM')), enabled: valido });
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar a margem'); }, [q.error]);
  const d = q.data;
  const vazias = (d?.linhas ?? []).filter((l) => l.vazia);

  return (
    <>
      <CabecalhoModulo titulo="Subarrendamento e margem" icone="sitemap" subtitulo="O que entra e o que sai pela mesma fracção. A margem é o negócio; as fracções no topo da lista são as que estão a custar dinheiro." />
      <Row gutter={[12, 12]} className="imo-kpis">
        <Col xs={12} md={6}><Card size="small"><Statistic title="Recebido dos subarrendatários" value={formatarKz(n(d?.totais.recebe ?? 0))} loading={q.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Pago aos senhorios" value={formatarKz(n(d?.totais.paga ?? 0))} loading={q.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Margem do período" value={formatarKz(n(d?.totais.margem ?? 0))} loading={q.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Fracções com margem negativa" value={d?.negativas ?? 0} loading={q.isLoading} /></Card></Col>
      </Row>
      {vazias.length > 0 && (
        <Alert type="warning" showIcon style={{ marginTop: 12 }} title={`${vazias.length} fracção(ões) arrendada(s) pela empresa e ainda sem subarrendamento`}
          description={vazias.map((l) => `${l.imovel_nome ?? ''} — ${l.fracao_designacao ?? ''} (${formatarKz(n(l.paga))} pagos)`).join('; ')} />
      )}
      <Card style={{ marginTop: 12 }}>
        <Space wrap style={{ marginBottom: 12 }}>
          <label htmlFor="imo-sub-de">De</label>
          <DatePicker id="imo-sub-de" picker="month" allowClear={false} value={de} onChange={(v) => v && setDe(v)} format="MM/YYYY" />
          <label htmlFor="imo-sub-ate">Até</label>
          <DatePicker id="imo-sub-ate" picker="month" allowClear={false} value={ate} onChange={(v) => v && setAte(v)} format="MM/YYYY" />
        </Space>
        {!valido && <Alert type="error" showIcon style={{ marginBottom: 12 }} title="O início do intervalo é posterior ao fim." />}
        <TabelaComModos<LinhaMargem>
          idVista="imobiliario_margem" rowKey="fracao_id" size="middle" loading={q.isFetching} dataSource={d?.linhas ?? []} scroll={{ x: 'max-content' }} impressao={{ titulo: 'Margem do subarrendamento' }}
          columns={[
            { title: 'Imóvel', dataIndex: 'imovel_nome', render: (v: string | null) => v ?? '—', valorImpressao: (l: LinhaMargem) => l.imovel_nome ?? '' },
            { title: 'Fracção', dataIndex: 'fracao_designacao', ocultavel: false, principal: true, render: (v: string | null) => v ?? '—', valorImpressao: (l: LinhaMargem) => l.fracao_designacao ?? '' },
            { title: 'Contratos tomados', dataIndex: 'tomadas', render: (v: string[]) => v.join(', ') || '—', valorImpressao: (l: LinhaMargem) => l.tomadas.join(', ') },
            { title: 'Subarrendamentos', dataIndex: 'subs', render: (v: string[]) => v.join(', ') || 'nenhum', valorImpressao: (l: LinhaMargem) => l.subs.join(', ') || 'nenhum' },
            { title: 'Pago', dataIndex: 'paga', align: 'right', render: (v: string) => formatarKz(n(v)), valorImpressao: (l: LinhaMargem) => formatarKz(n(l.paga)) },
            { title: 'Recebido', dataIndex: 'recebe', align: 'right', render: (v: string) => formatarKz(n(v)), valorImpressao: (l: LinhaMargem) => formatarKz(n(l.recebe)) },
            { title: 'Margem', dataIndex: 'margem', align: 'right', render: (v: string, l: LinhaMargem) => <Tag color={l.negativa ? 'red' : n(v) > 0 ? 'green' : 'default'}>{formatarKz(n(v))}</Tag>, valorImpressao: (l: LinhaMargem) => formatarKz(n(l.margem)) },
            { title: 'Taxa', dataIndex: 'taxa', align: 'right', render: (v: string | null) => (v === null ? '—' : `${v} %`), valorImpressao: (l: LinhaMargem) => (l.taxa === null ? '—' : `${l.taxa} %`) },
          ]}
          accoes={(l) => [
            ...(l.tomadas.length > 0 ? [{ icone: 'sitemap', titulo: 'Ver a cadeia', onClick: () => setFracao(l) }] : []),
            { icone: 'calendar', titulo: 'Mês a mês', onClick: () => setMesAMes(l) },
          ]}
          vazio={{ titulo: 'Sem dados', texto: 'Ainda não há contratos tomados nem subarrendamentos com rendas neste intervalo. Ponha o imóvel com o papel «Arrendado» e registe o contrato do tipo «Arrendamento tomado».' }}
        />
        <p style={{ marginTop: 8 }}>A margem compara o que foi facturado ao subarrendatário com o que a empresa devia pagar no mesmo período, não o que já entrou ou saiu de caixa. Cada renda reparte-se pelos meses que cobre.</p>
      </Card>
      <Cadeia linha={fracao} aoFechar={() => setFracao(null)} />
      <Modal title={mesAMes ? `Mês a mês — ${mesAMes.fracao_designacao ?? ''}` : 'Mês a mês'} open={!!mesAMes} onCancel={() => setMesAMes(null)} footer={<Button onClick={() => setMesAMes(null)}>Fechar</Button>} width={larguraModal(560)} destroyOnHidden>
        <Table size="small" rowKey="periodo" pagination={false} dataSource={mesAMes?.periodos ?? []} scroll={{ x: 'max-content' }}
          columns={[{ title: 'Período', dataIndex: 'periodo' }, { title: 'Pago', dataIndex: 'paga', align: 'right', render: (v: string) => formatarKz(n(v)) }, { title: 'Recebido', dataIndex: 'recebe', align: 'right', render: (v: string) => formatarKz(n(v)) },
            { title: 'Margem', dataIndex: 'margem', align: 'right', render: (v: string) => formatarKz(n(v)) }]} />
      </Modal>
    </>
  );
}

function Cadeia({ linha, aoFechar }: { linha: LinhaMargem | null; aoFechar: () => void }) {
  const q = useQuery({ queryKey: CHAVES_CT.cadeia(linha?.fracao_id ?? 0), queryFn: () => obterCadeia(linha?.fracao_id as number), enabled: !!linha });
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar a cadeia'); }, [q.error]);

  return (
    <Modal title={linha ? `Cadeia da fracção ${linha.fracao_designacao ?? ''}` : 'Cadeia'} open={!!linha} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>} width={larguraModal(760)} destroyOnHidden>
      {(q.data ?? []).map((t) => (
        <Card key={t.id} size="small" style={{ marginBottom: 12 }} title={<span>{t.numero} — a empresa paga <Tag color={COR_CONTRATO[t.estado]}>{t.estado.charAt(0) + t.estado.slice(1).toLowerCase()}</Tag>{!t.permite_sub && <Tag color="orange">NÃO autoriza subarrendar</Tag>}</span>}>
          <p>{t.inicio} a {t.fim ?? 'sem termo'} · Pago {formatarKz(t.paga)} · Recebido {formatarKz(t.recebe)} · <b>Margem {formatarKz(t.margem)}</b></p>
          <Table size="small" rowKey="id" pagination={false} dataSource={t.subarrendamentos} scroll={{ x: 'max-content' }} locale={{ emptyText: t.permite_sub ? 'Sem subarrendamentos.' : 'Sem subarrendamentos — e o contrato não os autoriza.' }}
            columns={[{ title: 'Subarrendamento', dataIndex: 'numero' }, { title: 'Vigência', render: (_, s) => `${s.inicio} a ${s.fim ?? 'sem termo'}` }, { title: 'Estado', dataIndex: 'estado', render: (v: string) => <Tag color={COR_CONTRATO[v as keyof typeof COR_CONTRATO]}>{v.charAt(0) + v.slice(1).toLowerCase()}</Tag> },
              { title: 'Facturado', dataIndex: 'facturado', align: 'right', render: (v: number) => formatarKz(v) }, { title: 'Em dívida', dataIndex: 'em_divida', align: 'right', render: (v: number) => formatarKz(v) }]} />
        </Card>
      ))}
      {q.isSuccess && (q.data ?? []).length === 0 && <p>Esta fracção não tem contrato tomado.</p>}
    </Modal>
  );
}
