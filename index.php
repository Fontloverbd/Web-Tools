<?php
/**
 * ============================================================
 *  Code Utility Telegram Bot — Single-file (Render & GitHub Ready)
 * ============================================================
 */

// ============================================================
// 1. CONFIGURATION (Environment Variables Only)
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

// Fail fast (and loudly, in logs) if no token was configured, instead of
// silently doing nothing on every request.
if (BOT_TOKEN === '') {
    error_log('FATAL: BOT_TOKEN environment variable is not set.');
    http_response_code(500);
    exit;
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
            error_log('tgApi cURL error [' . $method . ']: ' . $err);
            return null;
        }
        $decoded = json_decode($result, true);
        if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
            error_log('tgApi Telegram error [' . $method . ']: ' . ($decoded['description'] ?? 'unknown'));
        }
        return $decoded;
    }
    error_log('tgApi error: curl extension not available.');
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
        // FIX: single mode now accumulates parts just like multi mode,
        // instead of a single overwritten string.
        'single_code_parts' => [],
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

function buttonColorHelpText() {
    return
        "📚 <b>Button Color — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি আপনার PHP কোডের ভেতরে থাকা Telegram বাটনগুলোতে (Reply Keyboard ও Inline Keyboard — উভয় ধরনের) " .
        "স্বয়ংক্রিয়ভাবে <b>style</b> (danger, success, primary) যুক্ত করে দেয়, একটার পর একটা ক্রমানুসারে।\n\n" .
        "<b>ধাপ ১:</b> 📝 <u>Code Submit</u> বাটনে চাপুন।\n" .
        "<b>ধাপ ২:</b> আপনার PHP কোড পাঠান। কোড অনেক বড় হলে একাধিক মেসেজে ভাগ করে পাঠাতে পারেন — বট প্রতিটি অংশ জমা রাখবে এবং শেষে সব অংশ নিজে থেকেই জোড়া লাগিয়ে নেবে (কোনো অংশ হারাবে না)। যতগুলো অংশ জমা হয়েছে তা প্রতিবার মেসেজে জানিয়ে দেওয়া হবে।\n" .
        "<b>ধাপ ৩:</b> কোড পাঠানো শেষ হলে 🎨 <u>Create Color</u> বাটনে চাপুন।\n" .
        "  • বট আপনার কোডের ভেতরে খুঁজে বের করবে কোন অ্যারেতে <code>'text' => '...'</code> আছে (এটাই বাটনের মূল চিহ্ন — Reply ও Inline দুই ধরনের বাটনেই থাকে)।\n" .
        "  • যেসব বাটন অ্যারেতে আগে থেকেই <code>'style'</code> কী দেওয়া নেই, সেখানে ক্রমানুসারে <code>danger → success → primary</code> style যুক্ত হবে।\n" .
        "  • যেখানে আগে থেকেই style দেওয়া আছে, সেটা স্পর্শ করা হবে না।\n" .
        "<b>ধাপ ৪:</b> কালার করা কোডসহ একটি <code>bot.php</code> ফাইল আপনাকে এবং টার্গেট গ্রুপে পাঠানো হবে।\n" .
        "<b>ধাপ ৫:</b> পরে আবার একই ফাইল পেতে চাইলে 📥 <u>Download PHP</u> বাটনে চাপুন — শেষ তৈরি করা রেজাল্ট আবার পাঠানো হবে।\n\n" .
        "⚠️ <b>মনে রাখবেন:</b> নতুন করে কোড পাঠানো শুরু করলে (আবার Code Submit চাপলে) আগের জমা করা অংশগুলো মুছে নতুন করে শুরু হয়।\n\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

function codeToFileHelpText() {
    return
        "📚 <b>Code To File — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি দিয়ে আপনি যেকোনো কোড টেক্সট থেকে সরাসরি ডাউনলোডযোগ্য ফাইল (PHP, HTML, PY বা ZIP) তৈরি করতে পারবেন। এখানে দুইটি মোড আছে — 📝 <b>Single Mode</b> এবং 📚 <b>Multi Mode</b>।\n\n" .
        "🔹 <b>Single Mode</b> — একটিমাত্র ফাইলের জন্য কোড জমা দিতে:\n" .
        "  ১. 📝 Singel Mode বাটনে চাপুন।\n" .
        "  ২. কোড পাঠান। কোড বড় হলে একাধিক মেসেজে ভাগ করে পাঠাতে পারেন — প্রতিটি অংশ জমা হবে এবং কতগুলো অংশ জমা হয়েছে তা জানিয়ে দেওয়া হবে। ফাইল তৈরির সময় সবগুলো অংশ নিজে থেকেই জোড়া লাগানো হবে।\n" .
        "  ৩. কোড পাঠানো শেষ হলে সরাসরি 📦 Create File চেপে ফরম্যাট বেছে নিন।\n\n" .
        "🔹 <b>Multi Mode</b> — একাধিক আলাদা অংশ (যেমন একাধিক ফাইলের কনটেন্ট এক ফাইলে জোড়া দিতে) জমা দিতে:\n" .
        "  ১. 📚 Multi Mode বাটনে চাপুন।\n" .
        "  ২. একের পর এক কোড অংশ পাঠান (প্রতিটি আলাদা মেসেজে) — প্রতিটি অংশ ক্রমানুসারে জমা হবে।\n" .
        "  ৩. সব অংশ পাঠানো শেষ হলে 📦 Create File চেপে ফরম্যাট বেছে নিন।\n\n" .
        "📦 <b>Create File — ফরম্যাট অপশনসমূহ:</b>\n" .
        "  • 🤖 <code>bot.php</code> / 📄 <code>index.php</code> / 🌐 <code>index.html</code> / 🐍 <code>index.py</code> — জমা করা কোড ওই নামে ও এক্সটেনশনে সরাসরি ফাইল করে পাঠানো হয়।\n" .
        "  • 📦 <code>index.zip</code> — জমা করা কোড <code>index.php</code> নামে একটি ফাইলের ভেতরে রেখে ZIP করে পাঠানো হয়।\n" .
        "  • Multi Mode-এ কোনো অংশ থাকলে সেটাকেই অগ্রাধিকার দেওয়া হয়, না থাকলে Single Mode-এর জমা করা কোড ব্যবহার হয়।\n\n" .
        "🗑 <b>Clear File</b> — জমা করা সব কোড (Single ও Multi, দুই মোডেরই) মুছে সম্পূর্ণ রিসেট করে।\n\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

// ============================================================
// 6. BUTTON-STYLE INJECTION ENGINE (Sequential: Danger -> Success -> Primary)
//    Custom (reply) keyboard বাটন ['text' => '...']  এবং
//    Inline keyboard বাটন ['text' => '...', 'url' => '...' / 'callback_data' => '...']
//    — উভয় ধরনের বাটন অ্যারেই এখানে ধরা হয়।
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
    $insertions = [];
    $styledArrayStarts = []; // duplicate injection ঠেকাতে - একই অ্যারেতে দুবার style না বসে

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        $ivMain = substr($tok[1], 1, -1);
        if ($ivMain !== 'text') { continue; } // 'text' কী-ই বাটনের মূল সূচক (custom ও inline উভয় বাটনেই থাকে)

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

        // 'text' => '...' যে অ্যারে লিটারেলের ভেতরে আছে, তার শুরুর ব্র্যাকেট পিছনের দিকে খুঁজে বের করা
        $start = $i; $depth = 0;
        while ($start > 0) {
            $start--;
            $tv = is_array($tokens[$start]) ? $tokens[$start][1] : $tokens[$start];
            if ($tv === ']' || $tv === '}' || $tv === ')') { $depth++; continue; }
            if ($tv === '[' || $tv === '{' || $tv === '(') {
                if ($depth === 0) break;
                $depth--;
            }
        }
        if (isset($styledArrayStarts[$start])) { continue; } // এই অ্যারেতে আগেই style বসানো হয়ে গেছে

        // সেই অ্যারের শেষ ব্র্যাকেট সামনের দিকে খুঁজে বের করা
        $end = $k; $depth = 0;
        while ($end < $n - 1) {
            $end++;
            $tv = is_array($tokens[$end]) ? $tokens[$end][1] : $tokens[$end];
            if ($tv === '[' || $tv === '{' || $tv === '(') { $depth++; continue; }
            if ($tv === ']' || $tv === '}' || $tv === ')') {
                if ($depth === 0) break;
                $depth--;
            }
        }

        // পুরো অ্যারে লিটারেল জুড়ে 'style' কী আগে থেকে আছে কিনা যাচাই (দিক নির্বিশেষে)
        $hasStyle = false;
        for ($p = $start; $p <= $end; $p++) {
            $t = $tokens[$p];
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                if (substr($t[1], 1, -1) === 'style') { $hasStyle = true; break; }
            }
        }

        if (!$hasStyle) {
            $sequence = ['danger', 'success', 'primary'];
            $style = $sequence[$counter % 3];
            $counter++;
            $insertions[$k] = ",\n    \"style\" => \"$style\"";
            $styledArrayStarts[$start] = true;
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
    try {
        handleMessage($update['message']);
    } catch (Throwable $e) {
        error_log('handleMessage FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
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
        case BTN_HELPLINE:
            // FIX: this button previously had no handler at all, so
            // tapping it did nothing. Now it shows a detailed Bangla
            // guide for whichever menu the user is currently in.
            if ($state['menu'] === 'button_color') {
                sendMessage($chatId, buttonColorHelpText(), buttonColorMenuKeyboard());
            } elseif ($state['menu'] === 'code_to_file') {
                sendMessage($chatId, codeToFileHelpText(), codeToFileMenuKeyboard());
            } else {
                sendMessage($chatId, homeText(), homeKeyboard());
            }
            return;
        case BTN_DOWNLOAD_PHP:
            if (!empty($state['last_result_code'])) {
                broadcastDocument($chatId, 'bot.php', $state['last_result_code'], '📥 Colored Code File');
            }
            return;
        case BTN_SINGLE_MODE:
            $state['mode'] = 'single_mode';
            // FIX: reset the accumulation buffer when (re-)entering single mode
            $state['single_code_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "📝 কোড পাঠান। কোড বড় হলে একাধিক মেসেজে ভাগ করে পাঠাতে পারেন, সবগুলো একসাথে জোড়া লাগানো হবে। শেষ হলে 📦 Create File চাপুন।", codeToFileMenuKeyboard());
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
        // FIX: append this incoming piece instead of overwriting the whole
        // single_code value. This is what previously caused large code
        // (sent across multiple Telegram messages) to lose everything
        // except the very last chunk.
        $state['single_code_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['single_code_parts']);
        sendMessage($chatId, "✅ কোড অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)\nআরও কোড থাকলে পাঠান, শেষ হলে 📦 Create File চাপুন।", codeToFileMenuKeyboard());
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
    // FIX: previously only $state['single_code'] (a plain string that got
    // overwritten by every new single-mode message) was used as the
    // fallback, so large multi-message code in single mode was truncated
    // to just its last chunk. Now single mode's own accumulated parts are
    // reassembled the same way multi mode's are.
    if (!empty($state['multi_parts'])) {
        $code = reassembleParts($state['multi_parts']);
    } elseif (!empty($state['single_code_parts'])) {
        $code = reassembleParts($state['single_code_parts']);
    } else {
        $code = '';
    }

    if (!$code) return;
    if ($ftName === 'index.zip') {
        $zipContent = buildZipFromCode($code, 'index.php');
        if ($zipContent) broadcastDocument($chatId, 'index.zip', $zipContent, '✅ ZIP Created');
    } else {
        broadcastDocument($chatId, $ftName, $code, '✅ File Created');
    }
}
