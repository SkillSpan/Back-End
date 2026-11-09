<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Organization Registration Update</title>
</head>
<body style="margin:0; padding:0; background-color:#F0F6FC; font-family: Inter, 'Segoe UI', Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F0F6FC; padding: 32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border: 1px solid #E4EBF5; border-radius: 12px; overflow: hidden;">
                    <tr>
                        <td align="center" style="padding: 32px 32px 16px 32px;">
                            <img src="{{ $logoUrl }}" alt="SkillSpan" width="140" style="display:block;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 32px 32px 32px; color:#3B4565; font-size:15px; line-height:1.6;">
                            <h2 style="margin: 0 0 16px 0; color:#05103D; font-size:20px; font-weight:600;">Hello, {{ $name }},</h2>
                            <p style="margin: 0 0 16px 0;">
                                After reviewing your registration for
                                <strong>{{ $organizationName }}</strong>, we're unable to
                                approve it at this time.
                            </p>
                            @if($reason)
                                <p style="margin: 0 0 16px 0; padding: 12px 16px; background-color:#FDECEC; border-radius:10px; color:#B02E2E;">
                                    <strong>Reason:</strong> {{ $reason }}
                                </p>
                            @endif
                            <p style="margin: 0 0 16px 0;">
                                You're welcome to update your registration details or proof
                                document and submit a new request. If you believe this was a
                                mistake, please contact our support team.
                            </p>
                            <hr style="border:none; border-top:1px solid #E4EBF5; margin: 0 0 16px 0;">
                            <p style="margin:0; font-size:12px; color:#6B7593;">Best regards, The SkillSpan Team</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
