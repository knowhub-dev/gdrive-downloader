<?php
// =================================================================
// Google Drive Downloader (Matrix Edition)
// © 2024 SamDevX. Barcha huquqlar himoyalangan.
// Ushbu skript Google Drive'dagi katta hajmli fayllarni,
// jumladan virus skanerlash sahifasini chetlab o'tib
// yuklab olish uchun mo'ljallangan.
// =================================================================

// --- CONFIGURATION ---
$realPassword = 'matrixCore2025'; // Kirish uchun parol

// --- SESSION & HELPERS ---
session_start();

// Bu funksiya SSE (Server-Sent Events) orqali front-end'ga xabar yuboradi
function send_sse_message($message, $event = 'log') {
    $sanitizedMessage = strip_tags($message, '<a><b><i>');
    $data = ['message' => $sanitizedMessage, 'event' => $event];
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

// Xavfsiz fayl nomini yaratish
function safeFilename($filename) {
    $filename = str_replace(' ', '_', $filename);
    return preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $filename);
}

// 'downloads' papkasini yaratish
function createDownloadsDir() {
    $dir = __DIR__ . '/downloads';
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            send_sse_message("📂 'downloads/' papkasi yaratildi.");
        } else {
            send_sse_message("❌ 'downloads/' papkasini yaratib bo'lmadi. Ruxsatlarni tekshiring.", 'error');
            exit();
        }
    }
    return $dir;
}


// --- MAIN LOGIC: SSE orqali fayl yuklash ---
if (isset($_GET['start_download']) && !empty($_SESSION['access_granted'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    $driveUrl = trim($_GET['drive_link']);
    if (empty($driveUrl)) {
        send_sse_message("❌ Google Drive linki kiritilmagan.", 'error');
        exit();
    }

    send_sse_message("🚀 Jarayon boshlandi...");
    send_sse_message("🌐 Kiritilgan link: " . htmlspecialchars($driveUrl));

    if (!preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $driveUrl, $match) && !preg_match('/id=([a-zA-Z0-9_-]+)/', $driveUrl, $match)) {
        send_sse_message("❌ Noto'g'ri Google Drive link formati. Fayl ID topilmadi.", 'error');
        exit();
    }

    $fileId = $match[1];
    send_sse_message("📥 Fayl ID topildi: <b>$fileId</b>");

    $cookieFile = tempnam(sys_get_temp_dir(), 'GDRIVE_COOKIE_');
    $initUrl = "https://drive.google.com/uc?export=download&id=$fileId";
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

    // 1-QADAM: RAZVEDKA - Dastlabki GET so'rovi
    send_sse_message("🕵️‍♂️ Razvedka boshlandi... Boshlang'ich javob kutilmoqda.");
    $ch = curl_init($initUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        send_sse_message("❌ Google serveriga ulanib bo'lmadi (1-qadam).", 'error');
        unlink($cookieFile);
        exit();
    }

    $downloadUrl = $initUrl;

    // 2-QADAM: DUSHMAN SAHIFASINI TAHLIL QILISH
    if (strpos($response, 'download-form') !== false) {
        send_sse_message("🛡️ Dushman (virus scan) sahifasi aniqlandi. Maxfiy ma'lumotlar yig'ilmoqda...");
        
        $dom = new DOMDocument();
        @$dom->loadHTML($response);
        $xpath = new DOMXPath($dom);
        $form = $xpath->query('//form[@id="download-form"]')->item(0);

        if ($form) {
            $actionUrl = $form->getAttribute('action');
            if ($actionUrl) {
                $decodedUrl = html_entity_decode($actionUrl, ENT_QUOTES, 'UTF-8');
                $downloadUrl = (strpos($decodedUrl, 'http') === 0) ? $decodedUrl : 'https://drive.google.com' . $decodedUrl;

                $formData = [];
                $inputs = $xpath->query('.//input[@type="hidden"]', $form);
                foreach ($inputs as $input) {
                    $formData[$input->getAttribute('name')] = $input->getAttribute('value');
                }
                
                // "Aldamchi Kalit" strategiyasi
                $queryString = http_build_query($formData);
                $downloadUrl .= (strpos($downloadUrl, '?') === false ? '?' : '&') . $queryString;

                send_sse_message("🔑 Aldamchi kalit yaratildi. Yangi manzil: " . htmlspecialchars(substr($downloadUrl, 0, 100))."...");
                send_sse_message("✅ Maxfiy ma'lumotlar muvaffaqiyatli qayta ishlandi.");
            }
        } else {
             send_sse_message("❌ Dushman sahifasini tahlil qilib bo'lmadi. Yuklash to'xtatildi.", 'error');
             unlink($cookieFile);
             exit();
        }
    } else {
        send_sse_message("⚠️ Dushman sahifasi yo'q. To'g'ridan-to'g'ri yuklashga urinilmoqda.");
    }

    // 3-QADAM: HAL QILUVCHI ZARBA - Faylni yuklash
    send_sse_message("⏬ Fayl yuklanishi boshlanmoqda...");

    $downloadsDir = createDownloadsDir();
    $tempFilename = 'download_' . uniqid() . '.tmp';
    $destinationPath = $downloadsDir . '/' . $tempFilename;
    $destinationFile = fopen($destinationPath, 'w');

    if (!$destinationFile) {
        send_sse_message("❌ Faylni yozish uchun ochib bo'lmadi: " . htmlspecialchars($destinationPath), 'error');
        unlink($cookieFile);
        exit();
    }
    
    $ch = curl_init($downloadUrl);
    curl_setopt($ch, CURLOPT_FILE, $destinationFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_REFERER, $initUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Origin: https://drive.google.com']);

    send_sse_message("💥 Hal qiluvchi zarba (GET): Tasdiqlash so'rovi yuborilmoqda...");

    $realFilename = '';
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$realFilename) {
        if (stripos($header, 'Content-Disposition:') === 0) {
            if (preg_match('/filename\*?=(?:UTF-8\'\')?\"?([^\"]+)\"?/i', $header, $matches)) {
                $realFilename = rawurldecode(basename(trim($matches[1], '"')));
            }
        }
        return strlen($header);
    });

    curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($destinationFile);
    
    if ($error) {
        send_sse_message("❌ Faylni yuklashda cURL xatoligi: $error", 'error');
        @unlink($destinationPath);
        unlink($cookieFile);
        exit();
    }

    $fileSize = filesize($destinationPath);
    if ($fileSize > 0) {
        $finalFilename = $realFilename ? safeFilename($realFilename) : 'downloaded_file_' . $fileId . '.zip';
        $finalPath = $downloadsDir . '/' . $finalFilename;
        
        if (file_exists($finalPath)) {
            $finalFilename = pathinfo($finalFilename, PATHINFO_FILENAME) . '_' . uniqid() . '.' . pathinfo($finalFilename, PATHINFO_EXTENSION);
            $finalPath = $downloadsDir . '/' . $finalFilename;
        }

        if (rename($destinationPath, $finalPath)) {
            $fileSavedPath = "downloads/" . $finalFilename;
            send_sse_message("<b>" . htmlspecialchars($finalFilename) . "</b> (" . round($fileSize / 1024 / 1024, 2) . " MB)", 'filename');
            send_sse_message("✅ Fayl muvaffaqiyatli saqlandi: <a href='$fileSavedPath' target='_blank'>$fileSavedPath</a>", 'complete');
        } else {
            send_sse_message("❌ Fayl nomini o'zgartirib bo'lmadi.", 'error');
        }
    } else {
        send_sse_message("❌ Yuklangan fayl bo'sh (0 bayt). Dushman g'olib chiqdi. Linkni tekshiring.", 'error');
        @unlink($destinationPath);
    }

    unlink($cookieFile);
    exit();
}

