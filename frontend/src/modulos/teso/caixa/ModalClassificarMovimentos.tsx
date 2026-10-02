import { Checkbox, Form, Modal, Typography } from 'antd';
import { useState } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { SeletorAux, SeletorUnidade } from '../../contab/comum/Seletores';
import { camposClassificacao } from '../caixaLiquidacao';

interface Valores {
  nota_demonstracao_id?: number;
  nota_fluxo_caixa_id?: number;
  unidade_negocio_id?: number;
  centro_custo_id?: number;
}

const CAMPOS: { chave: keyof Valores; rotulo: string }[] = [
  { chave: 'nota_demonstracao_id', rotulo: 'Nota às demonstrações' },
  { chave: 'nota_fluxo_caixa_id', rotulo: 'Nota de fluxo de caixa' },
  { chave: 'unidade_negocio_id', rotulo: 'Unidade de negócio' },
  { chave: 'centro_custo_id', rotulo: 'Centro de custo' },
];

/**
 * Folha de caixa › Classificar linhas (A-11; «Aplicar às linhas seleccionadas» do legado): notas DEMO/fluxo, UN e CC
 * nos movimentos escolhidos. Os campos deixados em branco mantêm o que cada linha já tem, salvo se se pedir para os limpar.
 */
export function ModalClassificarMovimentos({ quantidade, carregando, aoConfirmar, aoFechar }: { quantidade: number; carregando?: boolean; aoConfirmar: (campos: Record<string, number | null>) => void; aoFechar: () => void }) {
  const [form] = Form.useForm<Valores>();
  const [limpar, setLimpar] = useState<string[]>([]);
  const [erro, setErro] = useState<string | null>(null);
  const confirmar = () => {
    const campos = camposClassificacao(form.getFieldsValue(), limpar);
    if (!Object.keys(campos).length) {
      setErro('Escolha pelo menos um valor a aplicar ou um campo a limpar.');
      return;
    }
    aoConfirmar(campos);
  };
  return (
    <Modal open title={`Classificar ${quantidade} movimento(s)`} width={larguraModal(560)} okText="Aplicar às linhas seleccionadas" cancelText="Cancelar" confirmLoading={carregando} onOk={confirmar} onCancel={aoFechar} destroyOnHidden>
      <Form form={form} layout="vertical" onValuesChange={() => setErro(null)}>
        {CAMPOS.map((c) => (
          <Form.Item
            key={c.chave}
            name={c.chave}
            label={c.rotulo}
            extra={<Checkbox checked={limpar.includes(c.chave)} onChange={(e) => { setErro(null); setLimpar((l) => (e.target.checked ? [...l, c.chave] : l.filter((x) => x !== c.chave))); }}>Limpar este campo nas linhas</Checkbox>}
          >
            {c.chave === 'unidade_negocio_id' ? (
              <SeletorUnidade placeholder="Manter" disabled={limpar.includes(c.chave)} style={{ width: '100%' }} />
            ) : (
              <SeletorAux
                tabela={c.chave === 'nota_demonstracao_id' ? 'notas-demonstracao' : c.chave === 'nota_fluxo_caixa_id' ? 'notas-fluxo-caixa' : 'centros-custo'}
                placeholder="Manter"
                disabled={limpar.includes(c.chave)}
                style={{ width: '100%' }}
              />
            )}
          </Form.Item>
        ))}
      </Form>
      {erro && <Typography.Text type="danger">{erro}</Typography.Text>}
    </Modal>
  );
}
