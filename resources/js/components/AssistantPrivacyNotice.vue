<script setup lang="ts">
defineProps<{ policy: string; noticeUrl: string | null }>();
</script>
<template>
    <div class="space-y-3 text-sm/6 text-zinc-700 dark:text-zinc-300">
        <p>
            A Facturac utiliza a API comercial da Anthropic para interpretar a
            pergunta que escrever. Enviamos o texto após filtragem local
            limitada, referências opacas seleccionadas, o mês e as operações de
            leitura disponíveis. Os nomes de clientes que escrever podem ser
            enviados. Não enviamos registos de clientes, facturas, evidência AGT
            ou os resultados da resposta. As respostas são construídas
            localmente a partir de registos autorizados. A IA pode escolher a
            operação errada; verifique o contexto e a fonte apresentados.
        </p>
        <p>
            Não cole números fiscais ou de documentos, valores, contactos,
            moradas, palavras-passe, chaves ou informação pessoal sensível. A
            filtragem não detecta tudo. O processamento utiliza a configuração
            aprovada nos Estados Unidos, com as excepções, conservação e
            transferências descritas na política. Pode utilizar a aplicação
            normal sem processamento externo de perguntas.
        </p>
        <a
            v-if="noticeUrl"
            :href="noticeUrl"
            class="inline-block rounded font-medium text-zinc-950 underline underline-offset-2 focus-ring dark:text-white"
            >Política de privacidade — {{ policy }}</a
        >
    </div>
</template>
