import { Alert, Button, DatePicker, Form, Input, InputNumber, Modal, Popconfirm, Select, Space } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAccao } from '@/componentes/Accoes';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarKz } from '@/utilitarios/formatacao';
import { AMBITOS, nomeAndar, nomeAndarPorOmissao, previaPortas } from './regras';
import type { Ambito, AndarPlanta, Inventario, Planta, PortaPlanta } from './tipos';

/** Modais da planta (assetFloorModal, assetRoomsModal, assetRoomModal, assetInventarioModal — js/activos_edificios.js:333-592). */

const INVALIDAR = [['activos']];

export function ModalAndar({ edificioId, andar, nAndares, aoFechar }: { edificioId: number; andar: AndarPlanta | null | undefined; nAndares: number; aoFechar: () => void }) {
  const [form] = Form.useForm<{ numero?: string; nome?: string; notas?: string }>();
  const accao = useAccao({ invalidar: INVALIDAR, aoSucesso: aoFechar });
  const aberto = andar !== undefined;
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(andar ? { numero: andar.numero ?? undefined, nome: andar.nome ?? undefined, notas: andar.notas ?? undefined } : { numero: String(nAndares) });
  }, [aberto, andar, nAndares, form]);
  const numero = Form.useWatch('numero', form);
  return (
    <Modal title={andar ? 'Editar andar' : 'Novo andar'} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar"
      confirmLoading={accao.isPending} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate(andar
        ? { metodo: 'put', url: `/ativos/andares/${andar.id}`, dados: v }
        : { url: `/ativos/edificios/${edificioId}/andares`, dados: v })}>
        <Form.Item name="numero" label="Número do piso">
          <Input maxLength={20} />
        </Form.Item>
        <Form.Item name="nome" label="Nome" tooltip="Vazio = nome automático">
          <Input maxLength={255} placeholder={`Ex.: Rés-do-chão, 1.º andar, Cave (por omissão: ${nomeAndarPorOmissao(numero)})`} />
        </Form.Item>
        <Form.Item name="notas" label="Notas">
          <Input maxLength={2000} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

