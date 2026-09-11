<?php
require_once __DIR__ . '/mail_config.php';

/**
 * Send an email via Brevo REST API using native PHP stream context
 */
function sendBrevoEmail(string $toEmail, string $toName, string $subject, string $htmlContent): array {
    $apiKey = defined('BREVO_API_KEY') ? BREVO_API_KEY : '';
    $senderEmail = defined('BREVO_SENDER_EMAIL') ? BREVO_SENDER_EMAIL : 'joyobratadas.85912@gmail.com';
    $senderName  = defined('BREVO_SENDER_NAME') ? BREVO_SENDER_NAME : 'SDSF Faculty Portal';

    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'Brevo API Key is missing.'];
    }

    $payload = [
        'sender' => [
            'name'  => $senderName,
            'email' => $senderEmail
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name'  => $toName ?: $toEmail
            ]
        ],
        'subject'     => $subject,
        'htmlContent' => $htmlContent
    ];

    $jsonPayload = json_encode($payload);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "api-key: {$apiKey}\r\n" .
                         "Content-Type: application/json\r\n" .
                         "Accept: application/json\r\n" .
                         "User-Agent: SDSF-Faculty-Portal\r\n" .
                         "Content-Length: " . strlen($jsonPayload) . "\r\n",
            'content' => $jsonPayload,
            'timeout' => 15,
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($opts);
    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);

    if ($response === false) {
        $err = error_get_last();
        return ['success' => false, 'error' => $err['message'] ?? 'Network request to Brevo API failed.'];
    }

    $resData = json_decode($response, true);
    if (isset($resData['messageId'])) {
        return ['success' => true, 'messageId' => $resData['messageId']];
    } else {
        $errMsg = $resData['message'] ?? 'Unknown error from Brevo API';
        return ['success' => false, 'error' => $errMsg, 'raw' => $response];
    }
}

/**
 * Send an official OTP Email
 */
function sendOtpEmail(string $toEmail, string $toName, string $otp, string $userType = 'Faculty'): array {
    $subject = "SDSF Portal — Your OTP Verification Code: {$otp}";
    $greeting = !empty($toName) ? "Hello " . htmlspecialchars($toName) : "Hello";

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .card { max-width: 540px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #1e3a8a 0%, #4f46e5 100%); color: #ffffff; padding: 28px 24px; text-align: center; }
  .header h1 { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: 0.5px; }
  .header p { margin: 4px 0 0 0; font-size: 12.5px; color: #cbd5e1; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .intro { font-size: 14px; color: #475569; line-height: 1.6; margin-bottom: 24px; }
  .otp-box { background: #f8fafc; border: 2px dashed #6366f1; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 24px; }
  .otp-label { font-size: 12px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; color: #64748b; margin-bottom: 6px; }
  .otp-code { font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #1e3a8a; font-family: monospace; }
  .expiry { font-size: 13px; color: #dc2626; margin-top: 8px; font-weight: 600; }
  .security-note { font-size: 12px; color: #64748b; line-height: 1.5; border-top: 1px solid #f1f5f9; padding-top: 16px; }
  .footer { background: #f8fafc; padding: 18px 24px; text-align: center; font-size: 11.5px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <h1>Devi Ahilya Vishwavidyalaya, Indore</h1>
    <p>School of Data Science &amp; Forecasting (SDSF) — Faculty Portal</p>
  </div>
  <div class="body">
    <div class="greeting">{$greeting},</div>
    <div class="intro">
      We received a request to verify your {$userType} account credentials and update your portal password. Please use the following One-Time Password (OTP) to complete the verification:
    </div>
    <div class="otp-box">
      <div class="otp-label">Verification OTP Code</div>
      <div class="otp-code">{$otp}</div>
      <div class="expiry">&#9201; Valid for 10 minutes only</div>
    </div>
    <div class="security-note">
      <strong>Important Security Notice:</strong> If you did not initiate this password reset request, please ignore this email or notify the SDSF department administrator immediately. Never share your OTP with anyone.
    </div>
  </div>
  <div class="footer">
    Devi Ahilya Vishwavidyalaya &bull; School of Data Science &amp; Forecasting, Takshashila Campus, Khandwa Road, Indore (M.P.)
  </div>
</div>
</body>
</html>
HTML;

    return sendBrevoEmail($toEmail, $toName, $subject, $html);
}
