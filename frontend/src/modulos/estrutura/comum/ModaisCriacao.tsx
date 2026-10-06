import { Alert, Button, Checkbox, Col, Input, Modal, Row, Select, Space, Table, Typography, message } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { TIPOS_UNIDADE, type Unidade } from './arvore';
import { ESTRUTURA_BASE, lerListaColada, validarNovasUnidades, type LinhaNovaUnidade } from './variasUnidades';

const CHAVES = [['rh', 'estrutura'], ['gestao']];

/** «Várias unidades» do legado: várias unidades no mesmo nível de uma só vez (colar uma lista do Excel distribui pelas linhas). */
export function ModalVariasUnidades({ aberto, unidades, paiInicial, aoFechar }: { aberto: boolean; unidades: Unidade[]; paiInicial?: number | null; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const [pai, setPai] = useState<number | null>(paiInicial ?? null);
  const [tipo, setTipo] = useState('DEPARTAMENTO');
  const [apoio, setApoio] = useState(false);
  const [linhas, setLinhas] = useState<LinhaNovaUnidade[]>(Array.from({ length: 4 }, () => ({ nome: '' })));
  const [erros, setErros] = useState<string[]>([]);
  const [aGravar, setAGravar] = useState(false);
  const muda = (i: number, p: Partial<LinhaNovaUnidade>) => setLinhas((ls) => ls.map((l, k) => (k === i ? { ...l, ...p } : l)));
  const colar = (i: number, texto: string) => {
    if (!/[\r\n\t]/.test(texto)) return false;
    const partes = lerListaColada(texto);
    setLinhas((ls) => {
      const n = [...ls];
      partes.forEach((p, k) => {
        n[i + k] = { ...(n[i + k] ?? { nome: '' }), ...p };
      });
      return n;
    });
    return true;
  };
  const gravar = async () => {
    const validas = linhas.filter((l) => l.nome.trim());
    const e = validarNovasUnidades(validas, pai, unidades);
    setErros(e);
    if (e.length) return;
    setAGravar(true);
    try {
      for (const [k, l] of validas.entries()) {
        await enviar('post', '/rh/estrutura/unidades', {
          nome: l.nome.trim(),
          codigo: l.codigo?.trim() || null,
          tipo: l.tipo || tipo,
          unidade_organica_pai_id: pai,
          apoio,
          ativo: true,
          ordem: k + 1,
        });
      }
      message.success(`${validas.length} unidade(s) criada(s).`);
      CHAVES.forEach((c) => void cliente.invalidateQueries({ queryKey: c }));
      aoFechar();
    } catch (er) {
      notificarErro(er, 'Não foi possível criar todas as unidades');
      CHAVES.forEach((c) => void cliente.invalidateQueries({ queryKey: c }));
    } finally {
      setAGravar(false);
    }
  };
  const n = linhas.filter((l) => l.nome.trim()).length;
  return (
    <Modal title="Criar várias unidades" open={aberto} onCancel={aoFechar} width={larguraModal(820)} destroyOnHidden okText={`Criar ${n} unidade(s)`} cancelText="Cancelar" onOk={() => void gravar()} confirmLoading={aGravar} okButtonProps={{ disabled: !n }}>
      <Row gutter={[12, 12]} style={{ marginBottom: 12 }}>
        <Col xs={24} md={10}>
          <Typography.Text strong>Unidade superior</Typography.Text>
          <Select
            allowClear
            showSearch
            optionFilterProp="label"
            placeholder="(nível de topo)"
            aria-label="Unidade superior"
            style={{ width: '100%' }}
            value={pai ?? undefined}
            onChange={(v?: number) => setPai(v ?? null)}
            options={unidades.map((u) => ({ value: u.id, label: `${u.codigo ? `${u.codigo} — ` : ''}${u.nome}` }))}
          />
        </Col>
        <Col xs={24} md={8}>
          <Typography.Text strong>Tipo por omissão</Typography.Text>
          <Select aria-label="Tipo por omissão" style={{ width: '100%' }} value={tipo} onChange={setTipo} options={Object.entries(TIPOS_UNIDADE).map(([value, label]) => ({ value, label }))} />
        </Col>
        <Col xs={24} md={6} style={{ display: 'flex', alignItems: 'flex-end' }}>
          <Checkbox checked={apoio} onChange={(e) => setApoio(e.target.checked)}>
            Unidades de apoio
          </Checkbox>
        </Col>
      </Row>
      <Table<LinhaNovaUnidade & { i: number }>
        size="small"
        pagination={false}
        scroll={scrollTabela()}
        rowKey="i"
        dataSource={linhas.map((l, i) => ({ ...l, i }))}
        columns={[
          { title: '#', key: 'n', width: 40, render: (_, l) => l.i + 1 },
          {
            title: 'Nome da unidade',
            key: 'nome',
            render: (_, l) => (
              <Input aria-label={`Nome da unidade ${l.i + 1}`} value={l.nome} maxLength={150} onChange={(e) => muda(l.i, { nome: e.target.value })} onPaste={(e) => colar(l.i, e.clipboardData.getData('text')) && e.preventDefault()} />
            ),
          },
          { title: 'Código', key: 'codigo', width: 120, render: (_, l) => <Input aria-label={`Código ${l.i + 1}`} value={l.codigo} maxLength={30} onChange={(e) => muda(l.i, { codigo: e.target.value })} /> },
          {
            title: 'Tipo',
            key: 'tipo',
            width: 170,
            render: (_, l) => (
              <Select allowClear placeholder="(por omissão)" aria-label={`Tipo ${l.i + 1}`} style={{ width: '100%' }} value={l.tipo} onChange={(v?: string) => muda(l.i, { tipo: v })} options={Object.entries(TIPOS_UNIDADE).map(([value, label]) => ({ value, label }))} />
            ),
          },
          { key: 'x', width: 44, render: (_, l) => <Button type="text" danger icon={<DeleteOutlined />} aria-label={`Remover linha ${l.i + 1}`} onClick={() => setLinhas((ls) => (ls.length > 1 ? ls.filter((_, k) => k !== l.i) : [{ nome: '' }]))} /> },
        ]}
      />
      <Space style={{ marginTop: 8 }} wrap>
        <Button type="dashed" icon={<PlusOutlined />} onClick={() => setLinhas((ls) => [...ls, { nome: '' }, { nome: '' }, { nome: '' }])}>
          Mais linhas
        </Button>
        <Typography.Text type="secondary">Pode colar uma lista do Excel (uma unidade por linha; «código» e «nome» em colunas).</Typography.Text>
      </Space>
      {erros.length > 0 && <Alert style={{ marginTop: 12 }} type="error" showIcon message="Corrija antes de gravar (nada foi criado):" description={erros.join(' ')} />}
    </Modal>
  );
}

/** «Criar estrutura base» (estCriarBase): estrutura de partida numa empresa sem unidades. */
export function useEstruturaBase() {
  const cliente = useQueryClient();
  const [aCriar, setACriar] = useState(false);
  const criar = (existentes: number) => {
    if (existentes > 0) return void message.warning('A empresa já tem unidades: a estrutura base só se cria numa empresa sem estrutura.');
    Modal.confirm({
      title: 'Criar estrutura base',
      content:
        'Criar uma estrutura de partida (Conselho de Administração › Direcção-Geral › Direcções Administrativa e Financeira, Comercial e de Operações, com os departamentos habituais)? Pode alterar tudo a seguir.',
      okText: 'Criar',
      cancelText: 'Cancelar',
      onOk: async () => {
        setACriar(true);
        const ids: Record<string, number> = {};
        try {
          for (const [chave, nome, tipo, pai, codigo, ordem, apoio] of ESTRUTURA_BASE) {
            const { dados } = await enviar<{ id: number }>('post', '/rh/estrutura/unidades', { nome, tipo, codigo, ordem, apoio, ativo: true, unidade_organica_pai_id: pai ? ids[pai] : null });
            ids[chave] = dados.id;
          }
          message.success(`${ESTRUTURA_BASE.length} unidades criadas. Defina os responsáveis, cargos e vagas.`);
        } catch (e) {
          notificarErro(e, 'Não foi possível criar a estrutura base');
        } finally {
          setACriar(false);
          CHAVES.forEach((c) => void cliente.invalidateQueries({ queryKey: c }));
        }
      },
    });
  };
  return { criar, aCriar };
}
