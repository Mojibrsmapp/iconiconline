<?php

// Old external lookup integrations used unverified providers and exposed credentials.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'error',
    'success' => false,
    'message' => 'এই সেবাটি বর্তমানে উপলব্ধ নয়।',
], JSON_UNESCAPED_UNICODE);
exit;
