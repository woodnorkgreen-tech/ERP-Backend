<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $payload['title'] ?? 'ERP Notification' }}</title>
</head>
<body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #e5e7eb;">
                    <tr>
                        <td style="padding:18px 24px;border-top:5px solid {{ $accent }};">
                            <div style="font-size:13px;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;">ERP System</div>
                            <div style="font-size:12px;color:#9ca3af;margin-top:4px;">{{ strtoupper($payload['module'] ?? 'system') }} notification</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <span style="display:inline-block;background:{{ $accent }};color:#ffffff;font-size:12px;font-weight:bold;text-transform:uppercase;padding:6px 10px;border-radius:4px;">
                                {{ $payload['urgency'] ?? 'info' }}
                            </span>

                            <h1 style="font-size:22px;line-height:1.3;margin:18px 0 10px;color:#111827;">
                                {{ $payload['title'] ?? 'Notification' }}
                            </h1>

                            <p style="font-size:15px;line-height:1.6;margin:0 0 22px;color:#4b5563;">
                                {{ $payload['message'] ?? '' }}
                            </p>

                            @if(!empty($payload['data']) && is_array($payload['data']))
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:20px 0;border:1px solid #e5e7eb;">
                                    @foreach($payload['data'] as $key => $value)
                                        @continue(is_array($value) || is_object($value))
                                        <tr>
                                            <td style="width:34%;padding:10px 12px;border-bottom:1px solid #e5e7eb;background:#f9fafb;color:#6b7280;font-size:13px;">
                                                {{ str_replace('_', ' ', ucfirst($key)) }}
                                            </td>
                                            <td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#111827;font-size:13px;">
                                                {{ $value }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if(!empty($payload['data']['url']))
                                <p style="margin:22px 0 0;">
                                    <a href="{{ url($payload['data']['url']) }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;padding:11px 16px;border-radius:4px;">
                                        View notification
                                    </a>
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;background:#f9fafb;color:#6b7280;font-size:12px;line-height:1.5;">
                            You received this because this notification was sent to your ERP account.
                            Manage your notification preferences inside the ERP notification hub.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
