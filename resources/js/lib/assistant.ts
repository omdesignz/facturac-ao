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
            const lines = [
                `Facturação registada · ${(data.period as { month: string }).month}`,
                'Valores nas moedas originais; não representam receitas, cobranças ou dívida.',
                `Consulta: ${data.as_of}`,
            ];

            for (const bucket of data.currencies as Record<string, unknown>[]) {
                lines.push(
                    `${bucket.currency_code} · Documentos: ${bucket.billed_document_count} · Notas de crédito: ${bucket.credit_note_count}`,
                );

                for (const [key, label] of Object.entries(metricLabels)) {
                    lines.push(
                        `${label}: ${formatAssistantMoney(String(bucket[key]))} ${bucket.currency_code}`,
                    );
                }
            }

            return lines;
        }
        default:
            return ['Informação indisponível.'];
    }
}
export function assistantReason(reason: string | null): string {
    const messages: Record<string, string> = {
        select_customer: 'Seleccione o identificador público do cliente.',
        select_document: 'Seleccione o identificador público do documento.',
        specify_month: 'Indique um mês no formato AAAA-MM.',
        refine_customer_search: 'Refine o nome do cliente.',
        outside_read_contract:
            'Esta informação não está disponível neste assistente de leitura.',
    };

    return messages[reason ?? ''] ?? 'Informação indisponível.';
}
