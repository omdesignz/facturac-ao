<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: hankengrotesk; font-size: 10.5pt; color: #333332; line-height: 1.45; }
        h1, h2, h3, p { margin: 0; }
        a { color: #333332; text-decoration: none; }
        .eyebrow { font-size: 8pt; text-transform: uppercase; letter-spacing: 2px; color: #6f6f69; }
        @page { header: html_guide-header; footer: html_guide-footer; }
        @page :first { background-color: #faf9f6; header: _blank; footer: _blank; }
        .cover { padding: 5mm 9mm 0; }
        .cover-title { font-size: 62pt; line-height: 0.98; letter-spacing: -2.5px; font-weight: bold; color: #171716; }
        .cover-intro { font-size: 15pt; color: #6f6f69; line-height: 1.4; }
        .cover-meta { border-top: 1px solid #d9d9d5; padding-top: 5mm; font-size: 8pt; color: #6f6f69; }
        .page { padding: 0 9mm; }
        .chapter-title { font-size: 30pt; line-height: 1.12; font-weight: bold; margin-top: 5mm; }
        .summary { font-size: 12pt; color: #6f6f69; margin-top: 4mm; margin-bottom: 7mm; }
        .rule { height: 1mm; background: #f9b233; margin-bottom: 7mm; }
        .step { margin-bottom: 5mm; }
        .step-number { color: #b35309; font-size: 9pt; font-weight: bold; }
        h3 { font-size: 12pt; font-weight: bold; margin-bottom: 1.5mm; }
        .note { background: #fffaeb; border-left: 2px solid #f9b233; padding: 4mm 5mm; margin-top: 3mm; font-size: 10pt; }
        .checklist { margin-top: 6mm; padding-top: 4mm; border-top: 1px solid #d9d9d5; }
        .check { margin-top: 1.5mm; font-size: 10pt; }
        .question { margin-top: 5mm; font-size: 10pt; }
        .question strong { color: #171716; }
        .related { margin-top: 5mm; font-size: 8pt; color: #6f6f69; }
        .scenario { margin-top: 7mm; padding-bottom: 5mm; border-bottom: 1px solid #deddd6; page-break-inside: avoid; }
        .scenario p { margin-top: 2mm; }
        .scenario-code { color: #b35309; font-size: 10pt; }
        .scenario-warning { color: #6f6f69; font-size: 9pt; }
        .toc { width: 100%; border-collapse: collapse; margin-top: 6mm; }
        .toc td { padding: 1.5mm 0; border-bottom: 1px solid #ececea; vertical-align: middle; }
        .toc-number { width: 10mm; color: #b35309; font-size: 10pt; }
        .toc-page { text-align: right; width: 10mm; color: #6f6f69; font-size: 9pt; }
        .toc-label { font-size: 11pt; font-weight: bold; }
        .toc-category { font-size: 8pt; color: #6f6f69; }
    </style>
</head>
<body>
<htmlpageheader name="guide-header">
    <table width="100%" style="font-size:8pt;color:#6f6f69;border-bottom:1px solid #ececea;"><tr><td>facturac.ao</td><td align="right">GUIA DE UTILIZAÇÃO · {{ $guide['edition'] }}</td></tr></table>
</htmlpageheader>
<htmlpagefooter name="guide-footer">
    <table width="100%" style="font-size:8pt;color:#6f6f69;"><tr><td>Aprender. Aplicar. Conferir.</td><td align="right">{PAGENO} / {nbpg}</td></tr></table>
</htmlpagefooter>
<div class="cover">
    <table width="100%"><tr><td><img src="{{ $logo }}" width="185" alt="facturac.ao"></td><td align="right" style="font-size:8pt;color:#6f6f69;">CENTRO DE<br>APRENDIZAGEM</td></tr></table>
    <div style="height:26mm;"></div>
    <p class="eyebrow">CLAREZA EM CADA PASSO</p>
    <div style="height:6mm;"></div>
    <h1 class="cover-title">Guia de<br>utilização<span style="color:#f9b233;">.</span></h1>
    <div style="height:8mm;"></div>
    <p class="cover-intro">Da primeira venda<br>à gestão do dia a dia.</p>
    <div style="height:4mm;"></div>
    <img src="{{ public_path('brand/guide-cover-art.svg') }}" style="width:162mm;height:71.28mm;" alt="Ilustração de documentos e recibos">
    <p style="font-size:9pt;letter-spacing:1px;margin-top:5mm;margin-bottom:10mm;">PREPARAR &nbsp; / &nbsp; OPERAR &nbsp; / &nbsp; CONFERIR</p>
    <div class="cover-meta"><table width="100%"><tr><td style="vertical-align:middle;width:24mm;"><img src="{{ public_path('brand/vap-developer.svg') }}" width="70" alt="VAP"></td><td style="vertical-align:middle;">DESENVOLVIDO POR VAP<br>Cenários e exemplos práticos</td><td align="right">EDIÇÃO {{ $guide['edition'] }}<br>{{ $guide['updated_at'] }}</td></tr></table></div>
</div>
<pagebreak />
<bookmark content="Comece por aqui" level="0" />
<div class="page">
    <p class="eyebrow">O SEU MAPA</p>
    <h1 class="chapter-title">Comece por aqui.</h1>
    <p class="summary">Leia pela ordem sugerida ou escolha a tarefa de hoje.<br>Os títulos do índice são ligações para os capítulos.</p>
    <table class="toc">
        @foreach ($guide['chapters'] as $chapter)
            <tr><td class="toc-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</td><td><a class="toc-label" href="#{{ $chapter['id'] }}">{{ $chapter['title'] }}</a><br><span class="toc-category">{{ $chapter['category'] }}</span></td></tr>
        @endforeach
    </table>
    <p style="margin-top:6mm;font-size:9pt;color:#6f6f69;">O guia descreve a aplicação nesta edição. As opções dependem das permissões e da configuração da empresa. As listas de conferência são apoio à aprendizagem; não substituem a validação dos dados nem o enquadramento fiscal da operação.</p>
</div>
@foreach ($guide['chapters'] as $chapter)
    <pagebreak />
    <bookmark content="{{ $chapter['title'] }}" level="0" />
    <a name="{{ $chapter['id'] }}"></a>
    <div class="page">
        <p class="eyebrow">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} &nbsp; / &nbsp; {{ $chapter['category'] }}</p>
        <h1 class="chapter-title">{{ $chapter['title'] }}</h1>
        <p class="summary">{{ $chapter['summary'] }}</p>
        <div class="rule"></div>
        @foreach ($chapter['steps'] as $step)
            <div class="step">
                <h3><span class="step-number">{{ $loop->iteration }}.</span> &nbsp; {{ $step['title'] }}</h3>
                <p>{{ $step['body'] }}</p>
            </div>
        @endforeach
        <div class="note">{{ $chapter['tip'] }}</div>
        <div class="checklist">
            <p class="eyebrow">ANTES DE CONTINUAR</p>
            @foreach ($chapter['checklist'] as $item)
                <p class="check">&#9633; &nbsp; {{ $item }}</p>
            @endforeach
        </div>
        <div class="question"><strong>{{ $chapter['question'] }}</strong><br>{{ $chapter['answer'] }}</div>
        <p class="related">CONTINUE A APRENDER &nbsp; / &nbsp;
            @foreach ($chapter['related'] as $id)
                <a href="#{{ $id }}">{{ collect($guide['chapters'])->firstWhere('id', $id)['title'] }}</a>{{ $loop->last ? '' : ' · ' }}
            @endforeach
        </p>
    </div>
    @foreach (array_chunk($chapter['scenarios'] ?? [], 3) as $scenarios)
        <pagebreak />
        <bookmark content="{{ $chapter['title'] }}: cenários {{ $loop->iteration }}" level="1" />
        <div class="page">
            <p class="eyebrow">NA PRÁTICA / {{ $chapter['title'] }}</p>
            <h2 class="chapter-title">Qual documento usar?</h2>
            <p class="summary">Situações ilustrativas. Confirme os dados e o enquadramento da sua operação.</p>
            @foreach ($scenarios as $scenario)
                <div class="scenario">
                    <h3><span class="scenario-code">{{ $scenario['code'] }}</span> &nbsp; {{ $scenario['title'] }}</h3>
                    <p><strong>{{ $scenario['when'] }}</strong><br>{{ $scenario['example'] }}</p>
                    <p>{{ $scenario['action'] }}</p>
                    <p class="scenario-warning">{{ $scenario['attention'] }}</p>
                </div>
            @endforeach
            @if (isset($chapter['source']))
                <p class="related">REFERÊNCIA &nbsp; / &nbsp; <a href="{{ $chapter['source']['url'] }}">{{ $chapter['source']['label'] }}</a></p>
            @endif
        </div>
    @endforeach
    @foreach ($chapter['screenshots'] ?? [] as $screenshot)
        <pagebreak />
        <bookmark content="{{ $chapter['title'] }}: exemplo visual" level="1" />
        <div class="page">
            <p class="eyebrow">EXEMPLO VISUAL / DADOS DE DEMONSTRAÇÃO</p>
            <h2 class="chapter-title">{{ $screenshot['title'] }}</h2>
            <p class="summary">{{ $screenshot['caption'] }}</p>
            <img src="{{ public_path(ltrim($screenshot['src'], '/')) }}" style="width:162mm;" alt="{{ $screenshot['alt'] }}">
            <div class="note">{{ $screenshot['instruction'] }}</div>
        </div>
    @endforeach
@endforeach
</body>
</html>
