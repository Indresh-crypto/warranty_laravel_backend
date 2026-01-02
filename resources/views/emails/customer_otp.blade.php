<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>OTP Verification</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f4f4f4; padding:20px;">
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table width="600" style="background:#ffffff; padding:20px; border-radius:8px;">
                    <tr>
                        <td>
                            <h2>Hello {{ $name }},</h2>
                            <p>Your One-Time Password (OTP) for login is:</p>

                            <h1 style="letter-spacing:4px; color:#2d89ef;">
                                {{ $otp }}
                            </h1>

                            <p>This OTP is valid for <strong>5 minutes</strong>.</p>

                            <p>If you did not request this login, please ignore this email.</p>

                            <br>
                            <p>Regards,<br><strong>Warranty Support Team</strong></p>
                        </td>
                    </tr>
                </table>

                <p style="font-size:12px; color:#999; margin-top:10px;">
                    This is an automated email. Please do not reply.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
