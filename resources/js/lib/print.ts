/** Wait for embedded SVG logos as well as ordinary images before printing. */
export async function printDocument(): Promise<void> {
    try {
        await document.fonts.ready;
        await Promise.all(
            Array.from(document.querySelectorAll('img, svg image')).map(
                async (element) => {
                    const source =
                        element.getAttribute('href') ??
                        element.getAttribute('src');

                    if (!source) {
                        return;
                    }

                    const image = new Image();
                    image.src = source;
                    await image.decode();
                },
            ),
        );
        await new Promise<void>((resolve) =>
            requestAnimationFrame(() => resolve()),
        );
        window.print();
    } catch {
        window.alert(
            'Não foi possível carregar as imagens do documento. Recarregue a página e tente imprimir novamente.',
        );
    }
}
