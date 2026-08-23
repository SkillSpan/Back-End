<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Password Reset</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f5f7; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7; padding: 30px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius: 8px; overflow: hidden;">
                    <tr>
                        <td align="center" style="padding: 32px 32px 16px 32px;">
                            <img src="{{ $logoUrl }}" alt="SkillSpan" width="140" style="display:block;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 32px 32px 32px; color:#222222; line-height:1.6;">
                            <h2 style="margin: 0 0 16px 0; color:#14213d;">Hello, {{ $name }}!</h2>
                            <p style="margin: 0 0 16px 0;">We received a request to reset the password for your SkillSpan account.</p>
                            <p style="margin: 0 0 16px 0;">Please use the following code to reset your password:</p>
                            <p style="margin: 0 0 16px 0; font-size: 32px; font-weight: bold; letter-spacing: 6px; color:#1b5fae;">{{ $otp }}</p>
                            <p style="margin: 0 0 16px 0;">This code is valid for <strong>{{ $minutes }} minutes</strong>.</p>
                            <p style="margin: 0 0 24px 0;">If you did not request a password reset, you can safely ignore this email.</p>
                            <hr style="border:none; border-top:1px solid #eeeeee; margin: 0 0 16px 0;">
                            <p style="margin:0; font-size:12px; color:#888888;">Best regards, The SkillSpan Team</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
