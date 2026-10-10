export interface AssistantContext {
    workspace_public_id: string;
    legal_entity_public_id: string;
    environment: 'production';
}
export interface AssistantResult {
    result_id: string;
    tool: string;
    read_at: string;
    data: Record<string, unknown>;
}
export interface AssistantResponse {
    request_id: string;
    interaction_id: string;
    context: AssistantContext;
    outcome: 'answered' | 'clarification_required' | 'unsupported';
    reason: string | null;
    results: AssistantResult[];
}
export function contextKey(context: AssistantContext): string {
    return `${context.workspace_public_id}/${context.legal_entity_public_id}/${context.environment}`;
}
export function acceptsResponse(
    expected: AssistantContext,
    actual: AssistantContext,
    current: number,
    submitted: number,
): boolean {
    return current === submitted && contextKey(expected) === contextKey(actual);
}
export function formatAssistantMoney(minor: string): string {
    if (!/^(0|-?[1-9][0-9]{0,18})$/.test(minor)) {
        throw new Error('Unavailable amount');
    }

    const negative = minor.startsWith('-');
    const digits = minor.replace('-', '').padStart(3, '0');

    return `${negative ? '-' : ''}${digits.slice(0, -2)},${digits.slice(-2)}`;
}

/**
 * Amount with the thousands grouping the app shows for kwanza (pt-AO uses a
 * non-breaking space, U+00A0). String maths only: minor units can exceed
 * Number.MAX_SAFE_INTEGER.
 */
export function groupAssistantMoney(minor: string): string {
    const [whole, cents] = formatAssistantMoney(minor).split(',');
    const negative = whole.startsWith('-');
    const digits = negative ? whole.slice(1) : whole;
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0');

    return `${negative ? '-' : ''}${grouped},${cents}`;
}
export function plainAssistantText(value: string): string {
    return value.replace(/[\p{Cc}\p{Cf}]/gu, (character) => {
        const code = character.codePointAt(0);

        return code === undefined
            ? ''
            : `\\u{${code.toString(16).toUpperCase()}}`;
    });
}
const explanations: Record<string, string> = {
    not_submitted: 'Sem submissão AGT aplicável.',
    delivery_pending: 'Entrega pendente.',
    delivery_acknowledged: 'Entrega recebida; não comprova validação.',
    processing_reported: 'A AGT reportou processamento.',
    validation_reported:
        'A AGT reportou validação; não constitui autorização fiscal.',
    invalidity_reported: 'A AGT reportou invalidade.',
    processing_cancelled:
        'Processamento cancelado; não significa cancelamento fiscal.',
    request_failed: 'Pedido sem resultado comprovado.',
    refresh_pending: 'Sincronização pendente.',
    sync_failed: 'Sincronização sem sucesso comprovado.',
    stale_observation:
        'Observação histórica; a idade não invalida uma aceitação comprovada.',
    unknown_response: 'Estado desconhecido.',
    evidence_conflict: 'Evidência contraditória; requer revisão.',
    legacy_unverified: 'Evidência insuficiente; requer revisão.',
    state_unavailable: 'Estado não comprovado.',
};
const metricLabels: Record<string, string> = {
    invoiced_gross_minor: 'Facturado bruto',
    invoiced_net_minor: 'Facturado líquido',
    invoiced_tax_minor: 'Imposto registado',
    credit_gross_minor: 'Créditos brutos',
    credit_net_minor: 'Créditos líquidos',
    credit_tax_minor: 'Imposto dos créditos',
    after_credits_gross_minor: 'Bruto após créditos',
    after_credits_net_minor: 'Líquido após créditos',
    after_credits_tax_minor: 'Imposto após créditos',
};
const billingCaveat =
    'Valores nas moedas originais; não representam receitas, cobranças ou dívida.';
const monthNames = [
    'janeiro',
    'fevereiro',
    'março',
    'abril',
    'maio',
    'junho',
    'julho',
    'agosto',
    'setembro',
    'outubro',
    'novembro',
    'dezembro',
];

function billingBuckets(
    data: Record<string, unknown>,
): Record<string, unknown>[] {
    return Array.isArray(data.currencies)
        ? (data.currencies as Record<string, unknown>[])
        : [];
}

