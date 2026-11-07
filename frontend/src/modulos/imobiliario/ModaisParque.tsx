import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Checkbox, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Switch, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { ErroApi } from '@/api/tipos';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { listarFracoes } from './api';
import {
  actualizarVaga, afectarVagas, criarVaga, eliminarVaga, gerarVagas, gravarVeiculos, libertarVagas, obterVeiculos, CHAVES_PK, type ListasParque, type VagaMapa, type VeiculoContrato,
} from './apiParque';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';
import { normalizarMatricula } from './logicaParque';

const op = (m: { valor: string; rotulo: string }[] | undefined) => (m ?? []).map((o) => ({ value: o.valor, label: o.rotulo }));
const SUGESTAO = [{ value: 'aberto', label: 'Deixar em aberto — o contrato pergunta' }, { value: 'sim', label: 'Sugerir como incluída na renda' }, { value: 'nao', label: 'Sugerir como acrescida à renda' }];
const sugestaoDe = (v: boolean | null | undefined): string => (v === true ? 'sim' : v === false ? 'nao' : 'aberto');
const sugestaoParaApi = (v: string | undefined): boolean | null => (v === 'sim' ? true : v === 'nao' ? false : null);

/** Gerar vagas por piso (imo_vaga_editar): numera os lugares por piso; gerar duas vezes não duplica (o que já existe é saltado). */
export function ModalGerarVagas({ imovel, nome, aberto, listas, aoFechar }: { imovel: number; nome: string; aberto: boolean; listas: ListasParque | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<Record<string, unknown>>();
  const refrescar = useRefrescar();
  useEffect(() => {
    if (aberto) { form.resetFields(); form.setFieldsValue({ pisos: '-1', por_piso: 20, prefixo: 'G', park_tipo: 'SUBTERRANEO', park_porte: 'LIGEIRO', area_bruta: 12.5, park_tomada: false }); }
  }, [aberto, form]);
  const gerar = useMutation({
    mutationFn: (v: Record<string, unknown>) => gerarVagas(imovel, v),
    onError: (e) => notificarErro(e, 'Não foi possível gerar as vagas'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(); },
  });

  return (
    <Modal title="Gerar vagas" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gerar" cancelText="Cancelar" confirmLoading={gerar.isPending} width={larguraModal(680)} destroyOnHidden>
      <p>Em {nome}. Os lugares ficam numerados por piso — piso −1 dá G-101, G-102…, piso −2 dá G-201. Gerar duas vezes não duplica: o que já existe é saltado.</p>
      <Form form={form} layout="vertical" onFinish={(v) => gerar.mutate(v)} disabled={gerar.isPending}>
        <Space wrap align="start" size={12}>
          <Form.Item name="pisos" label="Piso(s)" extra="Separe por vírgulas. Use números negativos para as caves." rules={[{ required: true, message: 'Indique o(s) piso(s).' }, { pattern: /^[-0-9,\s]+$/, message: 'Só números separados por vírgulas.' }]}><Input style={{ width: 160 }} /></Form.Item>
          <Form.Item name="por_piso" label="Lugares por piso" rules={[{ required: true, message: 'Indique quantos lugares por piso.' }]}><InputNumber min={1} max={500} precision={0} style={{ width: 140 }} /></Form.Item>
          <Form.Item name="prefixo" label="Prefixo" extra="G-101, P-101, E-101…"><Input maxLength={4} style={{ width: 100 }} /></Form.Item>
          <Form.Item name="bloco" label="Bloco (opcional)"><Input maxLength={40} style={{ width: 120 }} placeholder="A" /></Form.Item>
        </Space>
        <Space wrap align="start" size={12}>
          <Form.Item name="park_tipo" label="Tipo"><Select style={{ width: 180 }} options={op(listas?.park_tipos)} /></Form.Item>
          <Form.Item name="park_porte" label="Porte"><Select style={{ width: 180 }} options={op(listas?.park_portes)} /></Form.Item>
          <Form.Item name="park_altura_max" label="Altura máxima (m)" extra="Em cave é o que evita prometer um lugar onde o jipe não entra."><InputNumber min={0} max={99} step={0.05} precision={2} style={{ width: 160 }} placeholder="2.10" /></Form.Item>
          <Form.Item name="area_bruta" label="Área por lugar (m²)"><InputNumber min={0} precision={2} style={{ width: 140 }} /></Form.Item>
          <Form.Item name="renda_referencia" label="Renda de referência" extra="O preço que o contrato vai propor por lugar."><InputNumber min={0} precision={2} style={{ width: 180 }} placeholder="25000" /></Form.Item>
        </Space>
        <Form.Item name="park_tomada" label="Com tomada para carregamento eléctrico" valuePropName="checked"><Switch /></Form.Item>
      </Form>
    </Modal>
  );
}

interface ValoresVaga {
  designacao: string; park_distico?: string; piso?: number; park_tipo?: string; park_porte?: string; park_altura_max?: number | null; park_comprimento?: number | null; park_largura?: number | null;
  area_bruta?: number | null; renda_referencia?: number | null; afectacao: string; fracao_id?: number; incluida?: string; park_tomada?: boolean; notas?: string; estado?: string;
}

/** Criar e editar uma vaga (imo_vaga_editar). Editar não recalcula o código nem perde o bloco e o número. */
export function ModalVaga({ imovel, vaga, listas, podeEditar, podeVeiculos, aoFechar, aoVeiculos }: {
  imovel: number; vaga: VagaMapa | null | undefined; listas: ListasParque | undefined; podeEditar: boolean; podeVeiculos: boolean; aoFechar: () => void; aoVeiculos: (contrato: number, numero: string) => void;
}) {
  const [form] = Form.useForm<ValoresVaga>();
  const refrescar = useRefrescar();
  const aberto = vaga !== undefined;
  const afectacao = (Form.useWatch('afectacao', form) as string | undefined) ?? 'AUTONOMA';
  const fracoes = useQuery({ queryKey: CHAVES_PK.fracoesDoImovel(imovel), queryFn: () => listarFracoes(imovel), enabled: aberto });
  const principais = useMemo(() => (fracoes.data ?? []).filter((f) => f.natureza === 'HABITACIONAL'), [fracoes.data]);
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(vaga ? { designacao: vaga.designacao, park_distico: vaga.distico ?? undefined, piso: vaga.piso ?? undefined, park_tipo: vaga.tipo ?? undefined, park_porte: vaga.porte ?? undefined,
      park_altura_max: vaga.altura_max, park_comprimento: vaga.comprimento, park_largura: vaga.largura, area_bruta: vaga.area, renda_referencia: vaga.renda_referencia, afectacao: vaga.afectacao,
      fracao_id: vaga.fracao_id ?? undefined, incluida: sugestaoDe(vaga.incluida_na_renda), park_tomada: vaga.tomada, notas: vaga.notas ?? undefined }
      : { piso: -1, park_tipo: 'SUBTERRANEO', park_porte: 'LIGEIRO', afectacao: 'AUTONOMA', incluida: 'aberto', park_tomada: false, estado: 'DISPONIVEL' });
  }, [aberto, vaga, form]);

  const gravar = useMutation({
    mutationFn: (v: ValoresVaga) => {
      const { incluida, fracao_id, estado, ...resto } = v;
      const dados: Record<string, unknown> = { ...resto };
      if (v.afectacao === 'PERMANENTE') { dados.fracao_id = fracao_id; dados.incluida_na_renda = sugestaoParaApi(incluida); }
      if (!vaga && estado) dados.estado = estado;
      return vaga ? actualizarVaga(vaga.id, dados) : criarVaga(imovel, dados);
    },
    onError: (e) => notificarErro(e, 'Não foi possível gravar a vaga'),
    onSuccess: async () => { message.success(vaga ? 'Vaga actualizada.' : 'Vaga criada.'); await refrescar(); aoFechar(); },
  });
  const apagar = useMutation({
    mutationFn: () => eliminarVaga((vaga as VagaMapa).id), onError: (e) => notificarErro(e, 'Não foi possível apagar a vaga'),
    onSuccess: async () => { message.success('Vaga apagada.'); await refrescar(); aoFechar(); },
  });
  const afectacoes = (listas?.park_afectacoes ?? []).map((a) => ({ value: a.valor, label: a.rotulo }));
  const ajuda = listas?.park_afectacoes.find((a) => a.valor === afectacao)?.ajuda;

  return (
    <Modal title={vaga ? vaga.designacao : 'Nova vaga'} open={aberto} onCancel={aoFechar} width={larguraModal(760)} destroyOnHidden
      footer={[
        ...(vaga && podeEditar ? [<Popconfirm key="ap" title={`Apagar «${vaga.designacao}»? Não há volta a dar.`} okText="Apagar" cancelText="Manter" okButtonProps={{ danger: true }} onConfirm={() => apagar.mutate()}><Button danger loading={apagar.isPending}>Apagar</Button></Popconfirm>] : []),
        ...(vaga?.ocupada && vaga.contrato_id && podeVeiculos ? [<Button key="ve" onClick={() => aoVeiculos(vaga.contrato_id as number, vaga.contrato ?? '')}>Veículos</Button>] : []),
        <Button key="c" onClick={aoFechar}>{podeEditar ? 'Cancelar' : 'Fechar'}</Button>,
        ...(podeEditar ? [<Button key="g" type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>] : []),
      ]}>
      {vaga?.ocupada
        ? <Alert type="info" showIcon style={{ marginBottom: 12 }} title={`Ocupada pelo contrato ${vaga.contrato}${vaga.via === 'FRACCAO' ? ' — por arrastamento da fracção a que está afecta' : ''}.`} />
        : <p>Uma vaga é uma fracção com natureza «estacionamento»: entra nos contratos, no mapa de ocupação e na rentabilidade como qualquer outra.</p>}
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={!podeEditar || gravar.isPending}>
        <Space wrap align="start" size={12}>
          <Form.Item name="designacao" label="Designação" rules={[{ required: true, message: 'Dê uma designação à vaga.' }]}><Input maxLength={120} placeholder="G-101" style={{ width: 200 }} /></Form.Item>
          <Form.Item name="park_distico" label="Dístico / cartão" extra="O número que a portaria confere."><Input maxLength={60} style={{ width: 180 }} /></Form.Item>
          <Form.Item name="piso" label="Piso"><InputNumber min={-20} max={300} precision={0} style={{ width: 100 }} /></Form.Item>
          <Form.Item name="park_tipo" label="Tipo"><Select style={{ width: 170 }} options={op(listas?.park_tipos)} /></Form.Item>
          <Form.Item name="park_porte" label="Porte" extra="O maior veículo que o lugar aceita."><Select style={{ width: 170 }} options={op(listas?.park_portes)} /></Form.Item>
        </Space>
        <Space wrap align="start" size={12}>
          <Form.Item name="park_altura_max" label="Altura máxima (m)"><InputNumber min={0} max={99} step={0.05} precision={2} style={{ width: 150 }} /></Form.Item>
          <Form.Item name="park_comprimento" label="Comprimento (m)"><InputNumber min={0} max={99} step={0.1} precision={2} style={{ width: 130 }} placeholder="5.00" /></Form.Item>
          <Form.Item name="park_largura" label="Largura (m)"><InputNumber min={0} max={99} step={0.1} precision={2} style={{ width: 130 }} placeholder="2.50" /></Form.Item>
          <Form.Item name="area_bruta" label="Área (m²)"><InputNumber min={0} precision={2} style={{ width: 120 }} /></Form.Item>
          <Form.Item name="renda_referencia" label="Renda de referência" extra="O que o contrato vai propor por este lugar."><InputNumber min={0} precision={2} style={{ width: 190 }} /></Form.Item>
        </Space>
        <Space wrap align="start" size={12}>
          <Form.Item name="afectacao" label="Afectação" extra={ajuda}><Select style={{ width: 260 }} options={afectacoes} /></Form.Item>
          {afectacao === 'PERMANENTE' && (
            <>
              <Form.Item name="fracao_id" label="Fracção a que está afecta" extra="Arrendar essa fracção leva este lugar junto. O preço decide-se no contrato." rules={[{ required: true, message: 'Escolha a fracção.' }]}>
                <Select showSearch optionFilterProp="label" style={{ width: 260 }} loading={fracoes.isLoading} placeholder="— fracção —" options={principais.map((f) => ({ value: f.id, label: `${f.designacao} (${f.codigo})` }))} />
              </Form.Item>
              <Form.Item name="incluida" label="Preço" extra="É só uma sugestão: o contrato pergunta sempre."><Select style={{ width: 280 }} options={SUGESTAO} /></Form.Item>
            </>
          )}
          {!vaga && <Form.Item name="estado" label="Estado inicial"><Select style={{ width: 170 }} options={[{ value: 'DISPONIVEL', label: 'Disponível' }, { value: 'EM_OBRA', label: 'Em obra' }, { value: 'INDISPONIVEL', label: 'Indisponível' }]} /></Form.Item>}
          <Form.Item name="park_tomada" label="Com tomada eléctrica" valuePropName="checked" extra="Permite carregar um veículo eléctrico."><Switch /></Form.Item>
        </Space>
        <Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}

