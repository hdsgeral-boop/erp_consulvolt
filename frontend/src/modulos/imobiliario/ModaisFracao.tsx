import { Alert, Col, Form, Input, InputNumber, Modal, Radio, Row, Select, message } from 'antd';
import { useMutation } from '@tanstack/react-query';
import { useEffect } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { gerarFracoes, gravarFracao, mudarEstadoFracao, type EstadoFracao, type Fracao, type Imovel, type Listas } from './api';
import { useRefrescar } from './hooks';
import { ESTADOS_FRACAO, exigeMotivo, transicoesDe } from './logica';

const opcoes = (l?: { valor: string; rotulo: string }[]) => (l ?? []).map((o) => ({ value: o.valor, label: o.rotulo }));
const limpar = (v: Record<string, unknown>) => Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x === undefined || x === '' ? null : x]));

/** Criar e editar uma fracção (imo_fraccao_editar). O estado só se escolhe ao criar; depois muda em «Mudar estado» (com trilho). */
export function ModalFracao({ imovel, fracao, listas, aoFechar }: { imovel: Imovel; fracao: Fracao | null | undefined; listas: Listas | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<Record<string, unknown>>();
  const aberto = fracao !== undefined;
  const refrescar = useRefrescar();
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue((fracao as unknown as Record<string, unknown> | null) ?? { natureza: 'HABITACIONAL', tipologia: 'OUTRA', finalidade: 'HABITACAO', mobilada: 'NAO', estado: 'DISPONIVEL' });
  }, [aberto, fracao, form]);
  const gravar = useMutation({
    mutationFn: (d: Record<string, unknown>) => gravarFracao(d as Partial<Fracao>, imovel.id, fracao?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar a fracção'),
    onSuccess: async () => { message.success(fracao ? 'Fracção actualizada.' : 'Fracção registada.'); await refrescar(); aoFechar(); },
  });
  const activas = new Set(listas?.naturezas_activas ?? ['HABITACIONAL', 'ARRECADACAO']);
  return (
    <Modal title={fracao ? `Editar fracção «${fracao.designacao}»` : `Nova fracção em ${imovel.codigo}`} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar"
      confirmLoading={gravar.isPending} width={larguraModal(780)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(limpar(v))} disabled={gravar.isPending}>
        <Row gutter={12}>
          <Col xs={24} md={16}><Form.Item name="designacao" label="Designação" rules={[{ required: true, message: 'Dê uma designação à fracção.' }]} extra="Ex.: «1.º Esq.», «Loja A», «Única»."><Input maxLength={120} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="codigo" label="Código" extra={fracao ? undefined : `Em branco: ${imovel.codigo}-01, -02…`}><Input maxLength={40} placeholder="Automático" /></Form.Item></Col>
          <Col xs={24} md={8}>
            <Form.Item name="natureza" label="Natureza">
              <Select options={(listas?.naturezas ?? []).map((o) => ({ value: o.valor, label: activas.has(o.valor) ? o.rotulo : `${o.rotulo} (no Parqueamento)`, disabled: !activas.has(o.valor) }))} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}><Form.Item name="tipologia" label="Tipologia"><Select options={opcoes(listas?.tipologias)} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="finalidade" label="Finalidade"><Select options={opcoes(listas?.finalidades)} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="bloco" label="Bloco"><Input maxLength={40} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="andar" label="Piso (nome)"><Input maxLength={60} placeholder="1.º andar" /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="piso" label="Piso (n.º)" extra="0 = R/C; -1 = cave"><InputNumber min={-20} max={300} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="numero" label="Número"><Input maxLength={30} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="area_bruta" label="Área bruta (m²)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="area_util" label="Área útil (m²)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="quartos" label="Quartos"><InputNumber min={0} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="casas_banho" label="Casas de banho"><InputNumber min={0} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="mobilada" label="Mobília"><Select options={opcoes(listas?.mobilia)} /></Form.Item></Col>
          {!fracao && <Col xs={24} md={8}><Form.Item name="estado" label="Estado inicial"><Select options={opcoes(listas?.estados_fracao)} /></Form.Item></Col>}
          <Col xs={24} md={8}><Form.Item name="matriz_predial" label="Artigo matricial próprio" extra="Sem ele vale o do imóvel."><Input maxLength={60} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="renda_referencia" label="Renda de referência (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="venda_referencia" label="Preço de venda de referência (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="valor_patrimonial" label="Valor patrimonial (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={5000} /></Form.Item></Col>
        </Row>
      </Form>
    </Modal>
  );
}

