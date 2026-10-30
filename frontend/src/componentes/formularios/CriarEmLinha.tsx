import { Button, Divider, Form, Modal, Space, Typography, type FormInstance } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import type { ReactElement, ReactNode } from 'react';

/**
 * Padrão «criar em linha»: um `Select` cujo utilizador não encontra o item que precisa pode criá-lo ali mesmo, sem
 * sair do ecrã onde está a trabalhar (extraído de `SeletorTerceiro`/`ModalCriarTerceiroRapido`, em
 * modulos/compras/comum/Seletores.tsx). Duas peças:
 *  - `dropdownComCriar`: acrescenta «Criar novo X» (ou «Sem permissão») no fundo da lista de opções de um `Select`.
 *  - `ModalCriacaoRapida`: o chrome comum de um modal de criação mínima (título, Ok/Cancelar, aviso de permissão);
 *    os campos do formulário ficam a cargo de quem usa, porque diferem por entidade.
 */

/** Acrescenta a barra «Criar novo X» (ou a nota de permissão em falta) no fundo da lista de opções de um Select. */
export function dropdownComCriar(
  menu: ReactElement,
  opts: { mostrar: boolean; podeCriar: boolean; rotulo: string; aoClicar: () => void },
): ReactElement {
  if (!opts.mostrar) return <>{menu}</>;
  return (
    <>
      {menu}
      <Divider style={{ margin: '4px 0' }} />
      {opts.podeCriar ? (
        <Button type="text" block icon={<PlusOutlined />} onMouseDown={(e) => e.preventDefault()} onClick={opts.aoClicar}>
          Criar novo {opts.rotulo}
        </Button>
      ) : (
        <Space style={{ padding: '4px 11px' }}>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>Sem permissão para criar.</Typography.Text>
        </Space>
      )}
    </>
  );
}

export interface PropsModalCriacaoRapida<V extends object> {
  aberto: boolean;
  titulo: string;
  podeCriar: boolean;
  aGravar: boolean;
  aoFechar: () => void;
  form: FormInstance<V>;
  onFinish: (v: V) => void;
  width?: number;
  children: ReactNode;
}

/** Chrome comum de um modal de criação mínima a partir de um selector — o conteúdo do formulário fica a cargo de quem usa. */
export function ModalCriacaoRapida<V extends object>({ aberto, titulo, podeCriar, aGravar, aoFechar, form, onFinish, width, children }: PropsModalCriacaoRapida<V>) {
  return (
    <Modal
      open={aberto}
      title={titulo}
      okText="Criar"
      cancelText="Cancelar"
      onCancel={() => { form.resetFields(); aoFechar(); }}
      okButtonProps={{ disabled: !podeCriar, loading: aGravar }}
      onOk={() => form.submit()}
      width={width}
      destroyOnHidden
    >
      {!podeCriar && <Typography.Paragraph type="warning">Não tem permissão para criar este registo.</Typography.Paragraph>}
      <Form<V> form={form} layout="vertical" disabled={!podeCriar} onFinish={onFinish}>
        {children}
      </Form>
    </Modal>
  );
}
