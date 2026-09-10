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
        'GEMINI_API_KEY'    => getenv('GEMINI_API_KEY') ?: 'YOUR_GEMINI_API_KEY_HERE', // এখানে আপনার জেমিনি এপিআই কি বসাবেন
    ];
}

if (!defined('BOT_TOKEN')) {
    define('BOT_TOKEN', $GLOBALS['BOT_CONFIG']['BOT_TOKEN']);
}

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
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', $GLOBALS['BOT_CONFIG']['GEMINI_API_KEY']);
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
        'single_code_parts' => [],
        'multi_parts' => [],
        'convert_parts' => [],
        'target_lang' => null,
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
        'underline'     => ['open' => '_',   'close' => '_'],
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
const BTN_CONVERT_CODE   = '🔄 Convert Code';
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

// Convert Language Buttons
const BTN_LANG_PYTHON    = '🐍 Python';
const BTN_LANG_PHP       = '🐘 PHP';
const BTN_LANG_JS        = '⚡ JavaScript';
const BTN_LANG_JAVA      = '☕ Java';
const BTN_LANG_CPP       = '⚙️ C++';
const BTN_LANG_CPLUS     = 'C++';
const BTN_LANG_CSHARP    = '🔷 C#';
const BTN_LANG_GO        = '🔵 Go';
const BTN_LANG_RUBY      = '💎 Ruby';
const BTN_RUN_CONVERT    = '🚀 Run Convert';
const BTN_CLEAR_CONVERT  = '🗑 Clear';

function homeKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_BUTTON_COLOR, BTN_CODE_TO_FILE], $c),
        styledRow([BTN_CONVERT_CODE], $c)
    ]);
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

function convertMenuKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_LANG_PYTHON, BTN_LANG_PHP], $c),
        styledRow([BTN_LANG_JS, BTN_LANG_JAVA], $c),
        styledRow([BTN_LANG_CPP, BTN_LANG_CSHARP], $c),
        styledRow([BTN_LANG_GO, BTN_LANG_RUBY], $c),
        styledRow([BTN_HELPLINE, BTN_HOME], $c)
    ]);
}

function convertActionKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_RUN_CONVERT, BTN_CLEAR_CONVERT], $c),
        styledRow([BTN_CONVERT_CODE, BTN_HOME], $c)
    ]);
}

// ============================================================
// 5. HELP TEXTS
// ============================================================
function homeText() {
    return "🤖 <b>Welcome to Code Utility Bot</b>\n\nনিচের Menu থেকে আপনার প্রয়োজনীয় অপশন নির্বাচন করুন।";
}