export function ModalPortas({ planta, andarId, aberto, aoFechar }: { planta: Planta; andarId?: number; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ andar_id: number; prefixo?: string; de: number; ate: number; tipo?: string; capacidade?: number }>();
  const accao = useAccao({ invalidar: INVALIDAR, aoSucesso: aoFechar });
  useEffect(() => {
    if (aberto) {
      form.resetFields();
      form.setFieldsValue({ andar_id: andarId ?? planta.andares[0]?.id, de: 1, ate: 6 });
    }
  }, [aberto, andarId, planta, form]);
  const prefixo = Form.useWatch('prefixo', form) ?? '';
  const de = Form.useWatch('de', form);
  const ate = Form.useWatch('ate', form);
  const previa = previaPortas(prefixo, de, ate);
  return (
    <Modal title="Criar portas" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Criar portas" cancelText="Cancelar"
      okButtonProps={{ disabled: !previa.valida }} confirmLoading={accao.isPending} destroyOnHidden>
      <p>As portas são numeradas automaticamente no intervalo indicado. Para uma porta única, ponha o mesmo número no início e no fim.</p>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/ativos/edificios/${planta.edificio.id}/portas`, dados: v })}>
        <Form.Item name="andar_id" label="Andar" rules={[{ required: true, message: 'Escolha o andar.' }]}>
          <Select options={planta.andares.map((a) => ({ value: a.id, label: nomeAndar(a) }))} />
        </Form.Item>
        <Space wrap>
          <Form.Item name="prefixo" label="Prefixo"><Input maxLength={10} placeholder="Ex.: A, 1., S" style={{ width: 120 }} /></Form.Item>
          <Form.Item name="de" label="De" rules={[{ required: true }]}><InputNumber min={0} precision={0} /></Form.Item>
          <Form.Item name="ate" label="Até" rules={[{ required: true }]}><InputNumber min={0} precision={0} /></Form.Item>
        </Space>
        <Space wrap>
          <Form.Item name="tipo" label="Tipo (opcional)"><Input maxLength={50} placeholder="Ex.: Gabinete, Armazém" /></Form.Item>
          <Form.Item name="capacidade" label="Capacidade (opcional)"><InputNumber min={0} precision={0} /></Form.Item>
        </Space>
        <Alert type={previa.valida ? 'info' : 'warning'} showIcon message={previa.texto} />
      </Form>
    </Modal>
  );
}

export function ModalPorta({ porta, aoFechar }: { porta: PortaPlanta | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ numero: string; nome?: string; tipo?: string; capacidade?: number }>();
  const accao = useAccao({ invalidar: INVALIDAR, aoSucesso: aoFechar });
  const { pode } = useSessao();
  useEffect(() => {
    if (porta) form.setFieldsValue({ numero: porta.numero, nome: porta.nome ?? undefined, tipo: porta.tipo ?? undefined, capacidade: porta.capacidade ?? undefined });
  }, [porta, form]);
  return (
    <Modal title={`Porta ${porta?.numero ?? ''}`} open={!!porta} onCancel={aoFechar} destroyOnHidden
      footer={[
        pode('activos_locais_gerir') && (
          <Popconfirm key="el" title={`Eliminar a porta ${porta?.numero ?? ''}?`} description={porta?.n_bens ? `${porta.n_bens} bem(ns) ficam sem porta (não são apagados).` : undefined}
            okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
            onConfirm={() => porta && accao.mutate({ metodo: 'delete', url: `/ativos/portas/${porta.id}` })}>
            <Button danger>Eliminar</Button>
          </Popconfirm>
        ),
        <Button key="c" onClick={aoFechar}>Cancelar</Button>,
        <Button key="g" type="primary" loading={accao.isPending} onClick={() => form.submit()}>Gravar</Button>,
      ]}>
      <Form form={form} layout="vertical" onFinish={(v) => porta && accao.mutate({ metodo: 'put', url: `/ativos/portas/${porta.id}`, dados: { ...v, nome: v.nome ?? null, tipo: v.tipo ?? null, capacidade: v.capacidade ?? null } })}>
        <Space wrap>
          <Form.Item name="numero" label="Número" rules={[{ required: true, message: 'A porta tem de ter número.' }]}><Input maxLength={20} /></Form.Item>
          <Form.Item name="capacidade" label="Capacidade"><InputNumber min={0} precision={0} /></Form.Item>
        </Space>
        <Form.Item name="nome" label="Nome / designação"><Input maxLength={255} /></Form.Item>
        <Form.Item name="tipo" label="Tipo"><Input maxLength={50} placeholder="Ex.: Gabinete, Sala de reuniões" /></Form.Item>
      </Form>
    </Modal>
  );
}

export interface PedidoInventario {
  ambito: Ambito;
  alvoId?: number | null;
}

/** Gerar folha de inventário (assetInventarioModal :534-592): âmbito, data, responsável e pré-visualização dos esperados. */
export function ModalGerarInventario({ planta, pedido, aoFechar }: { planta: Planta | undefined; pedido: PedidoInventario | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ ambito: Ambito; andar_id?: number; porta_id?: number; data: Dayjs; responsavel?: string; notas?: string }>();
  const navegar = useNavigate();
  const { utilizador } = useSessao();
  const [modal, contexto] = Modal.useModal();
  const accao = useAccao<Inventario>({ invalidar: INVALIDAR, aoSucesso: (inv) => { aoFechar(); navegar(`/m/activos/activos_edificios/inventario/${inv.id}`); } });
  useEffect(() => {
    if (!pedido) return;
    form.resetFields();
    form.setFieldsValue({ ambito: pedido.ambito, andar_id: pedido.ambito === 'ANDAR' ? pedido.alvoId ?? undefined : undefined,
      porta_id: pedido.ambito === 'PORTA' ? pedido.alvoId ?? undefined : undefined, data: dayjs(), responsavel: utilizador?.nome_completo || utilizador?.nome_utilizador || undefined });
  }, [pedido, form, utilizador]);
  const ambito = Form.useWatch('ambito', form);
  const andarId = Form.useWatch('andar_id', form);
  const portaId = Form.useWatch('porta_id', form);
  const portas = useMemo(() => (planta?.andares ?? []).flatMap((a) => a.portas.map((p) => ({ ...p, andar: nomeAndar(a) }))), [planta]);
  const esperados = ambito === 'PORTA' ? portas.filter((p) => p.id === portaId)
    : ambito === 'ANDAR' ? portas.filter((p) => p.andar_id === andarId) : portas;
  const nBens = esperados.reduce((s, p) => s + p.n_bens, 0);
  const valor = esperados.reduce((s, p) => s + Number(p.valor || 0), 0);
  const gerar = (v: { ambito: Ambito; andar_id?: number; porta_id?: number; data: Dayjs; responsavel?: string; notas?: string }, confirmar = false) => {
    if (!planta) return;
    const dados = { ...v, data: dataApi(v.data), confirmar_vazia: confirmar || undefined };
    if (!nBens && !confirmar) {
      void modal.confirm({ title: 'Folha sem bens', content: 'Não há bens esperados neste âmbito. Gerar a folha mesmo assim (só para registar bens não previstos)?',
        okText: 'Gerar', cancelText: 'Cancelar', onOk: () => gerar(v, true) });
      return;
    }
    accao.mutate({ url: `/ativos/edificios/${planta.edificio.id}/inventarios-fisicos`, dados });
  };
  return (
    <Modal title="Gerar folha de inventário" open={!!pedido} onCancel={aoFechar} onOk={() => form.submit()} okText="Gerar folha" cancelText="Cancelar"
      confirmLoading={accao.isPending} destroyOnHidden>
      {contexto}
      <p>{planta?.edificio.codigo} — {planta?.edificio.descricao}</p>
      <Form form={form} layout="vertical" onFinish={(v) => gerar(v)}>
        <Form.Item name="ambito" label="Âmbito" rules={[{ required: true }]}><Select options={AMBITOS.map((a) => ({ ...a }))} /></Form.Item>
        {ambito === 'ANDAR' && (
          <Form.Item name="andar_id" label="Andar" rules={[{ required: true, message: 'Escolha o andar.' }]}>
            <Select options={(planta?.andares ?? []).map((a) => ({ value: a.id, label: nomeAndar(a) }))} />
          </Form.Item>
        )}
        {ambito === 'PORTA' && (
          <Form.Item name="porta_id" label="Porta" rules={[{ required: true, message: 'Escolha a porta.' }]}>
            <Select showSearch optionFilterProp="label" options={portas.map((p) => ({ value: p.id, label: `${p.numero} — ${p.andar}` }))} />
          </Form.Item>
        )}
        <Space wrap>
          <Form.Item name="data" label="Data da contagem" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="responsavel" label="Responsável"><Input maxLength={150} /></Form.Item>
        </Space>
        <Form.Item name="notas" label="Notas"><Input maxLength={2000} placeholder="Opcional" /></Form.Item>
        <Alert type="info" showIcon message={`A folha vai ter ${nBens} bem(ns) esperado(s), no valor de ${formatarKz(valor.toFixed(2))}.`} />
      </Form>
    </Modal>
  );
}
