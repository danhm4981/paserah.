<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}
$cfg = require __DIR__ . '/config_ai.php';

const TTS_ALLOWED_SRC = ['reminder', 'assistant'];

function fail($msg, $code = 502) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) fail('انتهت الجلسة', 401);

$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$src = (string) ($in['src'] ?? '');
if (!in_array($src, TTS_ALLOWED_SRC, true)) fail('مصدر غير مسموح', 403);

$text = trim(mb_substr((string) ($in['text'] ?? ''), 0, 400));
if ($text === '') fail('لا يوجد نص', 400);

// المساعد: لا نقبل إلا جملًا ولّدها assistant.php لهذه الجلسة (حماية الحصة من نصوص عشوائية)
if ($src === 'assistant') {
    $allowed = (array) ($_SESSION['ast_say'] ?? []);
    if (!in_array(hash('sha256', $text), $allowed, true)) fail('عبارة غير مصرّح بها', 403);
}
session_write_close();   // لا نحجز الجلسة أثناء التوليد (الطلبات تأتي بالتوازي)

$model = $cfg['tts_model'] ?? 'gemini-3.8-flash-tts';
// النماذج القديمة أوقفتها Google: نستبدلها حتى لو بقيت مكتوبة في config_ai.php
if (in_array($model, ['gemini-2.5-flash-preview-tts', 'gemini-2.5-pro-preview-tts', 'gemini-3.1-flash-tts-preview'], true)) {
    $model = 'gemini-3.8-flash-tts';
}
$voice = $cfg['tts_voice'] ?? 'Kore';
$style = $cfg['tts_style'] ?? '';

// ===== ذاكرة مؤقتة: نفس النص + الصوت + الأسلوب = نفس الملف =====
$cacheDir = __DIR__ . '/tts_cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0750, true);
    @file_put_contents($cacheDir . '/.htaccess', "Require all denied\n");
}
$cacheFile = $cacheDir . '/' . hash('sha256', $model . '|' . $voice . '|' . $style . '|' . $text) . '.wav';

if (is_file($cacheFile)) {
    header('Content-Type: audio/wav');
    header('Content-Length: ' . filesize($cacheFile));
    header('Cache-Control: no-store');
    readfile($cacheFile);
    exit;
}

// إن نفدت الحصة مؤخرًا لا نكرر المحاولة مع Google (يرجع المتصفح لصوته فورًا)
$blockFile = $cacheDir . '/.quota_block';
if (is_file($blockFile) && (int) @file_get_contents($blockFile) > time()) fail('الحصة منتهية مؤقتًا', 429);

// ===== طلب الصوت من Gemini مع إعادة المحاولة عند الأعطال المؤقتة =====
// نماذج 3.8 تقرأ النص حرفيًا، لذلك يُرسل الأسلوب في حقل مستقل
$part = ['text' => $text];
$styleText = trim($style, " :\r\n\t");
if ($styleText !== '') $part['speech_metadata'] = ['style' => $styleText];
$body = [
    'contents' => [['role' => 'user', 'parts' => [$part]]],
    'generationConfig' => [
        'responseModalities' => ['AUDIO'],
        'speechConfig' => ['voiceConfig' => ['voice' => $voice]],
    ],
];
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
$inline = null; $lastErr = 'unknown'; $quota = false;

for ($try = 0; $try < 3 && !$inline; $try++) {
    if ($try) usleep(700000 * $try);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $cfg['gemini_key']],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_SSL_VERIFYPEER => (bool) $cfg['ssl_verify'],
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($res === false) { $lastErr = 'curl: ' . $err; continue; }

    $j = json_decode($res, true);
    if ($code !== 200) {
        $lastErr = 'HTTP ' . $code . ' ' . ($j['error']['message'] ?? '');
        if ($code === 429 && stripos($lastErr, 'quota') !== false) { $quota = true; break; }
        if (in_array($code, [400, 401, 403, 404], true)) break;
        continue;
    }

    foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) {
        if (isset($p['inlineData']['data'])) { $inline = $p['inlineData']; break; }
    }
    if (!$inline) $lastErr = 'لم يصل صوت (finish: ' . ($j['candidates'][0]['finishReason'] ?? '?') . ')';
}

if (!$inline) {
    if ($quota) {
        // الحد اليومي: نحجب ساعة. حد الدقيقة: 75 ثانية فقط
        $perDay = stripos($lastErr, 'per day') !== false || stripos($lastErr, 'PerDay') !== false;
        @file_put_contents($blockFile, (string) (time() + ($perDay ? 3600 : 75)));
    }
    fail($lastErr, $quota ? 429 : 502);
}
@unlink($blockFile);

// ===== PCM خام إلى WAV =====
$pcm  = base64_decode($inline['data']);
$rate = 24000;
if (preg_match('/rate=(\d+)/', $inline['mimeType'] ?? '', $m)) $rate = (int) $m[1];
$len = strlen($pcm);
// النماذج الجديدة ترجع ملف WAV جاهزًا، فلا نضيف ترويسة ثانية
$wav = strncmp($pcm, 'RIFF', 4) === 0 ? $pcm
     : 'RIFF' . pack('V', 36 + $len) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
     . 'data' . pack('V', $len) . $pcm;

$tmp = $cacheFile . '.' . uniqid('', true) . '.tmp';
if (@file_put_contents($tmp, $wav, LOCK_EX) !== false) @rename($tmp, $cacheFile);

header('Content-Type: audio/wav');
header('Content-Length: ' . strlen($wav));
header('Cache-Control: no-store');
echo $wav;
