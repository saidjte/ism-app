<?php
/**
 * WhatsApp Alert Helper
 * Supports two sending methods:
 * 1. wa.me (click-to-chat - requires manual send)
 * 2. UltraMsg API (fully automatic - sends without user interaction)
 */

if (!defined('APP_VERSION')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/upgrades.php';
}
if (!class_exists('ErrorHandler')) { require_once __DIR__ . '/ErrorHandler.php'; }

/**
 * Generate WhatsApp message text for expiry alerts
 */
function generateWhatsAppMessage($expired_list, $expiring_list, $days_setting, $currency_symbol = 'د.م.') {
    $store_name = '';
    try {
        $store_name = getSetting('store_name', '');
    } catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp_alert::generateMessage_storeName'); }

    $today = date('Y/m/d');
    $msg = "";

    // Header
    $msg .= "🚨 *" . t('whatsapp_expiry_report_title');
    if ($store_name) $msg .= " - " . $store_name;
    $msg .= "*\n";
    $msg .= "📅 " . t('whatsapp_date_label') . " " . $today . "\n";
    $msg .= "━━━━━━━━━━━━━━━\n\n";

    // Expired products
    $expired_count = count($expired_list);
    if ($expired_count > 0) {
        $msg .= "🔴 *" . t('whatsapp_expired_products') . " " . $expired_count . " " . t('products') . "*\n\n";
        $total_loss = 0;
        $i = 1;
        foreach ($expired_list as $ep) {
            $qty = floatval($ep['expired_qty'] ?? 0);
            $val = floatval($ep['expired_value'] ?? 0);
            $total_loss += $val;
            $msg .= $i . ". *" . ($ep['name'] ?? '—') . "*\n";
            $msg .= "   " . t('whatsapp_quantity_label') . " " . number_format($qty, 2) . " " . ($ep['unit'] ?? '') . "\n";
            if ($val > 0) {
                $msg .= "   " . t('whatsapp_loss_label') . " " . number_format($val, 2) . " " . $currency_symbol . "\n";
            }
            $msg .= "\n";
            $i++;
        }
        $msg .= "💰 " . t('whatsapp_total_loss') . " *" . number_format($total_loss, 2) . " " . $currency_symbol . "*\n\n";
    }

    // Expiring soon products
    $expiring_count = count($expiring_list);
    if ($expiring_count > 0) {
        $msg .= "━━━━━━━━━━━━━━━\n\n";
        $msg .= "🟡 *" . t('whatsapp_expiring_soon_products') . " " . $expiring_count . " " . t('products') . "*\n";
        $msg .= "(" . t('whatsapp_within_days') . " " . $days_setting . " " . t('day') . ")\n\n";

        $i = 1;
        foreach ($expiring_list as $es) {
            $qty = floatval($es['expiring_qty'] ?? 0);
            $nearest = $es['nearest_expiry'] ?? '';
            $days_left = $nearest ? intval((strtotime($nearest) - strtotime(date('Y-m-d'))) / 86400) : 0;

            $msg .= $i . ". *" . ($es['name'] ?? '—') . "*\n";
            $msg .= "   " . t('whatsapp_quantity_label') . " " . number_format($qty, 2) . " " . ($es['unit'] ?? '') . "\n";
            if ($nearest) {
                $icon = $days_left <= 0 ? "🚨" : ($days_left <= 7 ? "🔴" : ($days_left <= 14 ? "🟡" : "🟢"));
                $msg .= "   " . $icon . " " . t('whatsapp_remaining') . " " . ($days_left <= 0 ? t('whatsapp_expired_bang') : $days_left . " " . t('day')) . "\n";
            }
            $msg .= "\n";
            $i++;
        }
    }

    // No issues
    if ($expired_count == 0 && $expiring_count == 0) {
        $msg .= "✅ *" . t('whatsapp_no_expired_or_expiring') . "*\n";
        $msg .= t('whatsapp_all_products_good') . "\n";
    }

    $msg .= "\n━━━━━━━━━━━━━━━\n";
    $msg .= "📱 " . t('whatsapp_auto_sent');

    return $msg;
}