function buttonColorHelpText() {
    return "📚 <b>Button Color — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি আপনার PHP কোডের ভেতরে থাকা Telegram বাটনগুলোতে style যুক্ত করে দেয়。\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

function codeToFileHelpText() {
    return "📚 <b>Code To File — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি দিয়ে যেকোনো কোড টেক্সট থেকে সরাসরি ডাউনলোডযোগ্য ফাইল তৈরি করা যায়।\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

function convertCodeHelpText() {
    return "📚 <b>Convert Code — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি দিয়ে আপনি Gemini AI ব্যবহার করে যেকোনো প্রোগ্রামিং ভাষাকে অন্য ভাষায় রূপান্তর (Convert) করতে পারবেন।\n\n" .
        "<b>ধাপ ১:</b> 🔄 <u>Convert Code</u> এ চাপার পর যে ভাষায় কোড রূপান্তর করতে চান তা সিলেক্ট করুন।\n" .
        "<b>ধাপ ২:</b> আপনার কোড পাঠান (একাধিক মেসেজে পাঠাতে পারেন)।\n" .
        "<b>ধাপ ৩:</b> কোড পাঠানো শেষ হলে নিচের **🚀 Run Convert** বাটনে ক্লিক করুন।\n\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

// ============================================================
// 6. BUTTON-STYLE INJECTION & GEMINI CONVERSION ENGINE
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
    $styledArrayStarts = [];

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        $ivMain = substr($tok[1], 1, -1);
        if ($ivMain !== 'text') { continue; }

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
        if (isset($styledArrayStarts[$start])) { continue; }

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

// Gemini API Code Conversion Function
function convertCodeWithGemini($code, $targetLang) {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE' || empty(GEMINI_API_KEY)) {
        return "❌ Gemini API Key সেট করা হয়নি! কোডের কনফিগারেশনে সঠিক API Key বসান।";
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . GEMINI_API_KEY;
    
    $prompt = "You are an expert programmer. Convert the following code completely into {$targetLang}. Provide ONLY the converted code inside a clean text format without extra conversation or unnecessary explanations so it can be directly copied and used.";

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt . "\n\nCode to convert:\n" . $code]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        return "❌ cURL Connection Error: " . $err;
    }

    if ($httpCode !== 200) {
        $errorDetails = json_decode($result, true);
        $errorMsg = $errorDetails['error']['message'] ?? 'Unknown API Error';
        return "❌ Gemini API Error (HTTP {$httpCode}): " . $errorMsg;
    }

    $decoded = json_decode($result, true);
    if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
        $convertedText = $decoded['candidates'][0]['content']['parts'][0]['text'];
        $convertedText = preg_replace('/^```[a-z]*\s*\n?/i', '', $convertedText);$convertedText = preg_replace('/\n?```\s*$/', '', $convertedText);
        return trim($convertedText);
    }

    return "❌ কোড কনভার্ট করতে সমস্যা হয়েছে। এপিআই থেকে সঠিক রেসপন্স পাওয়া যায়নি।";
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

function sendCodeAsCopyable($chatId, $code) {
    $escaped = htmlspecialchars($code, ENT_NOQUOTES, 'UTF-8');
    $tagOpen = "<pre><code>";
    $tagClose = "</code></pre>";
    $reserveForLabel = 60;
    $maxChunkLen = 4096 - strlen($tagOpen) - strlen($tagClose) - $reserveForLabel;
    if ($maxChunkLen < 500) { $maxChunkLen = 500; }

    $lines = explode("\n", $escaped);
    $chunks = [];
    $current = '';
    foreach ($lines as $line) {
        $candidate = ($current === '') ? $line : ($current . "\n" . $line);
        if (strlen($candidate) > $maxChunkLen && $current !== '') {
            $chunks[] = $current;
            $current = $line;
        } else {
            $current = $candidate;
        }
        while (strlen($current) > $maxChunkLen) {
            $cut = $maxChunkLen;
            $spacePos = strrpos(substr($current, 0, $maxChunkLen), ' ');
            if ($spacePos !== false && $spacePos > $maxChunkLen - 100) {
                $cut = $spacePos;
            }
            $chunks[] = substr($current, 0, $cut);
            $current = substr($current, $cut);
        }
    }
    if ($current !== '' || empty($chunks)) {
        $chunks[] = $current;
    }

    $total = count($chunks);
    foreach ($chunks as $idx => $chunk) {
        $label = $total > 1 ? "📋 <b>Converted Code</b> (অংশ " . ($idx + 1) . "/{$total}):\n" : "📋 <b>Converted Code</b>:\n";
        sendMessage($chatId, $label . $tagOpen . $chunk . $tagClose);
    }
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

    $state = getState($userId);
    $trimmed = trim($text);

    // Commands should work regardless of current state mode
    if ($trimmed === '/start' || $text === '/start') {
        clearState($userId);
        sendMessage($chatId, homeText(), homeKeyboard());
        return;
    }
    if ($trimmed === '/cancel' || $text === '/cancel') {
        clearState($userId);
        sendMessage($chatId, "✅ রিসেট করা হয়েছে।", homeKeyboard());
        return;
    }

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
        case BTN_CONVERT_CODE:
            $state['menu'] = 'convert_code';
            $state['mode'] = 'select_lang';
            saveState($userId, $state);
            sendMessage($chatId, "🔄 <b>Convert Code</b>\nকোড কোন ভাষায় রূপান্তর করতে চান তা নিচের অপশন থেকে সিলেক্ট করুন:", convertMenuKeyboard());
            return;
        case BTN_RUN_CONVERT:
            if (!empty($state['convert_parts'])) {
                handleGeminiConversion($chatId, $userId, $state);
            } else {
                sendMessage($chatId, "❌ কোনো কোড জমা করা হয়নি! প্রথমে কোড পাঠান।", convertActionKeyboard());
            }
            return;
        case BTN_CLEAR_CONVERT:
            $state['convert_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "🗑 কনভার্ট করার কোড বাফার পরিষ্কার করা হয়েছে। নতুন কোড পাঠান:", convertActionKeyboard());
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
            if ($state['menu'] === 'button_color') {
                sendMessage($chatId, buttonColorHelpText(), buttonColorMenuKeyboard());
            } elseif ($state['menu'] === 'code_to_file') {
                sendMessage($chatId, codeToFileHelpText(), codeToFileMenuKeyboard());
            } elseif ($state['menu'] === 'convert_code') {
                sendMessage($chatId, convertCodeHelpText(), convertMenuKeyboard());
            } else {
                sendMessage($chatId, homeText(), homeKeyboard());
            }
            return;
        case BTN_DOWNLOAD_PHP:
            if (!empty($state['last_result_code'])) {
                broadcastDocument($chatId, 'bot.php', $state['last_result_code'], '📥 Colored Code File');
            } else {
                sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", buttonColorMenuKeyboard());
            }
            return;
        case BTN_COPY_CODE:
            if (!empty($state['last_result_code'])) {
                sendCodeAsCopyable($chatId, $state['last_result_code']);
            } else {
                sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", homeKeyboard());
            }
            return;
        case BTN_SINGLE_MODE:
            $state['mode'] = 'single_mode';
            $state['single_code_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "📝 কোড পাঠান। শেষ হলে 📦 Create File চাপুন।", codeToFileMenuKeyboard());
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
        case BTN_FT_INDEX_PY:
            createAndSendFile($chatId, $userId, 'index.py', $state);
            return;
        case BTN_FT_INDEX_ZIP:
            createAndSendFile($chatId, $userId, 'index.zip', $state);
            return;
        case BTN_BACK:
            $state['menu'] = 'code_to_file';
            saveState($userId, $state);
            sendMessage($chatId, "📁 <b>Code To File</b> মেনু:", codeToFileMenuKeyboard());
            return;
    }

    // Handle Language Selection for Conversion
    if ($state['menu'] === 'convert_code' && $state['mode'] === 'select_lang') {
        $langs = [
            BTN_LANG_PYTHON => 'Python',
            BTN_LANG_PHP => 'PHP',
            BTN_LANG_JS => 'JavaScript',
            BTN_LANG_JAVA => 'Java',
            BTN_LANG_CPP => 'C++',
            BTN_LANG_CPLUS => 'C++',
            BTN_LANG_CSHARP => 'C#',
            BTN_LANG_GO => 'Go',
            BTN_LANG_RUBY => 'Ruby'
        ];

        if (isset($langs[$trimmed])) {
            $state['target_lang'] = $langs[$trimmed];
            $state['mode'] = 'convert_input';
            $state['convert_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "✅ আপনি সিলেক্ট করেছেন: <b>{$langs[$trimmed]}</b>\n\nএখন কোড পাঠান (একাধিক মেসেজে পাঠাতে পারেন)। কোড পাঠানো শেষ হলে নিচের <b>🚀 Run Convert</b> বাটনে চাপুন।", convertActionKeyboard());
            return;
        }
    }

    if ($state['mode'] === 'code_submit') {
        $state['code_buffer'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['code_buffer']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", buttonColorMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'single_mode') {
        $state['single_code_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['single_code_parts']);
        sendMessage($chatId, "✅ কোড অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", codeToFileMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'multi_mode') {
        $state['multi_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['multi_parts']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", codeToFileMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'convert_input') {
        $state['convert_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['convert_parts']);
        sendMessage($chatId, "✅ কোড অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)\nআরও থাকলে পাঠান অথবা কনভার্ট করতে নিচের <b>🚀 Run Convert</b> বাটনে চাপুন।", convertActionKeyboard());
        return;
    }
}

function handleCreateColor($chatId, $userId, $state) {
    if (empty($state['code_buffer'])) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি! প্রথমে 📝 Code Submit চেপে কোড পাঠান।", buttonColorMenuKeyboard());
        return;
    }
    $colored = applyButtonStyles(reassembleParts($state['code_buffer']));
    $state['last_result_code'] = $colored;
    $state['code_buffer'] = [];
    $state['mode'] = null;
    saveState($userId, $state);
    broadcastDocument($chatId, 'bot.php', $colored, '🎨 Colored Code');
    sendMessage($chatId, "✅ কালার করা সম্পন্ন হয়েছে!", buttonColorResultKeyboard(true));
}

function handleGeminiConversion($chatId, $userId, &$state) {
    if (empty($state['convert_parts'])) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", convertActionKeyboard());
        return;
    }

    $fullCode = reassembleParts($state['convert_parts']);
    $targetLang = $state['target_lang'] ?? 'Python';

    sendMessage($chatId, "⏳ Gemini AI কোড রূপান্তর করছে ({$targetLang}), অনুগ্রহ করে অপেক্ষা করুন...");

    $convertedCode = convertCodeWithGemini($fullCode, $targetLang);

    $state['last_result_code'] = $convertedCode;
    $state['convert_parts'] = [];
    $state['mode'] = null;
    saveState($userId, $state);

    sendCodeAsCopyable($chatId, $convertedCode);
    sendMessage($chatId, "✅ কোড রূপান্তর সফল হয়েছে!", homeKeyboard());
}

function createAndSendFile($chatId, $userId, $ftName, $state) {
    if (!empty($state['multi_parts'])) {
        $code = reassembleParts($state['multi_parts']);
    } elseif (!empty($state['single_code_parts'])) {
        $code = reassembleParts($state['single_code_parts']);
    } else {
        $code = '';
    }

    if (!$code) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", codeToFileMenuKeyboard());
        return;
    }
    if ($ftName === 'index.zip') {
        $zipContent = buildZipFromCode($code, 'index.php');
        if ($zipContent) broadcastDocument($chatId, 'index.zip', $zipContent, '✅ ZIP Created');
    } else {
        broadcastDocument($chatId, $ftName, $code, '✅ File Created');
    }
}
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
        'single_code_parts' => [],
        'multi_parts' => [],
        'convert_parts' => [],
        'target_lang' => null,
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
        'underline'     => ['open' => '_',   'close' => '_'],
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
const BTN_CONVERT_CODE   = '🔄 Convert Code';
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

// Convert Language Buttons
const BTN_LANG_PYTHON    = '🐍 Python';
const BTN_LANG_PHP       = '🐘 PHP';
const BTN_LANG_JS        = '⚡ JavaScript';
const BTN_LANG_JAVA      = '☕ Java';
const BTN_LANG_CPP       = '⚙️ C++';
const BTN_LANG_CPLUS     = 'C++';
const BTN_LANG_CSHARP    = '🔷 C#';
const BTN_LANG_GO        = '🔵 Go';
const BTN_LANG_RUBY      = '💎 Ruby';
const BTN_RUN_CONVERT    = '🚀 Run Convert';
const BTN_CLEAR_CONVERT  = '🗑 Clear';

function homeKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_BUTTON_COLOR, BTN_CODE_TO_FILE], $c),
        styledRow([BTN_CONVERT_CODE], $c)
    ]);
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

function convertMenuKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_LANG_PYTHON, BTN_LANG_PHP], $c),
        styledRow([BTN_LANG_JS, BTN_LANG_JAVA], $c),
        styledRow([BTN_LANG_CPP, BTN_LANG_CSHARP], $c),
        styledRow([BTN_LANG_GO, BTN_LANG_RUBY], $c),
        styledRow([BTN_HELPLINE, BTN_HOME], $c)
    ]);
}

function convertActionKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_RUN_CONVERT, BTN_CLEAR_CONVERT], $c),
        styledRow([BTN_CONVERT_CODE, BTN_HOME], $c)
    ]);
}

// ============================================================
// 5. HELP TEXTS
// ============================================================
function homeText() {
    return "🤖 <b>Welcome to Code Utility Bot</b>\n\nনিচের Menu থেকে আপনার প্রয়োজনীয় অপশন নির্বাচন করুন।";
}

function buttonColorHelpText() {
    return "📚 <b>Button Color — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি আপনার PHP কোডের ভেতরে থাকা Telegram বাটনগুলোতে style যুক্ত করে দেয়।\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

function codeToFileHelpText() {
    return "📚 <b>Code To File — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি দিয়ে যেকোনো কোড টেক্সট থেকে সরাসরি ডাউনলোডযোগ্য ফাইল তৈরি করা যায়।\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

function convertCodeHelpText() {
    return "📚 <b>Convert Code — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি দিয়ে আপনি Gemini AI ব্যবহার করে যেকোনো প্রোগ্রামিং ভাষাকে অন্য ভাষায় রূপান্তর (Convert) করতে পারবেন।\n\n" .
        "<b>ধাপ ১:</b> 🔄 <u>Convert Code</u> এ চাপার পর যে ভাষায় কোড রূপান্তর করতে চান তা সিলেক্ট করুন।\n" .
        "<b>ধাপ ২:</b> আপনার কোড পাঠান (একাধিক মেসেজে পাঠাতে পারেন)।\n" .
        "<b>ধাপ ৩:</b> কোড পাঠানো শেষ হলে নিচের **🚀 Run Convert** বাটনে ক্লিক করুন।\n\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

// ============================================================
// 6. BUTTON-STYLE INJECTION & GEMINI CONVERSION ENGINE
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
    $styledArrayStarts = [];

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        $ivMain = substr($tok[1], 1, -1);
        if ($ivMain !== 'text') { continue; }

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
        if (isset($styledArrayStarts[$start])) { continue; }

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

// Gemini API Code Conversion Function
function convertCodeWithGemini($code, $targetLang) {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE' || empty(GEMINI_API_KEY)) {
        return "❌ Gemini API Key সেট করা হয়নি! কোডের কনফিগারেশনে সঠিক API Key বসান।";
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . GEMINI_API_KEY;
    
    $prompt = "You are an expert programmer. Convert the following code completely into {$targetLang}. Provide ONLY the converted code inside a clean text format without extra conversation or unnecessary explanations so it can be directly copied and used.";

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt . "\n\nCode to convert:\n" . $code]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        return "❌ cURL Connection Error: " . $err;
    }

    if ($httpCode !== 200) {
        $errorDetails = json_decode($result, true);
        $errorMsg = $errorDetails['error']['message'] ?? 'Unknown API Error';
        return "❌ Gemini API Error (HTTP {$httpCode}): " . $errorMsg;
    }

    $decoded = json_decode($result, true);
    if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
        $convertedText = $decoded['candidates'][0]['content']['parts'][0]['text'];
        $convertedText = preg_replace('/^```[a-z]*\s*\n?/i', '', $convertedText);$convertedText = preg_replace('/\n?```\s*$/', '', $convertedText);
        return trim($convertedText);
    }

    return "❌ কোড কনভার্ট করতে সমস্যা হয়েছে। এপিআই থেকে সঠিক রেসপন্স পাওয়া যায়নি।";
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

function sendCodeAsCopyable($chatId, $code) {
    $escaped = htmlspecialchars($code, ENT_NOQUOTES, 'UTF-8');
    $tagOpen = "<pre><code>";
    $tagClose = "</code></pre>";
    $reserveForLabel = 60;
    $maxChunkLen = 4096 - strlen($tagOpen) - strlen($tagClose) - $reserveForLabel;
    if ($maxChunkLen < 500) { $maxChunkLen = 500; }

    $lines = explode("\n", $escaped);
    $chunks = [];
    $current = '';
    foreach ($lines as $line) {
        $candidate = ($current === '') ? $line : ($current . "\n" . $line);
        if (strlen($candidate) > $maxChunkLen && $current !== '') {
            $chunks[] = $current;
            $current = $line;
        } else {
            $current = $candidate;
        }
        while (strlen($current) > $maxChunkLen) {
            $cut = $maxChunkLen;
            $spacePos = strrpos(substr($current, 0, $maxChunkLen), ' ');
            if ($spacePos !== false && $spacePos > $maxChunkLen - 100) {
                $cut = $spacePos;
            }
            $chunks[] = substr($current, 0, $cut);
            $current = substr($current, $cut);
        }
    }
    if ($current !== '' || empty($chunks)) {
        $chunks[] = $current;
    }

    $total = count($chunks);
    foreach ($chunks as $idx => $chunk) {
        $label = $total > 1 ? "📋 <b>Converted Code</b> (অংশ " . ($idx + 1) . "/{$total}):\n" : "📋 <b>Converted Code</b>:\n";
        sendMessage($chatId, $label . $tagOpen . $chunk . $tagClose);
    }
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
        case BTN_CONVERT_CODE:
            $state['menu'] = 'convert_code';
            $state['mode'] = 'select_lang';
            saveState($userId, $state);
            sendMessage($chatId, "🔄 <b>Convert Code</b>\nকোড কোন ভাষায় রূপান্তর করতে চান তা নিচের অপশন থেকে সিলেক্ট করুন:", convertMenuKeyboard());
            return;
        case BTN_RUN_CONVERT:
            if (!empty($state['convert_parts'])) {
                handleGeminiConversion($chatId, $userId, $state);
            } else {
                sendMessage($chatId, "❌ কোনো কোড জমা করা হয়নি! প্রথমে কোড পাঠান।", convertActionKeyboard());
            }
            return;
        case BTN_CLEAR_CONVERT:
            $state['convert_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "🗑 কনভার্ট করার কোড বাফার পরিষ্কার করা হয়েছে। নতুন কোড পাঠান:", convertActionKeyboard());
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
            if ($state['menu'] === 'button_color') {
                sendMessage($chatId, buttonColorHelpText(), buttonColorMenuKeyboard());
            } elseif ($state['menu'] === 'code_to_file') {
                sendMessage($chatId, codeToFileHelpText(), codeToFileMenuKeyboard());
            } elseif ($state['menu'] === 'convert_code') {
                sendMessage($chatId, convertCodeHelpText(), convertMenuKeyboard());
            } else {
                sendMessage($chatId, homeText(), homeKeyboard());
            }
            return;
        case BTN_DOWNLOAD_PHP:
            if (!empty($state['last_result_code'])) {
                broadcastDocument($chatId, 'bot.php', $state['last_result_code'], '📥 Colored Code File');
            } else {
                sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", buttonColorMenuKeyboard());
            }
            return;
        case BTN_COPY_CODE:
            if (!empty($state['last_result_code'])) {
                sendCodeAsCopyable($chatId, $state['last_result_code']);
            } else {
                sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", homeKeyboard());
            }
            return;
        case BTN_SINGLE_MODE:
            $state['mode'] = 'single_mode';
            $state['single_code_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "📝 কোড পাঠান। শেষ হলে 📦 Create File চাপুন।", codeToFileMenuKeyboard());
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
        case BTN_FT_INDEX_PY:
            createAndSendFile($chatId, $userId, 'index.py', $state);
            return;
        case BTN_FT_INDEX_ZIP:
            createAndSendFile($chatId, $userId, 'index.zip', $state);
            return;
        case BTN_BACK:
            $state['menu'] = 'code_to_file';
            saveState($userId, $state);
            sendMessage($chatId, "📁 <b>Code To File</b> মেনু:", codeToFileMenuKeyboard());
            return;
    }

    // Handle Language Selection for Conversion
    if ($state['menu'] === 'convert_code' && $state['mode'] === 'select_lang') {
        $langs = [
            BTN_LANG_PYTHON => 'Python',
            BTN_LANG_PHP => 'PHP',
            BTN_LANG_JS => 'JavaScript',
            BTN_LANG_JAVA => 'Java',
            BTN_LANG_CPP => 'C++',
            BTN_LANG_CPLUS => 'C++',
            BTN_LANG_CSHARP => 'C#',
            BTN_LANG_GO => 'Go',
            BTN_LANG_RUBY => 'Ruby'
        ];

        if (isset($langs[$trimmed])) {
            $state['target_lang'] = $langs[$trimmed];
            $state['mode'] = 'convert_input';
            $state['convert_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "✅ আপনি সিলেক্ট করেছেন: <b>{$langs[$trimmed]}</b>\n\nএখন কোড পাঠান (একাধিক মেসেজে পাঠাতে পারেন)। কোড পাঠানো শেষ হলে নিচের <b>🚀 Run Convert</b> বাটনে চাপুন।", convertActionKeyboard());
            return;
        }
    }

    if ($state['mode'] === 'code_submit') {
        $state['code_buffer'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['code_buffer']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", buttonColorMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'single_mode') {
        $state['single_code_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['single_code_parts']);
        sendMessage($chatId, "✅ কোড অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", codeToFileMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'multi_mode') {
        $state['multi_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['multi_parts']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", codeToFileMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'convert_input') {
        $state['convert_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['convert_parts']);
        sendMessage($chatId, "✅ কোড অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)\nআরও থাকলে পাঠান অথবা কনভার্ট করতে নিচের <b>🚀 Run Convert</b> বাটনে চাপুন।", convertActionKeyboard());
        return;
    }
}

function handleCreateColor($chatId, $userId, $state) {
    if (empty($state['code_buffer'])) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি! প্রথমে 📝 Code Submit চেপে কোড পাঠান।", buttonColorMenuKeyboard());
        return;
    }
    $colored = applyButtonStyles(reassembleParts($state['code_buffer']));
    $state['last_result_code'] = $colored;
    $state['code_buffer'] = [];
    $state['mode'] = null;
    saveState($userId, $state);
    broadcastDocument($chatId, 'bot.php', $colored, '🎨 Colored Code');
    sendMessage($chatId, "✅ কালার করা সম্পন্ন হয়েছে!", buttonColorResultKeyboard(true));
}

function handleGeminiConversion($chatId, $userId, &$state) {
    if (empty($state['convert_parts'])) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", convertActionKeyboard());
        return;
    }

    $fullCode = reassembleParts($state['convert_parts']);
    $targetLang = $state['target_lang'] ?? 'Python';

    sendMessage($chatId, "⏳ Gemini AI কোড রূপান্তর করছে ({$targetLang}), অনুগ্রহ করে অপেক্ষা করুন...");

    $convertedCode = convertCodeWithGemini($fullCode, $targetLang);

    $state['last_result_code'] = $convertedCode;
    $state['convert_parts'] = [];
    $state['mode'] = null;
    saveState($userId, $state);

    sendCodeAsCopyable($chatId, $convertedCode);
    sendMessage($chatId, "✅ কোড রূপান্তর সফল হয়েছে!", homeKeyboard());
}

function createAndSendFile($chatId, $userId, $ftName, $state) {
    if (!empty($state['multi_parts'])) {
        $code = reassembleParts($state['multi_parts']);
    } elseif (!empty($state['single_code_parts'])) {
        $code = reassembleParts($state['single_code_parts']);
    } else {
        $code = '';
    }

    if (!$code) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", codeToFileMenuKeyboard());
        return;
    }
    if ($ftName === 'index.zip') {
        $zipContent = buildZipFromCode($code, 'index.php');
        if ($zipContent) broadcastDocument($chatId, 'index.zip', $zipContent, '✅ ZIP Created');
    } else {
        broadcastDocument($chatId, $ftName, $code, '✅ File Created');
    }
}
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
        'single_code_parts' => [],
        'multi_parts' => [],
        'convert_parts' => [],
        'target_lang' => null,
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
        'underline'     => ['open' => '_',   'close' => '_'],
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
const BTN_CONVERT_CODE   = '🔄 Convert Code';
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

// Convert Language Buttons
const BTN_LANG_PYTHON    = '🐍 Python';
const BTN_LANG_PHP       = '🐘 PHP';
const BTN_LANG_JS        = '⚡ JavaScript';
const BTN_LANG_JAVA      = '☕ Java';
const BTN_LANG_CPP       = '⚙️ C++';
const BTN_LANG_CPLUS     = 'C++';
const BTN_LANG_CSHARP    = '🔷 C#';
const BTN_LANG_GO        = '🔵 Go';
const BTN_LANG_RUBY      = '💎 Ruby';
const BTN_RUN_CONVERT    = '🚀 Run Convert';
const BTN_CLEAR_CONVERT  = '🗑 Clear';

function homeKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_BUTTON_COLOR, BTN_CODE_TO_FILE], $c),
        styledRow([BTN_CONVERT_CODE], $c)
    ]);
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

function convertMenuKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_LANG_PYTHON, BTN_LANG_PHP], $c),
        styledRow([BTN_LANG_JS, BTN_LANG_JAVA], $c),
        styledRow([BTN_LANG_CPP, BTN_LANG_CSHARP], $c),
        styledRow([BTN_LANG_GO, BTN_LANG_RUBY], $c),
        styledRow([BTN_HELPLINE, BTN_HOME], $c)
    ]);
}

function convertActionKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_RUN_CONVERT, BTN_CLEAR_CONVERT], $c),
        styledRow([BTN_CONVERT_CODE, BTN_HOME], $c)
    ]);
}

// ============================================================
// 5. HELP TEXTS
// ============================================================
function homeText() {
    return "🤖 <b>Welcome to Code Utility Bot</b>\n\nনিচের Menu থেকে আপনার প্রয়োজনীয় অপশন নির্বাচন করুন।";
}

function buttonColorHelpText() {
    return "📚 <b>Button Color — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি আপনার PHP কোডের ভেতরে থাকা Telegram বাটনগুলোতে style যুক্ত করে দেয়।\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

function codeToFileHelpText() {
    return "📚 <b>Code To File — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি দিয়ে যেকোনো কোড টেক্সট থেকে সরাসরি ডাউনলোডযোগ্য ফাইল তৈরি করা যায়।\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

function convertCodeHelpText() {
    return "📚 <b>Convert Code — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি দিয়ে আপনি Gemini AI ব্যবহার করে যেকোনো প্রোগ্রামিং ভাষাকে অন্য ভাষায় রূপান্তর (Convert) করতে পারবেন।\n\n" .
        "<b>ধাপ ১:</b> 🔄 <u>Convert Code</u> এ চাপার পর যে ভাষায় কোড রূপান্তর করতে চান তা সিলেক্ট করুন।\n" .
        "<b>ধাপ ২:</b> আপনার কোড পাঠান (একাধিক মেসেজে পাঠাতে পারেন)।\n" .
        "<b>ধাপ ৩:</b> কোড পাঠানো শেষ হলে নিচের **🚀 Run Convert** বাটনে ক্লিক করুন।\n\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

// ============================================================
// 6. BUTTON-STYLE INJECTION & GEMINI CONVERSION ENGINE
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
    $styledArrayStarts = [];

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        $ivMain = substr($tok[1], 1, -1);
        if ($ivMain !== 'text') { continue; }

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
        if (isset($styledArrayStarts[$start])) { continue; }

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

// Gemini API Code Conversion Function (Fixed with proper model endpoint)
function convertCodeWithGemini($code, $targetLang) {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE' || empty(GEMINI_API_KEY)) {
        return "❌ Gemini API Key সেট করা হয়নি! কোডের কনফিগারেশনে সঠিক API Key বসান।";
    }

    // Using gemini-2.5-flash or gemini-1.5-flash standard API endpoint structure
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . GEMINI_API_KEY;
    
    $prompt = "You are an expert programmer. Convert the following code completely into {$targetLang}. Provide ONLY the converted code inside a clean text format without extra conversation or unnecessary explanations so it can be directly copied and used.";

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt . "\n\nCode to convert:\n" . $code]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        return "❌ cURL Connection Error: " . $err;
    }

    if ($httpCode !== 200) {
        $errorDetails = json_decode($result, true);
        $errorMsg = $errorDetails['error']['message'] ?? 'Unknown API Error';
        return "❌ Gemini API Error (HTTP {$httpCode}): " . $errorMsg;
    }

    $decoded = json_decode($result, true);
    if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
        $convertedText = $decoded['candidates'][0]['content']['parts'][0]['text'];
        $convertedText = preg_replace('/^```[a-z]*\s*\n?/i', '', $convertedText);$convertedText = preg_replace('/\n?```\s*$/', '', $convertedText);
        return trim($convertedText);
    }

    return "❌ কোড কনভার্ট করতে সমস্যা হয়েছে। এপিআই থেকে সঠিক রেসপন্স পাওয়া যায়নি।";
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

