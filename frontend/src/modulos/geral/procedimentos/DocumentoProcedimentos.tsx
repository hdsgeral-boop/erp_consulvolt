import { forwardRef, type ReactNode } from 'react';
import { formatarData } from '@/utilitarios/formatacao';
import type { DefinicoesManual, Processo, Recolha } from './api';
import { blocosDeTexto, capitulos, nomeDoProcesso, type Capitulo } from './logica';
import './procedimentos.css';

/** Campos do formulário que o documento mostra (o servidor só calcula; o que se escreve no ecrã vê-se logo). */
export type FormaDocumento = Pick<DefinicoesManual, 'titulo' | 'versao' | 'data_documento' | 'aprovado_em' | 'elaborado_por' | 'aprovado_por'
  | 'apresentacao' | 'objetivo' | 'principios' | 'notas' | 'incluir_diagrama' | 'incluir_legenda' | 'incluir_matriz' | 'incluir_controlos' | 'incluir_cruzamentos' | 'incluir_cobertura' | 'conferir_funcoes'>;

const Texto = ({ texto }: { texto: string }) => (
  <>
    {blocosDeTexto(texto).map((b, i) =>
      b.tipo === 'p' ? <p key={i}>{b.itens[0]}</p> : <ul key={i}>{b.itens.map((x, j) => <li key={j}>{x}</li>)}</ul>,
    )}
  </>
);

function Cap({ c, children }: { c: Capitulo | undefined; children: ReactNode }) {
  return c ? <section className="pd-cap" aria-labelledby={`pd-${c.id}`}><h2 id={`pd-${c.id}`}>{c.n}. {c.titulo}</h2>{children}</section> : null;
}

const Falta = ({ children = 'por definir' }: { children?: ReactNode }) => <span className="pd-falta">{children}</span>;

function Diagrama({ p }: { p: Processo }) {
  return (
    <div className="pd-diagrama" role="img" aria-label={`Etapas de ${p.titulo}: ${p.etapas.map((e) => e.nome).join(', ')}`}>
      {p.etapas.map((e, i) => (
        <span key={i} className="pd-diagrama-par">
          <span className="pd-no imp-sem-quebra">
            <span className="pd-n">{i + 1}</span>
            <b>{e.nome}</b>
            <small>{e.quem || '—'}</small>
          </span>
          {i < p.etapas.length - 1 && <span className="pd-seta" aria-hidden="true">▶</span>}
        </span>
      ))}
    </div>
  );
}

/**
 * O Manual de Procedimentos como documento (porte de documento() do legado): capa com a ficha, índice, capítulos por
 * ordem e condições, numeração N.i.j e marcadores «por definir». Renderizado no ecrã (pré-visualização) e entregue ao motor
 * comum de impressão (`ref`), que acrescenta a identidade da empresa, o rodapé e «Página X de Y».
 */
