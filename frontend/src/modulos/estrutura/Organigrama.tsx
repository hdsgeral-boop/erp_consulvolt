import { Button, Card, Checkbox, Empty, Flex, Segmented, Select, Skeleton, Slider, Space, Typography } from 'antd';
import { PrinterOutlined } from '@ant-design/icons';
import { useMemo, useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useColaboradores, useCargos } from '@/modulos/rh/comum/consultas';
import type { Colaborador } from '@/modulos/rh/api';
import { TIPOS_UNIDADE, aplanar, construirArvore, type NoUnidade } from './comum/arvore';
import { useEstrutura } from './Estrutura';

/* Organigrama em CSS puro: cada nível é uma lista com conectores desenhados por pseudo-elementos. */
const ESTILO = `
.organigrama ul { padding-top: 20px; position: relative; display: flex; justify-content: center; margin: 0; padding-left: 0; }
.organigrama li { list-style: none; text-align: center; position: relative; padding: 20px 6px 0 6px; }
.organigrama li::before, .organigrama li::after { content: ''; position: absolute; top: 0; right: 50%; border-top: 1px solid #bfbfbf; width: 50%; height: 20px; }
.organigrama li::after { right: auto; left: 50%; border-left: 1px solid #bfbfbf; }
.organigrama li:only-child::after, .organigrama li:only-child::before { display: none; }
.organigrama li:only-child { padding-top: 0; }
.organigrama li:first-child::before, .organigrama li:last-child::after { border: 0 none; }
.organigrama li:last-child::before { border-right: 1px solid #bfbfbf; border-radius: 0 5px 0 0; }
.organigrama li:first-child::after { border-radius: 5px 0 0 0; }
.organigrama ul ul::before { content: ''; position: absolute; top: 0; left: 50%; border-left: 1px solid #bfbfbf; width: 0; height: 20px; }
.organigrama > ul { padding-top: 0; }
.organigrama .caixa { display: inline-block; min-width: 170px; max-width: 240px; background: #fff; border: 1px solid #d9d9d9; border-radius: 8px; padding: 8px 10px; text-align: left; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
.organigrama .caixa.apoio { border-style: dashed; }
@media print { .sem-impressao { display: none !important; } .organigrama { transform: none !important; } }
`;

