<!DOCTYPE html>
<html lang="fr">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>@yield('title', 'Mibeko - Document Juridique')</title>
    <style>
        @page {
            /* Set margins for the content area on every page */
            margin: 4.5cm 2cm 2.5cm 2cm;
        }
        
        body {
            font-family: "DejaVu Serif", Georgia, serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #1a1a1a;
            margin: 0; /* Reset body margin since @page handles it */
        }

        /* Prevent text overflow and word breaking issues */
        * {
            box-sizing: border-box;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        header {
            position: fixed;
            /* Move header into the top margin area */
            top: -4.5cm;
            left: -2cm;
            right: -2cm;
            height: 3.8cm;
            background-color: #ffffff;
            padding: 0.8cm 2cm 0.2cm 2cm;
            border-bottom: 1.5pt solid #1565C0;
            z-index: 1000;
        }
        
        .header-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .header-table td {
            vertical-align: top;
            padding: 0;
        }

        .republic-box {
            text-align: left;
            width: 60%;
        }

        .republic-text {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 2px;
            color: #000;
        }

        .motto-text {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 8pt;
            font-style: italic;
            color: #444;
        }
        
        .meta-box {
            text-align: right;
            width: 40%;
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 8.5pt;
            color: #666;
            line-height: 1.3;
        }

        .logo-container {
            position: absolute;
            top: 2.2cm;
            left: 0;
            right: 0;
            text-align: center;
        }

        .logo-text {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 20pt;
            font-weight: bold;
            color: #1565C0;
            letter-spacing: 4px;
        }

        footer {
            position: fixed; 
            /* Move footer into the bottom margin area */
            bottom: -2.5cm;
            left: -2cm;
            right: -2cm;
            height: 1.5cm;
            background-color: #ffffff;
            color: #7f8c8d;
            text-align: center;
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 8pt;
            border-top: 0.5pt solid #eee;
            padding-top: 10px;
        }

        .pagenum:before {
            content: counter(page);
        }

        /* Layout helpers */
        .page-break {
            page-break-after: always;
        }

        .no-break {
            page-break-inside: avoid;
        }

        /* Cover Page Redesign */
        .cover-page {
            position: absolute;
            /* Absolute position to cover the entire page including margins */
            top: -4.5cm;
            left: -2cm;
            right: -2cm;
            bottom: -2.5cm;
            height: 29.7cm; /* Full A4 height */
            width: 21cm; /* Full A4 width */
            background-color: #ffffff;
            z-index: 2000;
            text-align: center;
            padding: 4cm 2cm;
            page-break-after: always;
        }

        .cover-content {
            height: 100%;
            display: block;
            border: 1pt solid #eee;
            padding: 2cm;
            position: relative;
        }

        .cover-badge {
            display: inline-block;
            background-color: #1565C0;
            color: #ffffff;
            padding: 6px 15px;
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 10pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 1.5cm;
            border-radius: 2px;
        }

        .cover-title {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-size: 24pt;
            font-weight: bold;
            color: #000;
            margin-bottom: 2cm;
            line-height: 1.3;
            text-transform: uppercase;
        }

        .cover-info {
            width: 100%;
            margin-top: 2cm;
            border-top: 1pt solid #1565C0;
            padding-top: 1cm;
        }

        /* Sections and Typography */
        .section-title {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            color: #1565C0;
            font-weight: bold;
            margin-top: 30pt;
            margin-bottom: 15pt;
            border-bottom: 0.5pt solid #1565C0;
            padding-bottom: 5pt;
            page-break-after: avoid;
        }

        .article-box {
            margin-bottom: 15pt;
            page-break-inside: avoid;
            text-align: justify;
        }

        .article-head {
            font-family: "DejaVu Sans", Helvetica, sans-serif;
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 6pt;
            color: #000;
            display: block;
        }

        .article-body {
            font-family: "DejaVu Serif", serif;
            font-size: 11pt;
            line-height: 1.6;
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            opacity: 0.04;
            font-size: 100pt;
            color: #000;
            z-index: -100;
            font-family: "DejaVu Sans", sans-serif;
            font-weight: bold;
        }
    </style>
    @yield('styles')
</head>
<body>
    <header>
        <table class="header-table">
            <tr>
                <td class="republic-box">
                    <div class="republic-text">République du Congo</div>
                    <div class="motto-text">Unité - Travail - Progrès</div>
                </td>
                <td class="meta-box">
                    @yield('header_meta')
                    <div style="margin-top: 5px;">Généré le: {{ date('d/m/Y') }}</div>
                </td>
            </tr>
        </table>
        <div class="logo-container">
            <span class="logo-text">MIBEKO</span>
        </div>
    </header>

    <footer>
        Mibeko - Le Droit numérique | &copy; {{ date('Y') }} | Page <span class="pagenum"></span>
    </footer>

    <div class="watermark">MIBEKO</div>

    @yield('content')
</body>
</html>
