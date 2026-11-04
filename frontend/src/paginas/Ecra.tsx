import { Result, message } from 'antd';
import { useEffect } from 'react';
import { Navigate, useParams } from 'react-router';
import { decidirEcra } from './guardaEcra';
import { redireccaoEcra } from './redireccoesEcra';
import { ECRAS } from '@/modulos/registo';
import { EmConstrucao } from '@/componentes/EmConstrucao';
import { useSessao } from '@/sessao/SessaoContexto';

/** /m/:modulo/:ecra/* — mostra o ecrã registado, se o utilizador o puder ver (o servidor volta a validar cada pedido). */
export function Ecra() {
  const { modulo, ecra } = useParams();
  const { menu, pode, utilizador } = useSessao();
  const redireccao = redireccaoEcra(modulo, ecra, utilizador?.papel);
  if (redireccao) return <Redirecciona para={redireccao.para} aviso={redireccao.aviso} />;
  const decisao = decidirEcra(menu, modulo, ecra, pode);

  if (decisao === 'sem_rota') return <SemRota />;
  if (decisao === 'negado') {
    return <Result status="403" title="Sem acesso" subTitle="Não tem permissão para este ecrã nesta empresa." />;
  }
  const definicao = menu.find((m) => m.id === modulo)?.ecras.find((e) => e.id === ecra);
  const Componente = ecra ? ECRAS[ecra] : undefined;
  return Componente ? <Componente /> : <EmConstrucao nome={definicao?.nome ?? ecra ?? 'Ecrã'} />;
}

/** Endereço que mudou de sítio (ou que o cliente não tem): navega, com aviso opcional. O destino aplica a sua própria guarda. */
function Redirecciona({ para, aviso }: { para: string; aviso?: string }) {
  useEffect(() => { if (aviso) message.warning(aviso); }, [aviso]);
  return <Navigate to={para} replace />;
}

/** URL de um ecrã que não existe como página (ex.: o chat do assistente é um botão flutuante): volta ao Início com aviso. */
function SemRota() {
  useEffect(() => { message.warning('Esse endereço não corresponde a nenhum ecrã. Foi reencaminhado para o Início.'); }, []);
  return <Navigate to="/inicio" replace />;
}