/** Estrutura orgânica › Organigrama (est_organigrama): vista funcional (unidades, postos e vagas) ou nominal (pessoas por posto). */
export default function Organigrama() {
  const estrutura = useEstrutura();
  const colaboradores = useColaboradores();
  const cargos = useCargos();
  const [modo, setModo] = useState<'funcional' | 'nominal'>('funcional');
  const [raiz, setRaiz] = useState<number | undefined>();
  const [escala, setEscala] = useState(100);
  const [comPostos, setComPostos] = useState(true);

  const arvore = useMemo(() => construirArvore(estrutura.data?.unidades ?? [], true), [estrutura.data]);
  const visivel = useMemo(() => (raiz ? aplanar(arvore).filter((n) => n.unidade.id === raiz) : arvore), [arvore, raiz]);
  const porPosto = useMemo(() => {
    const m = new Map<number, Colaborador[]>();
    colaboradores.lista.filter((c) => c.estado === 'ACTIVO').forEach((c) => c.posto_trabalho_id && m.set(c.posto_trabalho_id, [...(m.get(c.posto_trabalho_id) ?? []), c]));
    return m;
  }, [colaboradores.lista]);
  const semPosto = (unidade: number) => colaboradores.lista.filter((c) => c.estado === 'ACTIVO' && c.unidade_organica_id === unidade && !c.posto_trabalho_id);

  const caixa = (n: NoUnidade) => {
    const u = n.unidade;
    return (
      <div className={`caixa${u.apoio ? ' apoio' : ''}`} style={{ borderTop: `4px solid ${u.cor ?? '#2a78d6'}` }}>
        <Typography.Text strong style={{ display: 'block' }}>{u.nome}</Typography.Text>
        <Typography.Text type="secondary" style={{ fontSize: 11, display: 'block' }}>
          {u.tipo ? TIPOS_UNIDADE[u.tipo] ?? u.tipo : ''} {u.colaborador_responsavel_id ? `· ${colaboradores.nome(u.colaborador_responsavel_id)}` : ''}
        </Typography.Text>
        {modo === 'funcional' ? (
          <>
            <Typography.Text style={{ fontSize: 11, display: 'block' }}>{n.ocupadosTotal}/{n.vagasTotal} vagas ocupadas · {n.membrosTotal} pessoa(s)</Typography.Text>
            {comPostos && u.postos.map((p) => (
              <div key={p.id} style={{ fontSize: 11, borderTop: '1px solid #f0f0f0', marginTop: 4, paddingTop: 2 }}>
                {p.chefia ? '★ ' : ''}{p.titulo || cargos.nome(p.cargo_funcao_id)} <span style={{ color: p.ocupados > (p.vagas ?? 0) ? '#cf1322' : 'rgba(0,0,0,0.55)' }}>({p.ocupados}/{p.vagas ?? 0})</span>
              </div>
            ))}
          </>
        ) : (
          <>
            {u.postos.map((p) => (
              <div key={p.id} style={{ fontSize: 11, borderTop: '1px solid #f0f0f0', marginTop: 4, paddingTop: 2 }}>
                <strong>{p.chefia ? '★ ' : ''}{p.titulo || cargos.nome(p.cargo_funcao_id)}</strong>
                {(porPosto.get(p.id) ?? []).map((c) => <div key={c.id}>{c.nome_completo}</div>)}
                {Array.from({ length: Math.max(0, (p.vagas ?? 0) - (porPosto.get(p.id)?.length ?? 0)) }).map((_, i) => <div key={`v${i}`} style={{ color: '#d46b08' }}>(vaga)</div>)}
              </div>
            ))}
            {semPosto(u.id).length > 0 && (
              <div style={{ fontSize: 11, borderTop: '1px solid #f0f0f0', marginTop: 4, paddingTop: 2 }}>
                <em>Sem posto:</em>
                {semPosto(u.id).map((c) => <div key={c.id}>{c.nome_completo}</div>)}
              </div>
            )}
          </>
        )}
      </div>
    );
  };

  const ramo = (nos: NoUnidade[]) => (
    <ul>
      {nos.map((n) => (
        <li key={n.unidade.id}>
          {caixa(n)}
          {n.filhos.length > 0 && ramo(n.filhos)}
        </li>
      ))}
    </ul>
  );

  return (
    <>
      <style>{ESTILO}</style>
      <div className="sem-impressao">
        <CabecalhoPagina
          titulo="Organigrama"
          subtitulo="Estrutura funcional (unidades, postos e vagas) ou nominal (pessoas em cada posto)"
          accoes={<Button icon={<PrinterOutlined />} onClick={() => window.print()}>Imprimir</Button>}
        />
        <Card size="small" style={{ marginBottom: 12 }}>
          <Flex gap={12} wrap align="center">
            <Segmented value={modo} onChange={(v) => setModo(v as 'funcional' | 'nominal')} options={[{ value: 'funcional', label: 'Funcional' }, { value: 'nominal', label: 'Nominal' }]} />
            <Select allowClear placeholder="Toda a estrutura" style={{ width: 260 }} value={raiz} onChange={setRaiz} options={aplanar(arvore).map((n) => ({ value: n.unidade.id, label: `${'— '.repeat(n.nivel)}${n.unidade.nome}` }))} />
            {modo === 'funcional' && <Checkbox checked={comPostos} onChange={(e) => setComPostos(e.target.checked)}>Mostrar postos</Checkbox>}
            <Space>
              <span>Zoom</span>
              <Slider min={40} max={130} step={10} value={escala} onChange={setEscala} style={{ width: 140 }} tooltip={{ formatter: (v) => `${v}%` }} />
            </Space>
          </Flex>
        </Card>
      </div>
      {estrutura.isLoading || colaboradores.isLoading ? (
        <Skeleton active />
      ) : !visivel.length ? (
        <Empty description="Ainda não há unidades orgânicas activas." />
      ) : (
        <div style={{ overflow: 'auto', paddingBottom: 16 }}>
          <div className="organigrama" role="tree" aria-label="Organigrama" style={{ transform: `scale(${escala / 100})`, transformOrigin: 'top center', minWidth: 'max-content' }}>
            {ramo(visivel)}
          </div>
        </div>
      )}
    </>
  );
}
