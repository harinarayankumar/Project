<?php
/**
 * Contact Form Handler for GEC Vaishali E-Cell
 * Author: Hari Narayan
 * Description: Handles contact form submissions and email notifications
 */

// Set content type for JSON response
header('Content-Type: application/json');

// Enable CORS if needed (adjust origin as needed)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit;
}

// Configuration
$config = [
    'email' => [
        'to' => 'ecell@gecvaishali.ac.in',
        'from' => 'noreply@gecvaishali.ac.in',
        'from_name' => 'GEC Vaishali E-Cell Website'
    ],
    'file_storage' => 'contacts/',
    'max_message_length' => 2000,
    'required_fields' => ['first_name', 'last_name', 'email', 'subject', 'message']
];

// Create contacts directory if it doesn't exist
if (!file_exists($config['file_storage'])) {
    mkdir($config['file_storage'], 0755, true);
}

try {
    // Sanitize and validate input data
    $data = sanitizeInput($_POST);
    
    // Validate required fields
    $validation = validateInput($data, $config);
    if (!$validation['valid']) {
        throw new Exception($validation['message']);
    }
    
    // Additional security checks
    if (detectSpam($data)) {
        throw new Exception('Spam detected. Please try again later.');
    }
    
    // Process the form submission
    $submission = processSubmission($data, $config);
    
    // Send email notification
    $emailSent = sendEmailNotification($data, $config);
    
    // Store submission to file
    $stored = storeSubmission($data, $config);
    
    // Prepare success response
    $response = [
        'success' => true,
        'message' => 'Thank you for your message! We will get back to you soon.',
        'submission_id' => $submission['id'],
        'email_sent' => $emailSent,
        'stored' => $stored
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Log error (in production, use proper logging)
    error_log('Contact form error: ' . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Sanitize input data
 */
function sanitizeInput($input) {
    $sanitized = [];
    
    foreach ($input as $key => $value) {
        if (is_string($value)) {
            // Remove potentially harmful characters
            $value = trim($value);
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $value = filter_var($value, FILTER_SANITIZE_STRING);
        }
        $sanitized[$key] = $value;
    }
    
    return $sanitized;
}

/**
 * Validate input data
 */
function validateInput($data, $config) {
    $errors = [];
    
    // Check required fields
    foreach ($config['required_fields'] as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }
    
    // Validate email
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    // Validate message length
    if (!empty($data['message']) && strlen($data['message']) > $config['max_message_length']) {
        $errors[] = 'Message is too long. Maximum ' . $config['max_message_length'] . ' characters allowed.';
    }
    
    // Validate name fields (no numbers or special characters)
    if (!empty($data['first_name']) && !preg_match('/^[a-zA-Z\s]+$/', $data['first_name'])) {
        $errors[] = 'First name should only contain letters and spaces.';
    }
    
    if (!empty($data['last_name']) && !preg_match('/^[a-zA-Z\s]+$/', $data['last_name'])) {
        $errors[] = 'Last name should only contain letters and spaces.';
    }
    
    // Validate phone number if provided
    if (!empty($data['phone']) && !preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $data['phone'])) {
        $errors[] = 'Please enter a valid phone number.';
    }
    
    return [
        'valid' => empty($errors),
        'message' => empty($errors) ? 'Valid' : implode(' ', $errors)
    ];
}

/**
 * Basic spam detection
 */
function detectSpam($data) {
    $spamWords = ['viagra', 'casino', 'loan', 'mortgage', 'pharmacy', 'replica', 'rolex'];
    $text = strtolower($data['message'] . ' ' . $data['first_name'] . ' ' . $data['last_name']);
    
    foreach ($spamWords as $word) {
        if (strpos($text, $word) !== false) {
            return true;
        }
    }
    
    // Check for excessive links
    if (preg_match_all('/http[s]?:\/\//', $data['message']) > 2) {
        return true;
    }
    
    // Check for suspicious patterns
    if (strlen($data['message']) < 10 && preg_match('/[0-9]{10,}/', $data['message'])) {
        return true;
    }
    
    return false;
}

/**
 * Process form submission
 */
function processSubmission($data, $config) {
    $submission = [
        'id' => uniqid('contact_', true),
        'timestamp' => date('Y-m-d H:i:s'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'data' => $data
    ];
    
    return $submission;
}

/**
 * Send email notification
 */
function sendEmailNotification($data, $config) {
    $to = $config['email']['to'];
    $subject = '[E-Cell Contact] ' . $data['subject'];
    
    // Create email content
    $message = "New contact form submission from GEC Vaishali E-Cell website:\n\n";
    $message .= "Name: " . $data['first_name'] . " " . $data['last_name'] . "\n";
    $message .= "Email: " . $data['email'] . "\n";
    
    if (!empty($data['phone'])) {
        $message .= "Phone: " . $data['phone'] . "\n";
    }
    
    $message .= "Subject: " . $data['subject'] . "\n";
    $message .= "Newsletter: " . (isset($data['newsletter']) ? 'Yes' : 'No') . "\n\n";
    $message .= "Message:\n" . $data['message'] . "\n\n";
    $message .= "---\n";
    $message .= "Submitted on: " . date('Y-m-d H:i:s') . "\n";
    $message .= "IP Address: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
    
    // Email headers
    $headers = [
        'From: ' . $config['email']['from_name'] . ' <' . $config['email']['from'] . '>',
        'Reply-To: ' . $data['email'],
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    // Attempt to send email
    $emailSent = mail($to, $subject, $message, implode("\r\n", $headers));
    
    // Also send auto-reply to user
    if ($emailSent) {
        sendAutoReply($data, $config);
    }
    
    return $emailSent;
}

/**
 * Send auto-reply to user
 */
function sendAutoReply($data, $config) {
    $subject = 'Thank you for contacting GEC Vaishali E-Cell';
    
    $message = "Dear " . $data['first_name'] . ",\n\n";
    $message .= "Thank you for reaching out to GEC Vaishali E-Cell. We have received your message and will get back to you within 2-3 business days.\n\n";
    $message .= "Your submitted details:\n";
    $message .= "Subject: " . $data['subject'] . "\n";
    $message .= "Message: " . substr($data['message'], 0, 100) . "...\n\n";
    $message .= "In the meantime, feel free to explore our website and follow us on social media for the latest updates on our programs and events.\n\n";
    $message .= "Best regards,\n";
    $message .= "GEC Vaishali E-Cell Team\n\n";
    $message .= "---\n";
    $message .= "This is an automated message. Please do not reply to this email.\n";
    $message .= "For urgent matters, please contact us directly at " . $config['email']['to'];
    
    $headers = [
        'From: ' . $config['email']['from_name'] . ' <' . $config['email']['from'] . '>',
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    return mail($data['email'], $subject, $message, implode("\r\n", $headers));
}

/**
 * Store submission to file
 */
function storeSubmission($data, $config) {
    $filename = $config['file_storage'] . 'submissions_' . date('Y-m') . '.json';
    
    // Create submission record
    $submission = [
        'id' => uniqid('contact_', true),
        'timestamp' => date('c'), // ISO 8601 format
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'data' => $data
    ];
    
    // Load existing submissions
    $submissions = [];
    if (file_exists($filename)) {
        $content = file_get_contents($filename);
        $submissions = json_decode($content, true) ?: [];
    }
    
    // Add new submission
    $submissions[] = $submission;
    
    // Save to file
    $saved = file_put_contents($filename, json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Also create a backup
    if ($saved) {
        $backupFile = $config['file_storage'] . 'backup_' . date('Y-m-d_H-i-s') . '_' . $submission['id'] . '.json';
        file_put_contents($backupFile, json_encode($submission, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    return $saved !== false;
}

/**
 * Rate limiting (basic implementation)
 */
function checkRateLimit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateLimitFile = 'contacts/rate_limit.json';
    $maxSubmissions = 5; // Max submissions per hour
    $timeWindow = 3600; // 1 hour in seconds
    
    $rateLimits = [];
    if (file_exists($rateLimitFile)) {
        $rateLimits = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    }
    
    $now = time();
    $ipData = $rateLimits[$ip] ?? ['count' => 0, 'first_request' => $now];
    
    // Reset counter if time window has passed
    if ($now - $ipData['first_request'] > $timeWindow) {
        $ipData = ['count' => 0, 'first_request' => $now];
    }
    
    $ipData['count']++;
    $rateLimits[$ip] = $ipData;
    
    // Clean old entries
    foreach ($rateLimits as $checkIp => $data) {
        if ($now - $data['first_request'] > $timeWindow) {
            unset($rateLimits[$checkIp]);
        }
    }
    
    file_put_contents($rateLimitFile, json_encode($rateLimits));
    
    return $ipData['count'] <= $maxSubmissions;
}
?>