function isActiveBucket(bucket: Record<string, unknown>): boolean {
    return (
        Number(bucket.billed_document_count) > 0 ||
        Number(bucket.credit_note_count) > 0
    );
}

function monthLabel(month: string): string {
    const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(month);

    return match ? `${monthNames[Number(match[2]) - 1]} de ${match[1]}` : month;
}

function countLabel(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/**
 * One plain sentence per active currency for the monthly billing answer, or
 * an empty list for every other tool. Amounts are never combined across
 * currencies.
 */
export function assistantHeadline(result: AssistantResult): string[] {
    if (result.tool !== 'getMonthlyRecordedBilling') {
        return [];
    }

    const data = result.data;
    const month = monthLabel(String((data.period as { month: string }).month));
    const active = billingBuckets(data).filter(isActiveBucket);

    if (active.length === 0) {
        return [`Não há facturação registada em ${month}.`];
    }

    return active.map((bucket) => {
        const code = String(bucket.currency_code);
        const unit = code === 'AOA' ? 'Kz' : code;
        const documents = Number(bucket.billed_document_count);
        const credits = Number(bucket.credit_note_count);
        const creditLabel = countLabel(
            credits,
            'nota de crédito',
            'notas de crédito',
        );

        if (documents === 0) {
            return `Em ${month} registou ${creditLabel} de ${groupAssistantMoney(String(bucket.credit_gross_minor))} ${unit}.`;
        }

        const sentence = `Em ${month} facturou ${groupAssistantMoney(String(bucket.invoiced_gross_minor))} ${unit} em ${countLabel(documents, 'documento', 'documentos')}.`;

        return credits > 0
            ? `${sentence} Com ${creditLabel}, fica em ${groupAssistantMoney(String(bucket.after_credits_gross_minor))} ${unit}.`
            : sentence;
    });
}
export function assistantLines(result: AssistantResult): string[] {
    const data = result.data;
    const customer = (item: Record<string, unknown>): string =>
        `${plainAssistantText(String(item.name))} · ${item.country_code} · ${item.is_active ? 'Activo' : 'Inactivo'} · ${item.public_id}`;

    switch (result.tool) {
        case 'searchCustomers':
            return [
                'Até dez correspondências nesta empresa.',
                ...(data.items as Record<string, unknown>[]).map(customer),
                ...(data.has_more
                    ? ['Existem mais correspondências. Refine a pesquisa.']
                    : []),
                ...((data.items as unknown[]).length === 0
                    ? ['Nenhuma correspondência nesta pesquisa autorizada.']
                    : []),
            ];
        case 'getCustomer':
            return [customer(data)];
        case 'getFiscalDocumentSummary':
            return [
                `Documento: ${plainAssistantText(String(data.document_no ?? 'Sem número'))} · ${data.document_type}`,
                `Revisão: ${data.revision}`,
                `Estado registado no documento: ${data.workflow_state}`,
                'Este estado não comprova validação AGT, validade legal ou autorização fiscal.',
            ];
        case 'getQualifiedAgtStatus':
            return [
                `Conhecimento: ${data.knowledge}`,
                `Estado reportado: ${data.reported_state ?? 'Não comprovado'}`,
                `Sincronização: ${data.synchronization} · Observação: ${data.freshness}`,
                explanations[String(data.explanation_code)] ??
                    'Estado não comprovado.',
                `Consulta: ${data.as_of}`,
                `Observado: ${data.observed_at ?? 'Indisponível'}`,
                `Última sincronização comprovada: ${data.last_successful_sync_at ?? 'Indisponível'}`,
                'Esta consulta não autoriza emissão ou liquidação fiscal.',
            ];
        case 'getMonthlyRecordedBilling': {
            const buckets = billingBuckets(data);
            const lines = [
                `Facturação registada · ${(data.period as { month: string }).month}`,
                billingCaveat,
                `Consulta: ${data.as_of}`,
            ];

            for (const bucket of buckets.filter(isActiveBucket)) {
                lines.push(
                    `${bucket.currency_code} · Documentos: ${bucket.billed_document_count} · Notas de crédito: ${bucket.credit_note_count}`,
                );

                for (const [key, label] of Object.entries(metricLabels)) {
                    lines.push(
                        `${label}: ${groupAssistantMoney(String(bucket[key]))} ${bucket.currency_code}`,
                    );
                }
            }

            const idle = buckets
                .filter((bucket) => !isActiveBucket(bucket))
                .map((bucket) => String(bucket.currency_code));

            if (idle.length > 0) {
                lines.push(`Sem movimento noutras moedas: ${idle.join(', ')}.`);
            }

            return lines;
        }
        default:
            return ['Informação indisponível.'];
    }
}
export function assistantReason(reason: string | null): string {
    const messages: Record<string, string> = {
        select_customer:
            'Escolha o cliente em «Referências» e volte a perguntar.',
        select_document:
            'Escolha o documento em «Referências» e volte a perguntar.',
        specify_month: 'Indique um mês no formato AAAA-MM.',
        refine_customer_search: 'Refine o nome do cliente.',
        outside_read_contract:
            'Esta informação não está disponível neste assistente de leitura.',
    };

    return messages[reason ?? ''] ?? 'Informação indisponível.';
}

const failureMessages: Record<string, string> = {
    unauthenticated:
        'A sua sessão terminou. Inicie sessão novamente para continuar.',
    forbidden:
        'O assistente exige autenticação em dois passos e acesso a esta empresa. Active a autenticação em dois passos em Conta e segurança ou peça acesso a um administrador.',
    not_found:
        'Não encontrámos esta empresa ou o assistente não está activo. Volte ao painel e tente novamente.',
    method_not_allowed:
        'O pedido não foi aceite. Recarregue a página e tente novamente.',
    interaction_conflict:
        'Este pedido já estava a ser tratado. Aguarde um momento e volte a perguntar.',
    session_expired:
        'A página expirou. Recarregue a página e volte a perguntar.',
    invalid_input:
        'A pergunta ou as referências não foram aceites. Reformule com menos palavras (máximo 2000 caracteres) e tente de novo.',
    rate_limited:
        'Atingiu o limite diário de perguntas. Tente novamente mais tarde.',
    unavailable:
        'O assistente está temporariamente indisponível. Utilize a pesquisa e as páginas habituais da aplicação.',
};
const statusCodes: Record<number, string> = {
    401: 'unauthenticated',
    403: 'forbidden',
    404: 'not_found',
    405: 'method_not_allowed',
    409: 'interaction_conflict',
    419: 'session_expired',
    422: 'invalid_input',
    429: 'rate_limited',
    503: 'unavailable',
};

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/**
 * The server's failure code for a rejected request, read from the JSON body
 * and, failing that, from the HTTP status. Null when there was no response.
 */
export function assistantFailureCode(error: unknown): string | null {
    const response = isRecord(error) ? error.response : null;

    if (!isRecord(response)) {
        return null;
    }

    let body: unknown = response.data;

    if (typeof body === 'string') {
        try {
            body = JSON.parse(body);
        } catch {
            body = null;
        }
    }

    const code =
        isRecord(body) && isRecord(body.error) ? body.error.code : null;

    if (typeof code === 'string' && code in failureMessages) {
        return code;
    }

    return typeof response.status === 'number'
        ? (statusCodes[response.status] ?? 'unavailable')
        : null;
}

/** What went wrong and what to do next, in words that never echo the server. */
export function assistantFailure(
    error: unknown,
    fallback = 'Não foi possível concluir o pedido. Utilize a pesquisa e as páginas habituais da aplicação.',
): string {
    const code = assistantFailureCode(error);

    return code === null ? fallback : (failureMessages[code] ?? fallback);
}

/** The question field holds 2000 characters; the counter appears near the end. */
export const QUESTION_MAX_LENGTH = 2000;
export const QUESTION_COUNT_FROM = 1800;

/** Whether the character counter should be on screen for this much text. */
export function shouldShowQuestionCount(length: number): boolean {
    return length > QUESTION_COUNT_FROM;
}

/**
 * The one short sentence a screen reader hears about the reference picker.
 * Empty while the list is closed, so nothing is read out of context.
 */
export function pickerAnnouncement(
    listOpen: boolean,
    searching: boolean,
    count: number,
): string {
    if (!listOpen) {
        return '';
    }

    if (searching) {
        return 'A procurar…';
    }

    if (count === 0) {
        return 'Nenhum resultado';
    }

    return count === 1 ? '1 resultado' : `${count} resultados`;
}
