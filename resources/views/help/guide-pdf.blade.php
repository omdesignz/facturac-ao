@php
    /*
     * The guide as a printed book: an ink cover, a map, one ink opener per
     * topic, then the chapters on paper. Laid out for mPDF, so tables stand in
     * for flex and grid, and every page type is a named @page.
     */
    $chapters = $guide['chapters'];
    $categories = $guide['categories'] ?? [];
    $numberOf = fn (array $chapter): string => str_pad((string) (array_search($chapter['id'], array_column($chapters, 'id'), true) + 1), 2, '0', STR_PAD_LEFT);
    $topics = collect(array_unique([...array_keys($categories), ...array_column($chapters, 'category')]))
        ->map(fn (string $name): array => [
            'name' => $name,
            'description' => $categories[$name] ?? '',
            'chapters' => array_values(array_filter($chapters, fn (array $chapter): bool => $chapter['category'] === $name)),
        ])
        ->filter(fn (array $topic): bool => $topic['chapters'] !== [])
        ->values();
    $totalMinutes = array_sum(array_column($chapters, 'minutes'));
    $titleOf = fn (string $id): string => collect($chapters)->firstWhere('id', $id)['title'] ?? $id;
    $supportEmail ??= null;
    $supportPhone ??= null;
@endphp
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: hankengrotesk; font-size: 10pt; color: #3a3a37; line-height: 1.55; }
        h1, h2, h3, p { margin: 0; }
        h1, h2 { font-weight: normal; }
        a { color: inherit; text-decoration: none; }
        table { border-collapse: collapse; }
        td { vertical-align: top; padding: 0; }

        .eyebrow { font-family: hankengroteskmedium; font-size: 7pt; text-transform: uppercase; letter-spacing: 1.2pt; color: #6f6f69; }
        .gold { color: #f9b233; }
        .muted { color: #6f6f69; }
        .semibold { font-family: hankengroteskmedium; font-weight: bold; }

        /* ------------------------------------------------------- page types */
        @page { header: html_page-header; footer: html_guide-footer; background-color: #ffffff; margin: 20mm 10mm 19mm 10mm; margin-header: 9mm; margin-footer: 9mm; }
        @page :first { background-color: #171716; header: _blank; footer: _blank; margin: 0; }
        @page opener { background-color: #171716; header: _blank; footer: _blank; margin: 0; }
        @page closing { background-color: #f7f7f5; header: _blank; footer: _blank; margin: 0; }
        @foreach ($topics as $topic)
        @page topic{{ $loop->iteration }} { header: html_topic-{{ $loop->iteration }}-header; footer: html_guide-footer; background-color: #ffffff; margin: 20mm 10mm 19mm 10mm; margin-header: 9mm; margin-footer: 9mm; }
        @endforeach

        /* ------------------------------------------------------------ cover */
        .cover { position: absolute; left: 0; top: 0; width: 210mm; height: 297mm; color: #f2f2ee; }
        .cover-title { font-size: 60pt; line-height: 0.98; letter-spacing: -2.2pt; color: #ffffff; }
        .cover-intro { font-size: 14pt; line-height: 1.45; color: #b9b9b2; }
        .cover-figure { font-size: 26pt; letter-spacing: -0.8pt; color: #ffffff; line-height: 1; }
        .cover-label { font-size: 7.5pt; color: #9a9a93; text-transform: uppercase; letter-spacing: 1pt; }

        /* -------------------------------------------------------------- map */
        .page { padding: 0 9mm; }
        .page-title { font-size: 30pt; line-height: 1.06; letter-spacing: -1pt; color: #171716; }
        .lead { font-size: 12pt; line-height: 1.5; color: #6f6f69; }
        .how td { width: 33%; padding-right: 6mm; }
        .how-number { font-size: 18pt; color: #f39c0b; letter-spacing: -0.5pt; }
        .map-topic { padding: 5mm 5mm 4mm; background-color: #f7f7f5; border-radius: 3mm; }
        .map-row td { padding: 1.4mm 0; border-bottom: 0.2mm solid #e6e5df; font-size: 9.5pt; vertical-align: middle; }
        .map-number { width: 8mm; color: #9a9a93; font-size: 8pt; }
        .map-minutes { width: 14mm; text-align: right; color: #9a9a93; font-size: 8pt; }

        /* ----------------------------------------------------------- opener */
        .opener { position: absolute; left: 0; top: 0; width: 210mm; height: 297mm; color: #f2f2ee; }
        .opener-number { font-size: 96pt; line-height: 0.9; letter-spacing: -4pt; color: #f9b233; }
        .opener-title { font-size: 40pt; line-height: 1.04; letter-spacing: -1.4pt; color: #ffffff; }
        .opener-row td { padding: 3.2mm 0; border-bottom: 0.2mm solid #3a3a37; vertical-align: middle; }

        /* ---------------------------------------------------------- chapter */
        .chapter-title { font-size: 27pt; line-height: 1.08; letter-spacing: -0.6pt; color: #171716; }
        .summary { font-size: 12pt; line-height: 1.5; color: #6f6f69; }
        .open-in-app { font-family: hankengroteskmedium; font-weight: bold; font-size: 8.5pt; color: #171716; background-color: #f7f7f5; border: 0.2mm solid #e6e5df; border-radius: 3mm; padding: 1.6mm 3.4mm; }
        .step-cell { padding-bottom: 6mm; }
        .step-number { width: 15mm; font-size: 24pt; line-height: 1; letter-spacing: -1pt; color: #f39c0b; }
        .step-title { font-family: hankengroteskmedium; font-weight: bold; font-size: 12.5pt; color: #171716; }
        .step-body { font-size: 10.5pt; line-height: 1.65; color: #3a3a37; }
        .note { background-color: #fffaeb; border-left: 0.8mm solid #f9b233; padding: 4.5mm 5.5mm; border-radius: 1.5mm; }
        .note p { font-size: 10.5pt; line-height: 1.6; color: #2b2b28; }
        .panel { background-color: #f7f7f5; padding: 5mm 5.5mm; border-radius: 3mm; }
        .check-row td { padding: 1.6mm 0; vertical-align: middle; font-size: 10.5pt; }
        .check-box { width: 7mm; }
        .box { width: 3.4mm; height: 3.4mm; border: 0.3mm solid #9a9a93; border-radius: 0.8mm; background-color: #ffffff; }
        .question { font-family: hankengroteskmedium; font-weight: bold; font-size: 10.5pt; color: #171716; }
        .answer { margin-top: 1.2mm; font-size: 10pt; line-height: 1.6; color: #55554f; }
        .related a { font-size: 8.5pt; color: #171716; }

        /* -------------------------------------------------------- scenarios */
        .scenario { margin-top: 4mm; padding: 5mm 5.5mm; background-color: #f7f7f5; border-radius: 3mm; page-break-inside: avoid; }
        .code { font-family: dejavusansmono; font-weight: bold; font-size: 9pt; color: #ffffff; background-color: #171716; border-radius: 1.5mm; padding: 1.4mm 0; text-align: center; }
        .scenario-title { font-family: hankengroteskmedium; font-weight: bold; font-size: 12.5pt; color: #171716; }
        .scenario-when { font-family: hankengroteskmedium; font-size: 10pt; color: #3a3a37; }
        .scenario p.body { font-size: 10pt; line-height: 1.6; color: #55554f; }
        .attention { margin-top: 2.4mm; border-left: 0.6mm solid #f9b233; padding-left: 3mm; font-size: 9.5pt; line-height: 1.55; color: #2b2b28; }

        /* ---------------------------------------------------------- closing */
        .closing { position: absolute; left: 0; top: 0; width: 210mm; height: 297mm; }
    </style>
</head>
<body>

{{-- One running header per topic, so a page says where in the book it is. --}}
<htmlpageheader name="page-header">
    <table width="100%" style="font-size:7.5pt;color:#9a9a93;"><tr>
        <td style="vertical-align:middle;"><img src="{{ $logo }}" style="height:3.6mm;" alt="facturac.ao"></td>
        <td align="right" style="vertical-align:middle;letter-spacing:1pt;">GUIA DE UTILIZAÇÃO</td>
    </tr></table>
</htmlpageheader>
@foreach ($topics as $topic)
    <htmlpageheader name="topic-{{ $loop->iteration }}-header">
        <table width="100%" style="font-size:7.5pt;color:#9a9a93;"><tr>
            <td style="vertical-align:middle;"><img src="{{ $logo }}" style="height:3.6mm;" alt="facturac.ao"></td>
            <td align="right" style="vertical-align:middle;letter-spacing:1pt;"><span style="color:#f39c0b;">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span> &nbsp; {{ mb_strtoupper($topic['name']) }}</td>
        </tr></table>
    </htmlpageheader>
@endforeach
<htmlpagefooter name="guide-footer">
    <table width="100%" style="font-size:7.5pt;color:#9a9a93;border-top:0.2mm solid #e6e5df;"><tr>
        <td style="padding-top:2.5mm;">Edição {{ $guide['edition'] }} &nbsp;·&nbsp; Emitida<span class="gold">.</span> Validada<span class="gold">.</span> Paga<span class="gold">.</span></td>
        <td align="right" style="padding-top:2.5mm;font-family:hankengroteskmedium;font-weight:bold;color:#171716;">{PAGENO}<span style="color:#9a9a93;font-weight:normal;font-family:hankengrotesk;"> / {nbpg}</span></td>
    </tr></table>
</htmlpagefooter>

{{-- ============================================================ cover --}}
{{--
    The cover is the brand's own move: the "c." hinge, where facturação
    becomes facturac.ao, set huge in light ink with its gold dot. mPDF only
    positions top-level blocks, so each layer is its own absolute div.
--}}
@php($lightMark = 'data:image/svg+xml;base64,'.base64_encode(str_replace('#171716', '#2b2b28', (string) file_get_contents(public_path('brand/facturac-ao-mark.svg')))))
<div class="cover"></div>
<div style="position:absolute;left:50mm;top:44mm;width:150mm;">
    <img src="{{ $lightMark }}" style="width:150mm;" alt="">
</div>
<div style="position:absolute;left:120mm;top:150mm;width:72mm;text-align:right;">
    <p class="cover-label" style="line-height:1.7;">factura<span style="color:#f2f2ee;">ç</span>ão &nbsp;→&nbsp; factura<span style="color:#f2f2ee;">c</span><span class="gold">.</span>ao<br>Tire a cedilha. Ponha um ponto.</p>
</div>
<div style="position:absolute;left:18mm;top:18mm;width:174mm;">
    <table width="100%"><tr>
        <td style="vertical-align:middle;"><img src="{{ public_path('brand/facturac-ao-dark.svg') }}" style="width:52mm;" alt="facturac.ao"></td>
        <td align="right" style="vertical-align:middle;" class="cover-label">Edição {{ $guide['edition'] }}<br><span style="color:#f2f2ee;">{{ $guide['updated_at'] }}</span></td>
    </tr></table>
</div>
<div style="position:absolute;left:18mm;top:150mm;width:174mm;">
    <p class="cover-label" style="color:#f9b233;">Centro de aprendizagem</p>
    <div style="height:5mm;"></div>
    <h1 class="cover-title">Guia de<br>utilização<span class="gold">.</span></h1>
    <div style="height:7mm;"></div>
    <p class="cover-intro" style="color:#f2f2ee;font-size:16pt;">Mais confiança. Em cada passo.</p>
    <div style="height:2mm;"></div>
    <p class="cover-intro">{{ $guide['subtitle'] }}</p>
</div>
<div style="position:absolute;left:18mm;width:174mm;top:250mm;">
    <table width="100%" style="border-top:0.2mm solid #3a3a37;"><tr>
        <td style="padding-top:5mm;width:22%;"><p class="cover-figure">{{ count($chapters) }}</p><p class="cover-label" style="margin-top:1.5mm;">capítulos</p></td>
        <td style="padding-top:5mm;width:22%;"><p class="cover-figure">{{ $topics->count() }}</p><p class="cover-label" style="margin-top:1.5mm;">temas</p></td>
        <td style="padding-top:5mm;width:22%;"><p class="cover-figure">{{ $totalMinutes }}</p><p class="cover-label" style="margin-top:1.5mm;">min de leitura</p></td>
        <td align="right" style="padding-top:5mm;vertical-align:bottom;font-size:12pt;color:#f2f2ee;">Emitida<span class="gold">.</span> Validada<span class="gold">.</span> Paga<span class="gold">.</span></td>
    </tr></table>
</div>

{{-- ============================================================== map --}}
<pagebreak />
<bookmark content="Comece por aqui" level="0" />
<div class="page">
    <p class="eyebrow">O seu mapa</p>
    <div style="height:3mm;"></div>
    <h1 class="page-title">Comece por aqui.</h1>
    <div style="height:4mm;"></div>
    <p class="lead" style="width:150mm;">{{ count($chapters) }} capítulos curtos, em {{ $topics->count() }} temas. Leia pela ordem sugerida, ou vá directamente à tarefa de hoje: cada título deste mapa é uma ligação para o capítulo.</p>

    <div style="height:9mm;"></div>
    <table width="100%" class="how"><tr>
        <td><p class="how-number">1</p><p class="semibold" style="margin-top:1.5mm;color:#171716;">Leia os passos</p><p class="muted" style="margin-top:1mm;font-size:9pt;">Três passos por capítulo, pela ordem em que se fazem na aplicação.</p></td>
        <td><p class="how-number">2</p><p class="semibold" style="margin-top:1.5mm;color:#171716;">Repare nas notas</p><p class="muted" style="margin-top:1mm;font-size:9pt;">As caixas douradas guardam o que costuma correr mal.</p></td>
        <td style="padding-right:0;"><p class="how-number">3</p><p class="semibold" style="margin-top:1.5mm;color:#171716;">Confira no fim</p><p class="muted" style="margin-top:1mm;font-size:9pt;">Marque a lista «Antes de continuar» antes de passar ao seguinte.</p></td>
    </tr></table>

    <div style="height:9mm;"></div>
    <table width="100%">
        @foreach ($topics->chunk(2) as $row)
            <tr>
                @foreach ($row as $topic)
                    <td style="width:50%;padding:5mm 5mm 4mm;background-color:#f7f7f5;border:2.5mm solid #ffffff;">
                        <div>
                            <table width="100%"><tr>
                                <td style="width:9mm;font-size:16pt;color:#f39c0b;letter-spacing:-0.5pt;line-height:1;">{{ str_pad((string) ($topics->search($topic) + 1), 2, '0', STR_PAD_LEFT) }}</td>
                                <td><p style="font-size:13pt;color:#171716;letter-spacing:-0.3pt;line-height:1.1;">{{ $topic['name'] }}</p><p class="muted" style="margin-top:1mm;font-size:8.5pt;line-height:1.45;">{{ $topic['description'] }}</p></td>
                            </tr></table>
                            <div style="height:3mm;"></div>
                            <table width="100%">
                                @foreach ($topic['chapters'] as $chapter)
                                    <tr class="map-row">
                                        <td class="map-number">{{ $numberOf($chapter) }}</td>
                                        <td><a href="#{{ $chapter['id'] }}" style="color:#171716;">{{ $chapter['title'] }}</a></td>
                                        <td class="map-minutes">{{ $chapter['minutes'] }} min</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    </td>
                @endforeach
                @if ($row->count() === 1)
                    <td style="width:50%;"></td>
                @endif
            </tr>
        @endforeach
    </table>

    <p class="muted" style="margin-top:2mm;font-size:8pt;line-height:1.5;">O guia descreve a aplicação nesta edição. As opções dependem das permissões e da configuração da empresa. As listas de conferência são apoio à aprendizagem; não substituem a validação dos dados nem o enquadramento fiscal da operação.</p>
</div>

@foreach ($topics as $topic)
    @php($topicNumber = str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT))
    @php($topicPage = 'topic'.$loop->iteration)

    {{-- ========================================================= opener --}}
    <pagebreak page-selector="opener" />
    <bookmark content="{{ $topicNumber }} · {{ $topic['name'] }}" level="0" />
    <div class="opener">
        <div style="padding:22mm 18mm 0;">
            <img src="{{ public_path('brand/facturac-ao-dark.svg') }}" style="width:34mm;" alt="facturac.ao">
            <div style="height:48mm;"></div>
            <p class="opener-number">{{ $topicNumber }}</p>
            <div style="height:8mm;"></div>
            <h1 class="opener-title">{{ $topic['name'] }}</h1>
            <div style="height:5mm;"></div>
            <p style="font-size:13pt;line-height:1.5;color:#b9b9b2;width:140mm;">{{ $topic['description'] }}</p>
            <div style="height:14mm;"></div>
            <p class="cover-label">Neste tema &nbsp;·&nbsp; {{ array_sum(array_column($topic['chapters'], 'minutes')) }} min</p>
            <div style="height:2mm;"></div>
            <table width="100%">
                @foreach ($topic['chapters'] as $chapter)
                    <tr class="opener-row">
                        <td style="width:12mm;color:#f9b233;font-size:9pt;">{{ $numberOf($chapter) }}</td>
                        <td><a href="#{{ $chapter['id'] }}" style="color:#ffffff;font-size:12pt;">{{ $chapter['title'] }}</a></td>
                        <td align="right" style="width:18mm;color:#9a9a93;font-size:8.5pt;">{{ $chapter['minutes'] }} min</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    @foreach ($topic['chapters'] as $chapter)
        {{-- ==================================================== chapter --}}
        <pagebreak page-selector="{{ $topicPage }}" />
        <bookmark content="{{ $chapter['title'] }}" level="1" />
        <a name="{{ $chapter['id'] }}"></a>
        <div class="page">
            <table width="100%"><tr>
                <td style="vertical-align:middle;"><p class="eyebrow">Capítulo {{ $numberOf($chapter) }} &nbsp;·&nbsp; {{ $chapter['category'] }} &nbsp;·&nbsp; {{ $chapter['minutes'] }} min</p></td>
                <td align="right" style="vertical-align:middle;"><a href="{{ route($chapter['route']) }}" class="open-in-app">Abrir na aplicação &#8599;</a></td>
            </tr></table>
            <div style="height:4mm;"></div>
            <h1 class="chapter-title">{{ $chapter['title'] }}</h1>
            <div style="height:3.5mm;"></div>
            <p class="summary">{{ $chapter['summary'] }}</p>
            <div style="height:6mm;"></div>
            <div style="height:0.6mm;background-color:#171716;width:16mm;"></div>
            <div style="height:7mm;"></div>

            <table width="100%">
                @foreach ($chapter['steps'] as $step)
                    <tr class="step">
                        <td class="step-number step-cell">{{ $loop->iteration }}</td>
                        <td class="step-cell">
                            <table width="100%">
                                <tr><td style="padding-bottom:1.8mm;" class="step-title">{{ $step['title'] }}</td></tr>
                                <tr><td class="step-body" style="line-height:1.65;">{{ $step['body'] }}</td></tr>
                            </table>
                        </td>
                    </tr>
                @endforeach
            </table>

            <div class="note">
                <p class="eyebrow" style="color:#b35309;">Tenha em conta</p>
                <p style="margin-top:1.5mm;">{{ $chapter['tip'] }}</p>
            </div>

            <div style="height:5mm;"></div>
            <div class="panel">
                <p class="eyebrow">Antes de continuar</p>
                <div style="height:2.5mm;"></div>
                <table width="100%">
                    @foreach ($chapter['checklist'] as $item)
                        <tr class="check-row"><td style="width:7mm;font-family:dejavusans;font-size:13pt;line-height:1;color:#9a9a93;">&#9744;</td><td>{{ $item }}</td></tr>
                    @endforeach
                </table>
            </div>
            <div style="height:5mm;"></div>
            <div style="border-top:0.2mm solid #e6e5df;border-bottom:0.2mm solid #e6e5df;padding:4.5mm 0;">
                <p class="eyebrow">Pergunta frequente</p>
                <div style="height:2mm;"></div>
                <p class="question">{{ $chapter['question'] }}</p>
                <p class="answer">{{ $chapter['answer'] }}</p>
            </div>

            @if ($chapter['related'] !== [])
                <div style="height:6mm;"></div>
                <table class="related"><tr>
                    <td style="vertical-align:middle;padding-right:3mm;"><p class="eyebrow">Continue a aprender</p></td>
                    <td style="vertical-align:middle;font-size:8.5pt;">
                        @foreach ($chapter['related'] as $id)
                            <a href="#{{ $id }}">{{ $titleOf($id) }}</a>@unless ($loop->last)<span class="gold"> &nbsp;●&nbsp; </span>@endunless
                        @endforeach
                    </td>
                </tr></table>
            @endif
        </div>

        @foreach (array_chunk($chapter['scenarios'] ?? [], 3) as $scenarios)
            <pagebreak />
            <bookmark content="{{ $chapter['title'] }}: na prática {{ $loop->iteration }}" level="2" />
            <div class="page">
                <p class="eyebrow">Na prática &nbsp;·&nbsp; {{ $chapter['title'] }}</p>
                <div style="height:3mm;"></div>
                <h2 class="chapter-title" style="font-size:22pt;">Qual documento usar?</h2>
                <div style="height:2.5mm;"></div>
                <p class="summary" style="font-size:10.5pt;">Situações ilustrativas. Confirme os dados e o enquadramento da sua operação.</p>
                <div style="height:2mm;"></div>
                @foreach ($scenarios as $scenario)
                    <div class="scenario">
                        <table width="100%"><tr>
                            <td style="width:17mm;">
                                <table><tr><td style="width:12mm;background-color:#171716;color:#ffffff;text-align:center;font-family:dejavusansmono;font-weight:bold;font-size:9pt;padding:1.8mm 0;">{{ $scenario['code'] }}</td></tr></table>
                            </td>
                            <td>
                                <table width="100%">
                                    <tr><td style="padding-bottom:0.8mm;"><p class="scenario-title">{{ $scenario['title'] }}</p></td></tr>
                                    <tr><td style="padding-bottom:2.2mm;"><p class="scenario-when">{{ $scenario['when'] }}</p></td></tr>
                                    <tr><td style="padding-bottom:1.6mm;"><p class="body">{{ $scenario['example'] }}</p></td></tr>
                                    <tr><td style="padding-bottom:2.6mm;"><p class="body">{{ $scenario['action'] }}</p></td></tr>
                                    <tr><td style="border-left:0.7mm solid #f9b233;padding:0.5mm 0 0.5mm 3mm;font-size:10pt;line-height:1.55;color:#2b2b28;">{{ $scenario['attention'] }}</td></tr>
                                </table>
                            </td>
                        </tr></table>
                    </div>
                @endforeach
                @if (isset($chapter['source']))
                    <p class="muted" style="margin-top:5mm;font-size:8pt;"><span class="eyebrow">Referência</span> &nbsp; <a href="{{ $chapter['source']['url'] }}" style="text-decoration:underline;">{{ $chapter['source']['label'] }}</a></p>
                @endif
            </div>
        @endforeach

        @foreach ($chapter['screenshots'] ?? [] as $screenshot)
            <pagebreak />
            <bookmark content="{{ $chapter['title'] }}: exemplo visual" level="2" />
            <div class="page">
                <p class="eyebrow">Exemplo visual &nbsp;·&nbsp; dados de demonstração</p>
                <div style="height:3mm;"></div>
                <h2 class="chapter-title" style="font-size:22pt;">{{ $screenshot['title'] }}</h2>
                <div style="height:2.5mm;"></div>
                <p class="summary" style="font-size:10.5pt;">{{ $screenshot['caption'] }}</p>
                <div style="height:6mm;"></div>
                @php([$shotWidth, $shotHeight] = getimagesize(public_path(ltrim($screenshot['src'], '/'))) ?: [1, 1])
                {{-- A tall screen is set narrower, so the whole of it stays on its page. --}}
                @php($printWidth = min(182, round(150 * $shotWidth / max($shotHeight, 1))))
                <div style="padding:6mm 0;background-color:#f7f7f5;border-radius:3mm;text-align:center;">
                    <img src="{{ public_path(ltrim($screenshot['src'], '/')) }}" style="width:{{ $printWidth }}mm;" alt="{{ $screenshot['alt'] }}">
                </div>
                <div style="height:5mm;"></div>
                <div class="note"><p>{{ $screenshot['instruction'] }}</p></div>
            </div>
        @endforeach
    @endforeach
@endforeach

{{-- ========================================================== closing --}}
<pagebreak page-selector="closing" />
<bookmark content="Ajuda" level="0" />
<div class="closing">
    <div style="padding:34mm 18mm 0;text-align:center;">
        <img src="{{ public_path('brand/guide-cover-art.svg') }}" style="width:150mm;height:66mm;" alt="Ilustração de documentos e recibos">
        <div style="height:14mm;"></div>
        <img src="{{ $logo }}" style="width:110mm;" alt="facturac.ao">
        <div style="height:10mm;"></div>
        <p style="font-size:15pt;color:#171716;letter-spacing:-0.3pt;">Tire o til. Tire a cedilha. Ponha um ponto.<br><span class="muted">É só isso.</span></p>
        <div style="height:16mm;"></div>
        <p class="eyebrow">Precisa de ajuda?</p>
        <div style="height:3mm;"></div>
        <p style="font-size:11pt;color:#3a3a37;line-height:1.6;">Na aplicação, abra <span class="semibold" style="color:#171716;">Ajuda e reclamações</span>.@if ($supportEmail)<br>Ou escreva para <span class="semibold" style="color:#171716;">{{ $supportEmail }}</span>@if ($supportPhone) ou ligue para <span class="semibold" style="color:#171716;">{{ $supportPhone }}</span>@endif.@endif</p>
    </div>
</div>
<div style="position:absolute;left:18mm;width:174mm;top:262mm;">
        <table width="100%" style="border-top:0.2mm solid #deddd6;"><tr>
            <td style="padding-top:6mm;vertical-align:middle;width:30mm;"><img src="{{ public_path('brand/vap-developer.svg') }}" style="width:22mm;" alt="VAP"></td>
            <td style="padding-top:6mm;vertical-align:middle;font-size:8pt;color:#6f6f69;letter-spacing:0.8pt;">DESENVOLVIDO POR VAP<br><span style="letter-spacing:0;">Cenários e exemplos práticos</span></td>
            <td align="right" style="padding-top:6mm;vertical-align:middle;font-size:8pt;color:#6f6f69;">EDIÇÃO {{ $guide['edition'] }}<br>{{ $guide['updated_at'] }}</td>
        </tr></table>
    </div>
</body>
</html>