function sendCodeAsCopyable($chatId, $code) {
    $escaped = htmlspecialchars($code, ENT_NOQUOTES, 'UTF-8');
    $tagOpen = "<pre><code>";
    $tagClose = "</code></pre>";
    $reserveForLabel = 60;
    $maxChunkLen = 4096 - strlen($tagOpen) - strlen($tagClose) - $reserveForLabel;
    if ($maxChunkLen < 500) { $maxChunkLen = 500; }

    $lines = explode("\n", $escaped);
    $chunks = [];
    $current = '';
    foreach ($lines as $line) {
        $candidate = ($current === '') ? $line : ($current . "\n" . $line);
        if (strlen($candidate) > $maxChunkLen && $current !== '') {
            $chunks[] = $current;
            $current = $line;
        } else {
            $current = $candidate;
        }
        while (strlen($current) > $maxChunkLen) {
            $cut = $maxChunkLen;
            $spacePos = strrpos(substr($current, 0, $maxChunkLen), ' ');
            if ($spacePos !== false && $spacePos > $maxChunkLen - 100) {
                $cut = $spacePos;
            }
            $chunks[] = substr($current, 0, $cut);
            $current = substr($current, $cut);
        }
    }
    if ($current !== '' || empty($chunks)) {
        $chunks[] = $current;
    }

    $total = count($chunks);
    foreach ($chunks as $idx => $chunk) {
        $label = $total > 1 ? "📋 <b>Converted Code</b> (অংশ " . ($idx + 1) . "/{$total}):\n" : "📋 <b>Converted Code</b>:\n";
        sendMessage($chatId, $label . $tagOpen . $chunk . $tagClose);
    }
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
        case BTN_CONVERT_CODE:
            $state['menu'] = 'convert_code';
            $state['mode'] = 'select_lang';
            saveState($userId, $state);
            sendMessage($chatId, "🔄 <b>Convert Code</b>\nকোড কোন ভাষায় রূপান্তর করতে চান তা নিচের অপশন থেকে সিলেক্ট করুন:", convertMenuKeyboard());
            return;
        case BTN_RUN_CONVERT:
            if ($state['menu'] === 'convert_code' && !empty($state['convert_parts'])) {
                handleGeminiConversion($chatId, $userId, $state);
            } else {
                sendMessage($chatId, "❌ কোনো কোড জমা করা হয়নি! প্রথমে কোড পাঠান।", convertActionKeyboard());
            }
            return;
        case BTN_CLEAR_CONVERT:
            $state['convert_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "🗑 কনভার্ট করার কোড বাফার পরিষ্কার করা হয়েছে। নতুন কোড পাঠান:", convertActionKeyboard());
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
            if ($state['menu'] === 'button_color') {
                sendMessage($chatId, buttonColorHelpText(), buttonColorMenuKeyboard());
            } elseif ($state['menu'] === 'code_to_file') {
                sendMessage($chatId, codeToFileHelpText(), codeToFileMenuKeyboard());
            } elseif ($state['menu'] === 'convert_code') {
                sendMessage($chatId, convertCodeHelpText(), convertMenuKeyboard());
            } else {
                sendMessage($chatId, homeText(), homeKeyboard());
            }
            return;
        case BTN_DOWNLOAD_PHP:
            if (!empty($state['last_result_code'])) {
                broadcastDocument($chatId, 'bot.php', $state['last_result_code'], '📥 Colored Code File');
            } else {
                sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", buttonColorMenuKeyboard());
            }
            return;
        case BTN_COPY_CODE:
            if (!empty($state['last_result_code'])) {
                sendCodeAsCopyable($chatId, $state['last_result_code']);
            } else {
                sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", homeKeyboard());
            }
            return;
        case BTN_SINGLE_MODE:
            $state['mode'] = 'single_mode';
            $state['single_code_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "📝 কোড পাঠান। শেষ হলে 📦 Create File চাপুন।", codeToFileMenuKeyboard());
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
        case BTN_FT_INDEX_PY:
            createAndSendFile($chatId, $userId, 'index.py', $state);
            return;
        case BTN_FT_INDEX_ZIP:
            createAndSendFile($chatId, $userId, 'index.zip', $state);
            return;
        case BTN_BACK:
            $state['menu'] = 'code_to_file';
            saveState($userId, $state);
            sendMessage($chatId, "📁 <b>Code To File</b> মেনু:", codeToFileMenuKeyboard());
            return;
    }

    // Handle Language Selection for Conversion
    if ($state['menu'] === 'convert_code' && $state['mode'] === 'select_lang') {
        $langs = [
            BTN_LANG_PYTHON => 'Python',
            BTN_LANG_PHP => 'PHP',
            BTN_LANG_JS => 'JavaScript',
            BTN_LANG_JAVA => 'Java',
            BTN_LANG_CPP => 'C++',
            BTN_LANG_CPLUS => 'C++',
            BTN_LANG_CSHARP => 'C#',
            BTN_LANG_GO => 'Go',
            BTN_LANG_RUBY => 'Ruby'
        ];

        if (isset($langs[$trimmed])) {
            $state['target_lang'] = $langs[$trimmed];
            $state['mode'] = 'convert_input';
            $state['convert_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "✅ আপনি সিলেক্ট করেছেন: <b>{$langs[$trimmed]}</b>\n\nএখন কোড পাঠান (একাধিক মেসেজে পাঠাতে পারেন)। কোড পাঠানো শেষ হলে নিচের <b>🚀 Run Convert</b> বাটনে চাপুন।", convertActionKeyboard());
            return;
        }
    }

    if ($state['mode'] === 'code_submit') {
        $state['code_buffer'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['code_buffer']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", buttonColorMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'single_mode') {
        $state['single_code_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['single_code_parts']);
        sendMessage($chatId, "✅ কোড অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", codeToFileMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'multi_mode') {
        $state['multi_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['multi_parts']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", codeToFileMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'convert_input') {
        $state['convert_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['convert_parts']);
        sendMessage($chatId, "✅ কোড অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)\nআরও থাকলে পাঠান অথবা কনভার্ট করতে নিচের <b>🚀 Run Convert</b> বাটনে চাপুন।", convertActionKeyboard());
        return;
    }
}

function handleCreateColor($chatId, $userId, $state) {
    if (empty($state['code_buffer'])) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি! প্রথমে 📝 Code Submit চেপে কোড পাঠান।", buttonColorMenuKeyboard());
        return;
    }
    $colored = applyButtonStyles(reassembleParts($state['code_buffer']));
    $state['last_result_code'] = $colored;
    $state['code_buffer'] = [];
    $state['mode'] = null;
    saveState($userId, $state);
    broadcastDocument($chatId, 'bot.php', $colored, '🎨 Colored Code');
    sendMessage($chatId, "✅ কালার করা সম্পন্ন হয়েছে!", buttonColorResultKeyboard(true));
}

function handleGeminiConversion($chatId, $userId, &$state) {
    if (empty($state['convert_parts'])) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", convertActionKeyboard());
        return;
    }

    $fullCode = reassembleParts($state['convert_parts']);
    $targetLang = $state['target_lang'] ?? 'Python';

    sendMessage($chatId, "⏳ Gemini AI কোড রূপান্তর করছে ({$targetLang}), অনুগ্রহ করে অপেক্ষা করুন...");

    $convertedCode = convertCodeWithGemini($fullCode, $targetLang);

    $state['last_result_code'] = $convertedCode;
    $state['convert_parts'] = [];
    $state['mode'] = null;
    saveState($userId, $state);

    sendCodeAsCopyable($chatId, $convertedCode);
    sendMessage($chatId, "✅ কোড রূপান্তর সফল হয়েছে!", homeKeyboard());
}

function createAndSendFile($chatId, $userId, $ftName, $state) {
    if (!empty($state['multi_parts'])) {
        $code = reassembleParts($state['multi_parts']);
    } elseif (!empty($state['single_code_parts'])) {
        $code = reassembleParts($state['single_code_parts']);
    } else {
        $code = '';
    }

    if (!$code) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", codeToFileMenuKeyboard());
        return;
    }
    if ($ftName === 'index.zip') {
        $zipContent = buildZipFromCode($code, 'index.php');
        if ($zipContent) broadcastDocument($chatId, 'index.zip', $zipContent, '✅ ZIP Created');
    } else {
        broadcastDocument($chatId, $ftName, $code, '✅ File Created');
    }
}
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
        'single_code_parts' => [],
        'multi_parts' => [],
        'convert_parts' => [],
        'target_lang' => null,
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
        'underline'     => ['open' => '_',   'close' => '_'],
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
const BTN_CONVERT_CODE   = '🔄 Convert Code';
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

// Convert Language Buttons
const BTN_LANG_PYTHON    = '🐍 Python';
const BTN_LANG_PHP       = '🐘 PHP';
const BTN_LANG_JS        = '⚡ JavaScript';
const BTN_LANG_JAVA      = '☕ Java';
const BTN_LANG_CPP       = '⚙️ C++';
const BTN_LANG_CPLUS     = 'C++';
const BTN_LANG_CSHARP    = '🔷 C#';
const BTN_LANG_GO        = '🔵 Go';
const BTN_LANG_RUBY      = '💎 Ruby';
const BTN_RUN_CONVERT    = '🚀 Run Convert';
const BTN_CLEAR_CONVERT  = '🗑 Clear';

function homeKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_BUTTON_COLOR, BTN_CODE_TO_FILE], $c),
        styledRow([BTN_CONVERT_CODE], $c)
    ]);
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

function convertMenuKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_LANG_PYTHON, BTN_LANG_PHP], $c),
        styledRow([BTN_LANG_JS, BTN_LANG_JAVA], $c),
        styledRow([BTN_LANG_CPP, BTN_LANG_CSHARP], $c),
        styledRow([BTN_LANG_GO, BTN_LANG_RUBY], $c),
        styledRow([BTN_HELPLINE, BTN_HOME], $c)
    ]);
}

function convertActionKeyboard() {
    $c = 0;
    return replyKeyboard([
        styledRow([BTN_RUN_CONVERT, BTN_CLEAR_CONVERT], $c),
        styledRow([BTN_CONVERT_CODE, BTN_HOME], $c)
    ]);
}

// ============================================================
// 5. HELP TEXTS
// ============================================================
function homeText() {
    return "🤖 <b>Welcome to Code Utility Bot</b>\n\nনিচের Menu থেকে আপনার প্রয়োজনীয় অপশন নির্বাচন করুন।";
}

function buttonColorHelpText() {
    return "📚 <b>Button Color — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি আপনার PHP কোডের ভেতরে থাকা Telegram বাটনগুলোতে style যুক্ত করে দেয়।\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

function codeToFileHelpText() {
    return "📚 <b>Code To File — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি দিয়ে যেকোনো কোড টেক্সট থেকে সরাসরি ডাউনলোডযোগ্য ফাইল তৈরি করা যায়।\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

function convertCodeHelpText() {
    return "📚 <b>Convert Code — সম্পূর্ণ গাইডলাইন</b>\n\n" .
        "এই ফিচারটি দিয়ে আপনি Gemini AI ব্যবহার করে যেকোনো প্রোগ্রামিং ভাষাকে অন্য ভাষায় রূপান্তর (Convert) করতে পারবেন।\n\n" .
        "<b>ধাপ ১:</b> 🔄 <u>Convert Code</u> এ চাপার পর যে ভাষায় কোড রূপান্তর করতে চান তা সিলেক্ট করুন।\n" .
        "<b>ধাপ ২:</b> আপনার কোড পাঠান (একাধিক মেসেজে পাঠাতে পারেন)।\n" .
        "<b>ধাপ ৩:</b> কোড পাঠানো শেষ হলে নিচের **🚀 Run Convert** বাটনে ক্লিক করুন।\n\n" .
        "🏠 মূল মেনুতে ফিরতে চাইলে Home বাটনে চাপুন।";
}

// ============================================================
// 6. BUTTON-STYLE INJECTION & GEMINI CONVERSION ENGINE
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
    $styledArrayStarts = [];

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        $ivMain = substr($tok[1], 1, -1);
        if ($ivMain !== 'text') { continue; }

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
        if (isset($styledArrayStarts[$start])) { continue; }

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

// Gemini API Code Conversion Function
function convertCodeWithGemini($code, $targetLang) {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE' || empty(GEMINI_API_KEY)) {
        return "❌ Gemini API Key সেট করা হয়নি! কোডের কনফিগারেশনে সঠিক API Key বসান।";
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . GEMINI_API_KEY;
    
    $prompt = "You are an expert programmer. Convert the following code into {$targetLang}. Provide ONLY the converted code inside a proper code block or as raw text without unnecessary explanations so it can be directly copied and used.";

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt . "\n\nCode:\n" . $code]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        return "❌ cURL Error: " . $err;
    }

    $decoded = json_decode($result, true);
    if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
        $convertedText = $decoded['candidates'][0]['content']['parts'][0]['text'];
        $convertedText = preg_replace('/^```[a-z]*\s*\n?/i', '', $convertedText);$convertedText = preg_replace('/\n?```\s*$/', '', $convertedText);
        return trim($convertedText);
    }

    return "❌ কোড কনভার্ট করতে সমস্যা হয়েছে। আবার চেষ্টা করুন।";
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

