import { Alert, Button, Form, Input, InputNumber, Skeleton, Typography } from 'antd';
import { SaveOutlined } from '@ant-design/icons';
import { useEffect } from 'react';
import { useAccao } from '@/componentes/Accoes';
import { ModalFormulario, SeccaoFormulario } from '@/componentes/formularios';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useDefinicoesPOS } from './dados';
import { SO_GRADE } from './formularios';
import type { DefinicoesPOS } from './tipos';
import './pos.css';

interface FormDefinicoes {
  conta_sobra?: string | null;
  conta_quebra?: string | null;
  conta_operador?: string | null;
  tolerancia_desvio?: number | null;
  codigo_diario?: string;
}

/**
 * «Definições de caixa» (contas dos desvios, tolerância e diário da integração — GET/PUT /pos/definicoes). Como no
 * legado, abre-se pelo botão «Definições de caixa» dos Terminais POS (3.3.4, 3.4.3); a Config. Impressão fica só com a
 * impressão.
 */
export function ModalDefinicoesCaixa({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const { pode } = useSessao();
  const definicoes = useDefinicoesPOS(aberto);
  const [form] = Form.useForm<FormDefinicoes>();
  const gerir = pode('pos_terminais_gerir');
  const gravar = useAccao<DefinicoesPOS>({ invalidar: [['pos', 'definicoes']], tituloErro: 'Não foi possível gravar as definições', aoSucesso: aoFechar });
  useEffect(() => {
    if (aberto && definicoes.data) form.setFieldsValue({ ...definicoes.data, tolerancia_desvio: Number(definicoes.data.tolerancia_desvio) });
  }, [aberto, definicoes.data, form]);

  return (
    <ModalFormulario
      chave="pos.definicoes_caixa"
      {...SO_GRADE}
      aberto={aberto}
      aoFechar={aoFechar}
      width={larguraModal(620)}
      titulo={
        <span>
          <IconeFa nome="sliders-h" /> Definições de caixa (POS)
        </span>
      }
      footer={[
        <Button key="c" onClick={aoFechar}>
          {gerir ? 'Cancelar' : 'Fechar'}
        </Button>,
        gerir && definicoes.data ? (
          <Button key="g" type="primary" icon={<SaveOutlined />} loading={gravar.isPending} onClick={() => form.submit()}>
            Gravar
          </Button>
        ) : null,
      ]}
    >
      {definicoes.isError ? (
        <Alert type="warning" showIcon message="Não tem acesso às definições de caixa do POS." />
      ) : !definicoes.data ? (
        <Skeleton active />
      ) : (
        <Form
          form={form}
          layout="vertical"
          className="pos-definicoes-form"
          disabled={!gerir}
          onFinish={(v) =>
            gravar.mutate({
              metodo: 'put',
              url: '/pos/definicoes',
              dados: {
                conta_sobra: v.conta_sobra?.trim() || null,
                conta_quebra: v.conta_quebra?.trim() || null,
                conta_operador: v.conta_operador?.trim() || null,
                tolerancia_desvio: v.tolerancia_desvio ?? 0,
                codigo_diario: v.codigo_diario?.trim().toUpperCase() || undefined,
              },
            })
          }
        >
          {/* campos, rótulos e ajudas do legado (posDefinicoesCaixa, pos_gestao.js:1063-1069), em dois blocos recolhíveis */}
          <SeccaoFormulario titulo="Contas dos desvios de caixa">
          <Form.Item name="conta_sobra" label="Conta de sobras de caixa (proveito)" extra="Usada quando a deliberação aceita a sobra como proveito.">
            <SeletorConta prefixo="6" />
          </Form.Item>
          <Form.Item name="conta_quebra" label="Conta de faltas de caixa (custo)" extra="Usada quando a deliberação assume a falta como custo da empresa.">
            <SeletorConta prefixo="7" />
          </Form.Item>
          <Form.Item name="conta_operador" label="Conta a receber do operador (faltas imputadas)" extra="Usada quando a deliberação imputa a falta ao operador de caixa.">
            <SeletorConta prefixo="3" />
          </Form.Item>
          </SeccaoFormulario>
          <SeccaoFormulario titulo="Tolerância e integração">
          <Form.Item name="tolerancia_desvio" label="Tolerância de desvio (Kz)" extra="Desvios até este valor não ficam pendentes de deliberação.">
            <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="codigo_diario" label="Código do diário da integração POS">
            <Input maxLength={10} style={{ textTransform: 'uppercase' }} />
          </Form.Item>
          </SeccaoFormulario>
          {!gerir && <Typography.Text type="secondary">Só consulta: alterar exige a permissão de configurar terminais.</Typography.Text>}
        </Form>
      )}
    </ModalFormulario>
  );
}
