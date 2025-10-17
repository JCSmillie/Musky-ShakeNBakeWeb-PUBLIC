<?php
// Must be a POST request, otherwise block
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit('Forbidden');
}
?>

<?php
// submit_problem.php
// Handles Problem Reports: Save locally, Email, (optional) Slack notification
// Also logs 🐾 MuskyPoof Easter Egg to device_manager_log.txt

// ==========================
// Load Configurations
// ==========================
include 'config.php'; // Email addresses, Slack settings, log paths

// ==========================
// Helper: Send message to Slack (if enabled)
// ==========================
function send_slack_message($message) {
    global $ENABLE_SLACK, $SLACK_WEBHOOK_URL;

    if (!$ENABLE_SLACK || empty($SLACK_WEBHOOK_URL)) {
        return;
    }

    $payload = json_encode(["text" => $message]);

    $ch = curl_init($SLACK_WEBHOOK_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// ==========================
// Handle POST Request
// ==========================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $screenshot = $_POST['screenshot'] ?? '';
    $feedback = $_POST['feedback'] ?? '';
    $serial = $_POST['serial'] ?? 'UNKNOWN_SERIAL';
    $assettag = $_POST['assettag'] ?? 'UNKNOWN_ASSETTAG';
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
    $authenticatedUser = $_SESSION['musky_user']['email'] ?? ($_SERVER['REMOTE_USER'] ?? 'Unknown User');

    if (empty($screenshot) || empty($feedback)) {
        http_response_code(400);
        echo "Missing required fields.";
        exit;
    }

    // Clean and decode screenshot
    $screenshot = preg_replace('#^data:image/\w+;base64,#i', '', $screenshot);
    $screenshotData = base64_decode($screenshot);

    if ($screenshotData === false) {
        http_response_code(400);
        echo "Failed to decode screenshot.";
        exit;
    }

    // Save Screenshot Locally
    $timestamp = date('Ymd_His');
    $screenshotFilename = rtrim($LOG_PATH, '/') . "/problem_screenshot_{$assettag}_{$timestamp}.png";
    file_put_contents($screenshotFilename, $screenshotData);

    // Save Feedback Log Locally (separate log for detailed reports)
    $reportMessage = "[" . date('Y-m-d H:i:s') . "] "
                   . "Problem Reported | Asset: {$assettag} | Serial: {$serial}\n"
                   . "Feedback: {$feedback}\n"
                   . "Screenshot saved: {$screenshotFilename}\n"
                   . "-------------------------------------------\n";

    file_put_contents(rtrim($LOG_PATH, '/') . '/problem_reports_log.txt', $reportMessage, FILE_APPEND | LOCK_EX);

    // ==========================
    // 🐾 MuskyPoof Easter Egg Log (goes into device_manager_log.txt)
    // ==========================
    $dmLog = rtrim($LOG_PATH, '/') . '/device_manager_log.txt';
    $ts = date('[Y-m-d H:i:s]');
    $entry = "$ts IP: $clientIP User: $authenticatedUser 🐾 MuskyPoof triggered | Asset: $assettag | Serial: $serial\n";
    file_put_contents($dmLog, $entry, FILE_APPEND | LOCK_EX);

    // ==========================
    // Send Slack Notification (if enabled)
    // ==========================
    $slackMessage = "🔔 *Problem Reported*\n"
                  . "*Asset Tag:* {$assettag}\n"
                  . "*Serial:* {$serial}\n"
                  . "IP Address: {$clientIP}\n"
                  . "Authenticated User: {$authenticatedUser}\n"
                  . "*Feedback:* {$feedback}";
    send_slack_message($slackMessage);

    // ==========================
    // Send Email with Attachment
    // ==========================
    $to = $PROBLEM_REPORT_EMAIL;
    $from = $EMAIL_SENDER_ADDRESS;
    $subject = "Device Manager Problem Report";
    $boundary = md5(time());

    $headers = "From: $from\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= "Device Info:\n";
    $body .= "Asset Tag: $assettag\n";
    $body .= "Serial Number: $serial\n\n";
    $body .= "User Client IP:\n$clientIP\n\n";
    $body .= "User Logged In:\n$authenticatedUser\n\n";
    $body .= "User Feedback:\n$feedback\n\n";	
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: image/png; name=\"screenshot.png\"\r\n";
    $body .= "Content-Disposition: attachment; filename=\"screenshot.png\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($screenshotData));
    $body .= "--$boundary--";

    if (mail($to, $subject, $body, $headers)) {
        echo "OK";
    } else {
        http_response_code(500);
        echo "Failed to send email.";
    }

} else {
    http_response_code(405);
    echo "Method Not Allowed";
}
?>