function sendCodeAsCopyable($chatId, $code) {
    $escaped = htmlspecialchars($code, ENT_NOQUOTES, 'UTF-8');
    $tagOpen = "<pre><code>";
    $tagClose = "</code></pre>";
    $reserveForLabel = 60;
    $maxChunkLen = 4096 - strlen($tagOpen) - strlen($tagClose) - $reserveForLabel;
    if ($maxChunkLen < 500) { $maxChunkLen = 500; }

    $lines = explode("\n", $escaped);
    $chunks = [];
    $current = '';
    foreach ($lines as $line) {
        $candidate = ($current === '') ? $line : ($current . "\n" . $line);
        if (strlen($candidate) > $maxChunkLen && $current !== '') {
            $chunks[] = $current;
            $current = $line;
        } else {
            $current = $candidate;
        }
        while (strlen($current) > $maxChunkLen) {
            $cut = $maxChunkLen;
            $spacePos = strrpos(substr($current, 0, $maxChunkLen), ' ');
            if ($spacePos !== false && $spacePos > $maxChunkLen - 100) {
                $cut = $spacePos;
            }
            $chunks[] = substr($current, 0, $cut);
            $current = substr($current, $cut);
        }
    }
    if ($current !== '' || empty($chunks)) {
        $chunks[] = $current;
    }

    $total = count($chunks);
    foreach ($chunks as $idx => $chunk) {
        $label = $total > 1 ? "📋 <b>Converted Code</b> (অংশ " . ($idx + 1) . "/{$total}):\n" : "📋 <b>Converted Code</b>:\n";
        sendMessage($chatId, $label . $tagOpen . $chunk . $tagClose);
    }
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
        case BTN_CONVERT_CODE:
            $state['menu'] = 'convert_code';
            $state['mode'] = 'select_lang';
            saveState($userId, $state);
            sendMessage($chatId, "🔄 <b>Convert Code</b>\nকোড কোন ভাষায় রূপান্তর করতে চান তা নিচের অপশন থেকে সিলেক্ট করুন:", convertMenuKeyboard());
            return;
        case BTN_RUN_CONVERT:
            if ($state['menu'] === 'convert_code' && !empty($state['convert_parts'])) {
                handleGeminiConversion($chatId, $userId, $state);
            } else {
                sendMessage($chatId, "❌ কোনো কোড জমা করা হয়নি! প্রথমে কোড পাঠান।", convertActionKeyboard());
            }
            return;
        case BTN_CLEAR_CONVERT:
            $state['convert_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "🗑 কনভার্ট করার কোড বাফার পরিষ্কার করা হয়েছে। নতুন কোড পাঠান:", convertActionKeyboard());
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
            if ($state['menu'] === 'button_color') {
                sendMessage($chatId, buttonColorHelpText(), buttonColorMenuKeyboard());
            } elseif ($state['menu'] === 'code_to_file') {
                sendMessage($chatId, codeToFileHelpText(), codeToFileMenuKeyboard());
            } elseif ($state['menu'] === 'convert_code') {
                sendMessage($chatId, convertCodeHelpText(), convertMenuKeyboard());
            } else {
                sendMessage($chatId, homeText(), homeKeyboard());
            }
            return;
        case BTN_DOWNLOAD_PHP:
            if (!empty($state['last_result_code'])) {
                broadcastDocument($chatId, 'bot.php', $state['last_result_code'], '📥 Colored Code File');
            } else {
                sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", buttonColorMenuKeyboard());
            }
            return;
        case BTN_COPY_CODE:
            if (!empty($state['last_result_code'])) {
                sendCodeAsCopyable($chatId, $state['last_result_code']);
            } else {
                sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", homeKeyboard());
            }
            return;
        case BTN_SINGLE_MODE:
            $state['mode'] = 'single_mode';
            $state['single_code_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "📝 কোড পাঠান। শেষ হলে 📦 Create File চাপুন।", codeToFileMenuKeyboard());
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
        case BTN_FT_INDEX_PY:
            createAndSendFile($chatId, $userId, 'index.py', $state);
            return;
        case BTN_FT_INDEX_ZIP:
            createAndSendFile($chatId, $userId, 'index.zip', $state);
            return;
        case BTN_BACK:
            $state['menu'] = 'code_to_file';
            saveState($userId, $state);
            sendMessage($chatId, "📁 <b>Code To File</b> মেনু:", codeToFileMenuKeyboard());
            return;
    }

    // Handle Language Selection for Conversion
    if ($state['menu'] === 'convert_code' && $state['mode'] === 'select_lang') {
        $langs = [
            BTN_LANG_PYTHON => 'Python',
            BTN_LANG_PHP => 'PHP',
            BTN_LANG_JS => 'JavaScript',
            BTN_LANG_JAVA => 'Java',
            BTN_LANG_CPP => 'C++',
            BTN_LANG_CPLUS => 'C++',
            BTN_LANG_CSHARP => 'C#',
            BTN_LANG_GO => 'Go',
            BTN_LANG_RUBY => 'Ruby'
        ];

        if (isset($langs[$trimmed])) {
            $state['target_lang'] = $langs[$trimmed];
            $state['mode'] = 'convert_input';
            $state['convert_parts'] = [];
            saveState($userId, $state);
            sendMessage($chatId, "✅ আপনি সিলেক্ট করেছেন: <b>{$langs[$trimmed]}</b>\n\nএখন কোড পাঠান (একাধিক মেসেজে পাঠাতে পারেন)। কোড পাঠানো শেষ হলে নিচের <b>🚀 Run Convert</b> বাটনে চাপুন।", convertActionKeyboard());
            return;
        }
    }

    if ($state['mode'] === 'code_submit') {
        $state['code_buffer'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['code_buffer']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", buttonColorMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'single_mode') {
        $state['single_code_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['single_code_parts']);
        sendMessage($chatId, "✅ কোড অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", codeToFileMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'multi_mode') {
        $state['multi_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['multi_parts']);
        sendMessage($chatId, "✅ অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)", codeToFileMenuKeyboard());
        return;
    }
    if ($state['mode'] === 'convert_input') {
        $state['convert_parts'][] = $text;
        saveState($userId, $state);
        $partCount = count($state['convert_parts']);
        sendMessage($chatId, "✅ কোড অংশ যোগ হয়েছে। (মোট অংশ: <b>{$partCount}</b>টি)\nআরও থাকলে পাঠান অথবা কনভার্ট করতে নিচের <b>🚀 Run Convert</b> বাটনে চাপুন।", convertActionKeyboard());
        return;
    }
}

function handleCreateColor($chatId, $userId, $state) {
    if (empty($state['code_buffer'])) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি! প্রথমে 📝 Code Submit চেপে কোড পাঠান।", buttonColorMenuKeyboard());
        return;
    }
    $colored = applyButtonStyles(reassembleParts($state['code_buffer']));
    $state['last_result_code'] = $colored;
    $state['code_buffer'] = [];
    $state['mode'] = null;
    saveState($userId, $state);
    broadcastDocument($chatId, 'bot.php', $colored, '🎨 Colored Code');
    sendMessage($chatId, "✅ কালার করা সম্পন্ন হয়েছে!", buttonColorResultKeyboard(true));
}

function handleGeminiConversion($chatId, $userId, &$state) {
    if (empty($state['convert_parts'])) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", convertActionKeyboard());
        return;
    }

    $fullCode = reassembleParts($state['convert_parts']);
    $targetLang = $state['target_lang'] ?? 'Python';

    sendMessage($chatId, "⏳ Gemini AI কোড রূপান্তর করছে ({$targetLang}), অনুগ্রহ করে অপেক্ষা করুন...");

    $convertedCode = convertCodeWithGemini($fullCode, $targetLang);

    $state['last_result_code'] = $convertedCode;
    $state['convert_parts'] = [];
    $state['mode'] = null;
    saveState($userId, $state);

    sendCodeAsCopyable($chatId, $convertedCode);
    sendMessage($chatId, "✅ কোড রূপান্তর সফল হয়েছে!", homeKeyboard());
}

function createAndSendFile($chatId, $userId, $ftName, $state) {
    if (!empty($state['multi_parts'])) {
        $code = reassembleParts($state['multi_parts']);
    } elseif (!empty($state['single_code_parts'])) {
        $code = reassembleParts($state['single_code_parts']);
    } else {
        $code = '';
    }

    if (!$code) {
        sendMessage($chatId, "❌ কোনো কোড পাওয়া যায়নি!", codeToFileMenuKeyboard());
        return;
    }
    if ($ftName === 'index.zip') {
        $zipContent = buildZipFromCode($code, 'index.php');
        if ($zipContent) broadcastDocument($chatId, 'index.zip', $zipContent, '✅ ZIP Created');
    } else {
        broadcastDocument($chatId, $ftName, $code, '✅ File Created');
    }
}