/**
 * Generate wa.me link with encoded message
 * @param string $phone - Phone number in international format (e.g., 212600000000)
 * @param string $message - The message text
 * @return string - Full wa.me URL
 */
function generateWhatsAppURL($phone, $message) {
    // Clean phone number - remove all non-digit chars
    $phone = preg_replace('/[^0-9]/', '', $phone);

    // If starts with 0, remove it
    if (strpos($phone, '0') === 0) {
        $phone = substr($phone, 1);
    }

    // URL encode the message
    $encoded_msg = urlencode($message);

    return "https://wa.me/" . $phone . "?text=" . $encoded_msg;
}

/**
 * Generate a short summary message for WhatsApp (when list is too long)
 */
function generateWhatsAppShortMessage($expired_count, $expiring_count, $total_loss, $days_setting, $currency_symbol = 'د.م.', $store_name = '') {
    $today = date('Y/m/d');

    $msg = "🚨 *" . t('whatsapp_expiry_report_title');
    if ($store_name) $msg .= " - " . $store_name;
    $msg .= "*\n";
    $msg .= "📅 " . $today . "\n\n";

    if ($expired_count > 0) {
        $msg .= "🔴 " . t('expiry_expired') . ": *" . $expired_count . "* " . t('product');
        if ($total_loss > 0) {
            $msg .= "\n💰 " . t('whatsapp_loss_label') . " *" . number_format($total_loss, 2) . " " . $currency_symbol . "*";
        }
        $msg .= "\n";
    }

    if ($expiring_count > 0) {
        $msg .= "🟡 " . t('whatsapp_expiring_soon_products') . " *" . $expiring_count . "* " . t('product') . "\n";
        $msg .= "⏰ " . t('whatsapp_within_days') . " " . $days_setting . " " . t('day') . "\n";
    }

    if ($expired_count == 0 && $expiring_count == 0) {
        $msg .= "✅ " . t('nav_no_alerts') . "\n";
    }

    return $msg;
}

/**
 * Fetch expiry data for WhatsApp alert (reusable)
 */
function fetchExpiryDataForWhatsApp() {
    $data = [
        'expired_list' => [],
        'expiring_list' => [],
        'expired_count' => 0,
        'expiring_count' => 0,
        'total_loss' => 0,
        'days_setting' => 30,
        'currency_symbol' => 'د.م.',
        'enabled' => false
    ];

    try {
        $data['enabled'] = (getSetting('expiry_alert_enabled', '1') === '1');
        $data['days_setting'] = intval(getSetting('expiry_alert_days', '30'));
        if ($data['days_setting'] < 1) $data['days_setting'] = 30;
    } catch (Exception $e) {
        return $data;
    }

    if (!$data['enabled']) return $data;

    try {
        $data['currency_symbol'] = getSetting('currency_symbol', 'د.م.');
    } catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp_alert::fetchExpiryData_currency'); }

    $today = date('Y-m-d');
    $expiry_days = date('Y-m-d', strtotime('+' . $data['days_setting'] . ' days'));

    // Without purchase batch expiry tracking, detailed expiry data is unavailable.
    $data['expired_list'] = [];
    $data['expired_count'] = 0;
    $data['total_loss'] = 0;
    $data['expiring_list'] = [];
    $data['expiring_count'] = 0;

    return $data;
}

/**
 * Check if WhatsApp alert should auto-show (once per day)
 */
function shouldShowWhatsAppAlert() {
    if (!isset($_SESSION)) return false;

    $today = date('Y-m-d');
    $last_shown = $_SESSION['whatsapp_alert_shown'] ?? '';

    return ($last_shown !== $today);
}

/**
 * Mark WhatsApp alert as shown for today
 */
function markWhatsAppAlertShown() {
    if (!isset($_SESSION)) return;
    $_SESSION['whatsapp_alert_shown'] = date('Y-m-d');
}

/**
 * Normalize phone number to international format
 * @param string $phone - Phone number (any format)
 * @return string - Clean international format
 */
function normalizePhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($phone, '0') === 0) {
        $phone = substr($phone, 1);
    }
    return $phone;
}

/**
 * Send WhatsApp message via UltraMsg API (fully automatic)
 * @param string $instance_id - UltraMsg instance ID
 * @param string $api_token - UltraMsg API token
 * @param string $phone - Recipient phone (international format, e.g., 212600000000)
 * @param string $message - Message text
 * @return array ['success' => bool, 'message' => string]
 */
function sendUltraMsg($instance_id, $api_token, $phone, $message) {
    $result = ['success' => false, 'message' => ''];

    if (empty($instance_id) || empty($api_token) || empty($phone)) {
        $result['message'] = t('whatsapp_ultramsg_incomplete');
        return $result;
    }

    $phone = normalizePhoneNumber($phone);
    if (strlen($phone) < 10) {
        $result['message'] = t('whatsapp_invalid_phone');
        return $result;
    }

    // Add international prefix if not present
    if (strpos($phone, '212') !== 0 && strpos($phone, '1') !== 0 && strpos($phone, '44') !== 0 && strpos($phone, '33') !== 0 && strpos($phone, '966') !== 0 && strpos($phone, '971') !== 0) {
        $phone = '212' . $phone; // Default to Morocco
    }

    $api_url = "https://api.ultramsg.com/" . $instance_id . "/messages/chat";

    $params = [
        'token' => $api_token,
        'to' => $phone . '@c.us',
        'body' => $message,
    ];

    // Build query string
    $query = http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url . '?' . $query,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        $result['message'] = t('whatsapp_connection_error_ultramsg') . ' ' . $curl_error;
        return $result;
    }

    $decoded = json_decode($response, true);

    if ($http_code == 200 && isset($decoded['status']) && $decoded['status'] == 'success') {
        $result['success'] = true;
        $msg_id = $decoded['id'] ?? '';
        $result['message'] = t('whatsapp_send_success') . ($msg_id ? ' (' . substr($msg_id, 0, 12) . '...)' : '');
    } else {
        $error_desc = $decoded['description'] ?? ($decoded['message'] ?? t('common_error_occurred'));
        $result['message'] = t('whatsapp_send_failed') . ' ' . $error_desc;
    }

    return $result;
}

/**
 * Test UltraMsg connection
 * @param string $instance_id - UltraMsg instance ID
 * @param string $api_token - UltraMsg API token
 * @return array ['success' => bool, 'message' => string, 'connected' => bool]
 */
function testUltraMsgConnection($instance_id, $api_token) {
    $result = ['success' => false, 'message' => '', 'connected' => false];

    if (empty($instance_id) || empty($api_token)) {
        $result['message'] = t('whatsapp_enter_instance_and_token');
        return $result;
    }

    $api_url = "https://api.ultramsg.com/" . $instance_id . "/instance/status";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url . "?token=" . $api_token,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        $result['message'] = t('whatsapp_connection_error') . ' ' . $curl_error;
        return $result;
    }

    $decoded = json_decode($response, true);

    if ($http_code == 200) {
        $status = $decoded['status'] ?? 'unknown';
        if ($status === 'connected') {
            $result['success'] = true;
            $result['connected'] = true;
            $result['message'] = t('whatsapp_connected_successfully');
        } else {
            $result['connected'] = false;
            $status_ar = [
                'notConnected' => t('whatsapp_not_connected_scan_qr'),
                'connecting' => t('whatsapp_connecting'),
                'timeout' => t('whatsapp_connection_timeout'),
            ];
            $result['message'] = t('status') . ': ' . ($status_ar[$status] ?? $status);
        }
    } else {
        $error_desc = $decoded['message'] ?? ($decoded['description'] ?? t('common_error_occurred'));
        $result['message'] = t('error') . ': ' . $error_desc;
    }

    return $result;
}

/**
 * Get the last auto-send timestamp from settings
 */