// --- Parolni tekshirish ---
$responseLog = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_password'])) {
    if ($_POST['access_password'] === $realPassword) {
        $_SESSION['access_granted'] = true;
        $responseLog[] = "🔐 Parol tasdiqlandi. Ruxsat berildi.";
    } else {
        $responseLog[] = "❌ Noto‘g‘ri parol!";
        $_SESSION['access_granted'] = false;
    }
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Google Drive Downloader by SamDevX</title>
<style>
    :root {
        --matrix-green: #00ff41;
        --background: #000;
        --border-glow: 0 0 8px var(--matrix-green);
    }
    body {
        background-color: var(--background);
        color: var(--matrix-green);
        font-family: 'Courier New', Courier, monospace;
        padding: 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
        min-height: 100vh;
        margin: 0;
    }
    h1 {
        text-shadow: var(--border-glow);
        letter-spacing: 3px;
        text-align: center;
    }
    .container {
        width: 95%;
        max-width: 800px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    form {
        margin-bottom: 20px;
        text-align: center;
    }
    label {
        display: block;
        margin-bottom: 10px;
        text-shadow: 0 0 5px var(--matrix-green);
    }
    input[type=text], input[type=password] {
        background: #0d0d0d;
        border: 1px solid var(--matrix-green);
        color: var(--matrix-green);
        padding: 12px;
        font-size: 16px;
        margin: 10px 0;
        width: 100%;
        max-width: 400px;
        box-sizing: border-box;
        outline: none;
        transition: box-shadow 0.3s ease;
    }
    input[type=text]:focus, input[type=password]:focus {
        box-shadow: var(--border-glow);
    }
    button, input[type=submit] {
        background: transparent;
        border: 1px solid var(--matrix-green);
        color: var(--matrix-green);
        font-weight: bold;
        padding: 10px 20px;
        cursor: pointer;
        margin-top: 10px;
        transition: background 0.3s ease, color 0.3s ease;
        font-family: 'Courier New', Courier, monospace;
        font-size: 16px;
    }
    button:hover, input[type=submit]:hover {
        background: var(--matrix-green);
        color: var(--background);
    }
    button:disabled {
        border-color: #555;
        color: #555;
        cursor: not-allowed;
    }
    .log-window {
        background-color: rgba(0, 0, 0, 0.8);
        border: 1px solid var(--matrix-green);
        color: var(--matrix-green);
        width: 100%;
        height: 400px;
        overflow-y: auto;
        white-space: pre-wrap;
        font-family: 'Courier New', Courier, monospace;
        font-size: 14px;
        padding: 15px;
        box-shadow: inset var(--border-glow);
        margin-top: 20px;
    }
    .log-window p {
        margin: 0 0 5px 0;
        word-wrap: break-word;
    }
    .log-window p.error { color: #ff4141; }
    .log-window p.complete { color: #41a1ff; }
    .log-window p.filename { color: #ffff00; font-weight: bold; }
    .log-window a { color: #33aaff; text-decoration: underline; }
    .log-window a:hover { color: #55ccff; }
    .blinking-cursor {
        display: inline-block;
        width: 8px;
        height: 1.2em;
        background-color: var(--matrix-green);
        animation: blink 1s step-end infinite;
        margin-left: 5px;
        vertical-align: bottom;
    }
    @keyframes blink {
        from, to { background-color: transparent; }
        50% { background-color: var(--matrix-green); }
    }
    .footer {
        margin-top: 40px;
        font-size: 12px;
        color: #888;
        text-align: center;
    }
    .footer a {
        color: var(--matrix-green);
        text-decoration: none;
    }
</style>
</head>
<body>

<div class="container">
    <h1>G-DRIVE DOWNLOADER [MATRIX CORE]</h1>

    <?php if (empty($_SESSION['access_granted'])): ?>
    <form method="POST">
        <label for="access_password">ACCESS CODE REQUIRED:</label>
        <input type="password" name="access_password" id="access_password" placeholder="************" required autofocus />
        <br/>
        <input type="submit" value="[ENTER]" />
    </form>
    <?php else: ?>
    <form id="downloadForm">
        <label for="drive_link">TARGET FILE URL:</label>
        <input type="text" name="drive_link" id="drive_link" placeholder="https://drive.google.com/file/d/FILE_ID/..." required autofocus />
        <br/>
        <button type="submit" id="submitBtn">[INITIATE DOWNLOAD]</button>
    </form>
    <?php endif; ?>

    <div class="log-window" id="logWindow">
    <?php
    // Parol xatosi kabi bir martalik xabarlarni ko'rsatish
    if (!empty($responseLog)) {
        foreach ($responseLog as $line) {
            echo '<p>' . htmlspecialchars($line) . '</p>';
        }
    } else {
        echo '<p>> System standby... Awaiting input.<span id="cursor" class="blinking-cursor"></span></p>';
    }
    ?>
    </div>
</div>

<footer class="footer">
    <p>&copy; 2024 Dastur muallifi: <a href="#" target="_blank">SamDevX</a>. Barcha huquqlar himoyalangan.</p>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const downloadForm = document.getElementById('downloadForm');
    if (!downloadForm) return;

    const logWindow = document.getElementById('logWindow');
    const submitBtn = document.getElementById('submitBtn');
    const linkInput = document.getElementById('drive_link');

    downloadForm.addEventListener('submit', (e) => {
        e.preventDefault();

        const driveLink = linkInput.value;
        if (!driveLink) {
            alert('Iltimos, Google Drive linkini kiriting.');
            return;
        }

        // Konsolni tozalash va jarayonni boshlash
        logWindow.innerHTML = '<p>> Initializing connection...<span id="cursor" class="blinking-cursor"></span></p>';
        submitBtn.disabled = true;
        submitBtn.textContent = '[PROCESSING...]';

        const url = ?start_download=1&drive_link=${encodeURIComponent(driveLink)};
        const eventSource = new EventSource(url);

        eventSource.onmessage = (event) => {
            const cursor = document.getElementById('cursor');
            if (cursor) {
                cursor.parentElement.remove(); // Eski kursorni o'chirish
            }

            const data = JSON.parse(event.data);
            const p = document.createElement('p');
            p.innerHTML = > ${data.message}; // Xabarni HTML sifatida qo'yish
            
            if (data.event) {
                p.classList.add(data.event);
            }

            logWindow.appendChild(p);

            // Yangi kursor qo'shish
            const newCursorP = document.createElement('p');
            newCursorP.innerHTML = '> <span id="cursor" class="blinking-cursor"></span>';
            logWindow.appendChild(newCursorP);
            
            logWindow.scrollTop = logWindow.scrollHeight; // Avtomatik pastga o'tkazish
            
            // Jarayon tugaganda
            if (data.event === 'complete' || data.event === 'error') {
                eventSource.close();
                submitBtn.disabled = false;
                submitBtn.textContent = '[INITIATE DOWNLOAD]';
                 const finalCursor = document.getElementById('cursor');
                 if(finalCursor) finalCursor.style.display = 'none'; // Kursorni yashirish
            }
        };

        eventSource.onerror = () => {
            const cursor = document.getElementById('cursor');
            if (cursor) {
                cursor.parentElement.innerHTML = "> [CONNECTION ERROR]: Server bilan aloqa uzildi.";
            }
            logWindow.scrollTop = logWindow.scrollHeight;
            eventSource.close();
            submitBtn.disabled = false;
            submitBtn.textContent = '[INITIATE DOWNLOAD]';
        };
    });
});
</script>

</body>
</html>