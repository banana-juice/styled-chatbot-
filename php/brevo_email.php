<?php
require_once __DIR__ . '/config/env.php';

function sendEmailViaBrevo($toEmail, $toName, $subject, $htmlContent, $textContent = '') {
    loadEnv();
    // Real key comes from .env (BREVO_API_KEY) — never hardcode it here.
    $apiKey = getenv('BREVO_API_KEY') ?: 'BREVO_API_KEY';

    $data = [
        'sender' => [
            'name' => 'Styled',
            'email' => 'orders.styledph@gmail.com'
        ],
        'to' => [
            ['email' => $toEmail, 'name' => $toName]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
        'textContent' => $textContent ?: strip_tags($htmlContent)
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 201) {
        return ['success' => true];
    } else {
        $errorMsg = "HTTP $httpCode: $response";
        if ($curlError) $errorMsg .= " | CURL: $curlError";
        return ['success' => false, 'error' => $errorMsg];
    }
}
?>