export const DocumentoProcedimentos = forwardRef<HTMLDivElement, { dados: Recolha; forma: FormaDocumento }>(function DocumentoProcedimentos({ dados, forma }, ref) {
  const caps = capitulos(forma, dados);
  const cap = (id: string) => caps.find((c) => c.id === id);
  const nProc = cap('processos')?.n ?? 0;
  const porChave = new Map(dados.processos.map((p) => [p.chave, p]));
  const ps = dados.processos;
  const cz = dados.cruzamentos;
  const liga = (a: string, b: string) => cz.ligacoes.some((l) => (l.a === a && l.b === b) || (l.a === b && l.b === a));
  const t = dados.totais;

  return (
    <div ref={ref} className="pd-doc">
      <section className="pd-capa imp-quebra-pagina">
        <h1>{forma.titulo || 'Manual de Procedimentos'}</h1>
        <p className="pd-sub">Como se executa cada processo: etapas, responsáveis, controlos e resultado esperado</p>
        <table className="pd-tabela pd-ficha">
          <tbody>
            <tr><th>Versão</th><td>{forma.versao}</td></tr>
            <tr><th>Data</th><td>{formatarData(forma.data_documento)}</td></tr>
            <tr><th>Elaborado por</th><td>{forma.elaborado_por || '—'}</td></tr>
            <tr><th>Aprovado por</th><td>{forma.aprovado_por ? `${forma.aprovado_por}${forma.aprovado_em ? ` · ${formatarData(forma.aprovado_em)}` : ''}` : '—'}</td></tr>
            <tr><th>Âmbito</th><td>{t.processos} processo(s), {t.etapas} etapa(s), {t.controlos} controlo(s)</td></tr>
            {(t.editados > 0 || t.proprios > 0) && (
              <tr><th>Adaptação</th><td>{t.editados} processo(s) adaptados{t.proprios ? ` e ${t.proprios} próprios da empresa` : ''}</td></tr>
            )}
          </tbody>
        </table>
      </section>
      <section className="pd-indice imp-quebra-pagina">
        <h2>Índice</h2>
        <ol className="pd-indice-lista">
          {caps.map((c) => <li key={c.id}><span>{c.n}.</span> {c.titulo}</li>)}
        </ol>
      </section>

      <Cap c={cap('apresentacao')}><Texto texto={forma.apresentacao} /></Cap>
      <Cap c={cap('objetivo')}><Texto texto={forma.objetivo} /></Cap>
      <Cap c={cap('legenda')}>
        <p>Cada procedimento está descrito por etapas. Em cada etapa indica-se:</p>
        <table className="pd-tabela"><tbody>
          <tr><th>Etapa</th><td>O passo do processo, pela ordem em que acontece.</td></tr>
          <tr><th>Quem executa</th><td>O papel responsável pelo passo — não a pessoa, para o manual não envelhecer a cada mudança.</td></tr>
          <tr><th>O que se faz</th><td>A acção concreta a executar.</td></tr>
          <tr><th>Controlos</th><td>O que tem de ser verdade para o passo estar bem feito. É aqui que vivem as regras de segregação de funções e os limites de alçada.</td></tr>
          <tr><th>Resultado</th><td>O que fica feito. Sem resultado não se sabe quando a etapa termina.</td></tr>
        </tbody></table>
        <p className="pd-obs">Os processos marcados como acompanhados têm, no ERP, um painel que mostra em que etapa está cada caso concreto (Fluxo de Processos).</p>
      </Cap>
      <Cap c={cap('principios')}><Texto texto={forma.principios} /></Cap>
      <Cap c={cap('mapa')}>
        <p>A empresa tem {t.processos} processo(s) descritos neste manual.</p>
        <table className="pd-tabela">
          <thead><tr><th className="c">#</th><th>Processo</th><th>Objectivo</th><th className="c">Etapas</th><th className="c">Painel</th></tr></thead>
          <tbody>{ps.map((p, i) => (
            <tr key={p.chave}>
              <td className="c">{nProc}.{i + 1}</td>
              <td><strong>{p.titulo}</strong>{p.origem === 'PROPRIO' && <><br /><small>processo próprio da empresa</small></>}</td>
              <td>{p.objetivo || <Falta />}</td>
              <td className="c">{p.etapas.length}</td>
              <td className="c">{p.tem_painel ? 'sim' : '—'}</td>
            </tr>
          ))}</tbody>
        </table>
      </Cap>
      <Cap c={cap('matriz')}>
        <p>Quem intervém em que processo. Um papel que só aparece num processo costuma ser candidato a ser absorvido por outro; um que aparece em todos é um ponto único de falha.</p>
        <table className="pd-tabela pd-matriz">
          <thead><tr><th>Interveniente</th>{ps.map((p, i) => <th key={p.chave} className="c" title={p.titulo}>{nProc}.{i + 1}</th>)}<th className="c">Total</th></tr></thead>
          <tbody>{dados.intervenientes.map((x) => (
            <tr key={x.nome}>
              <td>{x.nome}{x.na_funcoes === false && <> <Falta>(sem função em RH)</Falta></>}</td>
              {ps.map((p) => <td key={p.chave} className="c">{x.processos.includes(p.chave) ? '●' : ''}</td>)}
              <td className="c"><strong>{x.processos.length}</strong></td>
            </tr>
          ))}</tbody>
        </table>
        <p className="pd-obs">A numeração das colunas corresponde à dos procedimentos no capítulo {nProc}.</p>
      </Cap>
      <Cap c={cap('cruzamentos')}>
        <p>Nenhum processo da empresa vive sozinho. Esta matriz mostra <strong>onde se encontram</strong>: cada ponto é uma entrega de um processo a outro, e por baixo diz-se o que passa em cada um.</p>
        <table className="pd-tabela pd-matriz">
          <thead><tr><th>Processo</th>{ps.map((p, i) => <th key={p.chave} className="c" title={p.titulo}>{nProc}.{i + 1}</th>)}<th className="c">Liga-se a</th></tr></thead>
          <tbody>{ps.map((a, i) => (
            <tr key={a.chave}>
              <td><strong>{nProc}.{i + 1}</strong> {a.titulo}</td>
              {ps.map((b) => (a.chave === b.chave ? <td key={b.chave} className="c pd-self">—</td> : <td key={b.chave} className="c">{liga(a.chave, b.chave) ? <span className="pd-pt">●</span> : ''}</td>))}
              <td className="c"><strong>{cz.grau[a.chave] ?? 0}</strong></td>
            </tr>
          ))}</tbody>
        </table>
        {cz.central && (cz.grau[cz.central] ?? 0) > 0 && porChave.get(cz.central) && (
          <p className="pd-obs"><strong>{porChave.get(cz.central)!.titulo}</strong> é o processo com mais ligações ({cz.grau[cz.central]}): é o que não pode parar sem parar os outros.</p>
        )}
        {cz.isolados.length > 0 && (
          <p className="pd-obs pd-falta">{cz.isolados.length} processo(s) sem nenhum cruzamento declarado: {cz.isolados.map((k) => porChave.get(k)?.titulo ?? k).join(', ')}.</p>
        )}
        <h3>Os pontos de encontro, um a um</h3>
        <table className="pd-tabela">
          <thead><tr><th>Entrega</th><th>Na etapa</th><th>Recebe</th><th>O que passa</th></tr></thead>
          <tbody>
            {cz.pares.map((x, i) => (
              <tr key={`p${i}`}><td><strong>{porChave.get(x.de)?.titulo ?? x.de}</strong></td><td>{x.onde || '—'}</td><td>{porChave.get(x.para)?.titulo ?? x.para}</td><td>{x.o_que || '—'}</td></tr>
            ))}
            {cz.externos.map((x, i) => (
              <tr key={`e${i}`} className="pd-fora"><td><strong>{porChave.get(x.de)?.titulo ?? x.de}</strong></td><td>{x.onde || '—'}</td><td>{x.para} <small>(fora deste manual)</small></td><td>{x.o_que || '—'}</td></tr>
            ))}
          </tbody>
        </table>
      </Cap>
      <Cap c={cap('cobertura')}>
        {dados.cobertura && (
          <>
            <p>Que módulos do ERP têm o seu processo descrito neste manual. Serve para saber o que falta escrever — e para não deixar envelhecer o manual à medida que o ERP cresce.</p>
            <table className="pd-tabela">
              <thead><tr><th>Módulo</th><th>Processos descritos</th><th>Por descrever</th><th className="c">Estado</th></tr></thead>
              <tbody>{dados.cobertura.modulos.map((m) => (
                <tr key={m.id}>
                  <td><strong>{m.nome}</strong></td>
                  <td>{m.descritos.length ? m.descritos.join(', ') : <Falta>nenhum</Falta>}</td>
                  <td>{m.em_falta.length ? <Falta>{m.em_falta.join(', ')}</Falta> : '—'}</td>
                  <td className="c">{m.coberto ? '✓ completo' : m.sem_processo ? <small>sem processo próprio</small> : <Falta>incompleto</Falta>}</td>
                </tr>
              ))}</tbody>
            </table>
            {dados.cobertura.painel_sem_narrativa.length > 0 && (
              <p className="pd-obs pd-falta"><strong>Com painel de acompanhamento mas sem procedimento escrito:</strong> {dados.cobertura.painel_sem_narrativa.join(', ')}. O ERP já segue estes processos, mas ninguém escreveu como se fazem.</p>
            )}
            {dados.cobertura.narrativa_sem_painel.length > 0 && (
              <p className="pd-obs">Descritos sem painel de acompanhamento (normal nos processos próprios da empresa): {dados.cobertura.narrativa_sem_painel.map((x) => x.titulo).join(', ')}.</p>
            )}
          </>
        )}
      </Cap>
      <Cap c={cap('processos')}>
        {ps.map((p, i) => {
          const daqui = p.cruza;
          const paraca = cz.pares.filter((x) => x.para === p.chave);
          return (
            <section key={p.chave} className="pd-processo">
              <h3>{nProc}.{i + 1}. {p.titulo}</h3>
              {p.objetivo ? <p className="pd-obj">{p.objetivo}</p> : <p className="pd-falta">Objectivo por definir.</p>}
              {p.intervenientes.length > 0 && <p className="pd-interv"><strong>Intervenientes:</strong> {p.intervenientes.join(' · ')}</p>}
              {forma.incluir_diagrama && <Diagrama p={p} />}
              <table className="pd-tabela">
                <thead><tr><th className="c">#</th><th>Etapa</th><th>Quem executa</th><th>O que se faz</th><th>Controlos</th><th>Resultado</th></tr></thead>
                <tbody>{p.etapas.map((e, j) => (
                  <tr key={j}>
                    <td className="c">{nProc}.{i + 1}.{j + 1}</td>
                    <td><strong>{e.nome}</strong></td>
                    <td>{e.quem || <Falta />}</td>
                    <td>{e.descricao || '—'}</td>
                    <td>{e.controlos || '—'}</td>
                    <td>{e.resultado || <Falta />}</td>
                  </tr>
                ))}</tbody>
              </table>
              {forma.incluir_cruzamentos && (daqui.length > 0 || paraca.length > 0) && (
                <>
                  <h4>Onde se cruza com outros processos</h4>
                  <table className="pd-tabela">
                    <thead><tr><th className="c">Sentido</th><th>Processo</th><th>Nesta etapa</th><th>O que passa</th></tr></thead>
                    <tbody>
                      {daqui.map((c, k) => <tr key={`d${k}`}><td className="c" aria-label="entrega">→</td><td>{nomeDoProcesso(c.com, porChave)}</td><td>{c.onde || '—'}</td><td>{c.o_que || '—'}</td></tr>)}
                      {paraca.map((c, k) => <tr key={`r${k}`} className="pd-rec"><td className="c" aria-label="recebe">←</td><td>{porChave.get(c.de)?.titulo ?? c.de}</td><td><em>em «{c.onde || '—'}»</em></td><td>{c.o_que || '—'}</td></tr>)}
                    </tbody>
                  </table>
                  <p className="pd-obs">→ o que este processo entrega a outro · ← o que recebe de outro.</p>
                </>
              )}
            </section>
          );
        })}
      </Cap>
      <Cap c={cap('controlos')}>
        <p>Reúnem-se aqui os {dados.controlos.length} controlos definidos nos procedimentos. É por esta lista que se verifica se o processo está a ser cumprido.</p>
        <table className="pd-tabela">
          <thead><tr><th>Processo</th><th>Etapa</th><th>Quem</th><th>Controlo</th></tr></thead>
          <tbody>{dados.controlos.map((c, i) => (
            <tr key={i}><td>{porChave.get(c.processo)?.titulo ?? c.processo}</td><td>{c.etapa}</td><td>{c.quem || '—'}</td><td>{c.controlos}</td></tr>
          ))}</tbody>
        </table>
      </Cap>
      <Cap c={cap('notas')}>
        <Texto texto={forma.notas} />
        <table className="pd-tabela pd-assinaturas imp-sem-quebra">
          <thead><tr><th>Elaborado por</th><th>Aprovado por</th></tr></thead>
          <tbody><tr>
            <td>{forma.elaborado_por || <Falta>nome</Falta>}<div className="pd-linha" /></td>
            <td>{forma.aprovado_por || <Falta>nome</Falta>}{forma.aprovado_em ? ` · ${formatarData(forma.aprovado_em)}` : ''}<div className="pd-linha" /></td>
          </tr></tbody>
        </table>
      </Cap>
    </div>
  );
});
