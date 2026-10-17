import { Alert, Checkbox, Modal, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { somar } from '@/utilitarios/decimal';
import type { PrestacaoDoMes } from '../faseB/tipos';

/**
 * «Importar Empréstimos» do cálculo (window.importarEmprestimos, js/modules/rh/emprestimos_ui.js:245-265): antes de
 * lançar, mostra as prestações por descontar no mês (GET /rh/salarios/periodos/{id}/emprestimos) — colaborador,
 * empréstimo, n.º da prestação e valor —, com a escolha dos colaboradores e de substituir o valor já lançado na rubrica.
 */
export function ModalImportarEmprestimos({ periodoId, mesAno, aberto, aoFechar, aoLancar }: {
  periodoId: number;
  mesAno: string;
  aberto: boolean;
  aoFechar: () => void;
  aoLancar: (dados: { substituir: boolean; colaboradores: number[] }) => Promise<unknown>;
}) {
  const q = useQuery({ queryKey: ['rh', 'salarios', 'emprestimos-mes', periodoId], queryFn: () => obter<PrestacaoDoMes[]>(`/rh/salarios/periodos/${periodoId}/emprestimos`), enabled: aberto });
  const linhas = q.data ?? [];
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const [substituir, setSubstituir] = useState(true);
  const [aLancar, setALancar] = useState(false);
  useEffect(() => { if (aberto && q.data) setSeleccao([...new Set(q.data.map((l) => l.colaborador_id))]); }, [aberto, q.data]);
  useEffect(() => { if (aberto) setSubstituir(true); }, [aberto]);
  const escolhidas = linhas.filter((l) => seleccao.includes(l.colaborador_id));
  return (
    <Modal title={`Importar empréstimos para ${mesAno}`} open={aberto} onCancel={aoFechar} width={larguraModal(760)} destroyOnHidden
      okText="Lançar no período" cancelText="Cancelar" okButtonProps={{ disabled: !escolhidas.length }} confirmLoading={aLancar}
      onOk={async () => {
        setALancar(true);
        try { await aoLancar({ substituir, colaboradores: seleccao }); aoFechar(); } catch { /* a notificação do erro é do chamador */ } finally { setALancar(false); }
      }}>
      <Typography.Paragraph type="secondary">Prestações por descontar neste mês. O valor entra como desconto na rubrica configurada (RH › Empréstimos); as prestações passam a descontadas e o empréstimo fica liquidado quando já não houver prestações.</Typography.Paragraph>
      <Checkbox checked={substituir} onChange={(e) => setSubstituir(e.target.checked)} style={{ marginBottom: 8 }}>
        Substituir o valor se o colaborador já tiver lançamento nessa rubrica
      </Checkbox>
      {q.error && <Alert type="error" showIcon message={(q.error as Error).message} style={{ marginBottom: 8 }} />}
      <Table<PrestacaoDoMes> size="small" rowKey={(l) => l.id ?? `${l.emprestimo_id}-${l.numero}`} loading={q.isFetching} dataSource={linhas} pagination={false} scroll={scrollTabela('45vh')}
        rowSelection={{ selectedRowKeys: linhas.filter((l) => seleccao.includes(l.colaborador_id)).map((l) => l.id ?? `${l.emprestimo_id}-${l.numero}`),
          onChange: (_, rows) => setSeleccao([...new Set(rows.map((r) => r.colaborador_id))]) }}
        locale={{ emptyText: 'Não há prestações por descontar neste mês.' }}
        columns={[
          { title: 'Colaborador', dataIndex: 'nome' },
          { title: 'Empréstimo', key: 'e', render: (_, l) => `${l.numero_emprestimo} · ${l.numero}/${l.prestacoes}` },
          { title: 'Prestação', dataIndex: 'total', align: 'right', render: (v: string) => <strong>{formatarKz(v)}</strong> },
        ]}
        summary={() => (linhas.length ? (
          <Table.Summary.Row>
            <Table.Summary.Cell index={0} colSpan={3}><strong>{escolhidas.length} prestação(ões) escolhida(s)</strong></Table.Summary.Cell>
            <Table.Summary.Cell index={1} align="right"><strong>{formatarKz(somar(escolhidas.map((l) => l.total)))}</strong></Table.Summary.Cell>
          </Table.Summary.Row>
        ) : null)} />
    </Modal>
  );
}
