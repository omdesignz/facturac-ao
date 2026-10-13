export function connectionRecovery(
    check:
        | { status: string; http_status: number | null; error_codes: string[] }
        | undefined,
): string {
    if (!check || check.status === 'passed') {
        return 'A verificação consulta as séries e não emite facturas. Depois importe as séries autorizadas.';
    }

    if (check.status === 'running') {
        return 'A verificação está em curso. Aguarde e actualize a página antes de tentar novamente.';
    }

    if (check.error_codes.some((code) => code.startsWith('LOCAL_'))) {
        return 'Conclua os requisitos assinalados abaixo. Se faltar uma chave ou a configuração do produtor, peça a revisão ao apoio Facturac.';
    }

    if (check.http_status === 401 || check.http_status === 403) {
        return 'O acesso foi recusado. Peça ao apoio Facturac para rever as credenciais API e a associação ao NIF. Não altere a palavra-passe do Portal do Contribuinte.';
    }

    if (
        check.http_status === null ||
        check.http_status === 429 ||
        check.http_status >= 500
    ) {
        return 'A AGT não confirmou a consulta. Aguarde um minuto e use Verificar ligação AGT novamente. Se persistir, contacte o apoio com a hora e o código da verificação, sem enviar segredos.';
    }

    return 'A AGT não aceitou a verificação. Confira o NIF e peça ao apoio Facturac que reveja a associação. Não volte a guardar credenciais sem indicação do apoio.';
}