function getWhatsAppLastAutoSend() {
    try {
        return getSetting('whatsapp_last_autosend', '');
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Save the last auto-send timestamp
 */
function saveWhatsAppLastAutoSend($timestamp = '') {
    try {
        saveSetting('whatsapp_last_autosend', $timestamp ?: date('Y-m-d H:i:s'), t('whatsapp_last_autosend_desc'));
    } catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp_alert::saveLastAutoSend'); }
}

/**
 * Check if auto-send should run today (once per day)
 */
function shouldAutoSendToday() {
    $last = getWhatsAppLastAutoSend();
    if (empty($last)) return true;

    $last_date = substr($last, 0, 10); // Get YYYY-MM-DD part
    return ($last_date !== date('Y-m-d'));
}

/**
 * Send WhatsApp expiry alert automatically
 * Handles both wa.me and UltraMsg methods
 * @return array ['sent' => bool, 'method' => string, 'message' => string]
 */
function sendWhatsAppAlertAuto() {
    $result = ['sent' => false, 'method' => '', 'message' => ''];

    // Check if WhatsApp alerts are enabled
    $wa_enabled = false;
    try {
        $wa_enabled = (getSetting('whatsapp_enabled', '0') === '1');
    } catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp_alert::sendAuto_waEnabled'); }

    if (!$wa_enabled) {
        $result['message'] = t('whatsapp_wa_not_enabled');
        return $result;
    }

    // Check if already sent today
    if (!shouldAutoSendToday()) {
        $result['message'] = t('whatsapp_already_sent_today') . ' ' . getWhatsAppLastAutoSend();
        return $result;
    }

    // Get WhatsApp method
    $method = 'wame';
    try {
        $method = getSetting('whatsapp_method', 'wame');
    } catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp_alert::sendAuto_waMethod'); }

    // Fetch expiry data
    $data = fetchExpiryDataForWhatsApp();
    if (!$data['enabled']) {
        $result['message'] = t('whatsapp_expiry_not_enabled');
        return $result;
    }

    if ($data['expired_count'] == 0 && $data['expiring_count'] == 0) {
        $result['message'] = t('whatsapp_no_alerts_to_send');
        saveWhatsAppLastAutoSend(date('Y-m-d H:i:s') . ' (' . t('nav_no_alerts') . ')');
        return $result;
    }

    // Get phone number
    $phone = '';
    try {
        $phone = trim(getSetting('whatsapp_number', ''));
    } catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp_alert::sendAuto_waPhone'); }

    if (empty($phone)) {
        $result['message'] = t('whatsapp_no_phone_set');
        return $result;
    }

    // Generate message
    $total_items = $data['expired_count'] + $data['expiring_count'];
    $store_name = '';
    try { $store_name = getSetting('store_name', ''); } catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp_alert::sendAuto_storeName'); }

    if ($total_items <= 20) {
        $message = generateWhatsAppMessage($data['expired_list'], $data['expiring_list'], $data['days_setting'], $data['currency_symbol']);
    } else {
        $message = generateWhatsAppShortMessage($data['expired_count'], $data['expiring_count'], $data['total_loss'], $data['days_setting'], $data['currency_symbol'], $store_name);
    }

    // Send based on method
    if ($method === 'ultramsg') {
        $instance_id = '';
        $api_token = '';
        try {
            $instance_id = trim(getSetting('whatsapp_ultramsg_instance', ''));
            $api_token = trim(getSetting('whatsapp_ultramsg_token', ''));
        } catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp_alert::sendAuto_ultramsgCreds'); }

        $send_result = sendUltraMsg($instance_id, $api_token, $phone, $message);
        $result['sent'] = $send_result['success'];
        $result['method'] = 'ultramsg';
        $result['message'] = $send_result['message'];

        if ($send_result['success']) {
            saveWhatsAppLastAutoSend(date('Y-m-d H:i:s'));
        }
    } else {
        // wa.me - generate URL (cannot truly auto-send, just return the URL)
        $wa_url = generateWhatsAppURL($phone, $message);
        $result['method'] = 'wame';
        $result['message'] = t('whatsapp_wame_manual_only');
        $result['url'] = $wa_url;
        $result['sent'] = false;
    }

    return $result;
}
