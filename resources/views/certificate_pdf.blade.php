<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: Georgia, 'Times New Roman', Times, serif;
            font-size: 12pt;
            line-height: 1.6;
            color: #222;
            background: #fff;
        }
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm 20mm 25mm 20mm;
            position: relative;
        }
        .header {
            border-bottom: 2px solid #444;
            padding-bottom: 12px;
            margin-bottom: 24px;
            display: block;
        }
        .header img.logo {
            max-height: 70px;
            max-width: 180px;
            display: block;
            margin-bottom: 8px;
        }
        .header-content {
            font-size: 10pt;
            color: #555;
        }
        .body-content {
            margin-top: 20px;
            margin-bottom: 40px;
        }
        .body-content p {
            margin-bottom: 10px;
        }
        .body-content h1, .body-content h2, .body-content h3 {
            margin-bottom: 12px;
        }
        .footer {
            border-top: 1px solid #aaa;
            padding-top: 12px;
            margin-top: 40px;
        }
        .footer img.signature {
            max-height: 60px;
            max-width: 150px;
            display: block;
            margin-bottom: 6px;
        }
        .footer-content {
            font-size: 9pt;
            color: #666;
        }
        .cert-number {
            font-family: 'Courier New', Courier, monospace;
            font-size: 9pt;
            color: #888;
            margin-top: 8px;
            letter-spacing: 1px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 6px 10px;
            text-align: left;
        }
        th {
            background: #f0f0f0;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="page">

    <div class="header">
        @if(!empty($logoPath) && file_exists($logoPath))
            <img src="{{ $logoPath }}" alt="Logo" class="logo">
        @endif
        @if(!empty($headerHtml))
            <div class="header-content">{!! $headerHtml !!}</div>
        @endif
    </div>

    <div class="body-content">
        {!! $resolvedHtml !!}
    </div>

    <div class="footer">
        @if(!empty($signaturePath) && file_exists($signaturePath))
            <img src="{{ $signaturePath }}" alt="Signature" class="signature">
        @endif
        @if(!empty($footerHtml))
            <div class="footer-content">{!! $footerHtml !!}</div>
        @endif
    </div>

</div>
</body>
</html>