/** Gerar fracções por piso, bloco e posição (imo_fraccao_editar). Mostra quantas vai criar e o limite de 500 (blocos incluídos). */
export function ModalGerar({ imovel, listas, aberto, aoFechar }: { imovel: Imovel; listas: Listas | undefined; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<Record<string, unknown>>();
  const refrescar = useRefrescar();
  const pisos = Number(Form.useWatch('pisos', form) ?? 0);
  const porPiso = Number(Form.useWatch('por_piso', form) ?? 0);
  const blocos = (Form.useWatch('blocos', form) as string[] | undefined) ?? [];
  const total = Math.max(1, pisos) * Math.max(1, porPiso) * Math.max(1, blocos.length);
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue({ pisos: imovel.n_pisos || 1, por_piso: 1, piso_inicial: imovel.piso_inicial, esquema: 'PORTA', tipologia: 'OUTRA', finalidade: 'HABITACAO', mobilada: 'NAO', blocos: [] });
  }, [aberto, imovel, form]);
  const gerar = useMutation({
    mutationFn: (d: Record<string, unknown>) => gerarFracoes(imovel.id, d),
    onError: (e) => notificarErro(e, 'Não foi possível gerar as fracções'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(); },
  });
  return (
    <Modal title={`Gerar fracções de ${imovel.codigo}`} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gerar" cancelText="Cancelar"
      confirmLoading={gerar.isPending} okButtonProps={{ disabled: total > 500 }} width={larguraModal(680)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => gerar.mutate(limpar(v))}>
        <Row gutter={12}>
          <Col xs={12} md={8}><Form.Item name="pisos" label="N.º de pisos"><InputNumber min={1} max={500} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={8}><Form.Item name="por_piso" label="Fracções por piso"><InputNumber min={1} max={500} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="piso_inicial" label="Piso inicial" extra="0 = R/C; -1 = cave"><InputNumber min={-20} max={300} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="blocos" label="Blocos (opcional)" extra="Escreva e prima Enter por cada bloco. Em branco: sem blocos."><Select mode="tags" tokenSeparators={[',']} open={false} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="esquema" label="Numeração"><Radio.Group options={opcoes(listas?.esquemas)} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="tipologia" label="Tipologia"><Select options={opcoes(listas?.tipologias)} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="finalidade" label="Finalidade"><Select options={opcoes(listas?.finalidades)} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="mobilada" label="Mobília"><Select options={opcoes(listas?.mobilia)} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="area_bruta" label="Área bruta (m²)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="area_util" label="Área útil (m²)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="quartos" label="Quartos"><InputNumber min={0} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="casas_banho" label="Casas de banho"><InputNumber min={0} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="renda_referencia" label="Renda de referência (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        <Alert type={total > 500 ? 'error' : 'info'} showIcon title={total > 500 ? `São ${total} fracções: acima do limite de 500 (contando os blocos). Gere por partes.` : `Vai gerar até ${total} fracção(ões); as designações que já existem são ignoradas.`} />
      </Form>
    </Modal>
  );
}

/** Mudar o estado de uma fracção (imo_estado_mudar): só os estados permitidos, com motivo quando as regras o exigem. */
export function ModalEstado({ fracao, listas, aoFechar }: { fracao: Pick<Fracao, 'id' | 'designacao' | 'estado'> | null; listas: Listas | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<{ estado?: EstadoFracao; motivo?: string }>();
  const refrescar = useRefrescar();
  const novo = Form.useWatch('estado', form);
  const permitidos = fracao ? transicoesDe(fracao.estado, listas?.transicoes ?? {}) : [];
  const precisaMotivo = !!fracao && !!novo && exigeMotivo(fracao.estado, novo);
  useEffect(() => { if (fracao) form.resetFields(); }, [fracao, form]);
  const mudar = useMutation({
    mutationFn: (v: { estado: string; motivo: string }) => mudarEstadoFracao(fracao!.id, v.estado, v.motivo),
    onError: (e) => notificarErro(e, 'Não foi possível mudar o estado'),
    onSuccess: async () => { message.success('Estado da fracção actualizado.'); await refrescar(); aoFechar(); },
  });
  return (
    <Modal title={fracao ? `Mudar o estado de «${fracao.designacao}»` : 'Mudar estado'} open={!!fracao} onCancel={aoFechar} onOk={() => form.submit()} okText="Mudar estado" cancelText="Cancelar"
      confirmLoading={mudar.isPending} destroyOnHidden>
      {fracao && <p>Estado actual: <b>{ESTADOS_FRACAO[fracao.estado].rotulo}</b></p>}
      <Form form={form} layout="vertical" onFinish={(v) => mudar.mutate({ estado: v.estado as string, motivo: v.motivo ?? '' })}>
        <Form.Item name="estado" label="Novo estado" rules={[{ required: true, message: 'Escolha o novo estado.' }]}>
          <Select placeholder="— novo estado —" options={permitidos.map((e) => ({ value: e, label: ESTADOS_FRACAO[e].rotulo }))} />
        </Form.Item>
        <Form.Item name="motivo" label={precisaMotivo ? 'Motivo (obrigatório)' : 'Motivo'} rules={[{ required: precisaMotivo, message: 'Indique o motivo desta mudança de estado.' }]}
          extra="Sair de arrendada ou vendida, e ficar indisponível, exigem motivo.">
          <Input.TextArea rows={2} maxLength={300} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
