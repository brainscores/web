<?php
// email_config.php template

// Replace with your actual API key
define('SENDGRID_API_KEY', 'YOUR_SENDGRID_API_KEY');
define('SENDGRID_SENDER_EMAIL', 'system@brainscores.ai');
define('SENDGRID_SENDER_NAME', 'BrainScores System');

/**
 * Send an email via SendGrid Web API (v3) using CURL.
 * 
 * @param string $to_email
 * @param string $subject
 * @param string $content_text
 * @param string $reply_to_email (optional)
 * @return array ['success' => bool, 'message' => string]
 */
function send_email_via_sendgrid($to_email, $subject, $content_text, $reply_to_email = null) {
    if (SENDGRID_API_KEY === 'YOUR_SENDGRID_API_KEY') {
        error_log("SendGrid Error: API Key not configured.");
        return ['success' => false, 'message' => "API Key not configured."];
    }

    $url = 'https://api.sendgrid.com/v3/mail/send';

    $data = [
        'personalizations' => [
            [
                'to' => [
                    ['email' => $to_email]
                ],
                'subject' => $subject
            ]
        ],
        'from' => [
            'email' => SENDGRID_SENDER_EMAIL,
            'name'  => SENDGRID_SENDER_NAME
        ],
        'content' => [
            [
                'type'  => 'text/plain',
                'value' => $content_text
            ]
        ]
    ];

    if ($reply_to_email) {
        $data['reply_to'] = [
            'email' => $reply_to_email
        ];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . SENDGRID_API_KEY,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        return ['success' => true, 'message' => 'Email queued successfully.'];
    } else {
        error_log("SendGrid Error: HTTP $http_code. Response: $response. Curl Error: $curl_error");
        return ['success' => false, 'message' => "Failed to send email. Code: $http_code. Response: $response"];
    }
}
?>
