import { Alert, Button, DatePicker, Descriptions, Modal, Space, Table } from 'antd';
import { CalculatorOutlined } from '@ant-design/icons';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { BotoesExportar } from '@/componentes/impressao';
import { larguraModal } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { notificarErro } from '@/utilitarios/erros';
import type { ResultadoSalarial } from '../api';
import { folhaSalariosHtml } from './impressao';
import { pedidoSimulacaoPeriodo } from './simulacao';
import { ModalSimulacaoColaborador } from './SimulacaoColaborador';

interface Simulacao { mes_ano: string; resultados: ResultadoSalarial[]; ignorados: string[]; totais: Record<string, string> }

/** Contratos › Simular massa salarial (simulateContractsPayroll do legado): cálculo do mês a partir dos contratos, sem gravar. */
export function SimularMassa({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const [mes, setMes] = useState<Dayjs | null>(dayjs());
  const [sim, setSim] = useState<Simulacao | null>(null);
  const [aCalcular, setACalcular] = useState(false);
  const [detalhe, setDetalhe] = useState<ResultadoSalarial | null>(null);
  const simular = async () => {
    if (!mes) return;
    setACalcular(true);
    try {
      setSim(await obter<Simulacao>('/rh/contratos/simulacao', { mes_ano: mes.format('MM/YYYY') }));
    } catch (e) {
      notificarErro(e);
    } finally {
      setACalcular(false);
    }
  };
  return (
    <Modal title="Simular massa salarial (contratos)" open={aberto} width={larguraModal(1000)} onCancel={aoFechar} destroyOnHidden
      footer={<Space wrap>
        <BotoesExportar chave="rh simulacao massa salarial" desactivado={!sim?.resultados.length} obterPedido={() => ({ titulo: 'Simulação da massa salarial (contratos)', periodo: sim?.mes_ano, filtros: ['Simulação: nada foi gravado'],
          conteudo: folhaSalariosHtml(sim?.resultados ?? [], (r) => r.nome ?? `#${r.colaborador_id}`) })} />
        <BotoesExportar chave="rh simulacao massa salarial detalhada" excel={false} desactivado={!sim?.resultados.length} textoImprimir="Mapa detalhado" textoPdf="Guardar mapa"
          obterPedido={() => sim && { ...pedidoSimulacaoPeriodo({ resultados: sim.resultados, nome: (r) => r.nome ?? `#${r.colaborador_id}`, mesAno: sim.mes_ano, estado: 'SIMULACAO' }), titulo: 'Simulação da massa salarial (contratos) — mapa detalhado' }} />
        <Button onClick={aoFechar}>Fechar</Button>
      </Space>}>
      <Space wrap style={{ marginBottom: 12 }}>
        <DatePicker picker="month" format="MM/YYYY" value={mes} onChange={setMes} aria-label="Mês" />
        <Button type="primary" icon={<CalculatorOutlined />} loading={aCalcular} disabled={!mes} onClick={() => void simular()}>Simular</Button>
      </Space>
      {sim && (
        <>
          <Descriptions size="small" bordered column={{ xs: 1, sm: 2, lg: 3 }} style={{ marginBottom: 12 }}>
            <Descriptions.Item label="Bruto">{formatarKz(sim.totais.bruto)}</Descriptions.Item>
            <Descriptions.Item label="INSS trabalhador">{formatarKz(sim.totais.inss_trabalhador)}</Descriptions.Item>
            <Descriptions.Item label="INSS empresa">{formatarKz(sim.totais.inss_patronal)}</Descriptions.Item>
            <Descriptions.Item label="IRT">{formatarKz(sim.totais.irt)}</Descriptions.Item>
            <Descriptions.Item label="Líquido">{formatarKz(sim.totais.liquido)}</Descriptions.Item>
            <Descriptions.Item label="Custo total (bruto + INSS empresa)">{formatarKz(Number(sim.totais.bruto) + Number(sim.totais.inss_patronal))}</Descriptions.Item>
          </Descriptions>
          {sim.ignorados.length > 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`${sim.ignorados.length} colaborador(es) fora da simulação`} description={sim.ignorados.join(' · ')} />}
          <Table<ResultadoSalarial> rowKey="colaborador_id" size="small" dataSource={sim.resultados} pagination={{ pageSize: 50 }} scroll={{ x: 'max-content' }} columns={[
            { title: 'Colaborador', dataIndex: 'nome' },
            { title: 'Bruto', dataIndex: 'bruto', align: 'right', render: (v: string) => formatarKz(v) },
            { title: 'INSS (trab.)', dataIndex: 'inss_trabalhador', align: 'right', render: (v: string) => formatarKz(v) },
            { title: 'IRT', dataIndex: 'irt', align: 'right', render: (v: string) => formatarKz(v) },
            { title: 'Líquido', dataIndex: 'liquido', align: 'right', render: (v: string) => <strong>{formatarKz(v)}</strong> },
            { title: 'INSS (empresa)', dataIndex: 'inss_patronal', align: 'right', responsive: ['md'], render: (v: string) => formatarKz(v) },
            { title: '', key: 'det', align: 'right', render: (_, r) => <Button size="small" icon={<CalculatorOutlined />} onClick={() => setDetalhe(r)}>Detalhe</Button> },
          ]} />
          <ModalSimulacaoColaborador resultado={detalhe} mesAno={sim.mes_ano} nome={detalhe?.nome ?? `#${detalhe?.colaborador_id ?? ''}`} aoFechar={() => setDetalhe(null)} />
        </>
      )}
    </Modal>
  );
}
