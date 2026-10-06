import { Alert, Button, Modal, Space, Table, Tag, Typography, message } from 'antd';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { larguraModal } from '@/componentes/responsivo';
import { SeletorConta } from '@/modulos/contab/comum/Seletores';
import { notificarErro } from '@/utilitarios/erros';
import type { MapeamentoEmFalta } from '../api';
import { useTiposOrganizacao } from './consultas';

/** Chave única de um mapeamento em falta (para a tabela e o estado das contas escolhidas). */
export const chaveMapeamento = (m: MapeamentoEmFalta): string =>
  `${m.tipo}|${m.infotipo_salarial_id ?? m.codigo}|${m.avencado ? 'A' : m.tipo_organizacao_id ?? ''}`;

/** Corpo do PUT /rh/mapeamentos-contabeis com as contas escolhidas (só as preenchidas). */
export function corpoMapeamentos(lista: MapeamentoEmFalta[], contas: Record<string, string | undefined>) {
  const celula = (m: MapeamentoEmFalta) => ({ tipo_organizacao_id: m.avencado ? null : m.tipo_organizacao_id, avencado: m.avencado, numero_conta: contas[chaveMapeamento(m)] });
  return {
    rubricas: lista.filter((m) => m.tipo === 'RUBRICA' && contas[chaveMapeamento(m)]).map((m) => ({ infotipo_salarial_id: m.infotipo_salarial_id, ...celula(m) })),
    sistema: lista.filter((m) => m.tipo === 'SISTEMA' && contas[chaveMapeamento(m)]).map((m) => ({ codigo: m.codigo, ...celula(m) })),
  };
}

const NOMES_SISTEMA: Record<string, string> = {
  NET_PAY_CREDIT: 'Remunerações a pagar (líquido)', IRT_CREDIT: 'IRT a entregar (Grupo A)', IRT_AVENCADO_CREDIT: 'IRT a entregar (avençados)',
  INSS_FUNC_CREDIT: 'INSS do trabalhador a entregar', INSS_EMP_DEBIT: 'Encargo do INSS da empresa (gasto)', INSS_EMP_CREDIT: 'INSS da empresa a entregar', ROUNDING_DIFF: 'Diferenças de arredondamento',
};

/**
 * Assistente de mapeamentos em falta (showPayrollMappingFixer/saveAndRetryPayrollIntegration, js/app_v2.js:10326): lista o
 * que o servidor indicou em MAPEAMENTO_EM_FALTA, deixa escolher a conta de cada um, grava e repete a contabilização.
 */
export function MapeamentosEmFalta({ lista, aoFechar, repetir }: { lista: MapeamentoEmFalta[] | null; aoFechar: () => void; repetir: () => Promise<unknown> }) {
  const tipos = useTiposOrganizacao();
  const [contas, setContas] = useState<Record<string, string | undefined>>({});
  const [aGravar, setAGravar] = useState(false);
  const todas = (lista ?? []).every((m) => contas[chaveMapeamento(m)]);

  const gravar = async () => {
    setAGravar(true);
    try {
      await enviar('put', '/rh/mapeamentos-contabeis', corpoMapeamentos(lista ?? [], contas));
      message.success('Mapeamentos gravados: a repetir a contabilização…');
      setContas({});
      aoFechar();
      await repetir();
    } catch (e) {
      notificarErro(e);
    } finally {
      setAGravar(false);
    }
  };

  return (
    <Modal title="Mapeamentos contabilísticos em falta" open={lista !== null} width={larguraModal(860)} onCancel={aoFechar} destroyOnHidden
      footer={<Space wrap><Button onClick={aoFechar}>Cancelar</Button><Button type="primary" disabled={!todas} loading={aGravar} onClick={() => void gravar()}>Gravar e contabilizar</Button></Space>}>
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="O lançamento dos salários não pode ser criado"
        description="Indique a conta de movimento de cada rubrica ou conta do sistema em falta. Os mapeamentos ficam gravados (Contabilidade › Mapeamento de salários) e a contabilização é repetida." />
      <Table<MapeamentoEmFalta> rowKey={chaveMapeamento} size="small" pagination={false} dataSource={lista ?? []} scroll={{ x: 'max-content' }} columns={[
        { title: 'Tipo', dataIndex: 'tipo', render: (t: string) => <Tag color={t === 'RUBRICA' ? 'blue' : 'purple'}>{t === 'RUBRICA' ? 'Rubrica' : 'Sistema'}</Tag> },
        { title: 'Rubrica / conta do sistema', render: (_, m) => (m.tipo === 'RUBRICA' ? m.rubrica ?? `#${m.infotipo_salarial_id}` : <><Typography.Text code>{m.codigo}</Typography.Text> {NOMES_SISTEMA[m.codigo ?? ''] ?? ''}</>) },
        { title: 'Tipo de organização', render: (_, m) => (m.avencado ? <Tag color="gold">Avençado</Tag> : tipos.nome(m.tipo_organizacao_id)) },
        { title: 'Conta', width: 300, render: (_, m) => <SeletorConta value={contas[chaveMapeamento(m)]} onChange={(v) => setContas({ ...contas, [chaveMapeamento(m)]: v })} style={{ width: 280 }} /> },
      ]} />
    </Modal>
  );
}
