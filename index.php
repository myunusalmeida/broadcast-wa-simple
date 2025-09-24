<?php
// ======================================================
// CONFIG — ganti dgn kredensial milikmu / pakai ENV
// ======================================================
date_default_timezone_set('Asia/Jakarta');
$WAPANELS_ENDPOINT = 'https://app.wapanels.com/api/create-message';
$APPKEY  = '';
$AUTHKEY = '';

// Storage dirs
$BASE_DIR   = __DIR__;
$STORAGE    = $BASE_DIR . '/storage';
$UPLOAD_DIR = $STORAGE . '/uploads';
$JOBS_DIR   = $STORAGE . '/jobs';
$LOGS_DIR   = $STORAGE . '/logs';

// Ensure dirs exist
foreach ([$STORAGE, $UPLOAD_DIR, $JOBS_DIR, $LOGS_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0775, true);
}

// ======================================================
// Routes: download log
// ======================================================
if (isset($_GET['download_log'])) {
    $job = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['download_log']);
    $logPath = "$LOGS_DIR/wa_{$job}.csv";
    if (is_file($logPath)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hasil_broadcast_' . $job . '.csv"');
        readfile($logPath);
        exit;
    } else {
        http_response_code(404);
        echo "Log not found.";
        exit;
    }
}

// ======================================================
// Helpers
// ======================================================
function normalize_phone($raw)
{
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if (strpos($digits, '6262') === 0) $digits = substr($digits, 2);
    if (strpos($digits, '0') === 0) $digits = '62' . substr($digits, 1);
    if (strpos($digits, '62') !== 0 && strlen($digits) >= 9) $digits = '62' . ltrim($digits, '0');
    return $digits;
}
function post_message($endpoint, $appkey, $authkey, $to, $message, $sandbox = 'false')
{
    $ch = curl_init();
    $payload = [
        'appkey'  => $appkey,
        'authkey' => $authkey,
        'to'      => $to,
        'message' => $message,
        'sandbox' => $sandbox,
    ];
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 35,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$resp, $err, $code];
}
function safe_text($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function fmt_hms($seconds)
{
    $seconds = max(0, (int)round($seconds));
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) return sprintf('%02d:%02d:%02d', $h, $m, $s);
    return sprintf('%02d:%02d', $m, $s);
}
function count_valid_rows($path, $delimiter, $hasHeader)
{
    $fh = @fopen($path, 'r');
    if (!$fh) return 0;
    $total = 0;
    $rowNum = 0;
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        $rowNum++;
        if ($hasHeader && $rowNum === 1) continue;
        if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) continue;
        $total++;
    }
    fclose($fh);
    return $total;
}
function job_path($JOBS_DIR, $job_id)
{
    return $JOBS_DIR . "/{$job_id}.json";
}
function write_log_header_if_needed($logPath)
{
    if (!is_file($logPath)) {
        file_put_contents($logPath, "timestamp,row,name,phone,status,http_code,info\n", LOCK_EX);
    }
}
function append_log($logPath, $row, $name, $phone, $status, $httpCode, $info)
{
    $ts = date('Y-m-d H:i:s');
    $csv = [
        $ts,
        $row,
        str_replace(['"', "\n", "\r"], ['""', ' ', ' '], (string)$name),
        str_replace(['"', "\n", "\r"], ['""', ' ', ' '], (string)$phone),
        $status,
        $httpCode,
        str_replace(['"', "\n", "\r"], ['""', ' ', ' '], substr((string)$info, 0, 500))
    ];
    $line = '"' . implode('","', $csv) . "\"\n";
    file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

// ======================================================
// State
// ======================================================
$view = [
    'results' => [],
    'summary' => null,
    'job'     => null,   // job array
    'hasMore' => false,
    'progress' => 0,
    'eta'     => null,
    'log_url' => null,
    'message_preview' => null,
];

function compute_eta($remaining, $ratePerMin)
{
    $ratePerMin = max(1, (int)$ratePerMin);
    $perMsgSec  = 60.0 / $ratePerMin;
    $totalSec   = $remaining * $perMsgSec;
    $bufferSec  = (int)round($totalSec * 1.2);
    $finish     = date('Y-m-d H:i:s', time() + (int)$totalSec);
    return [
        'plain' => fmt_hms($totalSec),
        'buffer' => fmt_hms($bufferSec),
        'finish' => $finish,
    ];
}

// ======================================================
// Controller
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t0           = microtime(true);
    $startAt      = date('Y-m-d H:i:s');

    // form fields
    $hasHeader    = isset($_POST['has_header']);
    $delimiter    = $_POST['delimiter'] ?? ',';
    if ($delimiter === '\\t') $delimiter = "\t";
    $messageTpl   = trim($_POST['message'] ?? '');
    $ratePerMin   = max(1, (int)($_POST['rate'] ?? 10));
    $sandbox      = isset($_POST['sandbox']) ? 'true' : 'false';
    $dryRun       = isset($_POST['dry_run']);
    $batchSize    = max(1, (int)($_POST['batch_size'] ?? 100));
    $autoContinue = isset($_POST['auto_continue']);
    $job_id       = isset($_POST['job_id']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['job_id']) : null;
    $saved_csv    = $_POST['saved_csv'] ?? null; // absolute path saved earlier

    // Validasi dasar
    if ($messageTpl === '') {
        $view['results'][] = ['row' => '-', 'name' => '-', 'phone' => '-', 'status' => 'FAILED', 'info' => 'Template pesan kosong'];
    } else {
        // --- INIT / RESUME ---
        $isInit = false;
        if (!$saved_csv || !$job_id) {
            // inisialisasi job baru (upload harus ada)
            if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
                $view['results'][] = ['row' => '-', 'name' => '-', 'phone' => '-', 'status' => 'FAILED', 'info' => 'CSV gagal di-upload'];
                $isInit = true; // tetap true untuk render form
            } else {
                $ext = pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION) ?: 'csv';
                $job_id = 'wa_' . str_replace('.', '', uniqid('', true));
                $dest   = $UPLOAD_DIR . "/{$job_id}." . $ext;
                if (!@move_uploaded_file($_FILES['csv']['tmp_name'], $dest)) {
                    $view['results'][] = ['row' => '-', 'name' => '-', 'phone' => '-', 'status' => 'FAILED', 'info' => 'Gagal menyimpan CSV ke storage'];
                    $isInit = true;
                } else {
                    $saved_csv = $dest;
                    $totalData = count_valid_rows($saved_csv, $delimiter, $hasHeader);
                    // tulis job state
                    $job = [
                        'job_id'      => $job_id,
                        'saved_csv'   => $saved_csv,
                        'created_at'  => $startAt,
                        'has_header'  => $hasHeader,
                        'delimiter'   => $delimiter,
                        'rate'        => $ratePerMin,
                        'sandbox'     => $sandbox,
                        'message'     => $messageTpl,
                        'total'       => $totalData,
                        'offset'      => 0,          // berapa data row sudah diproses
                        'batch_size'  => $batchSize,
                        'last_update' => $startAt,
                    ];
                    file_put_contents(job_path($JOBS_DIR, $job_id), json_encode($job, JSON_PRETTY_PRINT), LOCK_EX);
                    // siapkan log
                    $logPath = "$LOGS_DIR/wa_{$job_id}.csv";
                    write_log_header_if_needed($logPath);
                    $view['job'] = $job;
                }
            }
        } else {
            // resume existing job
            $jp = job_path($JOBS_DIR, $job_id);
            $job = is_file($jp) ? json_decode(file_get_contents($jp), true) : null;
            if (!$job) {
                $view['results'][] = ['row' => '-', 'name' => '-', 'phone' => '-', 'status' => 'FAILED', 'info' => 'Job state tidak ditemukan. Mulai ulang.'];
            } else {
                // update pengaturan dinamis (boleh ganti rate/batch/message di tengah)
                $job['has_header'] = $hasHeader;
                $job['delimiter']  = $delimiter;
                $job['rate']       = $ratePerMin;
                $job['sandbox']    = $sandbox;
                $job['message']    = $messageTpl;
                $job['batch_size'] = $batchSize;
                file_put_contents(job_path($JOBS_DIR, $job_id), json_encode($job, JSON_PRETTY_PRINT), LOCK_EX);
                $view['job'] = $job;
            }
        }

        // Jika init gagal (misal upload gagal), render view apa adanya
        if (!$view['job'] && !isset($job)) {
            // no-op
        } else {
            // gunakan $job dari init/resume
            $job = $view['job'] ?? $job;
            $total    = (int)($job['total'] ?? 0);
            $offset   = (int)($job['offset'] ?? 0);
            $logPath  = "$LOGS_DIR/wa_{$job_id}.csv";
            write_log_header_if_needed($logPath);

            // Hitung remaining & ETA (sebelum eksekusi batch ini)
            $remainingBefore = max(0, $total - $offset);
            $view['eta'] = compute_eta($remainingBefore, $ratePerMin);

            // --- DRY RUN: hanya preview, tidak update offset ---
            if ($dryRun) {
                // ambil maksimal batchSize baris utk preview
                $fh = @fopen($saved_csv, 'r');
                if ($fh) {
                    $rowNum = 0;
                    $dataRow = 0;
                    $shown = 0;
                    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                        $rowNum++;
                        if ($hasHeader && $rowNum === 1) continue;
                        if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) continue;
                        if ($dataRow++ < $offset) continue; // preview mulai dari offset saat ini
                        if ($shown >= $batchSize) break;

                        $name  = isset($row[0]) ? trim($row[0]) : '';
                        $phone = normalize_phone(isset($row[1]) ? trim($row[1]) : '');
                        $msg   = str_replace(['{{name}}', '{{phone}}'], [$name, $phone], $messageTpl);
                        $view['results'][] = ['row' => $rowNum, 'name' => $name, 'phone' => $phone, 'status' => 'DRY-RUN', 'info' => substr($msg, 0, 200)];
                        $shown++;
                    }
                    fclose($fh);
                }
                $view['summary'] = [
                    'total'   => $total,
                    'sent'    => 0,
                    'fail'    => 0,
                    'rate'    => $ratePerMin,
                    'delay'   => 60.0 / $ratePerMin,
                    'start'   => $startAt,
                    'finish'  => date('Y-m-d H:i:s'),
                    'elapsed' => microtime(true) - $t0,
                    'avg'     => 0,
                ];
                $view['hasMore'] = ($offset < $total);
                $view['log_url'] = null;
                $view['message_preview'] = true;
            } else {
                // --- EXECUTE ONE BATCH ---
                $fh = @fopen($saved_csv, 'r');
                if (!$fh) {
                    $view['results'][] = ['row' => '-', 'name' => '-', 'phone' => '-', 'status' => 'FAILED', 'info' => 'CSV tidak bisa dibuka'];
                } else {
                    $rowNum = 0;
                    $dataRow = 0;
                    $processedInBatch = 0;
                    $sent = 0;
                    $fail = 0;
                    $delaySeconds = max(0.5, 60.0 / $ratePerMin);
                    $delayMicros  = (int)round($delaySeconds * 1_000_000);

                    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                        $rowNum++;
                        if ($hasHeader && $rowNum === 1) continue;
                        if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) continue;

                        if ($dataRow++ < $offset) continue;               // lompat ke offset
                        if ($processedInBatch >= $batchSize) break;        // batasi 1 batch

                        $name     = isset($row[0]) ? trim($row[0]) : '';
                        $phoneRaw = isset($row[1]) ? trim($row[1]) : '';
                        $phone    = normalize_phone($phoneRaw);

                        if ($phone === '' || strlen($phone) < 8) {
                            $view['results'][] = ['row' => $rowNum, 'name' => $name, 'phone' => $phone, 'status' => 'FAILED', 'info' => 'Nomor tidak valid'];
                            append_log($logPath, $rowNum, $name, $phone, 'FAILED', 0, 'Nomor tidak valid');
                            $fail++;
                        } else {
                            $message = str_replace(['{{name}}', '{{phone}}'], [$name, $phone], $messageTpl);
                            [$resp, $err, $code] = post_message($WAPANELS_ENDPOINT, $APPKEY, $AUTHKEY, $phone, $message, $sandbox);
                            if ($err) {
                                $view['results'][] = ['row' => $rowNum, 'name' => $name, 'phone' => $phone, 'status' => 'FAILED', 'info' => $err];
                                append_log($logPath, $rowNum, $name, $phone, 'FAILED', 0, $err);
                                $fail++;
                            } else {
                                $ok = ($code >= 200 && $code < 300);
                                $status = $ok ? 'SENT' : 'FAILED';
                                $info = "HTTP $code | " . substr($resp ?? '', 0, 200);
                                $view['results'][] = ['row' => $rowNum, 'name' => $name, 'phone' => $phone, 'status' => $status, 'info' => $info];
                                append_log($logPath, $rowNum, $name, $phone, $status, $code, $resp ?? '');
                                $ok ? $sent++ : $fail++;
                            }
                            usleep($delayMicros);
                        }
                        $processedInBatch++;
                    }
                    fclose($fh);

                    // Update offset & job state
                    $offset += $processedInBatch;
                    $job['offset'] = $offset;
                    $job['last_update'] = date('Y-m-d H:i:s');
                    file_put_contents(job_path($JOBS_DIR, $job_id), json_encode($job, JSON_PRETTY_PRINT), LOCK_EX);

                    $elapsed = microtime(true) - $t0;
                    $avg     = $processedInBatch > 0 ? $elapsed / $processedInBatch : 0;

                    $view['summary'] = [
                        'total'   => $total,
                        'sent'    => $sent,
                        'fail'    => $fail,
                        'rate'    => $ratePerMin,
                        'delay'   => $delaySeconds,
                        'start'   => $startAt,
                        'finish'  => date('Y-m-d H:i:s'),
                        'elapsed' => $elapsed,
                        'avg'     => $avg,
                    ];
                    $view['hasMore']  = ($offset < $total);
                    $view['job']      = $job;
                    $view['log_url']  = "?download_log=" . urlencode($job_id);

                    // progress bar
                    $view['progress'] = $total > 0 ? (int)floor(($offset / $total) * 100) : 0;

                    // eta after this batch
                    $remainingAfter = max(0, $total - $offset);
                    $view['eta'] = compute_eta($remainingAfter, $ratePerMin);
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>WA Broadcast CSV — Aman (Batch + Resume)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Tailwind via CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 text-gray-800">
    <div class="max-w-5xl mx-auto p-6">
        <h1 class="text-2xl font-bold mb-1">WA Broadcast dari CSV (Batch + Resume)</h1>
        <p class="text-sm text-gray-600 mb-6">Aman dari timeout: proses per batch, auto-continue, resume job jika tab tertutup. Log lintas batch bisa diunduh.</p>

        <!-- FORM -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6">
            <form method="post" enctype="multipart/form-data" class="space-y-5" id="broadcastForm">
                <?php if (!empty($view['job'])): ?>
                    <!-- Resume mode: gunakan hidden saved_csv & job_id -->
                    <input type="hidden" name="job_id" value="<?= safe_text($view['job']['job_id']) ?>">
                    <input type="hidden" name="saved_csv" value="<?= safe_text($view['job']['saved_csv']) ?>">
                <?php endif; ?>

                <div class="<?= !empty($view['job']) ? 'hidden' : '' ?>">
                    <label class="block font-semibold mb-2">File CSV</label>
                    <input id="csvInput" type="file" name="csv" accept=".csv"
                        class="block w-full rounded-xl p-2 border border-gray-300 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 <?= !empty($view['job']) ? 'pointer-events-none opacity-60' : '' ?>"
                        <?= !empty($view['job']) ? 'disabled' : 'required' ?>>
                    <p class="mt-2 text-xs text-gray-500">Kolom 1: <b>Nama</b>, kolom 2: <b>Nomor</b>.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="has_header" id="has_header" <?= (!empty($view['job']) && $view['job']['has_header']) ? 'checked' : 'checked' ?> class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        <label for="has_header" class="font-medium">CSV punya header?</label>
                    </div>
                    <div>
                        <label class="block font-semibold mb-2">Delimiter</label>
                        <select name="delimiter" id="delimiter" class="w-full rounded-xl p-2 border border-gray-300 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            <?php
                            $delim = !empty($view['job']) ? $view['job']['delimiter'] : ',';
                            $opts = [',' => 'Koma (,)', ';' => 'Titik koma (;)', '\\t' => 'Tab (TAB)'];
                            foreach ($opts as $val => $label) {
                                $sel = ($val === $delim || ($val === '\\t' && $delim === "\t")) ? 'selected' : '';
                                echo "<option value=\"" . safe_text($val) . "\" $sel>" . safe_text($label) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold mb-2">Kecepatan (pesan/menit)</label>
                        <?php $rate = !empty($view['job']) ? (int)$view['job']['rate'] : 20; ?>
                        <select name="rate" id="rate" class="w-full rounded-xl p-2 border border-gray-300 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            <?php foreach ([10, 12, 15, 20, 25, 30] as $r): ?>
                                <option value="<?= $r ?>" <?= $r === $rate ? 'selected' : '' ?>><?= $r ?><?= $r === 10 ? ' (paling aman)' : '' ?><?= $r === 20 ? ' (sedang)' : '' ?><?= $r === 30 ? ' (agak agresif)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Mulai dari 10–15/min, stabil? Naik ke 20–25.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block font-semibold mb-2">Batch size</label>
                        <?php $bs = !empty($view['job']) ? (int)$view['job']['batch_size'] : 100; ?>
                        <input type="number" name="batch_size" min="10" value="<?= $bs ?>" class="w-full rounded-xl p-2 border border-gray-300 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="text-xs text-gray-500 mt-1">100–150 disarankan.</p>
                    </div>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="auto_continue" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" <?= !empty($view['hasMore']) ? 'checked' : 'checked' ?>>
                        <span class="font-medium">Auto-continue batch berikutnya</span>
                    </label>
                    <div></div>
                </div>

                <div>
                    <label class="block font-semibold mb-2">Template Pesan</label>
                    <textarea name="message" rows="5" required placeholder="Halo {{name}}, ini pesan broadcast dari kami."
                        class="p-2 w-full rounded-xl border border-gray-300 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"><?= !empty($view['job']) ? safe_text($view['job']['message']) : '' ?></textarea>
                    <p class="text-xs text-gray-500 mt-1">Placeholder: <code>{{name}}</code>, <code>{{phone}}</code>.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="sandbox" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" <?= !empty($view['job']) && $view['job']['sandbox'] === 'true' ? 'checked' : '' ?>>
                        <span class="font-medium">Mode Sandbox/Test</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="dry_run" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" <?= !empty($view['message_preview']) ? 'checked' : '' ?>>
                        <span class="font-medium">Dry-run (preview tanpa kirim & tanpa update offset)</span>
                    </label>
                </div>

                <?php if (!empty($view['job'])): ?>
                    <!-- Progress & ETA (server-side) -->
                    <?php
                    $total   = (int)$view['job']['total'];
                    $offset  = (int)$view['job']['offset'];
                    $remain  = max(0, $total - $offset);
                    $percent = $total > 0 ? (int)floor(($offset / $total) * 100) : 0;
                    ?>
                    <div class="mt-2">
                        <div class="flex justify-between text-xs text-gray-600 mb-1">
                            <div>Progress</div>
                            <div><?= $percent ?>% (<?= $offset ?>/<?= $total ?>)</div>
                        </div>
                        <div class="w-full h-3 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-3 bg-emerald-500" style="width: <?= $percent ?>%"></div>
                        </div>
                        <?php if (!empty($view['eta'])): ?>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3 text-sm">
                                <div class="p-3 rounded-xl bg-gray-50 border">Sisa kontak: <b><?= $remain ?></b></div>
                                <div class="p-3 rounded-xl bg-gray-50 border">ETA batch berikutnya: <b><?= safe_text($view['eta']['plain']) ?></b> (buffer 20%: <?= safe_text($view['eta']['buffer']) ?>)</div>
                                <div class="p-3 rounded-xl bg-gray-50 border">Perkiraan selesai: <b><?= safe_text($view['eta']['finish']) ?></b></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="flex items-center gap-3">
                    <button type="submit" class="inline-flex items-center px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold shadow-sm">
                        <?= !empty($view['job']) ? 'Proses Batch Ini' : 'Mulai Job' ?>
                    </button>
                    <span class="text-xs text-gray-500">Kredensial disetel di CONFIG. Simpan file ini di server yang aman.</span>
                </div>
            </form>
        </div>

        <!-- HASIL -->
        <?php if (!empty($view['results']) || !empty($view['summary'])): ?>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 space-y-4">
                <h2 class="text-xl font-bold">Hasil (Batch ini)</h2>

                <?php if (!empty($view['summary'])): ?>
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
                        <div class="p-3 rounded-xl bg-gray-50 border">
                            <div class="text-gray-500">Total (Job)</div>
                            <div class="text-lg font-semibold"><?= safe_text($view['summary']['total']) ?></div>
                        </div>
                        <div class="p-3 rounded-xl bg-green-50 border border-green-200">
                            <div class="text-green-700">Sukses (Batch)</div>
                            <div class="text-lg font-semibold text-green-700"><?= safe_text($view['summary']['sent']) ?></div>
                        </div>
                        <div class="p-3 rounded-xl bg-rose-50 border border-rose-200">
                            <div class="text-rose-700">Gagal (Batch)</div>
                            <div class="text-lg font-semibold text-rose-700"><?= safe_text($view['summary']['fail']) ?></div>
                        </div>
                        <div class="p-3 rounded-xl bg-gray-50 border">
                            <div class="text-gray-500">Rate</div>
                            <div class="text-lg font-semibold"><?= safe_text($view['summary']['rate']) ?>/menit (<?= number_format($view['summary']['delay'], 2) ?> dtk/pesan)</div>
                        </div>
                        <div class="p-3 rounded-xl bg-gray-50 border">
                            <div class="text-gray-500">Mulai</div>
                            <div class="text-lg font-semibold"><?= safe_text($view['summary']['start']) ?></div>
                        </div>
                        <div class="p-3 rounded-xl bg-gray-50 border">
                            <div class="text-gray-500">Selesai</div>
                            <div class="text-lg font-semibold"><?= safe_text($view['summary']['finish']) ?></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                        <div class="p-3 rounded-xl bg-gray-50 border">
                            <div class="text-gray-500">Elapsed (Batch)</div>
                            <div class="text-lg font-semibold"><?= fmt_hms($view['summary']['elapsed']) ?></div>
                        </div>
                        <div class="p-3 rounded-xl bg-gray-50 border">
                            <div class="text-gray-500">Rata-rata/pesan (Batch)</div>
                            <div class="text-lg font-semibold"><?= number_format($view['summary']['avg'], 2) ?> dtk</div>
                        </div>
                        <div class="p-3 rounded-xl bg-gray-50 border">
                            <div class="text-gray-500">Unduh Log (Job)</div>
                            <div class="text-lg font-semibold">
                                <?php if (!empty($view['log_url'])): ?>
                                    <a class="text-emerald-700 hover:underline" href="<?= $view['log_url'] ?>">Download hasil_broadcast.csv</a>
                                <?php else: ?>
                                    <span class="text-gray-400">Belum tersedia</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-200 rounded-xl overflow-hidden text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 border-b text-left">#Row</th>
                                <th class="px-3 py-2 border-b text-left">Nama</th>
                                <th class="px-3 py-2 border-b text-left">Nomor</th>
                                <th class="px-3 py-2 border-b text-left">Status</th>
                                <th class="px-3 py-2 border-b text-left">Info</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($view['results'] as $r): ?>
                                <tr class="odd:bg-white even:bg-gray-50">
                                    <td class="px-3 py-2 border-b"><?= safe_text($r['row']) ?></td>
                                    <td class="px-3 py-2 border-b"><?= safe_text($r['name']) ?></td>
                                    <td class="px-3 py-2 border-b"><?= safe_text($r['phone']) ?></td>
                                    <td class="px-3 py-2 border-b">
                                        <?php if ($r['status'] === 'SENT'): ?>
                                            <span class="inline-flex items-center px-2 py-1 text-xs font-semibold text-green-700 bg-green-100 rounded-full">SENT</span>
                                        <?php elseif ($r['status'] === 'DRY-RUN'): ?>
                                            <span class="inline-flex items-center px-2 py-1 text-xs font-semibold text-sky-700 bg-sky-100 rounded-full">DRY-RUN</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-1 text-xs font-semibold text-rose-700 bg-rose-100 rounded-full">FAILED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 border-b">
                                        <pre class="whitespace-pre-wrap text-xs"><?= safe_text($r['info']) ?></pre>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($view['job'])): ?>
                    <?php $hasMore = !empty($view['hasMore']); ?>
                    <form method="post" class="mt-4" id="nextBatchForm">
                        <!-- persist all important fields -->
                        <input type="hidden" name="job_id" value="<?= safe_text($view['job']['job_id']) ?>">
                        <input type="hidden" name="saved_csv" value="<?= safe_text($view['job']['saved_csv']) ?>">
                        <input type="hidden" name="has_header" value="<?= $view['job']['has_header'] ? 'on' : '' ?>">
                        <input type="hidden" name="delimiter" value="<?= $view['job']['delimiter'] === "\t" ? '\\t' : safe_text($view['job']['delimiter']) ?>">
                        <input type="hidden" name="rate" value="<?= (int)$view['job']['rate'] ?>">
                        <input type="hidden" name="batch_size" value="<?= (int)$view['job']['batch_size'] ?>">
                        <input type="hidden" name="message" value="<?= safe_text($view['job']['message']) ?>">
                        <?php if ($view['job']['sandbox'] === 'true'): ?><input type="hidden" name="sandbox" value="on"><?php endif; ?>

                        <div class="flex items-center gap-3">
                            <?php if ($hasMore): ?>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="auto_continue" id="auto_continue" class="rounded border-gray-300 text-emerald-600" checked>
                                    <span class="text-sm">Auto-continue batch berikutnya</span>
                                </label>
                                <button class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">Lanjut Batch Berikutnya</button>
                                <span class="text-xs text-gray-500">Jangan tutup tab saat auto-continue.</span>
                            <?php else: ?>
                                <span class="px-3 py-2 rounded-xl bg-emerald-50 text-emerald-700 text-sm font-semibold">Selesai semua 🎉</span>
                                <?php if (!empty($view['log_url'])): ?>
                                    <a class="text-sm text-emerald-700 underline" href="<?= $view['log_url'] ?>">Download log</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if ($hasMore): ?>
                        <script>
                            // Auto-continue setelah 1.5 detik
                            setTimeout(function() {
                                const ac = document.getElementById('auto_continue');
                                if (ac && ac.checked) document.getElementById('nextBatchForm').submit();
                            }, 1500);
                        </script>
                    <?php endif; ?>
                <?php endif; ?>

                <p class="text-xs text-gray-500">Tips: gunakan <b>batch 100–150</b> + <b>rate 10–20/min</b>. Jika stabil, naikan perlahan.</p>
            </div>
        <?php endif; ?>

        <!-- Info format -->
        <div class="mt-6 text-xs text-gray-500">
            <p><b>Format CSV minimal:</b> <code>Nama,Nomor</code> di dua kolom pertama. Baris kosong dilewati.</p>
            <pre class="mt-2 bg-white border border-gray-200 rounded-xl p-4">Nama,Nomor
Budi,6281234567890
Sari,081234567890
</pre>
        </div>
    </div>
</body>

</html>
