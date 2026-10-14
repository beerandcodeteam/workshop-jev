<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <title>{{ $title }}</title>
        <style>
            @page { margin: 36px 44px; }
            body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; line-height: 1.45; color: #1f1f23; }
            h1 { font-size: 22px; margin: 0 0 2px; color: #18181b; }
            h1 + p { margin: 0 0 10px; color: #52525b; }
            h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #772ce8; border-bottom: 1px solid #d4d4d8; padding-bottom: 3px; margin: 14px 0 6px; }
            h3 { font-size: 11px; margin: 8px 0 2px; }
            p { margin: 0 0 6px; }
            ul { margin: 0 0 6px; padding-left: 14px; }
            li { margin-bottom: 2px; }
            a { color: #772ce8; text-decoration: none; }
        </style>
    </head>
    <body>
        {!! $html !!}
    </body>
</html>
