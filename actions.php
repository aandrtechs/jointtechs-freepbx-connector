<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

require_once('/etc/freepbx.conf');

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$name] = $value;
    }
}

$body = file_get_contents('php://input');
$connector = null;
try {
    $connector = FreePBX::Jointtechsconnector();
} catch (\Throwable $exception) {
    if (!class_exists('\FreePBX\modules\Jointtechsconnector')) {
        require_once __DIR__ . '/Jointtechsconnector.class.php';
    }
    try {
        $connector = new \FreePBX\modules\Jointtechsconnector(\FreePBX::Create());
    } catch (\Throwable $constructorException) {
        $connector = new \FreePBX\modules\Jointtechsconnector();
    }
}

$connector->handleInboundAction($body ?: '', $headers);