/** Afectar vagas a uma fracção, ou libertá-las (imo_vaga_afectar). Escolher um lugar que já é de outra fracção pede confirmação (o legado mudava-o em silêncio). */
export function ModalAfectar({ imovel, vagas, aberto, listas, aoFechar }: { imovel: number; vagas: VagaMapa[]; aberto: boolean; listas: ListasParque | undefined; aoFechar: () => void }) {
  const [fracao, setFracao] = useState<number | undefined>();
  const [escolhidas, setEscolhidas] = useState<number[]>([]);
  const [sugestao, setSugestao] = useState('aberto');
  const refrescar = useRefrescar();
  const fracoes = useQuery({ queryKey: CHAVES_PK.fracoesDoImovel(imovel), queryFn: () => listarFracoes(imovel), enabled: aberto });
  const principais = useMemo(() => (fracoes.data ?? []).filter((f) => f.natureza === 'HABITACIONAL'), [fracoes.data]);
  useEffect(() => { if (aberto) { setFracao(undefined); setEscolhidas([]); setSugestao('aberto'); } }, [aberto]);
  const candidatas = vagas.filter((v) => v.arrendavel);
  const concluir = async (r: { mensagem: string; dados: { avisos: string[] } }) => {
    (r.dados.avisos.length ? message.warning : message.success)(r.dados.avisos.length ? `${r.mensagem} ${r.dados.avisos.join(' ')}` : r.mensagem, r.dados.avisos.length ? 8 : 3);
    await refrescar();
    aoFechar();
  };
  const afectar = useMutation({
    mutationFn: (reatribuir: boolean) => afectarVagas({ fracao_id: fracao as number, vaga_ids: escolhidas, incluida_na_renda: sugestaoParaApi(sugestao), reatribuir }),
    onError: (e) => {
      if (e instanceof ErroApi && e.codigo === 'PARQUE_JA_AFECTA') {
        Modal.confirm({ title: 'Mudar um lugar de fracção?', content: e.message, okText: 'Mudar para esta fracção', cancelText: 'Rever', onOk: () => afectar.mutate(true) });
        return;
      }
      notificarErro(e, 'Não foi possível afectar as vagas');
    },
    onSuccess: concluir,
  });
  const libertar = useMutation({ mutationFn: () => libertarVagas(escolhidas), onError: (e) => notificarErro(e, 'Não foi possível libertar as vagas'), onSuccess: concluir });
  const tipoDe = (v: VagaMapa) => [listas?.park_tipos.find((t) => t.valor === v.tipo)?.rotulo, listas?.park_portes.find((t) => t.valor === v.porte)?.rotulo, v.renda_referencia ? formatarKz(v.renda_referencia) : null].filter(Boolean).join(' · ');
  const podeAfectar = !!fracao && escolhidas.length > 0;

  return (
    <Modal title="Afectar vagas a uma fracção" open={aberto} onCancel={aoFechar} width={larguraModal(640)} destroyOnHidden
      footer={[
        <Popconfirm key="l" title={`${escolhidas.length} lugar(es) voltam a autónomos e deixam de ir junto no contrato da fracção.`} okText="Libertar" cancelText="Cancelar" disabled={escolhidas.length === 0} onConfirm={() => libertar.mutate()}>
          <Button disabled={escolhidas.length === 0} loading={libertar.isPending}>Libertar as escolhidas</Button>
        </Popconfirm>,
        <Button key="c" onClick={aoFechar}>Cancelar</Button>,
        <Button key="a" type="primary" disabled={!podeAfectar} loading={afectar.isPending} onClick={() => afectar.mutate(false)}>Afectar</Button>,
      ]}>
      <p>Uma vaga afecta é acessório da fracção: arrendar a fracção leva o lugar junto, e o contrato pergunta se vai incluído na renda ou acrescido.</p>
      {principais.length === 0 && !fracoes.isLoading && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Este imóvel não tem fracções a que afectar vagas." />}
      {candidatas.length === 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Este imóvel não tem vagas arrendáveis." />}
      <Form layout="vertical">
        <Form.Item label="Fracção" required>
          <Select aria-label="Fracção" showSearch optionFilterProp="label" value={fracao} onChange={setFracao} loading={fracoes.isLoading} placeholder="— fracção —" options={principais.map((f) => ({ value: f.id, label: `${f.designacao} (${f.codigo})` }))} />
        </Form.Item>
        <Form.Item label="Lugares" extra="Escolher um lugar que já é de outra fracção muda-o para esta, depois de confirmar.">
          <Checkbox.Group value={escolhidas} onChange={(v) => setEscolhidas(v as number[])} style={{ display: 'grid', gap: 6, maxHeight: 280, overflow: 'auto', width: '100%' }}>
            {candidatas.map((v) => (
              <Checkbox key={v.id} value={v.id}>
                <b>{v.designacao}</b> <small>{tipoDe(v)}</small>
                {v.afectacao === 'PERMANENTE' && v.afecta_a && <Tag style={{ marginInlineStart: 6 }}>já de {v.afecta_a}</Tag>}
                {v.ocupada && <Tag color="blue" style={{ marginInlineStart: 6 }}>ocupada</Tag>}
              </Checkbox>
            ))}
          </Checkbox.Group>
        </Form.Item>
        <Form.Item label="Sugestão de preço"><Select aria-label="Sugestão de preço" value={sugestao} onChange={setSugestao} options={SUGESTAO} /></Form.Item>
      </Form>
    </Modal>
  );
}

const VAZIO: VeiculoContrato = { matricula: '', marca: '', modelo: '', cor: '', porte: undefined, condutor: '', distico: '', vaga_id: null };

/** Veículos de um contrato (imo_vaga_veiculos): ficam no contrato, não na vaga; a cor e o dístico gravam-se. A matrícula repetida noutro contrato pede confirmação. */
export function ModalVeiculos({ contrato, numero, listas, aoFechar }: { contrato: number | null; numero: string; listas: ListasParque | undefined; aoFechar: () => void }) {
  const aberto = contrato !== null;
  const refrescar = useRefrescar();
  const [linhas, setLinhas] = useState<VeiculoContrato[]>([]);
  const q = useQuery({ queryKey: CHAVES_PK.veiculos(contrato ?? 0), queryFn: () => obterVeiculos(contrato as number), enabled: aberto });
  useEffect(() => { if (q.data) setLinhas(q.data.veiculos.length ? q.data.veiculos.map((v) => ({ ...v })) : [{ ...VAZIO }]); }, [q.data]);
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar os veículos'); }, [q.error]);
  const mudar = (i: number, campo: keyof VeiculoContrato, valor: string | number | null | undefined) => setLinhas((l) => l.map((x, k) => (k === i ? { ...x, [campo]: valor } : x)));
  const gravar = useMutation({
    mutationFn: (confirmar: boolean) => gravarVeiculos(contrato as number, linhas.filter((l) => l.matricula.trim() !== '').map((l) => ({ ...l, matricula: normalizarMatricula(l.matricula) })), confirmar),
    onError: (e) => {
      if (e instanceof ErroApi && e.codigo === 'VEICULOS_MATRICULA_REPETIDA') {
        Modal.confirm({ title: 'Matrícula repetida', content: e.message, okText: 'Continuar mesmo assim', cancelText: 'Rever', onOk: () => gravar.mutate(true) });
        return;
      }
      notificarErro(e, 'Não foi possível gravar os veículos');
    },
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(); },
  });
  const vagas = q.data?.vagas ?? [];

  return (
    <Modal title={`Veículos do contrato ${numero}`} open={aberto} onCancel={aoFechar} onOk={() => gravar.mutate(false)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} width={larguraModal(900)} destroyOnHidden>
      <p>Os veículos ficam no contrato, não na vaga: mudam com o inquilino e o lugar é o mesmo. É esta lista que a portaria consulta. {vagas.length ? `Este contrato dá direito a ${vagas.length} lugar(es).` : 'Este contrato não tem lugares de estacionamento.'}</p>
      {linhas.map((l, i) => (
        <fieldset key={i} className="imo-veiculo" aria-label={`Veículo ${i + 1}`}>
          <Space wrap align="end" size={8}>
            <label>Matrícula *<Input aria-label={`Matrícula do veículo ${i + 1}`} value={l.matricula} placeholder="LD-12-34-AB" style={{ width: 150, textTransform: 'uppercase' }} onChange={(e) => mudar(i, 'matricula', e.target.value)} onBlur={() => mudar(i, 'matricula', normalizarMatricula(l.matricula))} /></label>
            <label>Marca<Input aria-label={`Marca do veículo ${i + 1}`} value={l.marca ?? ''} placeholder="Toyota" style={{ width: 130 }} onChange={(e) => mudar(i, 'marca', e.target.value)} /></label>
            <label>Modelo<Input aria-label={`Modelo do veículo ${i + 1}`} value={l.modelo ?? ''} placeholder="Hilux" style={{ width: 130 }} onChange={(e) => mudar(i, 'modelo', e.target.value)} /></label>
            <label>Cor<Input aria-label={`Cor do veículo ${i + 1}`} value={l.cor ?? ''} style={{ width: 110 }} onChange={(e) => mudar(i, 'cor', e.target.value)} /></label>
            <label>Dístico<Input aria-label={`Dístico do veículo ${i + 1}`} value={l.distico ?? ''} style={{ width: 110 }} onChange={(e) => mudar(i, 'distico', e.target.value)} /></label>
            <label>Porte<Select aria-label={`Porte do veículo ${i + 1}`} allowClear value={l.porte ?? undefined} style={{ width: 170 }} options={op(listas?.park_portes)} onChange={(v) => mudar(i, 'porte', v ?? null)} /></label>
            <label>Lugar<Select aria-label={`Lugar do veículo ${i + 1}`} allowClear placeholder="qualquer" value={l.vaga_id ?? undefined} style={{ width: 130 }} options={vagas.map((v) => ({ value: v.id, label: v.designacao }))} onChange={(v) => mudar(i, 'vaga_id', v ?? null)} /></label>
            <label>Condutor<Input aria-label={`Condutor do veículo ${i + 1}`} value={l.condutor ?? ''} style={{ width: 140 }} onChange={(e) => mudar(i, 'condutor', e.target.value)} /></label>
            <Button danger icon={<DeleteOutlined />} aria-label={`Remover o veículo ${i + 1}`} onClick={() => setLinhas((x) => x.filter((_, k) => k !== i))} />
          </Space>
        </fieldset>
      ))}
      <Button icon={<PlusOutlined />} onClick={() => setLinhas((x) => [...x, { ...VAZIO }])}>Outro veículo</Button>
    </Modal>
  );
}
