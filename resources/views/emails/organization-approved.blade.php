<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Organization Approved</title>
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
                            <h2 style="margin: 0 0 16px 0; color:#14213d;">Congratulations, {{ $name }}!</h2>
                            <p style="margin: 0 0 16px 0;">
                                We're happy to let you know that
                                <strong>{{ $organizationName }}</strong> has been reviewed and
                                <strong style="color:#1b9e5c;">approved</strong> on SkillSpan.
                            </p>
                            <p style="margin: 0 0 16px 0;">
                                You can now log in to your organization account and start using
                                all the features available to verified organizations.
                            </p>
                            <p style="margin: 0 0 24px 0;">
                                If you have any questions, feel free to reach out to our support team.
                            </p>
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
