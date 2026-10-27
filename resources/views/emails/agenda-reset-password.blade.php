<!doctype html>
<html lang="{{ $language }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $copy['subject'] }}</title></head>
<body style="margin:0;padding:0;background:#f5f4f8;color:#242033;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f4f8;"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;">
<tr><td style="padding:0 0 24px;font-size:22px;font-weight:bold;color:#6338f6;">Virtus <span style="color:#242033;">Agenda</span></td></tr>
<tr><td style="background:#ffffff;border-top:4px solid #6338f6;padding:32px 24px;">
<h1 style="margin:0 0 20px;font-size:26px;line-height:1.3;">{{ $copy['title'] }}</h1>
<p style="font-size:16px;line-height:1.6;margin:0 0 24px;">{{ $copy['intro'] }}</p>
<table role="presentation" cellspacing="0" cellpadding="0"><tr><td bgcolor="#6338f6" style="border-radius:6px;">
<a href="{{ $url }}" style="display:inline-block;padding:15px 24px;color:#ffffff !important;text-decoration:none;font-size:16px;font-weight:bold;">{{ $copy['button'] }}</a>
</td></tr></table>
<p style="font-size:14px;line-height:1.6;margin:24px 0 8px;">{{ $copy['expiry'] }}</p>
<p style="font-size:14px;line-height:1.6;margin:0 0 24px;">{{ $copy['minimum'] }}</p>
<p style="font-size:14px;line-height:1.6;color:#625d70;margin:0;">{{ $copy['ignore'] }}</p>
<hr style="border:0;border-top:1px solid #eceaf1;margin:24px 0;">
<p style="font-size:12px;line-height:1.6;color:#625d70;margin:0 0 8px;">{{ $copy['alternative'] }}</p>
<p style="font-size:12px;line-height:1.6;word-break:break-all;margin:0;"><a href="{{ $url }}" style="color:#6338f6;">{{ $url }}</a></p>
</td></tr>
<tr><td style="padding:20px 0;font-size:12px;color:#625d70;">Virtus Agenda</td></tr>
</table></td></tr></table>
</body></html>
