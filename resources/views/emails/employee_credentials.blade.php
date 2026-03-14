<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Employee Account Created</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, Helvetica, sans-serif;">

  <!-- Wrapper -->
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8; padding:20px 0;">
    <tr>
      <td align="center">

        <!-- Card -->
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.08); overflow:hidden;">

          <!-- Header -->
          <tr>
            <td style="background:#1d4ed8; padding:20px; text-align:center;">
              <h1 style="margin:0; font-size:22px; color:#ffffff;">
                Employee Account Created
              </h1>
              <p style="margin:6px 0 0; font-size:13px; color:#e0e7ff;">
                Welcome to {{ config('app.name') }}
              </p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:24px 28px; color:#111827; font-size:14px; line-height:1.6;">

              <p style="margin-top:0;">
                Hello <strong>{{ $employee->first_name }}</strong>,
              </p>

              <p>
                Your employee account has been successfully created.  
                Below are your login credentials:
              </p>

              <!-- Credentials Box -->
              <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:6px; margin:18px 0;">
                <tr>
                  <td style="padding:16px;">

                    <p style="margin:6px 0;">
                      <strong>Employee ID:</strong> {{ $employee->employee_id }}
                    </p>

                    <p style="margin:6px 0;">
                      <strong>Phone:</strong> {{ $employee->personal_phone }}
                    </p>

                    <p style="margin:6px 0;">
                      <strong>Email:</strong> {{ $employee->official_email }}
                    </p>

                    <p style="margin:6px 0;">
                      <strong>Password:</strong>
                      <span style="background:#111827; color:#ffffff; padding:4px 8px; border-radius:4px; font-family:monospace;">
                        {{ $password }}
                      </span>
                    </p>

                  </td>
                </tr>
              </table>

              <!-- Security Note -->
              <p style="background:#fff7ed; border-left:4px solid #f97316; padding:10px 12px; font-size:13px; border-radius:4px;">
                🔐 <strong>Security Notice:</strong><br>
                Please change your password immediately after your first login.
              </p>

              <p style="margin-bottom:0;">
                If you have any questions or face issues while logging in, please contact your administrator.
              </p>

            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f9fafb; padding:14px 20px; text-align:center; font-size:12px; color:#6b7280;">
              <p style="margin:0;">
                © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
              </p>
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>

</body>
</html>