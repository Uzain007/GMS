<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $emailTitle ?? 'IronCore' }}</title>
    <style>
        body, table, td, a { box-sizing: border-box; }
        table { border-spacing: 0; }
        img { display: block; max-width: 100%; height: auto; border: 0; }
        .email-shell, .email-card, .email-content, .email-header, .email-footer { width: 100%; max-width: 100%; }
        .email-content, .email-heading, .email-copy, .email-footer-copy, .email-header-label, .info-label, .info-value, .fallback-link {
            overflow-wrap: anywhere;
            word-wrap: break-word;
        }
        @media only screen and (max-width: 480px) {
            .email-card { border-radius: 0 !important; }
            .email-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .email-heading { font-size: 26px !important; line-height: 33px !important; }
            .email-header-brand, .email-header-label { display: block !important; width: 100% !important; text-align: left !important; }
            .email-header-label { padding-top: 14px !important; }
            .email-button-table { width: 100% !important; max-width: 100% !important; }
            .email-button { display: block !important; width: 100% !important; box-sizing: border-box !important; }
            .info-label, .info-value { display: block !important; width: 100% !important; text-align: left !important; }
            .info-label { padding: 10px 20px 2px !important; }
            .info-value { padding: 0 20px 12px !important; }
        }
    </style>
</head>
<body style="width:100%;margin:0;padding:0;background:#f7f7fa;color:#191b24;font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
<span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;mso-hide:all;">{{ $preheader ?? 'A secure IronCore account update.' }}</span>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:100%;background:#f7f7fa;">
    <tr>
        <td align="center" style="width:100%;padding:32px 12px;box-sizing:border-box;">
            <table role="presentation" class="email-shell" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;margin:0 auto;table-layout:fixed;">
                <tr>
                    <td style="width:100%;max-width:100%;">
                        <table role="presentation" class="email-card" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:100%;table-layout:fixed;background:#ffffff;border:1px solid #e9e9ef;border-radius:18px;overflow:hidden;box-shadow:0 8px 30px rgba(23,20,42,.06);">
                            <tr>
                                <td class="email-pad" style="width:100%;max-width:100%;padding:30px 48px 22px;border-bottom:1px solid #e9e9ef;box-sizing:border-box;">
                                    @include('emails.partials.header')
                                </td>
                            </tr>
                            <tr>
                                <td class="email-pad email-content" style="width:100%;max-width:100%;padding:40px 48px 42px;box-sizing:border-box;overflow-wrap:anywhere;word-wrap:break-word;">
                                    @yield('content')
                                </td>
                            </tr>
                            <tr>
                                <td class="email-pad email-footer" style="width:100%;max-width:100%;padding:26px 48px;background:#fafafd;border-top:1px solid #e9e9ef;box-sizing:border-box;overflow-wrap:anywhere;word-wrap:break-word;">
                                    @include('emails.partials.footer')
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
