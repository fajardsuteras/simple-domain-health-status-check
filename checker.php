<?php

/* =========================
   SECURITY KEY
========================= */

// $secret = "CHANGE_THIS_SECRET_KEY";

// if (!isset($_GET['key']) || $_GET['key'] !== $secret) {
//     http_response_code(403);
//     exit("Forbidden");
// }

/* =========================
   EXECUTION SETTINGS
========================= */

set_time_limit(0);
date_default_timezone_set("Asia/Jakarta");

/* =========================
   LOAD CONFIG
========================= */

$config = json_decode(file_get_contents("configs.json"), true);
$targetsData = json_decode(file_get_contents("targets.json"), true);

$targets = $targetsData['targets'];

$timeout = $config['settings']['request_timeout'] ?? 5;
$delay = $config['settings']['delay_between_check'] ?? 200000;

// start running log
writeLog("Cron job started");

/* =========================
   FUNCTION CHECK WEBSITE
========================= */

function checkWebsite($url, $timeout)
{

    $start = microtime(true);

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $curlError = curl_error($ch);

    curl_close($ch);

    $time = round((microtime(true) - $start) * 1000);

    $errorMessage = "";

    if ($curlError) {

        $errorMessage = $curlError;

    } else {

        if ($httpCode >= 400) {
            $errorMessage = "HTTP Error " . $httpCode;
        }

        if ($response) {

            if (
                stripos($response, "fatal error") !== false ||
                stripos($response, "warning") !== false ||
                stripos($response, "exception") !== false ||
                stripos($response, "stack trace") !== false
            ) {
                $errorMessage = "Script error detected";
            }

        } else {

            if (!$errorMessage) {
                $errorMessage = "Empty response";
            }

        }

    }

    $status = ($httpCode == 200 && !$errorMessage) ? "UP" : "DOWN";

    return [
        "status" => $status,
        "http_code" => $httpCode,
        "response_time" => $time,
        "error_message" => $errorMessage
    ];
}


/* =========================
   FUNCTION WRITE LOG
========================= */

function writeLog($message)
{
    $logDir = __DIR__ . "/logs";

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $date = date("Y-m-d");
    $logFile = $logDir . "/cron-" . $date . ".log";

    $time = date("Y-m-d H:i:s");

    $line = "[".$time."] ".$message.PHP_EOL;

    file_put_contents($logFile, $line, FILE_APPEND);
}

/* =========================
   RUN CHECK
========================= */

/* =========================
   RUN CHECK & SEND TELEGRAM
========================= */

$results = [];
$combinedReport = "🔎 System Checker Report (Batch)\n\n";
$separateMessage = $config['settings']['separate_message_per_domain'] ?? false;
$showOnlyError = $config['settings']['show_only_error_notification'] ?? false;
$hasErrors = false;

// Ambil Config Telegram
$botToken = $config['telegram']['bot_token'];
$chatId = $config['telegram']['chat_id'];
$threadId = $config['telegram']['message_thread_id'] ?? null;

foreach ($targets as $target) {

    $result = checkWebsite($target['url'], $timeout);

    $results[] = [
        "name" => $target['name'],
        "url" => $target['url'],
        "status" => $result['status'],
        "http_code" => $result['http_code'],
        "response_time" => $result['response_time'],
        "error_message" => $result['error_message']
    ];

    $emoji = $result['status'] == "UP" ? "✅" : "❌";
    
    // Format pesan untuk satu target
    $singleReport = $emoji . " " . $target['name'] . "\n";
    $singleReport .= "URL: " . $target['url'] . "\n";
    $singleReport .= "Status: " . $result['status'] . " (" . $result['http_code'] . ")\n";
    if ($result['error_message']) {
        $singleReport .= "Error: " . $result['error_message'] . "\n";
    }
    $singleReport .= "Response: " . $result['response_time'] . " ms\n";

    $isError = $result['status'] !== "UP";
    $shouldNotify = !$showOnlyError || $isError;

    if ($shouldNotify) {
        if ($separateMessage) {
            // KIRIM LANGSUNG PER DOMAIN
            $singleReport .= "\nTime: " . date("H:i:s");
            sendTelegram($botToken, $chatId, $singleReport, $threadId);
        } else {
            // GABUNGKAN KE SATU PESAN BESAR
            $combinedReport .= $singleReport . "--------------------------\n";
            $hasErrors = true;
        }
    }

    // Jeda antar target (mikrodetik)
    usleep($delay);
}

/* =========================
   FINAL SEND (IF BATCH)
========================= */

if (!$separateMessage) {
    if (!$showOnlyError || $hasErrors) {
        $combinedReport .= "\nFinal Check: " . date("Y-m-d H:i:s");
        sendTelegram($botToken, $chatId, $combinedReport, $threadId);
    }
}

/* =========================
   HELPER: SEND TELEGRAM FUNCTION
========================= */

function sendTelegram($token, $chatId, $message, $threadId = null) {
    $url = "https://api.telegram.org/bot".$token."/sendMessage";
    $data = [
        "chat_id" => $chatId,
        "text" => $message
    ];

    if ($threadId) {
        $data["message_thread_id"] = $threadId;
    }

    $options = [
        "http" => [
            "header"  => "Content-type: application/x-www-form-urlencoded\r\n",
            "method"  => "POST",
            "content" => http_build_query($data),
        ],
    ];

    $context  = stream_context_create($options);
    return file_get_contents($url, false, $context);
}

/* =========================
   ADD TIMESTAMP
========================= */

$timeNow = date("Y-m-d H:i:s");

$report .= "Time: " . $timeNow;

// /* =========================
//   SEND TELEGRAM
// ========================= */

// $botToken = $config['telegram']['bot_token'];
// $chatId = $config['telegram']['chat_id'];
// $threadId = $config['telegram']['message_thread_id'] ?? null;

// $url = "https://api.telegram.org/bot".$botToken."/sendMessage";

// $data = [
//     "chat_id" => $chatId,
//     "text" => $report
// ];

// if ($threadId) {
//     $data["message_thread_id"] = $threadId;
// }

// $options = [
//     "http" => [
//         "header"  => "Content-type: application/x-www-form-urlencoded\r\n",
//         "method"  => "POST",
//         "content" => http_build_query($data),
//     ],
// ];

// $context  = stream_context_create($options);
// file_get_contents($url, false, $context);

/* =========================
   SAVE STATUS
========================= */

$status = [
    "last_run" => $timeNow,
    "notification_sent" => true,
    "results" => $results
];

file_put_contents("status.json", json_encode($status, JSON_PRETTY_PRINT));

echo "Checker executed successfully";

?>