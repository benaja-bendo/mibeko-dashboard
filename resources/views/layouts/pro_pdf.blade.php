<!DOCTYPE html>
<html lang="fr">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>@yield('title', 'Mibeko - Document Juridique')</title>
    <style>
        /*
         | Identité visuelle Mibeko (thème « Lex Gold ») adaptée à l'impression :
         | encre quasi-noire sur blanc, or #9a7b33 pour les accents (plus dense
         | que l'or écran #c8a86a, illisible sur papier), filets or pâle.
         */
        @page {
            margin: 3.2cm 2cm 2.6cm 2cm;
        }

        body {
            font-family: "DejaVu Serif", Georgia, serif;
            font-size: 10.5pt;
            line-height: 1.55;
            color: #1c1a16;
            margin: 0;
        }

        * {
            box-sizing: border-box;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        /* ── En-tête courant (pages intérieures) ─────────────────────────── */
        header {
            position: fixed;
            top: -3.2cm;
            left: -2cm;
            right: -2cm;
            height: 2.4cm;
            padding: 0.75cm 2cm 0 2cm;
            border-bottom: 0.75pt solid #d8c79b;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: bottom;
            padding: 0 0 6pt 0;
        }

        .brand-mark {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 3px;
            color: #9a7b33;
        }

        .brand-sub {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 6.5pt;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #8d8678;
            margin-top: 2pt;
        }

        .header-meta {
            text-align: right;
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 7.5pt;
            color: #8d8678;
            line-height: 1.45;
        }

        /* ── Pied de page courant ─────────────────────────────────────────── */
        footer {
            position: fixed;
            bottom: -2.6cm;
            left: -2cm;
            right: -2cm;
            height: 1.6cm;
            padding: 8pt 2cm 0 2cm;
            border-top: 0.75pt solid #d8c79b;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 7.5pt;
            color: #8d8678;
        }

        .footer-table td { padding: 0; }

        .pagenum:before {
            content: counter(page);
        }

        /* ── Aides de mise en page ───────────────────────────────────────── */
        .page-break { page-break-after: always; }
        .no-break { page-break-inside: avoid; }

        /* ── Page de couverture ──────────────────────────────────────────── */
        .cover-page {
            position: absolute;
            top: -3.2cm;
            left: -2cm;
            right: -2cm;
            height: 29.7cm;
            width: 21cm;
            background-color: #ffffff;
            z-index: 2000;
            page-break-after: always;
        }

        .cover-band {
            background-color: #18140c;
            height: 3.4cm;
            padding: 1.15cm 2cm 0 2cm;
        }

        .cover-band .brand-mark {
            font-size: 17pt;
            color: #c8a86a;
        }

        .cover-band .brand-sub {
            color: #9a8d70;
        }

        .cover-body {
            padding: 2.2cm 2.4cm 0 2.4cm;
            text-align: center;
        }

        .cover-badge {
            display: inline-block;
            border: 1pt solid #9a7b33;
            color: #9a7b33;
            padding: 4pt 14pt;
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 8.5pt;
            font-weight: bold;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            border-radius: 2px;
        }

        .cover-title {
            font-family: "DejaVu Serif", Georgia, serif;
            font-size: 22pt;
            font-weight: bold;
            color: #18140c;
            line-height: 1.35;
            margin: 1.1cm 0 0 0;
        }

        .cover-rule {
            width: 2.6cm;
            border: 0;
            border-top: 1.5pt solid #c8a86a;
            margin: 0.9cm auto;
        }

        .cover-info-table {
            width: 78%;
            margin: 0 auto;
            border-collapse: collapse;
            font-size: 9.5pt;
        }

        .cover-info-table td {
            padding: 7pt 10pt;
            border-bottom: 0.5pt solid #eee7d4;
            text-align: left;
        }

        .cover-info-table .label {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9a7b33;
            width: 38%;
        }

        .cover-republic {
            position: absolute;
            bottom: 1.6cm;
            left: 0;
            right: 0;
            text-align: center;
            font-family: "DejaVu Sans", Helvetica, sans-serif;
        }

        .cover-republic .republic {
            font-size: 9.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #18140c;
        }

        .cover-republic .motto {
            font-size: 7.5pt;
            font-style: italic;
            color: #8d8678;
            margin-top: 3pt;
        }

        /* ── Sommaire ────────────────────────────────────────────────────── */
        .toc-title {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 13pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #18140c;
            border-bottom: 1.5pt solid #c8a86a;
            padding-bottom: 6pt;
            margin: 0 0 14pt 0;
        }

        .toc-entry {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 9pt;
            color: #1c1a16;
            padding: 3pt 0;
            border-bottom: 0.5pt dotted #e6ddc6;
        }

        .toc-entry .toc-kind {
            color: #9a7b33;
            font-weight: bold;
        }

        /* ── Corps du document ───────────────────────────────────────────── */
        .section-title {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 11pt;
            font-weight: bold;
            color: #18140c;
            margin: 24pt 0 12pt 0;
            padding-bottom: 4pt;
            border-bottom: 0.75pt solid #d8c79b;
            page-break-after: avoid;
        }

        .section-title .section-kind {
            color: #9a7b33;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 8.5pt;
        }

        .article-box {
            margin-bottom: 13pt;
            page-break-inside: avoid;
            text-align: justify;
        }

        .article-head {
            display: block;
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-weight: bold;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9a7b33;
            margin-bottom: 4pt;
        }

        .article-body {
            font-family: "DejaVu Serif", Georgia, serif;
            font-size: 10.5pt;
            line-height: 1.62;
        }

        /* ── Bandeau de certification (bas de l'extrait d'article) ──────── */
        .certification {
            margin-top: 1.4cm;
            border-top: 0.75pt solid #d8c79b;
            padding-top: 10pt;
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 8pt;
            color: #8d8678;
            text-align: center;
        }

        .certification .cert-id {
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 7pt;
            color: #b3ab9a;
            margin-top: 4pt;
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            opacity: 0.035;
            font-size: 90pt;
            color: #9a7b33;
            z-index: -100;
            font-family: "DejaVu Sans", sans-serif;
            font-weight: bold;
            letter-spacing: 6px;
        }
    </style>
    @yield('styles')
</head>
<body>
    <header>
        <table class="header-table">
            <tr>
                <td style="width: 55%;">
                    <div class="brand-mark">MIBEKO</div>
                    <div class="brand-sub">Le Droit numérique — République du Congo</div>
                </td>
                <td class="header-meta">
                    @yield('header_meta')
                    <div>Généré le {{ date('d/m/Y') }}</div>
                </td>
            </tr>
        </table>
    </header>

    <footer>
        <table class="footer-table">
            <tr>
                <td style="width: 40%;">Mibeko — Le Droit numérique</td>
                <td style="width: 20%; text-align: center;">Page <span class="pagenum"></span></td>
                <td style="width: 40%; text-align: right;">&copy; {{ date('Y') }} Mibeko</td>
            </tr>
        </table>
    </footer>

    <div class="watermark">MIBEKO</div>

    @yield('content')
</body>
</html>
