<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Password Reset</title>
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
                            <h2 style="margin: 0 0 16px 0; color:#05103D; font-size:20px; font-weight:600;">Hello, {{ $name }}!</h2>
                            <p style="margin: 0 0 16px 0;">We received a request to reset the password for your SkillSpan account.</p>
                            <p style="margin: 0 0 16px 0;">Please use the following code to reset your password:</p>
                            <p style="margin: 0 0 16px 0; font-size: 32px; font-weight: bold; letter-spacing: 6px; color:#1667E0; background-color:#E2EEFC; border-radius:10px; padding: 14px 0; text-align:center;">{{ $otp }}</p>
                            <p style="margin: 0 0 16px 0;">This code is valid for <strong>{{ $minutes }} minutes</strong>.</p>
                            <p style="margin: 0 0 24px 0;">If you did not request a password reset, you can safely ignore this email.</p>
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
