import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { Form, Input } from 'antd';
import { describe, expect, it, vi } from 'vitest';

/**
 * Regra do antd 6 que já partiu um ecrã (Registar proposta de fornecedor: «Indique a quantidade a propor…» mesmo com
 * valores): no `onFinish`, os itens de um Form.List só trazem os subcampos que têm Form.Item. Propriedades como ids da
 * linha (pedido_compra_id, item_pedido_id…) perdem-se — ler a lista completa com `form.getFieldValue('<lista>')`.
 */
describe('antd Form.List — subcampos sem Form.Item no onFinish', () => {
  it('o onFinish só traz os subcampos registados; a lista completa lê-se com getFieldValue', async () => {
    const aoFinalizar = vi.fn();
    let completa: unknown;
    function F() {
      const [form] = Form.useForm();
      return (
        <Form form={form} onFinish={(v) => { completa = form.getFieldValue('linhas'); aoFinalizar(v); }} initialValues={{ linhas: [{ pedido_compra_id: 5, quantidade: 3 }] }}>
          <Form.List name="linhas">{(campos) => campos.map((c) => (<Form.Item key={c.key} name={[c.name, 'quantidade']}><Input aria-label="q" /></Form.Item>))}</Form.List>
          <button type="submit">ok</button>
        </Form>
      );
    }
    render(<F />);
    fireEvent.click(screen.getByText('ok'));
    await waitFor(() => expect(aoFinalizar).toHaveBeenCalled());
    expect(aoFinalizar.mock.calls[0][0]).toEqual({ linhas: [{ quantidade: 3 }] });
    expect(completa).toEqual([{ pedido_compra_id: 5, quantidade: 3 }]);
  });
});
