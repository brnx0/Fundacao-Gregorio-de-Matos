import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type { AnexoDoCadastro, ArquivoCadastro, PendenciaDoAnexo, SituacaoDosAnexos, SituacaoDoDocumento } from '../../types';

const INTERVALO_CONSULTA_MS = 5_000;

interface Entrada {
    anexos: AnexoDoCadastro[];
    pendencias: PendenciaDoAnexo[];
    podeContinuar: boolean;
}

interface SituacaoLocalDosAnexos {
    /** Situação de cada anexo, pelo campo; nulo = nada enviado. */
    situacoes: Ref<Partial<Record<ArquivoCadastro, SituacaoDoDocumento | null>>>;
    /** O que ainda impede de concluir a etapa. */
    pendencias: Ref<PendenciaDoAnexo[]>;
    podeContinuar: Ref<boolean>;
}

/**
 * Situação de cada anexo enviado e o que falta para concluir a etapa: cópia local, que a consulta periódica atualiza
 * sem recarregar a página (a cada 5 s, enquanto algum anexo está conferindo). Cada recarga das props (enviar,
 * remover, conferir de novo) recomeça a cópia. Mesmo algoritmo de useSituacaoDosDocumentos, da inscrição.
 */
export const useSituacaoDosAnexos = (props: Entrada): SituacaoLocalDosAnexos => {
    const situacoes = ref<Partial<Record<ArquivoCadastro, SituacaoDoDocumento | null>>>({});
    const pendencias = ref<PendenciaDoAnexo[]>([]);
    const podeContinuar = ref<boolean>(false);
    let geracao = 0;

    const sincronizar = (): void => {
        geracao += 1;
        situacoes.value = Object.fromEntries(
            props.anexos.map((anexo): [ArquivoCadastro, SituacaoDoDocumento | null] => [
                anexo.campo,
                anexo.enviado ? { situacao: anexo.enviado.situacao, motivo: anexo.enviado.motivo } : null,
            ]),
        );
        pendencias.value = [...props.pendencias];
        podeContinuar.value = props.podeContinuar;
    };
    sincronizar();
    watch(() => [props.anexos, props.pendencias, props.podeContinuar], sincronizar);

    const temConferindo = computed<boolean>(() => Object.values(situacoes.value).some((situacao) => situacao?.situacao === 'conferindo'));

    // Uma falha de rede fica para a próxima rodada; a resposta de antes de uma recarga das props é descartada.
    const consultar = async (): Promise<void> => {
        const daGeracao = geracao;
        try {
            // Caminho da rota cadastro.anexos.situacao (o front não tem helper de rotas nomeadas).
            const resposta = await fetch('/cadastro/anexos/situacao', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!resposta.ok || daGeracao !== geracao) {
                return;
            }

            const atual = (await resposta.json()) as SituacaoDosAnexos;
            if (daGeracao !== geracao) {
                return;
            }

            for (const anexo of atual.anexos) {
                situacoes.value[anexo.campo] = { situacao: anexo.situacao, motivo: anexo.motivo };
            }
            pendencias.value = atual.pendencias;
            podeContinuar.value = atual.podeContinuar;
        } catch {
            // Sem conexão: tenta de novo na próxima rodada.
        }
    };

    let temporizador: ReturnType<typeof setInterval> | undefined;
    const pararConsulta = (): void => {
        clearInterval(temporizador);
        temporizador = undefined;
    };
    watch(
        temConferindo,
        (conferindo) => {
            if (conferindo && temporizador === undefined) {
                temporizador = setInterval(() => void consultar(), INTERVALO_CONSULTA_MS);
            } else if (!conferindo) {
                pararConsulta();
            }
        },
        { immediate: true },
    );
    onBeforeUnmount(pararConsulta);

    return { situacoes, pendencias, podeContinuar };
};
