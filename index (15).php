<?php
/**
 * index.php (thư mục de-thi)
 * – Khi thí sinh chọn một câu (bất kỳ phần 1,2,3,4), chỉ gửi AJAX “autosaveSingle”
 *   để lưu câu vừa chọn (merge vào JSON).
 * – Mỗi 2s vẫn gửi “autosaveTime” để cập nhật remainingSeconds.
 */

// --- XỬ LÝ BÁO LỖI (AJAX POST tới index.php?reportError=1) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['reportError'])) {
    // Lấy dữ liệu (ưu tiên POST), sanitize
    $exam      = preg_replace('/[^A-Za-z0-9_\-]/', '', $_POST['exam'] ?? ($_GET['exam'] ?? ''));
    $studentID = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $_POST['studentID'] ?? '');
    $content   = trim($_POST['error_content'] ?? '');
    $email     = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';

    if ($content === '' || $studentID === '') {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Thiếu thông tin báo lỗi (số báo danh / nội dung).']);
        exit;
    }

    date_default_timezone_set('Asia/Ho_Chi_Minh');
    $errorDir = __DIR__ . "";
    if (!is_dir($errorDir)) mkdir($errorDir, 0755, true);
    $errorFile = $errorDir . "/error.json";

    // Đọc hiện tại
    $errors = [];
    if (is_file($errorFile)) {
        $raw = file_get_contents($errorFile);
        $errors = json_decode($raw, true) ?: [];
    }

    $record = [
        'exam'      => $exam,
        'studentID' => $studentID,
        'email'     => $email,
        'time'      => (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s'),
        'content'   => $content,
        'user_ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua'        => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    $errors[] = $record;

    file_put_contents($errorFile, json_encode($errors, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

    // (TÙY CHỌN) gửi webhook (vd: Google Apps Script)
    $webhookUrl = 'https://script.google.com/macros/s/AKfycbxKBgmu6P4MNGigFWUMvxNRC44VCvXNwwwpi3XBvjHlrbxXCz-ySqVLP1K7OcJknvk5kw/exec';
    if ($webhookUrl) {
        $payload = json_encode($record);
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if (curl_errno($ch) || strpos($response, '"status":"ok"') === false) {
            error_log("Webhook gửi lỗi: " . curl_error($ch) . " / Resp: {$response}");
        }
        curl_close($ch);
    }

    // Trả về JSON cho client
    echo json_encode(['status'=>'ok']);
    exit;
}

// ------------------ BƯỚC 0: HÀM TIỆN ÍCH ------------------
$tz = new DateTimeZone('Asia/Ho_Chi_Minh');
/**
 * Trả về đường dẫn tuyệt đối tới file JSON lưu data của thí sinh
 */
function getSavePath($examName, $studentID) {
    return __DIR__ . "/$examName/$studentID.json";
}

/**
 * Kiểm tra file tồn tại và startTime chưa quá 2 ngày (và chưa submit)
 */
function canResume($filePath) {
    if (!file_exists($filePath)) return false;
    $raw = file_get_contents($filePath);
    $data = json_decode($raw, true);
    if (!$data || empty($data['startTime'])) return false;
    $startTs = strtotime($data['startTime']);
    if ($startTs === false) return false;
    if (time() - $startTs > 2 * 24 * 3600) return false;
    if (!empty($data['submitTime'])) return false;
    return true;
}

/**
 * Ghi JSON data vào file, với JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
 */
function writeJsonFile($path, $arr) {
    file_put_contents($path, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Load time.json cho exam, trả về mảng 4 trường hoặc giá trị rỗng nếu chưa có
 */
/**
 * Load time.json cho exam, trả về các mốc start/end hoặc chuỗi rỗng
 */
function loadTimeFile($examName) {
    $path = __DIR__ . "/$examName/time.json";
    if (!file_exists($path)) {
        return [
            'realNameExam'   => '',
            'timeStartExam'  => '',
            'timeEndExam'    => '',
            'timeStartKey'   => '',
            'timeEndKey'     => '',
            'note'           => '',
            'session'        => ''
        ];
    }
    $data = json_decode(file_get_contents($path), true);
    return [
        'realNameExam'   => $data['realNameExam']   ?? '',
        'timeStartExam'  => $data['timeStartExam']  ?? '',
        'timeEndExam'    => $data['timeEndExam']    ?? '',
        'timeStartKey'   => $data['timeStartKey']   ?? '',
        'timeEndKey'     => $data['timeEndKey']     ?? '',
        'note'           => $data['note']           ?? '',
        'session'        => $data['session']        ?? ''
    ];
}

// --- HÀM HỖ TRỢ: archive file cũ và trả về mảng các "time-N" để merge vào file mới ---
function archiveOldSaveAndReturn(string $filePath) {
    if (!file_exists($filePath)) return [];

    $raw = file_get_contents($filePath);
    $old = json_decode($raw, true);
    if (!is_array($old)) return [];

    // Lấy ra tất cả các time-N (nếu có) và phần còn lại (lần làm gần nhất)
    $archives = [];
    foreach ($old as $k => $v) {
        if (preg_match('/^time-(\d+)$/', $k, $m)) {
            $archives[(int)$m[1]] = $v;
            unset($old[$k]);
        }
    }
    // Phần còn lại (lần gần nhất) -> thêm vào cuối danh sách
    $archives[] = $old;

    // Sắp xếp theo index numeric (nếu có), rồi reindex thành time-1, time-2, ...
    ksort($archives, SORT_NUMERIC);
    $reindexed = [];
    $i = 1;
    foreach ($archives as $a) {
        $reindexed["time-{$i}"] = $a;
        $i++;
    }

    // Tạo thư mục backup và lưu backup file theo timestamp
    $archiveDir = __DIR__ . '/archive';
    if (!is_dir($archiveDir)) @mkdir($archiveDir, 0755, true);
    $basename = basename($filePath);
    $stamp = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Ymd_His');
    $backupFile = $archiveDir . '/' . $basename . '.' . $stamp . '.backup.json';
    file_put_contents($backupFile, json_encode($reindexed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Xóa file cũ (đã backup). Nếu bạn muốn giữ file cũ, comment dòng này đi.
    @unlink($filePath);

    return $reindexed; // ['time-1'=>..., 'time-2'=>...]
}

// --- CHÈN VÀO ĐÂY để khởi tạo $segments, $indexDeThi, $examNameURL, $studentIDURL ---
$requestUri   = $_SERVER['REQUEST_URI'];
$requestPath  = parse_url($requestUri, PHP_URL_PATH);
$segments     = explode('/', trim($requestPath, '/'));
$indexDeThi   = array_search('bai-thi', $segments);
$examNameURL  = ($indexDeThi !== false && isset($segments[$indexDeThi + 1]))
                ? $segments[$indexDeThi + 1] : '';
$studentIDURL = ($indexDeThi !== false && isset($segments[$indexDeThi + 2]))
                ? $segments[$indexDeThi + 2] : '';
// --- KẾT THÚC CHÈN ---

// Lấy examName từ URL
$examName = $_GET['examName'] ?? $examNameURL;

// Load các mốc start/end
$timeData   = loadTimeFile($examName);
$tz   = new DateTimeZone('Asia/Ho_Chi_Minh');
$now  = new DateTime('now', $tz);
$startExam = $timeData['timeStartExam']
    ? DateTime::createFromFormat('Y-m-d\TH:i', $timeData['timeStartExam'], $tz)
    : null;
$endExam   = $timeData['timeEndExam']
    ? DateTime::createFromFormat('Y-m-d\TH:i', $timeData['timeEndExam'], $tz)
    : null;

// ------------------ BƯỚC 1: XỬ LÝ AUTOSAVE SINGLE (câu mới) & AUTOSAVE TIME ------------------

$action = $_GET['action'] ?? '';
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($action, ['autosaveSingle','autosaveTime','toggleFlag'], true)
) {
    // Lấy examName/studentID (từ GET hoặc từ URL segment) như đã hướng dẫn
    $examName  = $_GET['examName']   ?? $examNameURL;
    $studentID = $_GET['studentID']  ?? $studentIDURL;

    // kiểm tra thiếu
    if (!$examName || !$studentID) {
        http_response_code(400);
        echo json_encode(['error' => 'Thiếu examName hoặc studentID thí sinh']);
        exit;
    }
    $savePath = getSavePath($examName, $studentID);
    if (!file_exists($savePath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Chưa bắt đầu thi hoặc file không tồn tại']);
        exit;
    }
    // Đọc dữ liệu cũ
    $existing = json_decode(file_get_contents($savePath), true);
    if (!$existing) $existing = [];

    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Body không phải JSON hợp lệ']);
        exit;
    }

    if ($action === 'autosaveSingle') {
        /**
         * Dữ liệu expected: 
         * {
         *   "code": "1.1", 
         *   "value": "B"
         * }
         * Cập nhật existing['answers'][code] = value
         * Đồng thời update existing['lastSaveTime']
         */
        $code  = $data['code']  ?? null;
        $value = $data['value'] ?? null;
        if (!$code) {
            http_response_code(400);
            echo json_encode(['error' => 'Thiếu code']);
            exit;
        }
        // Merge vào answers
        if (!isset($existing['answers']) || !is_array($existing['answers'])) {
            $existing['answers'] = [];
        }
        $existing['answers'][$code] = $value;
        $existing['lastSaveTime'] = date('Y-m-d H:i:s');
        // <<< THÊM Ở ĐÂY: cập nhật exitCount & screenshotCount
        $existing['exitCount']        = intval($data['exitCount']        ?? 0);
        $existing['screenshotCount']  = intval($data['screenshotCount']  ?? 0);
        // <<< HẾT PHẦN THÊM >>>
        writeJsonFile($savePath, $existing);
        echo json_encode(['status' => 'ok']);
        exit;
    }
     elseif ($action === 'toggleFlag') {
        /**
         * Expect JSON body:
         * { "code": "1.1", "flag": true }
         * Lưu vào existing['flag'] dưới dạng object: { "1.1": true, ... }
         */
        $code = $data['code'] ?? null;
        // dùng FILTER_VALIDATE_BOOLEAN để chấp nhận true/false/"true"/"false"/1/0
        $flag = array_key_exists('flag', $data)
                ? filter_var($data['flag'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null;

        if (!$code || $flag === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Thiếu code hoặc flag không hợp lệ']);
            exit;
        }

        if (!isset($existing['flag']) || !is_array($existing['flag'])) {
            $existing['flag'] = [];
        }

        if ($flag) {
            // đặt true (lưu như object key để xuất thành {"1.1":true})
            $existing['flag'][$code] = true;
        } else {
            // gỡ cờ
            if (isset($existing['flag'][$code])) {
                unset($existing['flag'][$code]);
            }
        }

        $existing['lastSaveTime'] = date('Y-m-d H:i:s');
        writeJsonFile($savePath, $existing);
        echo json_encode(['status' => 'ok', 'flag' => $flag, 'code' => $code]);
        exit;
    }
    elseif ($action === 'autosaveTime') {
        /**
         * Dữ liệu expected:
         * {
         *    "remainingSeconds": 1234
         * }
         */
        $remainingSeconds = $data['remainingSeconds'] ?? null;
        if (!is_numeric($remainingSeconds)) {
            http_response_code(400);
            echo json_encode(['error' => 'Thiếu remainingSeconds hợp lệ']);
            exit;
        }
        $existing['remainingSeconds'] = intval($remainingSeconds);
        $existing['lastSaveTime'] = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
        // <<< THÊM Ở ĐÂY: lưu exitCount & screenshotCount >>>
        $existing['exitCount']        = intval($data['exitCount']       ?? 0);
        $existing['screenshotCount']  = intval($data['screenshotCount'] ?? 0);
        // <<< HẾT PHẦN THÊM >>>
        writeJsonFile($savePath, $existing);
        echo json_encode(['status' => 'ok']);
        exit;
    }
    elseif ($action === 'checkError') {
        // Đọc lại file (lạm dụng $existing đọc ở trên)
        $existing = json_decode(file_get_contents($savePath), true) ?: [];
        $err = [];
        if (isset($existing['error']) && is_array($existing['error'])) {
            $err = $existing['error'];
        }
        // Trả về object error (có thể rỗng)
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'error' => $err], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --------- NEW: ackError: đánh dấu đã thông báo (error[level] = "yes") ----------
    elseif ($action === 'ackError') {
        $level = $data['level'] ?? null;
        if ($level === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Thiếu level']);
            exit;
        }
        $level = (string)$level;

        if (!isset($existing) || !is_array($existing)) $existing = json_decode(file_get_contents($savePath), true) ?: [];
        if (!isset($existing['error']) || !is_array($existing['error'])) {
            $existing['error'] = [];
        }
        // Đánh dấu đã thông báo
        $existing['error'][$level] = 'yes';
        $existing['lastSaveTime'] = date('Y-m-d H:i:s');
        writeJsonFile($savePath, $existing);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'level' => $level], JSON_UNESCAPED_UNICODE);
        exit;
    }
    else {
        http_response_code(400);
        echo json_encode(['error' => 'action không hợp lệ']);
        exit;
    }
}

// ------------------ BƯỚC 2: LẤY THÔNG TIN URL, XỬ LÝ start/submit ------------------

$requestUri  = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$segments    = explode('/', trim($requestPath, '/'));

$indexDeThi = array_search('bai-thi', $segments);
if ($indexDeThi === false || !isset($segments[$indexDeThi + 1])) {
    http_response_code(404);
    echo "<h1>404 Not Found</h1>";
    exit;
}

$examName  = $segments[$indexDeThi + 1];           
$studentID = isset($segments[$indexDeThi + 2]) ? $segments[$indexDeThi + 2] : null;
$action    = $_GET['action'] ?? null;

// ------------------ Load user list từ 2 file ------------------
$userList = [];
$files = [
    __DIR__ . '/../user/user.json',
    __DIR__ . '/../user/user1.json'
];
foreach ($files as $filePath) {
    $realPath = realpath($filePath);
    if ($realPath && file_exists($realPath)) {
        $data = json_decode(file_get_contents($realPath), true);
        if (is_array($data)) {
            // Ghép mảng, tránh trùng key nếu cần: ở đây merge đơn giản
            $userList = array_merge($userList, $data);
        }
    } else {
        // (tuỳ chọn) log hoặc echo cảnh báo
        // error_log("Không tìm thấy file: $filePath");
    }
}

// --- MỚI: Load thêm từ database MySQL ---
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli(
    'sql101.infinityfree.com',   // host MySQL
    'if0_38357405',               // username
    'QzQ3BHZMMyCF3u',             // password
    'if0_38357405_userexam',      // database
    3306                          // port
);
if (! $mysqli->connect_errno) {
    $stmt = $mysqli->prepare("
        SELECT name AS 'Họ và tên',
               code AS 'Email',
               school AS 'Trường',
               password AS 'CF',
               candidate_no AS '0000'
        FROM candidates
        WHERE candidate_no = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $studentID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        // Nếu tìm thấy trong DB, thêm vào cuối mảng
        $userList[] = $row;
    }
    $stmt->close();
}
$mysqli->close();

// Tìm user theo $studentID trong mảng đã ghép
$foundUser = null;
if ($studentID) {
    foreach ($userList as $usr) {
        if (isset($usr['0000']) && $usr['0000'] === $studentID) {
            $foundUser = $usr;
            break;
        }
    }
}

$savePath = getSavePath($examName, $studentID);
$archived = []; // default: no archived data unless we explicitly create/merge on action=start

// Chỉ xử lý tạo/ghi file bài làm và archive khi truy cập bằng ?action=start
// (Mục đích: tránh tạo file mới hoặc reindex time-N khi gọi autosave / toggleFlag / API khác)
if (isset($action) && $action === 'start') {
    // Nếu file tồn tại nhưng không thể resume -> archive và lấy dữ liệu cũ
    if (file_exists($savePath) && !canResume($savePath)) {
        $archived = archiveOldSaveAndReturn($savePath); // trả về ['time-1'=>..., ...]
    }
    // Nếu file không tồn tại (mới) -> tạo file mới và merge archived vào
    if (!file_exists($savePath)) {
        // Tùy chỉnh các giá trị khởi tạo này theo logic của bạn (initialRemaining, idExam, name...)
        $initialRemaining = $initialRemaining ?? 0;
        $idExam = $idExam ?? '';
        $newData = [
            'studentID' => $studentID,
            'name'      => $foundUser['Họ và tên'] ?? $foundUser['name'] ?? '',
            'examName'  => $examName,
            'idExam'    => $idExam,
            'startTime' => (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s'),
            'answers'   => new stdClass(), // {} thay vì [] khi encode
            'remainingSeconds' => intval($initialRemaining),
            'lastSaveTime' => (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s'),
            'exitCount' => 0,
            'screenshotCount' => 0,
            'flag' => new stdClass(),
        ];

        // Nếu có dữ liệu cũ đã được archive, merge vào (ở đây chèn time-1... vào newData)
        if (!empty($archived) && is_array($archived)) {
            foreach ($archived as $k => $v) {
                $newData[$k] = $v;
            }
        }

        // Ghi file mới
        writeJsonFile($savePath, $newData);
    }
}

/**
 * Chuyển một số Ả-rập (1,2,3,…) thành số La Mã (I, II, III, …)
 */
function toRoman(int $num): string {
    $map = [
        1000 => 'M',  900 => 'CM',
         500 => 'D',  400 => 'CD',
         100 => 'C',   90 => 'XC',
          50 => 'L',   40 => 'XL',
          10 => 'X',    9 => 'IX',
           5 => 'V',    4 => 'IV',
           1 => 'I',
    ];
    $res = '';
    foreach ($map as $arabic => $roman) {
        while ($num >= $arabic) {
            $res  .= $roman;
            $num -= $arabic;
        }
    }
    return $res;
}

// ========== XỬ LÝ “submit” ==========

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $examNamePOST   = $_POST['examName']   ?? '';
    $studentIDPOST  = $_POST['studentID']  ?? '';
    if (!$examNamePOST || !$studentIDPOST) {
        echo "<h2>Lỗi: Thiếu examName hoặc studentID ts.</h2>";
        exit;
    }
    
    // ============ KIỂM TRA EXAM GROUP ============
    $dethiPath = __DIR__ . '/dethi.json';
    $isExamGroup = false;
    $examGroup = null;
    
    if (file_exists($dethiPath)) {
        $dethiAll = json_decode(file_get_contents($dethiPath), true);
        if (isset($dethiAll[$examNamePOST]) && isset($dethiAll[$examNamePOST]['exams']) && is_array($dethiAll[$examNamePOST]['exams'])) {
            $isExamGroup = true;
            $examGroup = $dethiAll[$examNamePOST];
        }
    }
    
    // ======= LOAD THANG ĐIỂM CHUNG (dùng khi chấm exam đơn) =======
    // giữ $markConfig nếu url là exam đơn, nhưng khi chấm từng sub-exam trong group
    // chúng ta sẽ load mark.json riêng cho từng sub-exam và truyền vào gradeOneExam.
    $markPath = __DIR__ . '/' . $examNamePOST . '/mark.json';
    $markConfig = [];
    if (file_exists($markPath)) {
        $markRaw = json_decode(file_get_contents($markPath), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $markConfig = $markRaw;
        }
    }

    // Hỗ trợ đọc mark.json cho một exam cụ thể (trả về mảng hoặc [])
    function loadMarkConfigForExam(string $examId): array {
        $p = __DIR__ . '/' . $examId . '/mark.json';
        if (!file_exists($p)) return [];
        $raw = json_decode(file_get_contents($p), true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($raw)) ? $raw : [];
    }

    // Các hàm phụ local sẽ được sử dụng bên trong gradeOneExam dựa trên $markCfg được truyền vào
    function getPart6Percents_local(int $n): array {
        static $table = [
            1 => [1.0],
            2 => [0.4, 1.0],
            3 => [0.25, 0.6, 1.0],
            4 => [0.1, 0.25, 0.5, 1.0],
            5 => [0.05, 0.2, 0.4, 0.65, 1.0],
        ];
        return $table[$n] ?? array_fill(0, $n, 1.0);
    }

    // ======= HÀM CHẤM 1 EXAM (tái sử dụng) - BẬT: nhận $markCfg riêng cho exam này =======
    function gradeOneExam($studentFilePath, $answerKeyPath, $saveResult = false, array $markCfg = []) {
        global $tz; // giữ global timezone và các hàm tiện ích khác (ví dụ writeJsonFile)
        // nếu file không có thì trả về null
        if (!file_exists($studentFilePath) || !file_exists($answerKeyPath)) {
            return null;
        }

        // đọc bài làm
        $studentData = json_decode(file_get_contents($studentFilePath), true);
        if (!$studentData) return null;
        $submitted = is_array($studentData['answers'] ?? null) ? $studentData['answers'] : [];

        // đọc đáp án
        $answerKeyRaw = json_decode(file_get_contents($answerKeyPath), true);
        if (!is_array($answerKeyRaw)) return null;
        $answerKeyMap = [];
        foreach ($answerKeyRaw as $item) {
            if (isset($item['code']) && isset($item['answer'])) {
                $answerKeyMap[$item['code']] = $item['answer'];
            }
        }

        // local helper thay cho getMark (dùng $markCfg cụ thể)
        $getMarkLocal = function($part, $key = null, $default = 0) use ($markCfg) {
            if (!isset($markCfg["part{$part}"])) return $default;
            $conf = $markCfg["part{$part}"];
            if (!is_array($conf)) return $conf;
            return ($key !== null && isset($conf["{$key}"])) ? $conf["{$key}"] : $default;
        };

        // chấm
        $score = [1=>0, 2=>0, 3=>0, 4=>0, 6=>0];

        // Phần 1
        foreach ($submitted as $code => $stuAns) {
            if (preg_match('/^1\.\d+$/', $code)) {
                $correct = $answerKeyMap[$code] ?? null;
                if ($stuAns && $correct && strtoupper($stuAns) === strtoupper($correct)) {
                    $score[1] += $getMarkLocal(1, null, 0.25);
                }
            }
        }

        // Phần 2 - Đúng/sai theo nhóm
        $grouped2_sub = [];
        $grouped2_key = [];
        foreach ($submitted as $code => $ans) {
            if (preg_match('/^2\.\d+\.[a-d]$/i', $code)) {
                $pieces = explode('.', $code);
                if (count($pieces) === 3) {
                    $gid = $pieces[0] . '.' . $pieces[1];
                    $grouped2_sub[$gid][$code] = $ans;
                    if (isset($answerKeyMap[$code])) {
                        $grouped2_key[$gid][$code] = $answerKeyMap[$code];
                    }
                }
            }
        }
        foreach ($grouped2_sub as $gid => $subArr) {
            $numCorrect = 0;
            foreach ($subArr as $code => $stuAns) {
                $correct = $grouped2_key[$gid][$code] ?? null;
                if ($stuAns && $correct && mb_strtolower($stuAns) === mb_strtolower($correct)) {
                    $numCorrect++;
                }
            }
            switch ($numCorrect) {
                case 1: $score[2] += $getMarkLocal(2, 1, 0.1);  break;
                case 2: $score[2] += $getMarkLocal(2, 2, 0.25); break;
                case 3: $score[2] += $getMarkLocal(2, 3, 0.5);  break;
                case 4: $score[2] += $getMarkLocal(2, 4, 1);    break;
            }
        }

        // Phần 3 - Ghép cặp (theo nhóm)
        $grouped3_sub = [];
        $grouped3_key = [];
        foreach ($submitted as $code => $ans) {
            if (preg_match('/^3\.\d+\.\d+$/', $code)) {
                $pieces = explode('.', $code);
                if (count($pieces) === 3 && is_numeric($pieces[2])) {
                    $gid = $pieces[0] . '.' . $pieces[1];
                    $grouped3_sub[$gid][$code] = $ans;
                    if (isset($answerKeyMap[$code])) {
                        $grouped3_key[$gid][$code] = $answerKeyMap[$code];
                    }
                }
            }
        }
        foreach ($grouped3_sub as $gid => $subArr) {
            $numCorrect = 0;
            foreach ($subArr as $code => $stuAns) {
                $correct = $grouped3_key[$gid][$code] ?? null;
                if ($stuAns && $correct && mb_strtolower($stuAns) === mb_strtolower($correct)) {
                    $numCorrect++;
                }
            }
            switch ($numCorrect) {
                case 1: $score[3] += $getMarkLocal(3, 1, 0.1);  break;
                case 2: $score[3] += $getMarkLocal(3, 2, 0.25); break;
                case 3: $score[3] += $getMarkLocal(3, 3, 0.5);  break;
                case 4: $score[3] += $getMarkLocal(3, 4, 1.0);  break;
            }
        }

        // Phần 4
        foreach ($submitted as $code => $stuAns) {
            if (preg_match('/^4\.\d+$/', $code) && strpos($code, '-') === false) {
                $correct = $answerKeyMap[$code] ?? '';
                if (mb_strtolower(trim($stuAns)) === mb_strtolower(trim($correct))) {
                    $score[4] += $getMarkLocal(4, null, 0.25);
                }
            }
        }

        // Phần 6 - kéo thả
        $part6Conf = $markCfg['part6'] ?? null;
        $isScalar  = !is_array($part6Conf);
        $fullScore = $isScalar ? null : floatval($part6Conf['full'] ?? 0);

        $part6Counts = [];
        foreach ($answerKeyMap as $code => $ans) {
            if (preg_match('/^6\.\d+\.\d+$/', $code)) {
                $pieces = explode('.', $code);
                if (count($pieces) === 3) {
                    $gid = $pieces[0] . '.' . $pieces[1];
                    $part6Counts[$gid] = ($part6Counts[$gid] ?? 0) + 1;
                }
            }
        }

        $correctCounts = [];
        foreach ($submitted as $code => $stuAns) {
            if (preg_match('/^6\.\d+\.\d+$/', $code) && isset($answerKeyMap[$code])) {
                $correct = $answerKeyMap[$code];
                if (trim($stuAns) !== '' && trim($stuAns) === trim($correct)) {
                    $pieces = explode('.', $code);
                    $gid = $pieces[0] . '.' . $pieces[1];
                    $correctCounts[$gid] = ($correctCounts[$gid] ?? 0) + 1;
                }
            }
        }

        foreach ($part6Counts as $gid => $n) {
            $cntOk = $correctCounts[$gid] ?? 0;
            if ($isScalar) {
                // nếu part6 được cấu hình là số (per-item), dùng per-item value
                $perItem = $getMarkLocal(6, null, floatval($part6Conf ?? 1.0));
                $score[6] += $cntOk * $perItem;
            } else {
                $percents = getPart6Percents_local($n);
                $percent  = $percents[min($cntOk, $n) - 1] ?? 0;
                $score[6] += $fullScore * $percent;
            }
        }

        // Lưu kết quả nếu cần
        if ($saveResult) {
            $totalScore = array_sum($score);
            $studentData['submitTime'] = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
            $studentData['score'] = [
                'part1' => $score[1],
                'part2' => $score[2],
                'part3' => $score[3],
                'part4' => $score[4],
                'part6' => $score[6],
                'total' => $totalScore
            ];
            // dùng hàm writeJsonFile có sẵn trong code của bạn
            writeJsonFile($studentFilePath, $studentData);
        }

        return $score;
    }

    // ============ CHẤM ĐIỂM (SỬA: truyền mark config đúng cho từng exam) ============
    $totalScore = 0;
    $scorePart = [1=>0, 2=>0, 3=>0, 4=>0, 6=>0];
    $answerLink = '';

    if ($isExamGroup && $examGroup) {
        // ========== CHẤM TỪNG EXAM MỘT (TUẦN TỰ) - load mark per sub-exam ==========
        foreach ($examGroup['exams'] as $subExam) {
            if (!isset($subExam['id'])) continue;
            $subId = (string)$subExam['id'];
            
            // --- CHÈN TRONG VÒNG xử lý mỗi $subId trước khi gọi gradeOneExam ---
            // $subStudentFile và $subAnswerFile đã được xác định ở chỗ đó
            if (file_exists($subStudentFile)) {
                $sJson = json_decode(file_get_contents($subStudentFile), true);
                if (!is_array($sJson)) $sJson = [];
                if (!isset($sJson['answers']) || !is_array($sJson['answers'])) $sJson['answers'] = [];

                // lấy mã câu từ file đáp án sub
                $subCodes = [];
                if (file_exists($subAnswerFile)) {
                    $sr = json_decode(file_get_contents($subAnswerFile), true);
                    if (is_array($sr)) {
                        $k = array_keys($sr);
                        $isAssoc = ($k !== range(0, count($sr)-1));
                        if ($isAssoc) $subCodes = $k;
                        else {
                            foreach ($sr as $it) if (is_array($it) && isset($it['code'])) $subCodes[] = $it['code'];
                        }
                    }
                }
                // fallback: có thể load cấu trúc đề con từ __DIR__.'/'.$subId.'/dethi.json' tương tự nếu cần

                foreach ($subCodes as $c) {
                    $cTrim = trim((string)$c);
                    // bỏ qua mã có dấu '-' ở cuối
                    if ($cTrim === '' || preg_match('/-\s*$/', $cTrim)) {
                        continue;
                    }
                    if (!array_key_exists($cTrim, $sJson['answers'])) {
                        $sJson['answers'][$cTrim] = '';
                    }
                }

                writeJsonFile($subStudentFile, $sJson);
            }
            // --- HẾT CHÈN ---

            // Đường dẫn file bài làm và đáp án của exam con
            $subStudentFile = __DIR__ . '/' . $subId . '/' . $studentIDPOST . '.json';
            $subAnswerFile  = __DIR__ . '/' . $subId . '/dap-an.json';

            // Load mark.json cho exam con này
            $subMarkCfg = loadMarkConfigForExam($subId);

            // Chấm exam này VÀ LƯU KẾT QUẢ vào file exam con, truyền $subMarkCfg
            $subScore = gradeOneExam($subStudentFile, $subAnswerFile, true, $subMarkCfg); // true = lưu kết quả

            if ($subScore !== null) {
                // Cộng dồn điểm
                foreach ($subScore as $part => $points) {
                    $scorePart[$part] += $points;
                }
            }
        }

        $totalScore = array_sum($scorePart);

        // Lưu tổng điểm vào file exam group (giữ nguyên)
        $groupSavePath = getSavePath($examNamePOST, $studentIDPOST);
        $groupData = file_exists($groupSavePath) ? json_decode(file_get_contents($groupSavePath), true) : [];
        if (!$groupData) $groupData = [
            'studentID' => $studentIDPOST,
            'examName' => $examNamePOST,
        ];

        $groupData['submitTime'] = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
        $groupData['score'] = [
            'part1' => $scorePart[1],
            'part2' => $scorePart[2],
            'part3' => $scorePart[3],
            'part4' => $scorePart[4],
            'part6' => $scorePart[6],
            'total' => $totalScore
        ];
        writeJsonFile($groupSavePath, $groupData);

    } else {
        // ========== EXAM ĐƠN LẺ: truyền $markConfig đã load ở trên ==========
        $studentFile = __DIR__ . '/' . $examNamePOST . '/' . $studentIDPOST . '.json';
        $answerFile  = __DIR__ . '/' . $examNamePOST . '/dap-an.json';

        if (!file_exists($studentFile)) {
            echo "<h2>Lỗi: File lưu bài làm không tồn tại.</h2>";
            exit;
        }
        if (!file_exists($answerFile)) {
            echo "<h2>Lỗi: Không tìm thấy file đáp án.</h2>";
            exit;
        }
        
        // --- CHÈN: đảm bảo tạo key cho mọi mã câu hỏi (kể cả chưa trả lời) ---
        // chèn ngay sau khi đã kiểm tra tồn tại $studentFile và $answerFile (trong branch "EXAM ĐƠN LẺ"), trước khi chấm
        $studentJson = json_decode(file_get_contents($studentFile), true);
        if (!is_array($studentJson)) $studentJson = [];
        if (!isset($studentJson['answers']) || !is_array($studentJson['answers'])) {
            $studentJson['answers'] = [];
        }

        // cố gắng đọc danh sách mã câu hỏi từ dap-an.json
        $codes = [];
        if (file_exists($answerFile)) {
            $ansRaw = json_decode(file_get_contents($answerFile), true);
            if (is_array($ansRaw)) {
                // nếu dap-an.json là associative array keyed by code
                $keys = array_keys($ansRaw);
                $isAssoc = ($keys !== range(0, count($ansRaw)-1));
                if ($isAssoc) {
                    $codes = $keys;
                } else {
                    // nếu là list items with ['code'] => tìm mã trong từng item
                    foreach ($ansRaw as $it) {
                        if (is_array($it) && isset($it['code'])) $codes[] = $it['code'];
                    }
                }
            }
        }

        // fallback: nếu không lấy được từ dap-an.json, thử load dethi.json để tìm câu (nếu có cấu trúc exam)
        if (empty($codes)) {
            $dethiPath = __DIR__ . '/dethi.json';
            if (file_exists($dethiPath)) {
                $dAll = json_decode(file_get_contents($dethiPath), true);
                if (isset($dAll[$examNamePOST])) {
                    // tìm cây câu trong cấu trúc đề (cách chung: đi tìm keys code trong mảng)
                    $walk = function($node) use (&$walk, &$codes) {
                        if (!is_array($node)) return;
                        foreach ($node as $k => $v) {
                            if ($k === 'code' && is_string($v)) $codes[] = $v;
                            elseif (is_array($v)) $walk($v);
                        }
                    };
                    $walk($dAll[$examNamePOST]);
                    $codes = array_values(array_unique($codes));
                }
            }
        }

        // chèn key rỗng cho các mã chưa có
        foreach ($codes as $c) {
            $cTrim = trim((string)$c);
            // bỏ qua nếu rỗng hoặc kết thúc bằng dấu '-'
            if ($cTrim === '' || preg_match('/-\s*$/', $cTrim)) {
                continue;
            }
            if (!array_key_exists($cTrim, $studentJson['answers'])) {
                $studentJson['answers'][$cTrim] = '';
            }
        }

        // ghi lại file student (giữ nguyên các phần khác)
        writeJsonFile($studentFile, $studentJson);
        // --- HẾT CHÈN ---

        // Chấm VÀ LƯU KẾT QUẢ vào file exam đơn, truyền $markConfig đã load
        $scorePart = gradeOneExam($studentFile, $answerFile, true, $markConfig); // true = lưu kết quả
        if ($scorePart === null) {
            echo "<h2>Lỗi: Không thể đọc file bài làm hoặc đáp án.</h2>";
            exit;
        }

        $totalScore = array_sum($scorePart);

        // Lấy answer link (chỉ cho exam đơn)
        $examJsonPath = __DIR__ . '/' . $examNamePOST . '/' . $examNamePOST . '.json';
        if (file_exists($examJsonPath)) {
            $examDataRaw = json_decode(file_get_contents($examJsonPath), true);
            if (isset($examDataRaw[1]['Key'])) {
                $answerLink = $examDataRaw[1]['Key'];
            }
        }
    }
    
    // ============ HIỂN THỊ KẾT QUẢ (giữ nguyên phần HTML) ============
    $displayExamNamePOST = $timeData['realNameExam'] ?: ($examNamePOST ?? '');
    // Hiển thị kết quả tóm tắt
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Kết quả thi: <?= htmlspecialchars($displayExamNamePOST) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon-32x32.png?v=2" type="image/png" sizes="32x32">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fa;
            height: 100vh;
            margin: 0;
            overscroll-behavior-y: none; 
            touch-action: pan-y;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background-color: #003366;
            color: #fff;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            font-size: 1.5rem;
            text-align: center;
            font-weight: bold;
        }
        .card-body {
            padding: 2rem;
        }
        .table thead th {
            background-color: #e9ecef;
        }
        #info-row p, #info-row code {
            word-break: break-all;
        }
        #info-row .label {
            color: #003366;
            font-weight: 600;
        }
        .btn-view-json {
            background-color: #003366;
            border-color: #003366;
            color: #fff;
        }
        p {margin: 0;}
        .btn-view-json:hover {
            background-color: #002244;
            border-color: #002244;
            color: #fff;
        }
        *{
            scrollbar-width: auto;      
            scrollbar-color: #003366 transparent; /* thumb color | track color */
        }
        #sidebar::-webkit-scrollbar { width: 10px; }
        #sidebar::-webkit-scrollbar-track { background: transparent; }
        #sidebar::-webkit-scrollbar-thumb {
            background: #003366;
            border-radius: 6px;
            border: 2px solid rgba(0,0,0,0); /* khoảng đệm mượt */
        }
        #sidebar::-webkit-scrollbar-thumb:hover { background: #002244; }
        .footer { text-align: center; padding: 1rem 0; padding-top: 5px; font-size: 0.9rem; color: #666; }
    </style>
</head>
<body>
    <div class="d-flex justify-content-center align-items-center h-100">
        <div class="card w-100" style="max-width: 600px; ">
            <div class="card-header">
                Kết quả thi: <?= htmlspecialchars($displayExamNamePOST) ?>
            </div>
            <div class="card-body" style="padding-bottom: 10px; padding-top: 20px;">
                <div id="info-row" class="text-center" style="margin-bottom: 15px;">
                    <p><span class="label" style="margin-bottom:10px;">Thí sinh:</span> <strong><?= htmlspecialchars($foundUser['Họ và tên']) ?></strong> – <span class="label">Số báo danh:</span> <strong><?= htmlspecialchars($studentIDPOST) ?></strong></p>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead style="background-color: #003366; text-align: center;" >
                            <tr>
                                <th scope="col">Phần</th>
                                <th scope="col">Điểm</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!is_null($scorePart[1])): ?>
                            <tr>
                                <td>Phần trắc nghiệm nhiều phương án lựa chọn</td>
                                <td style="text-align: center;"><?= number_format($scorePart[1], 2) ?></td>
                            </tr>
                            <?php endif; ?>

                            <?php if (!is_null($scorePart[2])): ?>
                            <tr>
                                <td>Phần trắc nghiệm đúng sai</td>
                                <td style="text-align: center;"><?= number_format($scorePart[2], 2) ?></td>
                            </tr>
                            <?php endif; ?>

                            <?php if (!is_null($scorePart[3])): ?>
                            <tr>
                                <td>Phần trắc nghiệm ghép cặp</td>
                                <td style="text-align: center;"><?= number_format($scorePart[3], 2) ?></td>
                            </tr>
                            <?php endif; ?>

                            <?php if (!is_null($scorePart[4])): ?>
                            <tr>
                                <td>Phần trắc nghiệm yêu cầu trả lời ngắn</td>
                                <td style="text-align: center;"><?= number_format($scorePart[4], 2) ?></td>
                            </tr>
                            <?php endif; ?>

                            <!-- Phần V luôn ẩn/hiện riêng vì điểm null và luôn in "--" -->
                            <?php if ($hasPart5): ?>
                            <tr>
                                <td>Phần tự luận</td>
                                <td style="text-align: center;">--</td>
                            </tr>
                            <?php endif; ?>

                            <?php if (!is_null($scorePart[6])): ?>
                            <tr>
                                <td>Phần trắc nghiệm kéo thả</td>
                                <td style="text-align: center;"><?= number_format($scorePart[6], 2) ?></td>
                            </tr>
                            <?php endif; ?>

                            <!-- Tổng điểm luôn hiện -->
                            <tr class="fw-bold">
                                <td style="text-align: center;">Tổng điểm</td>
                                <td style="text-align: center;"><?= number_format($totalScore, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php if ($hasPart5): ?>
                        <p style="font-size: 0.85rem; text-align: center;margin-bottom: 15px;">
                            <i>Lưu ý: Tổng điểm thi chưa được cộng điểm phần Tự luận (Chấm sau)</i>
                        </p>
                        <?php endif; ?>
                </div>

                <div class="text-center">
                    <p><span class="label">Thời gian nộp bài:</span> <?= htmlspecialchars($existing['submitTime']) ?></p>
                    <!-- Nút mở file JSON -->
                    <?php if ($endExam && $now < $endExam): ?>
                        <span id="view-json-btn">
                            <button 
                            type="button" 
                            class="btn btn-view-json mt-3" style="background-color: #fff; color: #003366;"
                            onclick="alert('Thời gian thi thử chưa kết thúc!')"
                            >
                            Xem bài làm
                            </button>
                        </span>
                        <?php if (!empty($answerLink)): ?>
                        <span id="answer-btn">
                            <button 
                            type="button" 
                            class="btn btn-view-json mt-3" style="background-color: #fff; color: #003366;"
                            onclick="alert('Thời gian thi thử chưa kết thúc!')"
                            >
                            Đáp án
                            </button>
                        </span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span id="view-json-btn">
                            <a 
                            href="https://biolifethithu.wuaze.com/bai-thi/show.php?exam=<?= htmlspecialchars($examNamePOST) ?>&result=<?= htmlspecialchars($studentIDPOST) ?>.json" 
                            target="_blank" 
                            class="btn btn-view-json mt-3"
                            >
                            Xem file bài làm
                            </a>
                        </span>
                        <?php if (!empty($answerLink)): ?>
                        <span id="answer-btn">
                            <?php if ($totalScore > 1): ?>
                            <a 
                            href="<?= htmlspecialchars($answerLink) ?>" 
                            target="_blank" 
                            class="btn btn-view-json mt-3"
                            >
                            Đáp án
                            </a>
                            <?php else: ?>
                            <button 
                            type="button" 
                            class="btn btn-view-json mt-3"
                            onclick="alert('Điểm thi chưa đủ điều kiện để xem đáp án!')"
                            >
                            Đáp án
                            </button>
                            <?php endif; ?>
                        </span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <a 
                      id="btnRetryExam"
                      href="https://biolifethithu.wuaze.com/bai-thi/<?= htmlspecialchars($examNamePOST) ?>/<?= htmlspecialchars($studentIDPOST) ?>?action=start"  
                      class="btn btn-view-json mt-3"
                    >
                      Làm lại
                    </a>

                    <script>
                    document.addEventListener('DOMContentLoaded', function () {
                      const btn = document.getElementById('btnRetryExam');
                      if (!btn) return;
                      btn.addEventListener('click', function (e) {
                        e.preventDefault(); // chặn chuyển trang tạm thời
                        const message = 'Khi làm lại bài thi, toàn bộ dữ liệu lượt thi trước sẽ bị xóa vĩnh viễn. Chọn ‘OK’ nếu bạn chắc chắn muốn làm lại.';
                        if (window.confirm(message)) {
                          // nếu xác nhận => vào link
                          window.location.href = btn.href;
                        } else {
                          // nếu hủy => không làm gì
                        }
                      });
                    });
                    </script>
                    <a 
                        href="https://biolifethithu.wuaze.com/tai-khoan/index.php?SBD=<?= htmlspecialchars($studentIDPOST) ?>"  
                        class="btn mt-3" style="background-color: red; color: #fff;"
                    >
                        Thoát
                    </a>
                </div>
            </div>
            <div class="footer" > &copy; <?= date('Y') ?> – Thi thử Biology's Life <?= date('Y') ?></div>
        </div>
    </div>
    

    <!-- Bootstrap JS (nếu cần) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Lấy mốc kết thúc thi thử từ PHP (ISO 8601)
    const endExamStr = <?= json_encode($timeData['timeEndExam'] ?: null) ?>;
    if (endExamStr) {
    const endExam = new Date(endExamStr);
    // Hàm cập nhật nút
    function updateButtons() {
        const now = new Date();
        if (now >= endExam) {
        // Thay span chứa nút "Xem file bài làm"
        const viewSpan = document.getElementById('view-json-btn');
        viewSpan.innerHTML = `
            <a 
            href="https://biolifethithu.wuaze.com/bai-thi/show.php?exam=<?= htmlspecialchars($examNamePOST) ?>&result=<?= htmlspecialchars($studentIDPOST) ?>.json" 
            target="_blank" 
            class="btn btn-view-json mt-3"
            >
            Xem file bài làm
            </a>`;
        // Thay span chứa nút "Đáp án"
        <?php if (!empty($answerLink)): ?>
        const ansSpan = document.getElementById('answer-btn');
        <?php if ($totalScore > 1): ?>
        ansSpan.innerHTML = `
            <a 
            href="<?= htmlspecialchars($answerLink) ?>" 
            target="_blank" 
            class="btn btn-view-json mt-3"
            >
            Đáp án
            </a>`;
        <?php else: ?>
        ansSpan.innerHTML = `
            <button 
            type="button" 
            class="btn btn-view-json mt-3"
            onclick="alert('Điểm thi chưa đủ điều kiện để xem đáp án!')"
            >
            Đáp án
            </button>`;
        <?php endif; ?>
        <?php endif; ?>

        // Ngừng interval sau khi đã chuyển
        clearInterval(timer);
        }
    }
    // Chạy ngay và lặp mỗi giây
    const timer = setInterval(updateButtons, 1000);
    updateButtons();
    }
    </script>
</body>
</html>
    <?php
    exit;
}

// === XỬ LÝ ?note ===
if (isset($_GET['note'])) {
    // Nếu thực sự có note
    if (! empty($timeData['note'])) {
        // ===== CHÈN ĐOẠN XỬ LÝ HEADER TẠI ĐÂY =====
        $rawNote    = $timeData['note'];
        $headerText = '';
        if (preg_match('/##(.*?)##/s', $rawNote, $matches)) {
            $headerText = trim($matches[1]);
            $rawNote    = preg_replace('/##.*?##/s', '', $rawNote);
        }
        $displayExamName = $timeData['realNameExam'] ?: $examName;
        $cardHeader = $headerText
            ? $headerText
            : 'Hướng dẫn thi: ' . htmlspecialchars($displayExamName);
        // ===== KẾT THÚC ĐOẠN XỬ LÝ HEADER =====
        ?>
        <!DOCTYPE html>
        <html lang="vi">
        <head>
          <meta charset="UTF-8">
          <title>Hướng dẫn thi: <?= htmlspecialchars($displayExamName) ?></title>
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <link rel="icon" href="/favicon-32x32.png?v=2" type="image/png" sizes="32x32">
          <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
          <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
          <style>
            body {
              font-family: 'Inter', sans-serif;
              background-color: #f5f7fa;
              margin: 0;
              padding: 1rem;
              overscroll-behavior-y: none; 
              touch-action: pan-y;
            }
            .note-wrapper {
              width: 90%;
              max-width: 1200px;
              margin: 2rem auto; /* chỉ canh ngang */
            }
            .note-card {
              border: none;
              border-radius: 12px;
              box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
              overflow: hidden;
            }
            .note-card .card-header {
              background-color: #003366;
              color: #fff;
              font-size: 1.5rem;
              font-weight: 600;
              padding: 1rem;
              text-align: center;
            }
            .note-card .card-body {
              background-color: #fff;
              padding: 1.5rem;
              line-height: 1.6;
              color: #333;
            }
            .note-card .card-footer {
              background-color: #f1f3f5;
              padding: 1rem;
              text-align: center;
            }
            .btn-start {
              background-color: #003366;
              border-color: #003366;
              font-size: 1rem;
              padding: 0.5rem 1.5rem;
              border-radius: .375rem;
            }
            .btn-start:hover {
              background-color: #002244;
              border-color: #002244;
            }
            *{
                scrollbar-width: auto;      
                scrollbar-color: #003366 transparent; /* thumb color | track color */
            }
            #sidebar::-webkit-scrollbar { width: 10px; }
            #sidebar::-webkit-scrollbar-track { background: transparent; }
            #sidebar::-webkit-scrollbar-thumb {
                background: #003366;
                border-radius: 6px;
                border: 2px solid rgba(0,0,0,0); /* khoảng đệm mượt */
            }
            #sidebar::-webkit-scrollbar-thumb:hover { background: #002244; }
            .footer { text-align: center; padding: 1rem 0; padding-top: 0px; font-size: 0.9rem; color: #666; }
          </style>
        </head>
        <body>
          <div class="note-wrapper">
            <div class="card note-card">
              <div class="card-header">
                <?= $cardHeader ?>
              </div>
              <div class="card-body">
                <div style="text-align: justify;"><?= $rawNote /* đã loại bỏ ##...## */ ?></div>
                <p style="width: 100%; text-align: center;">
                    Họ và tên: <strong><?= htmlspecialchars($foundUser['Họ và tên']) ?></strong>
                    – Số báo danh: <strong><?= htmlspecialchars($studentID) ?></strong> 
                    – Trường: <strong><?= htmlspecialchars($foundUser['Trường']) ?></strong>
                </p>
              <div style="width: 100%; text-align: center;">
                <a href="?action=start" class="btn btn-start btn-lg" style="color: #fff;">
                  Bắt đầu làm bài
                </a>
              </div>
              </div>
              <div class="footer" > &copy; <?= date('Y') ?> – Thi thử Biology's Life <?= date('Y') ?></div>
            </div>
          </div>
        </body>
        </html>
        <?php
        exit;  // dừng script, không render phần form chính
    } else {
        // Không có note, quay về trang thi chính
        header("Location: /bai-thi/{$examName}/{$studentID}");
        exit;
    }
}


// ------------------ BƯỚC 3: HIỂN THỊ FORM (action=start và resume) ------------------


if ($action === 'start' && $foundUser) {
    // === XÁC ĐỊNH THƯ MỤC GỐC CHỨA 'bai-thi' ===
    $dir = __DIR__;
    while (basename($dir) !== 'bai-thi' && dirname($dir) !== $dir) {
        $dir = dirname($dir);
    }
    $baseDir      = $dir;                
    $startLogFile = $baseDir . '/start.json';
    
    // 1) Load time.json và khởi tạo DateTime
    date_default_timezone_set('Asia/Ho_Chi_Minh');
    $timeData = loadTimeFile($examName);
    // nếu đã lưu realNameExam thì hiển thị, ngược lại fallback về mã thư mục
    $displayExamName = $timeData['realNameExam'] ?: $examName;
    $tz       = new DateTimeZone('Asia/Ho_Chi_Minh');
    $now      = new DateTime('now', $tz);
    $startExam = !empty($timeData['timeStartExam'])
        ? DateTime::createFromFormat('Y-m-d\TH:i', $timeData['timeStartExam'], $tz)
        : null;
    $endExam   = !empty($timeData['timeEndExam'])
        ? DateTime::createFromFormat('Y-m-d\TH:i', $timeData['timeEndExam'], $tz)
        : null;

    // 2) Nếu chưa đến giờ — thông báo lỗi và exit
    if ($startExam && $now < $startExam) {
        echo "<h2 style='color: #ff0000;'>Thời gian làm bài chưa bắt đầu.<br>"
            . "Bắt đầu từ: " . $startExam->format('H:i d/m/Y') 
            . "</h2>";
        exit;
    }
    // 3) Nếu đã quá giờ — thông báo lỗi và exit
    if ($endExam && $now > $endExam) {
        echo "<h2 style='color: #ff0000;'>Thời gian làm bài đã kết thúc.<br>"
            . "Kết thúc vào: " . $endExam->format('H:i d/m/Y')
            . "</h2>";
        exit;
    }
    
    // --- START: Support for exam group (dethi.json) when single exam folder not found --
    $examJsonPath = __DIR__ . '/' . $examName . '/' . $examName . '.json';
    $isExamGroup = false;
    $mergedPermFixed = [];
    $mergedPermPairs = [];

    if (file_exists($examJsonPath)) {
        // Single-exam (cũ) behavior
        $examDataRaw = json_decode(file_get_contents($examJsonPath), true);
        if (!$examDataRaw || !is_array($examDataRaw) || count($examDataRaw) < 2) {
            echo "<h2>Lỗi: Định dạng JSON không hợp lệ.</h2>";
            exit;
        }
    } else {
        // Try group-mode: only if type=DGNL and dethi.json exists and has this key
        $dethiPath = __DIR__ . '/dethi.json';
        if (!empty($_GET['type']) && $_GET['type'] === 'DGNL' && file_exists($dethiPath)) {
            $dethiAll = json_decode(file_get_contents($dethiPath), true);
            if (isset($dethiAll[$examName]) && isset($dethiAll[$examName]['exams']) && is_array($dethiAll[$examName]['exams'])) {
                $examGroup = $dethiAll[$examName];
                $combinedItems = [];
                $totalTime = 0;
                $examSectionIndex = 0;
                foreach ($examGroup['exams'] as $subExam) {
                    $examSectionIndex++;
                    if (!isset($subExam['id'])) continue;
                    $subId = (string)$subExam['id'];
                    $subName = isset($subExam['name']) ? $subExam['name'] : $subId;
                    $subJson = __DIR__ . '/' . $subId . '/' . $subId . '.json';
                    if (!file_exists($subJson)) {
                        echo "<h2>Không tìm thấy đề phụ '$subId' (một phần của nhóm '$examName').</h2>";
                        exit;
                    }
                    $subData = json_decode(file_get_contents($subJson), true);
                    if (!$subData || !is_array($subData) || count($subData) < 2) {
                        echo "<h2>Lỗi: Định dạng JSON không hợp lệ cho đề '$subId'.</h2>";
                        exit;
                    }
                    // tính thời gian cộng dồn
                    $subTime = intval($subData[0]['Time']);
                    $totalTime += $subTime;

                    // chuẩn hóa subId để không chứa '-' (vì code '-' thường dùng cho instruction)
                    $safeSubId = str_replace('-', '_', $subId);

                    // chèn một item 'section header' (được coi là instruction do chứa '-' trong code SECTION-...)
                    $sectionItem = [
                        'code' => 'SECTION-' . $safeSubId,
                        'content' => '__EXAM_SECTION_TITLE__' . $subName,
                        '_section_index' => $examSectionIndex,
                    ];
                    $combinedItems[] = $sectionItem;

                    // lấy items thực tế (bỏ phần header time)
                    $subItems = array_slice($subData, 1);
                    $localIndex = 0;
                    foreach ($subItems as $it) {
                        $localIndex++;
                        // prefix mã câu nếu có 'code'
                        if (isset($it['code'])) {
                            $origCode = (string)$it['code'];
                            $it['code'] = 'exam_' . $safeSubId . '_' . $origCode;
                        }
                        // tag thông tin exam nguồn (dùng sau khi render nếu cần)
                        $it['_exam_id'] = $subId;
                        $it['_exam_name'] = $subName;
                        $it['_section_index'] = $examSectionIndex;
                        $it['_local_index'] = $localIndex;
                        $combinedItems[] = $it;
                    }

                    // merge permutation (nếu có) từ thư mục đề con
                    $permPath = __DIR__ . '/' . $subId . '/permutation.json';
                    if (file_exists($permPath)) {
                        $permLocal = json_decode(file_get_contents($permPath), true);
                        if (is_array($permLocal)) {
                            // block/fixed
                            if (isset($permLocal['block']) && is_array($permLocal['block'])) {
                                foreach ($permLocal['block'] as $b) {
                                    $mergedPermFixed[] = 'exam_' . $safeSubId . '_' . $b;
                                }
                            }
                            // pair
                            if (isset($permLocal['pair']) && is_array($permLocal['pair'])) {
                                foreach ($permLocal['pair'] as $pairStr) {
                                    $parts = explode('&', $pairStr);
                                    $newParts = [];
                                    foreach ($parts as $p) {
                                        $p = trim($p);
                                        if ($p === '') continue;
                                        $newParts[] = 'exam_' . $safeSubId . '_' . $p;
                                    }
                                    if (count($newParts) > 0) {
                                        $mergedPermPairs[] = implode('&', $newParts);
                                    }
                                }
                            }
                        }
                    }
                } // end foreach exams in group

                // build combined examDataRaw with header as first element (Time)
                $examDataRaw = array_merge([['Time' => $totalTime, 'Name' => (isset($examGroup['name']) ? $examGroup['name'] : $examName)]], $combinedItems);
                $isExamGroup = true;

                // de-duplicate merged perm arrays
                $mergedPermFixed = array_values(array_unique($mergedPermFixed));
                $mergedPermPairs = array_values(array_unique($mergedPermPairs));
            } // end if group exists in dethi.json
        } // end if type=DGNL && dethi.json exists

        if (!$isExamGroup) {
            // fallback: báo lỗi như trước
            echo "<h2>Đề thi \"$examName\" không tồn tại.</h2>";
            exit;
        }
    }
    // --- END group support ---
    
    // --- START: Enhanced normalize / resolve merged perms (with debug) ---
    // Build available code map and also attach section/local index to items
    $availableCodes = []; // actual_code => index in $examDataRaw
    $seq = 0;
    foreach ($examDataRaw as $i => &$itm) {
        if ($i === 0 && isset($itm['Time'])) {
            continue;
        }
        $seq++;
        if (isset($itm['code'])) {
            $codeKey = (string)$itm['code'];
            $availableCodes[$codeKey] = $i;
        }
        $itm['_section_index'] = $itm['_section_index'] ?? 0;
        $itm['_local_index'] = $itm['_local_index'] ?? $seq;
    }
    unset($itm);

    // Build a normalized lookup map: many variants -> actual_code
    $normMap = []; // normalized_variant => array of actual_codes (keep array in case of collisions)
    function make_variants_for($code) {
        $variants = [];
        $c = (string)$code;
        $c = trim($c);
        $lower = strtolower($c);
        $variants[] = $lower;
        // replace separators
        $variants[] = str_replace('_', '.', $lower);
        $variants[] = str_replace('.', '_', $lower);
        // remove leading 'exam_' prefix if present (and with one more segment)
        if (strpos($lower, 'exam_') === 0) {
            $after = preg_replace('/^exam_[^_]+_/', '', $lower);
            if ($after !== $lower) $variants[] = $after;
            // also try removing only 'exam_' prefix
            $variants[] = preg_replace('/^exam_/', '', $lower);
        }
        // produce suffix-only candidates: last two segments split by '_' or '.'
        $splitUnd = preg_split('/[_\.]/', $lower);
        $n = count($splitUnd);
        if ($n >= 1) {
            $variants[] = $splitUnd[$n-1];
        }
        if ($n >= 2) {
            $variants[] = $splitUnd[$n-2] . '.' . $splitUnd[$n-1];
            $variants[] = $splitUnd[$n-2] . '_' . $splitUnd[$n-1];
        }
        // also remove non-alphanumeric except dot and underscore
        $variants[] = preg_replace('/[^a-z0-9._]/', '', $lower);

        // unique
        $variants = array_values(array_unique(array_filter($variants, function($v){ return $v !== ''; })));
        return $variants;
    }

    foreach ($availableCodes as $acode => $idx) {
        $vars = make_variants_for($acode);
        foreach ($vars as $v) {
            if (!isset($normMap[$v])) $normMap[$v] = [];
            if (!in_array($acode, $normMap[$v], true)) $normMap[$v][] = $acode;
        }
    }

    // helper: try to resolve code to an actual code using normMap
    function resolve_code_to_existing_enhanced($codeStr, $normMap, $availableCodes) {
        $c = trim(strtolower((string)$codeStr));
        $candidates = [];
        $candidates[] = $c;
        $candidates[] = str_replace('_', '.', $c);
        $candidates[] = str_replace('.', '_', $c);
        if (strpos($c, 'exam_') === 0) {
            $candidates[] = preg_replace('/^exam_[^_]+_/', '', $c);
            $candidates[] = preg_replace('/^exam_/', '', $c);
        }
        // suffix attempts (last 1..3 parts)
        $parts = preg_split('/[_\.]/', $c);
        $len = count($parts);
        for ($k = 1; $k <= min(3, $len); $k++) {
            $suf = implode('.', array_slice($parts, $len - $k, $k));
            $candidates[] = $suf;
            $candidates[] = str_replace('.', '_', $suf);
        }
        // clean versions
        $candidates[] = preg_replace('/[^a-z0-9._]/', '', $c);

        // dedup
        $candidates = array_values(array_unique(array_filter($candidates, function($v){ return $v !== ''; })));

        // try to find a match in normMap
        foreach ($candidates as $cand) {
            if (isset($normMap[$cand])) {
                // if multiple actual codes map to this normalized key, prefer exact match if present
                if (count($normMap[$cand]) === 1) return $normMap[$cand][0];
                // try to pick the one whose normalized form equals cand exactly
                foreach ($normMap[$cand] as $ac) {
                    if ($ac === $codeStr) return $ac;
                    if (strtolower($ac) === $cand) return $ac;
                }
                // fallback to first
                return $normMap[$cand][0];
            }
        }

        // final fallback: try suffix match directly on availableCodes keys
        foreach ($availableCodes as $ac => $idx) {
            if (substr($ac, -strlen($c)) === $c) return $ac;
            $alt = str_replace('_', '.', $ac);
            if (substr($alt, -strlen($c)) === $c) return $ac;
        }

        return null;
    }

    // Resolve merged perm lists (works for group-mode merged lists)
    $debugInfo = ['availableCodes' => array_keys($availableCodes), 'normMap_sample' => []];
    $cnt = 0;
    foreach ($normMap as $k => $v) {
        if ($cnt < 50) {
            $debugInfo['normMap_sample'][$k] = $v;
            $cnt++;
        } else break;
    }

    if (isset($mergedPermFixed) && is_array($mergedPermFixed)) {
        $fixedResolved = [];
        foreach ($mergedPermFixed as $f) {
            $f = trim($f);
            if ($f === '') continue;
            $resolved = resolve_code_to_existing_enhanced($f, $normMap, $availableCodes);
            if ($resolved !== null) {
                $fixedResolved[] = $resolved;
            } else {
                error_log("Unknown part (permutation fixed): $f");
            }
        }
        $mergedPermFixed = array_values(array_unique($fixedResolved));
    }

    if (isset($mergedPermPairs) && is_array($mergedPermPairs)) {
        $pairsResolved = [];
        foreach ($mergedPermPairs as $pairStr) {
            $parts = explode('&', $pairStr);
            $resolvedParts = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $r = resolve_code_to_existing_enhanced($p, $normMap, $availableCodes);
                if ($r !== null) $resolvedParts[] = $r;
                else error_log("Unknown part (permutation pair element): $p");
            }
            if (count($resolvedParts) >= 2) {
                $pairsResolved[] = implode('&', $resolvedParts);
            } else {
                error_log("Skipping pair because insufficient resolved parts: $pairStr");
            }
        }
        $mergedPermPairs = array_values(array_unique($pairsResolved));
    }

    // If debug=1 in URL, print diagnostics to page to help you see what's available
    if (!empty($_GET['debug']) && $_GET['debug'] == '1') {
        echo '<div style="background:#fff;padding:10px;border:1px solid #ccc;margin:10px 0;font-family:monospace;">';
        echo '<strong>DEBUG: availableCodes (count=' . count($availableCodes) . ")</strong><br><pre>";
        echo htmlspecialchars(json_encode(array_keys($availableCodes), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        echo "</pre><strong>DEBUG: normMap sample</strong><br><pre>";
        echo htmlspecialchars(json_encode($debugInfo['normMap_sample'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        echo "</pre><strong>DEBUG: mergedPermFixed resolved</strong><br><pre>";
        echo htmlspecialchars(json_encode($mergedPermFixed, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        echo "</pre><strong>DEBUG: mergedPermPairs resolved</strong><br><pre>";
        echo htmlspecialchars(json_encode($mergedPermPairs, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        echo "</pre></div>";
    }
    // --- END Enhanced normalize / resolve merged perms ---

    
    $timeMinutes = intval($examDataRaw[0]['Time']);
    
    // --- LƯU TOÀN BỘ ITEM VÀ TÁCH instruction vs câu thật ---
    $allItems = array_slice($examDataRaw, 1);
    $instructionBlocks = [];
    $notes = [
        'note1' => [],
        'note2' => [],
        'note3' => []
    ];
    $questions = [];

    foreach ($allItems as $item) {
        $code = isset($item['code']) ? trim((string)$item['code']) : '';

        // nếu code rỗng -> bỏ qua luôn (không tính là câu)
        if ($code === '') {
            // optional: ghi log để debug dữ liệu nguồn
            error_log("Warning: skipping item with empty code in exam {$examName}: " . json_encode($item, JSON_UNESCAPED_UNICODE));
            continue;
        }

        // Nếu là note1/note2/note3 -> lưu vào $notes và bỏ qua (không coi là question)
        if (preg_match('/^note([123])$/i', $code, $m)) {
            $key = 'note' . $m[1];
            $notes[strtolower($key)][] = $item;
            continue;
        }

        // Instruction blocks (ví dụ "1.1-1.2-1.3") giữ nguyên
        if (strpos($code, '-') !== false) {
            $instructionBlocks[] = $item;
        } else {
            $questions[] = $item;
        }
    }
    // --- KẾT THÚC TÁCH ---

    // --- BƯỚC PERMUTATION: ĐỌC permutation.json & XÁO TRỘN NHÓM ---
    $examDir  = __DIR__ . '/' . $examName;
    $permFile = $examDir . '/permutation.json';

    // Nếu đã ghép nhóm exam, ta đã chuẩn bị $mergedPermFixed/$mergedPermPairs
    if (!empty($isExamGroup) && $isExamGroup) {
        $fixed = isset($mergedPermFixed) ? $mergedPermFixed : [];
        $pairs = isset($mergedPermPairs) ? $mergedPermPairs : [];
    } else {
        $fixed    = [];  // danh sách code block riêng lẻ
        $pairs    = [];  // danh sách các cặp code
        if (file_exists($permFile)) {
            $permData = json_decode(file_get_contents($permFile), true);
            if ($permData && is_array($permData)) {
                $fixed = isset($permData['block']) && is_array($permData['block']) ? $permData['block'] : [];
                $pairs = isset($permData['pair']) && is_array($permData['pair']) ? $permData['pair'] : [];
            }
        }
    }
    
    $typeRaw = '';
    if (!empty($_GET['type'])) $typeRaw = (string)$_GET['type'];
    elseif (!empty($_POST['type'])) $typeRaw = (string)$_POST['type'];
    $isDGNL = (strtoupper(trim($typeRaw)) === 'DGNL');
    

    // Nhóm câu theo phần (1,2,3,4)
    $byPart = [];
    foreach ($questions as $q) {
        $part = intval(substr($q['code'], 0, 1));
        $byPart[$part][] = $q;
    }

    // --- MAP CÁC CÂU CÓ INSTRUCTION (ví dụ "1.17") ---
    $hasInstruction = [];
    foreach ($instructionBlocks as $ins) {
        // ins['code'] có dạng "1.1-1.2-1.3" => tách từng subcode
        foreach (explode('-', $ins['code']) as $sub) {
            $sub = trim($sub);
            if ($sub !== '') {
                $hasInstruction[$sub] = true;
            }
        }
    }

    // --- Bổ sung: hỗ trợ type=DGNL để tráo nhóm chung across parts ---
    $typeRaw = '';
    if (!empty($_GET['type'])) $typeRaw = (string)$_GET['type'];
    elseif (!empty($_POST['type'])) $typeRaw = (string)$_POST['type'];
    $isDGNL = (strtoupper(trim($typeRaw)) === 'DGNL');

    // Chuẩn bị container để gom tất cả final groups khi isDGNL
    $allFinalGroups = []; // mỗi phần sẽ push các nhóm vào đây nếu isDGNL

    // --- BẮT ĐẦU: xử lý theo phần như trước, nhưng thay đổi nhỏ khi $isDGNL === true ---
    $shuffled = [];
    foreach ($byPart as $part => $items) {
        $origCodes     = array_column($items, 'code');
        $groups        = [];
        $usedCodes     = [];
        $seenGroupKeys = [];  // để tránh nhóm trùng

        // 1) Nhóm theo pair (giữ như cũ)
        foreach ($pairs as $pairStr) {
            $codes = explode('&', $pairStr);
            if (count(array_diff($codes, $origCodes)) === 0) {
                sort($codes, SORT_STRING);
                $key = implode('&', $codes);
                if (isset($seenGroupKeys[$key])) continue;
                $grp = [];
                foreach ($items as $it) {
                    if (in_array($it['code'], $codes, true)) {
                        $grp[] = $it;
                        $usedCodes[] = $it['code'];
                    }
                }
                $groups[] = $grp;
                $seenGroupKeys[$key] = true;
            }
        }

        // 2) Nhóm theo block (riêng lẻ hoặc block+pair)
        foreach ($fixed as $b) {
            if (in_array($b, $origCodes, true) && !in_array($b, $usedCodes, true)) {
                $key = $b;
                if (isset($seenGroupKeys[$key])) continue;
                foreach ($items as $it) {
                    if ($it['code'] === $b) {
                        $groups[] = [$it];
                        $usedCodes[] = $b;
                        $seenGroupKeys[$key] = true;
                        break;
                    }
                }
            }
        }

        // 3) Nhóm mỗi câu còn lại làm nhóm đơn
        foreach ($items as $it) {
            $c = $it['code'];
            if (!in_array($c, $usedCodes, true)) {
                $key = $c;
                if (isset($seenGroupKeys[$key])) continue;
                $groups[]    = [$it];
                $usedCodes[] = $c;
                $seenGroupKeys[$key] = true;
            }
        }

        // 4) Phân movable vs fixed-group
        $movable     = [];
        $fixedGroups = [];
        if ($isDGNL) {
            // Khi DGNL: chỉ coi các nhóm có >1 phần tử và có mã nằm trong $fixed là fixed-group.
            // Các fixed single sẽ được coi là movable (để có thể tráo chung across parts).
            foreach ($groups as $g) {
                $codesInG = array_column($g, 'code');
                $intersectCount = count(array_intersect($codesInG, $fixed));
                if ($intersectCount > 0 && count($g) > 1) {
                    $fixedGroups[] = $g; // block cố định nhiều phần tử
                } else {
                    $movable[] = $g;
                }
            }
        } else {
            // Hành vi cũ: mọi group có mã thuộc $fixed được coi là fixedGroups
            foreach ($groups as $g) {
                $codesInG = array_column($g, 'code');
                if (count(array_intersect($codesInG, $fixed)) > 0) {
                    $fixedGroups[] = $g;
                } else {
                    $movable[] = $g;
                }
            }
        }

        // Tách fixedGroups thành fixedSinglesMap (1 phần tử) và fixedUnitGroups (>1 phần tử)
        $fixedSinglesMap = [];   // code => item
        $fixedUnitGroups  = [];  // array of groups (each group is array of items)
        foreach ($fixedGroups as $fg) {
            if (count($fg) === 1) {
                $fixedSinglesMap[$fg[0]['code']] = $fg[0];
            } else {
                $fixedUnitGroups[] = $fg;
            }
        }

        // 5) Xáo movable
        shuffle($movable);

        // 6) Kết hợp: bắt đầu từ movable; chèn các fixedUnitGroups (nhiều phần tử) vào vị trí gốc của phần tử đầu nhóm
        $finalGroups = $movable;
        foreach ($fixedUnitGroups as $fg) {
            $firstCode = $fg[0]['code'];
            $pos       = array_search($firstCode, $origCodes, true);
            $insertAt  = 0;
            if ($pos !== false) {
                $cntBefore = 0;
                foreach ($movable as $mg) {
                    $idx = array_search($mg[0]['code'], $origCodes, true);
                    if ($idx !== false && $idx < $pos) {
                        $cntBefore++;
                    }
                }
                $insertAt = $cntBefore;
            }
            array_splice($finalGroups, $insertAt, 0, [$fg]);
        }

        // 7) Xử lý từng nhóm: tráo nhóm con, đẩy instruction lên đầu, đảm bảo mã lớn nhất cuối (giữ logic hiện tại)
        foreach ($finalGroups as &$fg) {
            if (is_array($fg) && count($fg) > 1) {
                shuffle($fg);
                // đặt instruction lên đầu nếu có
                $instrIndex = null;
                foreach ($fg as $i => $it) {
                    if (isset($hasInstruction[$it['code']])) {
                        $instrIndex = $i;
                        break;
                    }
                }
                if ($instrIndex !== null && $instrIndex !== 0) {
                    $instrItem = $fg[$instrIndex];
                    array_splice($fg, $instrIndex, 1);
                    array_unshift($fg, $instrItem);
                }

                // đảm bảo mã lớn nhất ở cuối
                $maxIndex = null;
                $maxVal = -INF;
                foreach ($fg as $i => $it) {
                    $val = floatval($it['code']);
                    if ($val > $maxVal) {
                        $maxVal = $val;
                        $maxIndex = $i;
                    }
                }

                if ($maxIndex === 0 && count($fg) > 1) {
                    $maxIndex = null;
                    $maxVal = -INF;
                    foreach ($fg as $i => $it) {
                        if ($i === 0) continue;
                        $val = floatval($it['code']);
                        if ($val > $maxVal) {
                            $maxVal = $val;
                            $maxIndex = $i;
                        }
                    }
                }

                if ($maxIndex !== null && $maxIndex !== count($fg) - 1) {
                    $maxItem = $fg[$maxIndex];
                    array_splice($fg, $maxIndex, 1);
                    $fg[] = $maxItem;
                }
            }
        }
        unset($fg);

        // --- LƯU lại finalGroups ---
        if ($isDGNL) {
            // Khi DGNL: gom tất cả final groups across parts vào $allFinalGroups
            foreach ($finalGroups as $grp) $allFinalGroups[] = $grp;
        } else {
            // Hành vi cũ: flatten finalGroups thành danh sách câu (bỏ fixed singles để chèn lại theo vị trí gốc)
            $flatList = [];
            foreach ($finalGroups as $grp) {
                foreach ($grp as $question) {
                    if (isset($fixedSinglesMap[$question['code']])) {
                        continue;
                    }
                    $flatList[] = $question;
                }
            }

            // Chèn fixed singles vào vị trí gốc
            $fixedInserts = [];
            foreach ($fixedSinglesMap as $code => $item) {
                $pos = array_search($code, $origCodes, true);
                if ($pos === false) {
                    $fixedInserts[] = ['pos' => count($origCodes), 'item' => $item];
                } else {
                    $fixedInserts[] = ['pos' => $pos + 1, 'item' => $item];
                }
            }
            usort($fixedInserts, function($a, $b) { return $a['pos'] <=> $b['pos']; });

            foreach ($fixedInserts as $fi) {
                $insertAt = max(0, intval($fi['pos']) - 1);
                if ($insertAt >= count($flatList)) {
                    $flatList[] = $fi['item'];
                } else {
                    array_splice($flatList, $insertAt, 0, [$fi['item']]);
                }
            }

            // gán newOrder tạm (will be used later)
            $orderInPart = 1;
            foreach ($flatList as $question) {
                $question['newOrder'] = $orderInPart++;
                $shuffled[] = $question;
            }
        }
    } // end foreach $byPart

    // Nếu isDGNL thì tráo chung các nhóm và flatten sau khi đã xử lý mọi part
    // Nếu isDGNL thì tráo chung các nhóm và flatten sau khi đã xử lý mọi part
    if ($isDGNL) {
        $flatList = [];

        // Nếu đang ở chế độ exam-group (hiển thị nhiều đề cùng lúc),
        // muốn hiển thị *toàn bộ* câu của exam thứ nhất rồi đến exam thứ hai...
        if (!empty($isExamGroup) && $isExamGroup) {
            // Gom các nhóm (groups) theo _section_index (mỗi _section_index tương ứng một exam con)
            $groupsBySection = [];
            foreach ($allFinalGroups as $grp) {
                // lấy section index của nhóm từ phần tử đầu (fallback 0)
                $sec = 0;
                foreach ($grp as $it) {
                    if (isset($it['_section_index'])) {
                        $sec = intval($it['_section_index']);
                        break;
                    }
                }
                if (!isset($groupsBySection[$sec])) $groupsBySection[$sec] = [];
                $groupsBySection[$sec][] = $grp;
            }

            // Sắp theo thứ tự section (exam) tăng dần
            ksort($groupsBySection, SORT_NUMERIC);

            // Lần lượt flatten từng section (exam) — theo exam 1, exam 2, ...
            foreach ($groupsBySection as $secGroups) {
                // nếu muốn vẫn tráo các nhóm *trong mỗi exam*, bật shuffle ở dòng dưới
                // shuffle($secGroups);

                foreach ($secGroups as $grp) {
                    foreach ($grp as $question) {
                        $flatList[] = $question;
                    }
                }
            }
        } else {
            // Hành vi cũ: tráo chung các nhóm toàn bộ (không phải exam-group)
            shuffle($allFinalGroups);
            foreach ($allFinalGroups as $grp) {
                foreach ($grp as $question) {
                    $flatList[] = $question;
                }
            }
        }

        // Gán newOrder liên tiếp trên toàn bài (như trước)
        $orderInPart = 1;
        foreach ($flatList as $question) {
            $question['newOrder'] = $orderInPart++;
            $shuffled[] = $question;
        }
    }

    // Tiếp tục phần còn lại của code như trước (thay thế mảng gốc, tạo orderMap, progressList, v.v.)
    $questions = $shuffled;
    // --- KẾT THÚC PERMUTATION ---

    // --- SAU KHI XÁO VÀ GÁN newOrder ---
    // Tạo map từ mã câu (ví dụ "1.17") sang vị trí mới (newOrder)
    $orderMap = [];
    foreach ($questions as $q) {
        $orderMap[$q['code']] = $q['newOrder'];
    }

    // --- Build progressList chỉ từ $questions đã lọc ---
    $progressList = [];
    foreach ($questions as $q) {
        $code = $q['code'];
        $part = intval(substr($code, 0, 1));
        $progressList[$part][] = $code;
    }

    $savePath   = getSavePath($examName, $studentID);
    $resumeData = null;

    // === TẠO MÃ ĐỀ idExam ===
        $sum = 0.0;
        foreach ($questions as $idx => $q) {
            $codeFloat = floatval($q['code']);
            $position  = $idx + 1;
            $sum      += pow($codeFloat, $position);
        }

        // chuyển sang integer bằng cách nhân để giữ phần thập phân
        $sumInt = intval(round($sum * 1000)); // nhân 1000 để giữ 3 chữ số thập phân

        // phần thời gian (lấy ms epoch rồi lấy 4 chữ số cuối)
        $timeMs = (int) (microtime(true) * 1000) % 10000;

        // tổng kết hợp
        $combined = $sumInt + $timeMs;

        // chuẩn hoá modulo về dương trong [0,9999]
        $combinedMod = (($combined % 10000) + 10000) % 10000;

        // idExam luôn 4 chữ số (zero-padded), không âm
        $idExam = sprintf('%04d', $combinedMod);
        $generatedAt = date('Y-m-d H:i:s');
    // ==== KẾT THÚC TẠO idExam ====

    // === TẠO ENTRY GHI LOG ===
    $entry = [
        'time'      => date('Y-m-d H:i:s'),
        'studentID' => $studentID,
        'name'      => $foundUser['Họ và tên'],
        'examName'  => $examName,
        'idExam'    => $idExam
    ];

    // Đọc mảng cũ (nếu có) và đảm bảo là mảng
    $logData = [];
    if (file_exists($startLogFile)) {
        $raw     = file_get_contents($startLogFile);
        $logData = json_decode($raw, true);
        if (!is_array($logData)) {
            $logData = [];
        }
    }

    // Thêm entry và ghi lại
    $logData[] = $entry;
    file_put_contents($startLogFile, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    // === KẾT THÚC GHI LOG START ===

    // --- XỬ LÝ RESUME / KHỞI TẠO MỚI ---
    if (canResume($savePath)) {
        // Resume cũ: đọc vào và **luôn** ghi đè idExam mới
        $resumeData = json_decode(file_get_contents($savePath), true);
        
        if (!isset($resumeData['flag']) || !is_array($resumeData['flag'])) {
            $resumeData['flag'] = [];
        }
        writeJsonFile($savePath, $resumeData); // (nếu bạn muốn persist bổ sung flag rỗng) — lưu ý: chỉ gọi nếu cần
        
        $resumeData['idExam'] = $idExam;
        writeJsonFile($savePath, $resumeData);
    } else {
        // Tạo mới hoàn toàn, lưu luôn idExam
        $resumeData = [
            "studentID"        => $studentID,
            "name"             => $foundUser['Họ và tên'],
            "examName"         => $examName,
            "idExam"           => $idExam,
            "startTime"        => date('Y-m-d H:i:s'),
            "answers"          => new stdClass(),
            "remainingSeconds" => $timeMinutes * 60,
            "lastSaveTime"     => date('Y-m-d H:i:s')
        ];
        writeJsonFile($savePath, $resumeData);
    }

    $resumeJson = json_encode($resumeData, JSON_UNESCAPED_UNICODE);
	?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <title>Thi thử: <?= htmlspecialchars($displayExamName) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/favicon-32x32.png?v=2" type="image/png" sizes="32x32">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- Google Font (tùy chọn) -->
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.css">
        
        <!-- Summernote (CDN) -->
        <link href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.18/summernote-lite.min.css" rel="stylesheet">
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.18/summernote-lite.min.js"></script>

        <!-- KaTeX core & auto-render extension -->
        <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.js" crossorigin="anonymous"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/contrib/auto-render.min.js" crossorigin="anonymous"></script>
      	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
        <style>
            html {
                box-sizing: border-box;
                height: 100vh;             /* tương đương 100% viewport height */
            }
            *, *::before, *::after {
                box-sizing: inherit;
            }

            body {
                margin: 0;
                height: 100%;              /* đã bao gồm padding nhờ box‑sizing:border‑box */
                font-family: 'Inter', sans-serif;
                background-color: #f8f9fa;
                overflow: hidden;          /* ẩn luôn scrollbar ngoài cùng */
                overscroll-behavior-y: none; 
                touch-action: pan-y;
            }
            .container-fluid {
              height: 100%;  /* trừ padding top+bottom của body */
              display: flex;
              overflow-x: hidden;
            }
            p {margin: 0.75rem 0;}
            /* ========== CỘT NỘI DUNG CHÍNH ========== */
            .content-col {
                flex: 1;
                overflow-y: auto;
                padding: 20px;
            }
            .content-header h1 {
                color: #003366;
                font-weight: bold;
            }
            .content-header p {
                color: #003366;
                margin-bottom: 0.5rem;
            }
            .exam-form {
                margin-top: 20px;
            }
            .question-card {
                border: 1px solid #dee2e6;
                border-radius: 8px;
                background-color: #fff;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
                padding: 16px;
                margin-bottom: 16px;
            }
            .question-card h5 {
                font-weight: 600;
                margin-bottom: 12px;
                color: #003366;
            }
            .question-content {
                margin-bottom: 12px;
                text-align: justify;
            }
            /* Nút chọn phương án Part 1 */
            .btn-option-old {
                width: 3rem;
                height: 3rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 1rem;
                border: 2px solid #003366;
                color: #003366;
                background-color: #fff;
                margin: 0.25rem;
                border-radius: 4px;
            }
            .btn-option {
                /* Bỏ width/height cứng */
                min-width: 3rem;
                margin-right: 0.5rem;
                border: 2px solid #003366;
                color: #003366;
                background-color: #fff;
                border-radius: 4px;
            }
            .btn-option:hover {
                /* Bỏ width/height cứng */
                background-color: #f0f8ff;
            }
            .btn-option-old:hover {
                /* Bỏ width/height cứng */
                background-color: #f0f8ff;
            }
            .btn-option-active {
                background-color: #003366 !important;
                color: #fff !important;
            }
            .btn-font {
                /* Bỏ width/height cứng */
                min-width: 1rem;
                border: 2px solid #003366;
                color: #003366;
                background-color: #fff;
                border-radius: 4px;
            }
            .btn-font:hover {
                /* Bỏ width/height cứng */
                background-color: #f0f8ff;
            }
            /* Nút Đúng/Sai (Part 2) */
            .tsa-btn {
                min-width: 4.3rem;
                margin-right: 0.5rem;
                border: 2px solid #003366;
                color: #003366;
                background-color: #fff;
                border-radius: 4px;
            }
            .tsa-btn:hover {
                /* Bỏ width/height cứng */
                background-color: #f0f8ff;
            }
            .tsa-btn-active {
                background-color: #003366 !important;
                color: #fff !important;
            }
            /* Select Part 3 */
            .form-select {
                max-width: 130px;
                display: inline-block;
            }
            /* Input Part 4 */
            .form-control.short-answer {
                max-width: 350px;
            }
            table, table th, table td { border: 1px solid; padding: 0 5px;  }

            /* ========== CỘT SIDEBAR ========== */
            .sidebar-col {
                width: 15%;
                border-left: 1px solid #dee2e6;
                display: flex;
                flex-direction: column;
                padding: 20px;
                background-color: #fff;
            }
            .timer {
                font-size: 1.1rem;
                font-weight: bold;
                margin-bottom: 16px;
                color: #003366;
            }
            .progress-container {
                flex: 1;
                overflow-y: auto;
            }
            .progress-container h5 {
                margin-top: 0;
                margin-bottom: 12px;
                font-weight: 600;
                color: #003366;
            }
            .list-group-item {
                cursor: pointer;
                border: none;
                border-bottom: 1px solid #eee;
                padding: 8px 12px;
            }
            .list-group-item:last-child {
                border-bottom: none;
            }
            .list-group-item:hover {
                background-color: #f0f0f0;
            }
            .list-group-item.answered {
                background-color: #d1e7dd; /* xanh nhạt */
                color: #0f5132;
            }
            .list-group-item.part-header {
                background-color: #003366;
                color: #fff;
                font-weight: 600;
            }
            .submit-box {
                margin-top: 20px;
            }
            .btn-submit {
                background-color: #28a745;
                color: #fff;
                border: none;
            }
            .btn-submit:hover {
                background-color: #218838;
            }
            /* Network status */
            #network-status {
                border-radius: 4px;
                font-size: 0.9rem;
            }
            .footer { text-align: center; padding: 1rem 0; padding-top: 0px; font-size: 0.9rem; color: #666; }
            /* Trên mobile (≤1000px): sidebar trượt ra ngoài bên phải */
            @media (max-width: 1000px) {
                .sidebar-col {
                    position: fixed;
                    top: 0;
                    right: 0;                   /* giữ bên phải */
                    width: 50%;                 /* hoặc tỉ lệ bạn muốn */
                    height: 100vh;
                    background: #fff;
                    transform: translateX(100%);/* ẩn hoàn toàn sang phải */
                    transition: transform 0.3s ease-in-out;
                    z-index: 1050;
                }
                .sidebar-col.show {
                    transform: translateX(0);   /* hiển thị khi có class show */
                }
                .content-col {
                    margin-right: 0 !important; /* nội dung full width */
                }
            }
            /* Trên desktop (>1000px): sidebar cố định bên phải */
            @media (min-width: 1001px) {
                .sidebar-col {
                    position: relative;
                    transform: none;
                    width: 15%;                 /* như cũ */
                }
            }

            /* ==== instruction-block chỉ Part I ==== */
            .instruction-card {
                background-color: #fff;
                border: 1px solid #003366;
                border-radius: 8px;
                padding: 12px;
                margin-bottom: 16px;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            }
            .instruction-card .instruction-content {
                font-style: normal;
                color: #003366;
                text-align: justify;
            }
            .katex {font-size: 1.05em; line-height: 1.5;}
            .vlist { font-size: 1.05em; }
            /* nhưng nếu vlist chứa thanh phân thì giữ kích thước mặc định hoặc khác */
            .vlist:has(.frac-line),
            .vlist:has(span[style*="border-bottom"]) {
              font-size: 1.5em; /* hoặc 1em tuỳ ý */
            }
            br + span:has(+ br) {
              display: block;       /* span thành block để chiếm cả hàng */
              text-align: center;   /* căn giữa nội dung */
              margin: 0;     /* khoảng cách tùy chỉnh */
              width: 100%;
            }
            .drop-zone {
                display: inline-block;
                min-width: 3rem;
                min-height: 1.5rem;
                margin: 0 .25rem;
                border-bottom: 2px dashed #003366;
                vertical-align: text-bottom;
                transition: background-color .2s, border-style .2s;                
            }
            .drop-zone.filled {
                min-width: 3rem;
                min-height: 1.5rem;      
                border: 1px dashed #003366;
                border-radius: 0.5rem;
                text-align: center;
                background-color: #f0f8ff;
                vertical-align: text-bottom;
                display: inline-block;
                cursor: grab;
                padding: 0 5px;
            }
            .drag-pool {
                margin-top: .5rem;
                padding: .5rem;
                background: #deeaf7;
                display: flex;
                flex-wrap: wrap;
                gap: .5rem;
            }
            .drag-item {
                padding: .25rem .5rem;
                border: 1px solid #003366;
                border-radius: 4px;
                background-color:#fff;
                cursor: grab;
                user-select: none;
            }
            @media (max-width:1000px) {
                /* cho phép các phần con wrap xuống dòng */
                .question-card .text-tf.d-flex {
                    flex-wrap: wrap;
                    gap: 6px;
                    align-items: center;
                }
                /* nút nhỏ hơn để vừa màn hình */
                .question-card .tsa-btn {
                    padding: .25rem .5rem;
                    font-size: .95rem;
                    min-width: 70px;
                }
                /* cho phần text mô tả xuống dòng sau nút */
                .question-card .text-tf .me-2[style] {
                    width: 100%;
                    margin-left: 0.25rem;
                    margin-top: 0.25rem;
                }
                .question-card .btn-option {
                    padding: .25rem .5rem;
                    font-size: .95rem;
                    min-width: 38px;
                }
            }
            *{
                scrollbar-width: auto;      
                scrollbar-color: #003366 transparent; /* thumb color | track color */
            }
            #sidebar::-webkit-scrollbar { width: 10px; }
            #sidebar::-webkit-scrollbar-track { background: transparent; }
            #sidebar::-webkit-scrollbar-thumb {
                background: #003366;
                border-radius: 6px;
                border: 2px solid rgba(0,0,0,0); /* khoảng đệm mượt */
            }
            #sidebar::-webkit-scrollbar-thumb:hover { background: #002244; }
            .form-control.short-answer {
                max-width: 360px;
                border: 1px solid #ced4da;
                border-radius: 4px;
                transition: border-color 0.2s;
            }
            .form-control.short-answer:focus {
                border-color: #003366;
                box-shadow: none;
            }
          	/* Kích thước icon trong nút */
            #fullscreenBtn .bi { font-size: 1.05rem; vertical-align: middle; }
            /* ===== group-with-instr layout ===== */
            .group-with-instr {
              display: flex;
              gap: 16px;
              align-items: flex-start; /* đảm bảo instr-col cao bằng nội dung */
              margin-bottom: 16px;
            }

            .group-with-instr .instr-col {
              width: 50%;
              flex-shrink: 0;
              max-height: 650px;
              overflow-y: auto;
              align-self: flex-start;
            }

            .group-with-instr .q-col {
              width: 50%;
              /* bỏ giới hạn cứng ở CSS — JS sẽ set inline max-height khi cần */
              max-height: none;
              overflow-y: auto;
            }
            
            /* splitter (ngăn kéo giữa instr-col và q-col) */
            .group-with-instr { position: relative; }

            .group-with-instr .splitter {
              width: 10px;
              cursor: col-resize;
              flex-shrink: 0;
              user-select: none;
              -webkit-user-select: none;
              align-self: stretch;
              background: linear-gradient(90deg, transparent, rgba(0,0,0,0.04), transparent);
              transition: background .12s, opacity .12s;
            }

            /* hover/active */
            .group-with-instr .splitter:hover { background: linear-gradient(90deg, transparent, rgba(0,0,0,0.08), transparent); }
            body.dragging-splitter { cursor: col-resize; user-select: none !important; }

            /* ẩn splitter trên mobile (đã có media query cho .group-with-instr -> block) */
            @media (max-width:1000px) {
              .group-with-instr .splitter { display: none; }
            }
            
            @media (min-height: 800px) {
              /* Make instruction column sticky inside the group container.
                 top value should leave space for any fixed header (adjust if you have a fixed top bar) */
              .group-with-instr .instr-col {
                  width: 50%;
                  flex-shrink: 0;
                  max-height: 800px;
                  overflow-y: auto;
                  align-self: flex-start;
              }
            }
            
            @media (min-width:1001px) {
              /* Make instruction column sticky inside the group container.
                 top value should leave space for any fixed header (adjust if you have a fixed top bar) */
              .group-with-instr .instr-col {
                position: -webkit-sticky;
                position: sticky;
                top: 18px;           /* điều chỉnh nếu bạn có header cố định */
                align-self: flex-start; /* đảm bảo nó bắt đầu từ trên cùng của nhóm */
                z-index: 2;
              }

              /* ensure q-col doesn't scroll independently on desktop (so sticky works relative to .content-col) */
              .group-with-instr .q-col {
                overflow-y: visible !important;
                max-height: none !important;
              }
            }
            
            @media (max-width: 1000px) {
              .group-with-instr {
                display: block;
              }
              .group-with-instr .instr-col,
              .group-with-instr .q-col {
                width: 100%;
                max-height: none;
                overflow: visible;
              }
              .instruction-card { min-height: 0;}
            }

            /* một chút style cho instruction-card trong layout nhóm */
            .group-with-instr .instruction-card {
              margin-bottom: 12px;
            }
            
            /* nút cờ nhỏ, nằm inline */
            .btn-flag {
                border: none;
                background: transparent;
                color: #666;
                padding: 0.15rem 0.35rem;
                margin-left: 8px;
                vertical-align: middle;
            }
            .btn-flag:hover { color: #003366; }

            /* khi đã gắn cờ -> màu vàng nổi bật */
            .btn-flag.flagged i { color: #ffc107; }

            /* progress list: khi item có flagged, thêm style */
            .list-group-item.flagged {
                position: relative;
            }
            .list-group-item .prog-flag {
                margin-left: 8px;
                color: #ffc107;
            }
            /* layout mặc định: ngang, canh baseline */
            .exam-header {
              display: flex;
              align-items: baseline;
              justify-content: space-between;
              gap: 12px; /* khoảng cách giữa các phần */
            }

            /* Khi màn hình <= 999px: đổi sang cột, nút xuống dòng */
            @media (max-width: 999px) {
              .exam-header {
                flex-direction: column;
                align-items: flex-start;  /* căn trái khi xuống dòng */
              }

              /* optional: cho nút có chút khoảng cách trên */
              #btn-report-error {
                margin-top: 6px;
              }
            }
            
            .note p {
              margin-top: 0;
              margin-bottom: 5px;
            }
            
            img {
              display: block;      /* hoặc inline-block nếu cần */
              max-width: 100%;     /* không vượt quá vùng chứa */
              height: auto;        /* giữ tỉ lệ */
              object-fit: contain; /* ảnh trong khung (không bắt buộc) */
              max-height: 80vh;    /* tùy chọn: tránh chiếm quá nhiều chiều cao viewport */
            }
            
            /* Font controls in sidebar */
            .font-controls .btn {
              padding: .25rem .45rem;
              border-radius: 6px;
            }
            #fontScaleDisplay {
              color: #003366;
              font-size: .95rem;
            }
            /* đảm bảo button/inner elements kế thừa font-size khi chúng ta set inline */
            .question-card .btn,
            .question-card .btn * {
              font-size: inherit !important;
            }
            .options-wrapper span,
            .options-wrapper,
            .tsa-btn,
            .drop-zone,
            .drag-item,
            .form-control.short-answer,
            .form-select {
              font-size: inherit !important;
            }
            #mobile-toggle {
              position: fixed;
              top: 12px;
              right: 12px;
              z-index: 1065;         /* cao hơn sidebar để luôn thấy nút */
              width: 44px;
              height: 44px;
              border-radius: 50%;
              display: inline-flex;
              align-items: center;
              justify-content: center;
              box-shadow: 0 4px 12px rgba(0,0,0,0.18);
              border: 1px solid rgba(0,0,0,0.08);
            }
        </style>
    </head>
    <body>

        <div class="container-fluid">
            <!-- ========== CỘT NỘI DUNG CHÍNH ========== -->
            <div class="content-col">
                <?php if (empty($isDGNL)): // nếu không phải DGNL thì show header ?>
                <div class="exam-header">
                    <div class="content-header tab-pane fade show active">
                        <h1>Đề thi: <?= htmlspecialchars($displayExamName) ?></h1>
                        <p>Thí sinh: <strong><?= htmlspecialchars($foundUser['Họ và tên']) ?></strong>
                            &ndash; Số báo danh: <strong><?= htmlspecialchars($foundUser['0000']) ?></strong>
                            &ndash; Trường: <strong><?= htmlspecialchars($foundUser['Trường']) ?></strong>
                            &ndash; Ngày thi: <strong><?= date('d/m/Y') ?></strong>
                            <?php
                            $sessionRaw = $timeData['session'] ?? '';
                            $session = is_string($sessionRaw) ? trim($sessionRaw) : (string)$sessionRaw;
                            ?>
                            <?php if (strlen($session) > 0): ?>
                              <a>&ndash; Ca thi: <strong><?= htmlspecialchars($session) ?></strong></a>
                            <?php endif; ?>
                        </p>
                    </div>

                    <button type="button" id="btn-report-error" class="btn btn-outline-danger btn-sm">
                        Báo lỗi
                    </button>
                </div>
                <?php else: // nếu là DGNL thì ẩn header và nút báo lỗi (không render gì) ?>
                <!-- DGNL mode: header & report button hidden -->
                <?php endif; ?>

                <form id="examForm" class="exam-form" method="POST" action="?action=submit">
                    <input type="hidden" name="examName" value="<?= htmlspecialchars($examName) ?>">
                    <input type="hidden" name="studentID" value="<?= htmlspecialchars($studentID) ?>">  
                    
                    <?php
                    // Nếu có note1 -> hiển thị đầu đề (dùng style instruction-card sẵn có)
                    if (!empty($notes['note1'])): ?>
                      <div class="mb-3">
                        <?php foreach ($notes['note1'] as $n): 
                            $noteHtml = html_entity_decode($n['content']);
                        ?>
                          <div class="instruction-card">
                            <div class="instruction-content note">
                              <?= $noteHtml ?>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>


                    <?php
                    // ---- START: New DGNL mixed-group rendering block ----
                    // Thay thế chỗ cũ: if ($isDGNL) { $mixedQuestions = $questions; } else { ... }

                    if ($isDGNL) {
                        // $questions đã là danh sách đã permutation ở trên
                        $mixedQuestions = $questions;

                        // --- Tạo map code->group dựa trên pairs/fixed giống logic part-based ---
                        $codeToGroup = [];
                        foreach ($pairs as $pairStr) {
                            $codes = explode('&', $pairStr);
                            sort($codes, SORT_STRING);
                            $key = 'pair:' . implode('&', $codes);
                            foreach ($codes as $c) $codeToGroup[trim($c)] = $key;
                        }
                        foreach ($fixed as $b) {
                            $codeToGroup[trim($b)] = 'block:' . $b;
                        }
                        foreach ($mixedQuestions as $q) {
                            $c = $q['code'];
                            if (!isset($codeToGroup[$c])) $codeToGroup[$c] = 'single:' . $c;
                        }

                        // --- ordered group keys (the order follows current $mixedQuestions sequence) ---
                        $orderedGroupKeys = [];
                        foreach ($mixedQuestions as $q) {
                            $gk = $codeToGroup[$q['code']];
                            if (!in_array($gk, $orderedGroupKeys, true)) $orderedGroupKeys[] = $gk;
                        }

                        // --- groups: key => [items...] ---
                        $groups = [];
                        foreach ($orderedGroupKeys as $gk) $groups[$gk] = [];
                        foreach ($mixedQuestions as $q) {
                            $groups[$codeToGroup[$q['code']]][] = $q;
                        }

                        // --- build instruction map once (subcode -> content) ---
                        $instrBySub = [];
                        foreach ($instructionBlocks as $ins) {
                            foreach (explode('-', $ins['code']) as $sub) {
                                $sub = trim($sub);
                                if ($sub !== '') $instrBySub[$sub] = $ins['content'];
                            }
                        }

                        // parseInstruction helper
                        // --- BUILD: code->newOrder maps, both global and per-exam ---
                        $globalCodeToNewOrder = [];       // map: fullCode -> newOrder
                        $globalSuffixToNewOrder = [];     // map: suffix like "1.14" -> newOrder (best-effort)
                        $byExamCodeToNewOrder = [];       // map: [examId][fullCode] -> newOrder
                        $byExamSuffixToNewOrder = [];     // map: [examId][suffix] -> newOrder

                        foreach ($mixedQuestions as $qq) {
                            if (!isset($qq['code'])) continue;
                            $full = $qq['code'];
                            $newOrder = isset($qq['newOrder']) ? intval($qq['newOrder']) : 0;

                            // global full code
                            $globalCodeToNewOrder[$full] = $newOrder;

                            // global suffix if present (e.g. "1.14")
                            if (preg_match('/([0-9]+\.[0-9]+)$/', $full, $mm)) {
                                $suf = $mm[1];
                                $globalSuffixToNewOrder[$suf] = $newOrder;
                            }

                            // per-exam maps (use _exam_id if present, else try _section_index -> examGroup)
                            $examId = $qq['_exam_id'] ?? null;
                            if (empty($examId) && !empty($qq['_section_index']) && !empty($examGroup['exams'])) {
                                $idx = intval($qq['_section_index']) - 1;
                                if (isset($examGroup['exams'][$idx]['id'])) $examId = $examGroup['exams'][$idx]['id'];
                            }

                            if (!empty($examId)) {
                                if (!isset($byExamCodeToNewOrder[$examId])) $byExamCodeToNewOrder[$examId] = [];
                                if (!isset($byExamSuffixToNewOrder[$examId])) $byExamSuffixToNewOrder[$examId] = [];

                                $byExamCodeToNewOrder[$examId][$full] = $newOrder;
                                if (preg_match('/([0-9]+\.[0-9]+)$/', $full, $mm2)) {
                                    $byExamSuffixToNewOrder[$examId][$mm2[1]] = $newOrder;
                                }
                            }
                        }

                        // --- parseInstruction helper (accepts a context exam id) ---
                        // Usage: $parseInstruction($raw, $contextExamId = null)
                        $parseInstruction = function($raw, $contextExamId = null) use (
                            $orderMap, $globalCodeToNewOrder, $globalSuffixToNewOrder,
                            $byExamCodeToNewOrder, $byExamSuffixToNewOrder
                        ) {
                            return preg_replace_callback(
                                '/\?([^\?]+)\?/',
                                function($m) use (
                                    $orderMap, $globalCodeToNewOrder, $globalSuffixToNewOrder,
                                    $byExamCodeToNewOrder, $byExamSuffixToNewOrder, $contextExamId
                                ) {
                                    $key = trim($m[1]);

                                    // 1) try exact orderMap match (legacy "1.14" -> order)
                                    if (isset($orderMap[$key])) return (string)$orderMap[$key];

                                    // 2) if context exam provided, try exam-scoped lookups first
                                    if (!empty($contextExamId)) {
                                        if (isset($byExamCodeToNewOrder[$contextExamId][$key])) {
                                            return (string)$byExamCodeToNewOrder[$contextExamId][$key];
                                        }
                                        if (preg_match('/([0-9]+\.[0-9]+)$/', $key, $mmx)) {
                                            $suf = $mmx[1];
                                            if (isset($byExamSuffixToNewOrder[$contextExamId][$suf])) {
                                                return (string)$byExamSuffixToNewOrder[$contextExamId][$suf];
                                            }
                                        }
                                    }

                                    // 3) try global full-code lookup (e.g. ?exam_TK_DGNL_..._1.14?)
                                    if (isset($globalCodeToNewOrder[$key])) return (string)$globalCodeToNewOrder[$key];

                                    // 4) try global suffix lookup (e.g. ?1.14?)
                                    if (preg_match('/([0-9]+\.[0-9]+)$/', $key, $mm)) {
                                        $suf = $mm[1];
                                        if (isset($globalSuffixToNewOrder[$suf])) return (string)$globalSuffixToNewOrder[$suf];
                                    }

                                    // 5) no match -> keep original token so author can debug
                                    return $m[0];
                                },
                                $raw
                            );
                        };

                        // --- Helper closures to render an individual item by its part type ---
                        // Each closure echoes the HTML for one question item (so we can reuse existing structure).
                        $renderPart1Item = function($q, $parseInstruction, $resumeData, $pairs, $fixed) {
                            $code    = $q['code'];
                            $decoded = html_entity_decode($q['content']);
                            $content = $decoded;

                            if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                $content = $parseInstruction($content);
                            }

                            $hasParsedOptions = false;
                            $options = ['A'=>'','B'=>'','C'=>'','D'=>''];
                            if (preg_match_all('#<([abcd])\.\s*>(.*?)</\1\.\s*>#isU', $decoded, $m)) {
                                $hasParsedOptions = true;
                                foreach ($m[1] as $i => $letter) {
                                    $optText = trim($m[2][$i]);
                                    if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                        $optText = $parseInstruction($optText);
                                    }
                                    $options[strtoupper($letter)] = $optText;
                                }
                                $content = preg_replace('#<([abcd])\.\s*>.*?</\1\.\s*>#isU', '', $decoded);
                                if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                    $content = $parseInstruction($content);
                                }
                            }

                            $lengths = array_filter(array_map('strlen', $options));
                            $maxLen = !empty($lengths) ? max($lengths) : 0;
                            if ($hasParsedOptions) {
                                if ($maxLen <= 40) {
                                    $wrapperClass    = 'd-flex flex-row flex-wrap align-items-start';
                                    $btnWrapperStyle = 'width:25%;';
                                } elseif ($maxLen <= 90) {
                                    $wrapperClass    = 'row row-cols-2 g-2';
                                    $btnWrapperStyle = 'width:50%;';
                                } else {
                                    $wrapperClass    = 'd-flex flex-column';
                                    $btnWrapperStyle = 'width:100%;';
                                }
                            } else {
                                $wrapperClass    = 'd-flex flex-wrap';
                                $btnWrapperStyle = '';
                            }

                            $btnClass = $hasParsedOptions ? 'btn-option' : 'btn-option-old';
                            $nameKey = isset($item['__nameKey']) ? $item['__nameKey'] : str_replace('.', '_', $code);
                            ?>
                            <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h5>Câu <?= $q['newOrder'] ?></h5>
                                    <?php $flagId = 'flag-' . (isset($item['__nameKey']) ? $item['__nameKey'] : str_replace('.', '_', $code));
                                          $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                    <button type="button" class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?>"
                                            id="<?= htmlspecialchars($flagId) ?>"
                                            onclick="toggleFlag('<?= htmlspecialchars($code) ?>')" title="Gắn / gỡ cờ">
                                        <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                    </button>
                                </div>
                                <div class="question-content"><?= $content ?></div>

                                <div class="<?= $wrapperClass ?>" data-maxlen="<?= $maxLen ?>">
                                    <?php
                                    if ($hasParsedOptions) {
                                        $displayKeys = array_keys($options);
                                        shuffle($displayKeys);
                                        $displayLabels = ['A','B','C','D'];
                                    } else {
                                        $displayKeys = ['A','B','C','D'];
                                        $displayLabels = ['A','B','C','D'];
                                    }
                                    for ($i = 0; $i < 4; $i++):
                                        $origKey = isset($displayKeys[$i]) ? $displayKeys[$i] : '';
                                        $dispLabel = $displayLabels[$i];
                                    ?>
                                        <div class="p-1"<?php if (!empty($btnWrapperStyle)) echo " style=\"$btnWrapperStyle\""; ?>>
                                            <div style="display: inline-flex;" class="mb-2 d-flex align-items-center">
                                                <button type="button" class="btn <?= $btnClass ?>"
                                                        id="<?= $nameKey . '_' . $origKey ?>"
                                                        onclick="selectPart1('<?= $code ?>','<?= $origKey ?>')">
                                                    <b><?= $dispLabel ?></b>
                                                </button>
                                                <span style="margin-top: 0px; margin-left: 0px;">
                                                    <?= $hasParsedOptions && $origKey !== '' 
                                                        ? preg_replace('#</?p\b[^>]*>#i', '', $options[$origKey]) 
                                                        : '' ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>

                                <input type="hidden" name="<?= $nameKey ?>" id="input-<?= $nameKey ?>">
                            </div>
                            <?php
                        };

                        $renderPart2Item = function($q, $parseInstruction, $resumeData) {
                            $code    = $q['code'];
                            $decoded = html_entity_decode($q['content']);
                            $content = $decoded;
                            $hasSubParsed = false;
                            $subContents  = ['a'=>'','b'=>'','c'=>'','d'=>''];
                            if (preg_match_all('#<([abcd])\)\s*>(.*?)</\1\)\>#isU', $decoded, $m)) {
                                $hasSubParsed = true;
                                foreach ($m[1] as $i => $letter) {
                                    $subContents[strtolower($letter)] = trim($m[2][$i]);
                                }
                                $content = preg_replace('#<([abcd])\)\s*>.*?</\1\)\>#isU', '', $decoded);
                            }
                            $baseKey = isset($item['__nameKey']) ? $item['__nameKey'] : str_replace('.', '_', $code);
                            ?>
                            <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h5>Câu <?= $q['newOrder'] ?></h5>
                                    <?php $flagId = 'flag-' . (isset($item['__nameKey']) ? $item['__nameKey'] : str_replace('.', '_', $code));
                                          $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                    <button type="button" class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?>"
                                            id="<?= htmlspecialchars($flagId) ?>"
                                            onclick="toggleFlag('<?= htmlspecialchars($code) ?>')" title="Gắn / gỡ cờ">
                                        <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                    </button>
                                </div>
                                <div class="question-content"><?= $content ?></div>
                                <div class="mt-2 ps-2">
                                <?php foreach (['a','b','c','d'] as $sub):
                                    $subKey = $baseKey . '_'. $sub;
                                ?>
                                <div class="text-tf mb-2  d-flex align-items-center">
                                    <span class="me-2"><b><?= $sub ?>)</b></span>
                                    <button type="button" class="btn tsa-btn" id="<?= $subKey.'_dung' ?>"
                                            onclick="selectPart2('<?= $code ?>','<?= $sub ?>','Đúng')">Đúng</button>
                                    <button type="button" class="btn tsa-btn" id="<?= $subKey.'_sai' ?>"
                                            onclick="selectPart2('<?= $code ?>','<?= $sub ?>','Sai')">Sai</button>
                                    <span class="me-2" style="margin-left: 0px; text-align: justify;">
                                        <?= $hasSubParsed ? preg_replace('#</?p\b[^>]*>#i', '', $subContents[$sub]) : '' ?>
                                    </span>
                                    <input type="hidden" name="<?= $subKey ?>" id="input-<?= $subKey ?>">
                                </div>
                                <?php endforeach ?>
                                </div>
                            </div>
                            <?php
                        };

                        $renderPart3Item = function($q, $parseInstruction, $resumeData) {
                            $code = $q['code'];
                            $content = $q['content'];
                            $baseName = isset($item['__nameKey']) ? $item['__nameKey'] : str_replace('.', '_', $code);
                            ?>
                            <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h5>Câu <?= $q['newOrder'] ?></h5>
                                    <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                          $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                    <button type="button" class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?>"
                                            id="<?= htmlspecialchars($flagId) ?>"
                                            onclick="toggleFlag('<?= htmlspecialchars($code) ?>')" title="Gắn / gỡ cờ">
                                        <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                    </button>
                                </div>
                                <div class="question-content"><?= $content ?></div>
                                <div class="mt-2">
                                    <?php for ($i = 1; $i <= 4; $i++):
                                        $subName = $baseName . '_' . $i;
                                    ?>
                                    <div class="mb-2 d-flex align-items-center">
                                        <label class="form-label me-2">Ý <?= $i ?> nối với:</label>
                                        <select class="form-select" name="<?= htmlspecialchars($subName) ?>"
                                                onchange="selectPart3or4('<?= $code ?>','<?= $subName ?>', this.value)">
                                            <option value="">-- Chọn --</option>
                                            <?php foreach (['a','b','c','d','e'] as $opt): ?>
                                            <option value="<?= $opt ?>"><?= $opt ?></option>
                                            <?php endforeach ?>
                                        </select>
                                    </div>
                                    <?php endfor ?>
                                </div>
                            </div>
                            <?php
                        };

                        $renderPart4Item = function($q, $parseInstruction, $resumeData) {
                            $code = $q['code'];
                            $decoded = html_entity_decode($q['content']);
                            $content = $decoded;
                            if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                $content = $parseInstruction($content);
                            }
                            $name4 = isset($item['__nameKey']) ? $item['__nameKey'] : str_replace('.', '_', $code);
                            ?>
                            <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h5>Câu <?= $q['newOrder'] ?></h5>
                                    <?php $flagId = 'flag-' . (isset($item['__nameKey']) ? $item['__nameKey'] : str_replace('.', '_', $code));
                                          $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                    <button type="button" class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?>"
                                            id="<?= htmlspecialchars($flagId) ?>"
                                            onclick="toggleFlag('<?= htmlspecialchars($code) ?>')" title="Gắn / gỡ cờ">
                                        <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                    </button>
                                </div>
                                <div class="question-content"><?= $content ?></div>
                                <input class="form-control short-answer mt-2" type="text" name="<?= htmlspecialchars($name4) ?>"
                                       id="input-<?= htmlspecialchars($name4) ?>" placeholder="Nhập câu trả lời dạng số"
                                       oninput="selectPart3or4('<?= $code ?>','<?= $name4 ?>', this.value)">
                            </div>
                            <?php
                        };

                        $renderPart5Item = function($q, $resumeData) {
                            $code     = $q['code'];
                            $nameBase = str_replace('.', '_', $code);
                            $html = html_entity_decode(str_replace('\/', '/', $q['content']));
                            // bóc subitems (nhanh)
                            $subItems = [];
                            if (preg_match_all('#<([a-z0-9]+)\)\s*>(.*?)</\1\)>#isU', $html, $matches, PREG_SET_ORDER)) {
                                foreach ($matches as $m) {
                                    $subItems[] = ['key'=>$m[1],'content'=>trim($m[2])];
                                    $html = str_replace($m[0], '', $html);
                                }
                            }
                            $mainText = trim($html);
                            ?>
                            <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h5>Câu <?= $q['newOrder'] ?></h5>
                                    <?php $flagId = 'flag-' . (isset($item['__nameKey']) ? $item['__nameKey'] : str_replace('.', '_', $code));
                                          $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                    <button type="button" class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?>"
                                            id="<?= htmlspecialchars($flagId) ?>"
                                            onclick="toggleFlag('<?= htmlspecialchars($code) ?>')" title="Gắn / gỡ cờ">
                                        <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                    </button>
                                </div>

                                <?php if ($mainText !== ''): ?>
                                <div class="question-content"><?= $mainText ?></div>
                                <?php endif; ?>

                                <?php if (!empty($subItems)): ?>
                                    <?php foreach ($subItems as $sub):
                                        $dotKey  = $code . '.' . $sub['key'];
                                        $fieldId = str_replace('.', '_', $dotKey);
                                    ?>
                                    <div class="mb-3">
                                        <div class="mb-1"><strong><?= $sub['key'] ?>)</strong> <?= $sub['content'] ?></div>
                                        <div class="form-control mt-2 summernote" contenteditable="true" id="input-<?= $fieldId ?>"
                                             data-code="<?= $dotKey ?>" style="min-height:120px; overflow:auto;">
                                            <?= $resumeData['answers'][$dotKey] ?? '' ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="form-control mt-2 summernote" contenteditable="true" id="input-<?= $nameBase ?>"
                                         data-code="<?= $code ?>" style="min-height:150px; overflow:auto;">
                                        <?= $resumeData['answers'][$code] ?? '' ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php
                        };

                        $renderPart6Item = function($q, $resumeData) {
                            $code    = $q['code'];
                            $nameKey = str_replace('.', '_', $code);
                            $decoded = html_entity_decode($q['content']);

                            preg_match_all('#<([0-9]+)>(.*?)</\1>#is', $decoded, $m);
                            $placeholders   = $m[1];
                            $allAnswers     = $m[2];

                            preg_match_all('#<n>(.*?)</n>#is', $decoded, $dn);
                            $distractors    = $dn[1];

                            $saved = isset($resumeData['answers']) ? (array)$resumeData['answers'] : [];
                            $used  = [];
                            foreach ($placeholders as $ph) {
                                $key = "{$nameKey}_{$ph}";
                                if (!empty($saved[$key])) $used[] = $saved[$key];
                            }
                            $mainPool = array_values(array_diff($allAnswers, $used));
                            $pool = array_merge($mainPool, $distractors);
                            shuffle($pool);
                            $cleanContent = preg_replace('#<n>.*?</n>#is', '', $decoded);

                            $contentHtml = preg_replace_callback('#<([0-9]+)>.*?</\1>#is', function($m) use($nameKey, $saved) {
                                $ph  = $m[1];
                                $id  = "{$nameKey}_{$ph}";
                                $val = isset($saved[$id]) ? $saved[$id] : '';
                                $cls = $val ? 'drop-zone filled' : 'drop-zone';
                                return "<span class=\"$cls\" data-id=\"$id\" ondragover=\"allowDrop(event)\" ondrop=\"drop(event)\">"
                                    . htmlspecialchars($val) . "</span>";
                            }, $cleanContent);
                            ?>
                            <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h5>Câu <?= $q['newOrder'] ?></h5>
                                    <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                          $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                    <button type="button" class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?>"
                                            id="<?= htmlspecialchars($flagId) ?>"
                                            onclick="toggleFlag('<?= htmlspecialchars($code) ?>')" title="Gắn / gỡ cờ">
                                        <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                    </button>
                                </div>
                                <div class="question-content"><?= $contentHtml ?></div>

                                <div class="drag-pool" id="pool-<?= $nameKey ?>" ondragover="allowDrop(event)" ondrop="handlePoolDrop(event)">
                                    <?php foreach ($pool as $ans): ?>
                                    <div class="drag-item" draggable="true" data-value="<?= htmlspecialchars($ans) ?>" ondragstart="drag(event)">
                                        <?= htmlspecialchars($ans) ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php foreach ($placeholders as $ph):
                                    $id = "{$nameKey}_{$ph}";
                                ?>
                                <input type="hidden" name="<?= htmlspecialchars($id) ?>" id="input-<?= htmlspecialchars($id) ?>"
                                       value="<?= htmlspecialchars($saved[$id] ?? '') ?>">
                                <?php endforeach; ?>
                            </div>
                            <?php
                        };

                        // --- START: render groups with exam-section headers when group-mode ---
                        $sectionNames = [];
                        if (!empty($isExamGroup) && $isExamGroup && !empty($examGroup) && isset($examGroup['exams']) && is_array($examGroup['exams'])) {
                            foreach ($examGroup['exams'] as $i => $sub) {
                                // _section_index đã bắt đầu từ 1 khi build trước đó
                                $sectionNames[$i + 1] = isset($sub['name']) ? $sub['name'] : (isset($sub['id']) ? $sub['id'] : ('Bài thi ' . ($i + 1)));
                            }
                        }

                        // current shown section index (so we only print header once per section)
                        $currentSection = null;

                        foreach ($groups as $gk => $gItems):
                            // determine section index for this group (prefer _section_index, fallback to matching _exam_id)
                            $secIndex = 0;
                            foreach ($gItems as $it) {
                                if (!empty($it['_section_index'])) {
                                    $secIndex = intval($it['_section_index']);
                                    break;
                                }
                            }
                            if ($secIndex === 0 && !empty($gItems[0]['_exam_id']) && !empty($examGroup['exams'])) {
                                // try to find index by exam id
                                foreach ($examGroup['exams'] as $idx => $s) {
                                    if (isset($s['id']) && $s['id'] == $gItems[0]['_exam_id']) {
                                        $secIndex = $idx + 1;
                                        break;
                                    }
                                }
                            }

                            // If this group belongs to a different exam section than last printed, print the exam title
                            if ($secIndex !== $currentSection) {
                                $currentSection = $secIndex;
                                $title = '';
                                if (isset($sectionNames[$secIndex]) && trim($sectionNames[$secIndex]) !== '') {
                                    $title = $sectionNames[$secIndex];
                                } elseif (!empty($gItems[0]['_exam_name'])) {
                                    $title = $gItems[0]['_exam_name'];
                                } else {
                                    $title = '';
                                }

                                // --- TÍNH PHẠM VI CÂU (min..max) cho section này ---
                                $minOrder = PHP_INT_MAX;
                                $maxOrder = 0;
                                foreach ($groups as $gk2 => $gItems2) {
                                    foreach ($gItems2 as $it2) {
                                        $match = false;
                                        // ưu tiên kiểm tra _section_index nếu có
                                        if (!empty($it2['_section_index']) && intval($it2['_section_index']) === $secIndex) {
                                            $match = true;
                                        } elseif (empty($it2['_section_index']) && !empty($it2['_exam_id']) && !empty($examGroup['exams'])) {
                                            // fallback: so sánh _exam_id với danh sách exams để tìm index
                                            foreach ($examGroup['exams'] as $idx => $s) {
                                                if (isset($s['id']) && $s['id'] == $it2['_exam_id']) {
                                                    if (($idx + 1) === $secIndex) {
                                                        $match = true;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                        if ($match && isset($it2['newOrder'])) {
                                            $o = intval($it2['newOrder']);
                                            if ($o < $minOrder) $minOrder = $o;
                                            if ($o > $maxOrder) $maxOrder = $o;
                                        }
                                    }
                                }
                                // build range text (empty nếu không tìm được)
                                $rangeText = '';
                                if ($minOrder !== PHP_INT_MAX) {
                                    if ($minOrder === $maxOrder) {
                                        $rangeText = " <i style='font-size: 20px;'>(từ câu " . $minOrder . ")</i>";
                                    } else {
                                        $rangeText = "<i style='font-size:20px;'>(Chủ đề này có " . (($maxOrder - $minOrder) + 1) . " câu từ " . $minOrder . " đến " . $maxOrder . ")</i>";

                                    }
                                }

                                // Chỉ in tiêu đề khi thực sự có $title (theo yêu cầu trước)
                                if ($title !== '') {
                                    echo '<div class="exam-section-title my-3">';
                                    echo '<div class="instruction-card" style="background-color: #003366; padding: 5px;">';
                                    echo '<div style="text-align: center; color: #fff; font-size: 25px"><strong>' 
                                            . htmlspecialchars($title, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')
                                            . '</strong>' 
                                            . ($rangeText !== '' ? ' <span class="exam-range">' . $rangeText . '</span>' : '')
                                            . '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                            }

                            // --- existing group rendering logic (unchanged) ---
                            // gather instruction parts for group
                            $groupHasInstr = false;
                            $instrHtmlParts = [];
                            foreach ($gItems as $it) {
                                $code = $it['code'];
                                    if (isset($instrBySub[$code])) {
                                        $groupHasInstr = true;
                                        // tìm exam context cho item $it (ưu tiên _exam_id, fallback _section_index -> examGroup)
                                        $contextExamId = null;
                                        if (!empty($it['_exam_id'])) {
                                            $contextExamId = $it['_exam_id'];
                                        } elseif (!empty($it['_section_index']) && !empty($examGroup['exams'])) {
                                            $idx = intval($it['_section_index']) - 1;
                                            if (isset($examGroup['exams'][$idx]['id'])) $contextExamId = $examGroup['exams'][$idx]['id'];
                                        }
                                        $instrHtmlParts[] = $parseInstruction($instrBySub[$code], $contextExamId);
                                    }
                            }

                            if ($groupHasInstr):
                            ?>
                            <div class="group-with-instr" data-group-key="<?= htmlspecialchars($gk) ?>">
                                <div class="instr-col">
                                    <?php foreach ($instrHtmlParts as $ih) {
                                        echo '<div class="instruction-card"><div class="instruction-content">' . $ih . '</div></div>';
                                    } ?>
                                </div>

                                <div class="splitter" role="separator" aria-orientation="vertical" tabindex="0"></div>

                                <div class="q-col">
                                    <?php
                                    foreach ($gItems as $it) {
                                        // Robust part extraction: lấy số phần
                                        $part = 0;
                                        if (preg_match('/(\d+)(?:\.\d+)?$/', $it['code'], $m)) {
                                            $part = intval($m[1]);
                                        } elseif (!empty($it['part'])) {
                                            $part = intval($it['part']);
                                        } else {
                                            $part = intval(substr($it['code'], 0, 1));
                                        }
                                        switch ($part) {
                                            case 1: $renderPart1Item($it, $parseInstruction, $resumeData, $pairs, $fixed); break;
                                            case 2: $renderPart2Item($it, $parseInstruction, $resumeData); break;
                                            case 3: $renderPart3Item($it, $parseInstruction, $resumeData); break;
                                            case 4: $renderPart4Item($it, $parseInstruction, $resumeData); break;
                                            case 5: $renderPart5Item($it, $resumeData); break;
                                            case 6: $renderPart6Item($it, $resumeData); break;
                                            default: echo '<div>Unknown part: '.htmlspecialchars($it['code']).'</div>';
                                        }
                                    }
                                    ?>
                                </div>
                            </div>
                            <?php
                            else:
                                // no instruction: render items sequentially (grouped visually)
                                echo '<div class="group-no-instr" data-group-key="'.htmlspecialchars($gk).'">';
                                foreach ($gItems as $it) {
                                    $part = 0;
                                    if (preg_match('/(\d+)(?:\.\d+)?$/', $it['code'], $m)) {
                                        $part = intval($m[1]);
                                    } elseif (!empty($it['part'])) {
                                        $part = intval($it['part']);
                                    } else {
                                        $part = intval(substr($it['code'], 0, 1));
                                    }
                                    switch ($part) {
                                        case 1: $renderPart1Item($it, $parseInstruction, $resumeData, $pairs, $fixed); break;
                                        case 2: $renderPart2Item($it, $parseInstruction, $resumeData); break;
                                        case 3: $renderPart3Item($it, $parseInstruction, $resumeData); break;
                                        case 4: $renderPart4Item($it, $parseInstruction, $resumeData); break;
                                        case 5: $renderPart5Item($it, $resumeData); break;
                                        case 6: $renderPart6Item($it, $resumeData); break;
                                        default: echo '<div>Unknown part: '.htmlspecialchars($it['code']).'</div>';
                                    }
                                }
                                echo '</div>';
                            endif;

                        endforeach;
                    } else {
                        // Khi không phải DGNL, giữ hành vi cũ: chia $questions thành $parts như trước
                        $parts = [1=>[], 2=>[], 3=>[], 4=>[], 5=>[], 6=>[]];
                        foreach ($questions as $q) {
                            $code = $q['code'];
                            $partNum = intval(substr($code,0,1));
                            $parts[$partNum][] = $q;
                        }
                    }
                    ?>

                    <!-- PHẦN 1--->
                    <?php if (!empty($parts[1])): ?>

                    <div class="mb-4">
                      <?php if (!$isDGNL): ?>  
                      	<h4 style="color: #003366; margin-bottom: 12px;"><b>Phần trắc nghiệm nhiều phương án lựa chọn</b></h4>
                      <?php endif; ?>

                      <?php
                      // --- CHUẨN BỊ NHÓM DỰA TRÊN permutation (pairs / fixed) ---
                      $codeToGroup = [];
                      // các pair: key là 'pair:codeA&codeB'
                      foreach ($pairs as $pairStr) {
                          $codes = explode('&', $pairStr);
                          sort($codes, SORT_STRING);
                          $key = 'pair:' . implode('&', $codes);
                          foreach ($codes as $c) $codeToGroup[trim($c)] = $key;
                      }
                      // các fixed (block) đơn lẻ
                      foreach ($fixed as $b) {
                          $codeToGroup[trim($b)] = 'block:' . $b;
                      }
                      // map các câu không thuộc pair/fixed thành single
                      foreach ($parts[1] as $q) {
                          $c = $q['code'];
                          if (!isset($codeToGroup[$c])) {
                              $codeToGroup[$c] = 'single:' . $c;
                          }
                      }

                      // xây danh sách group theo thứ tự xuất hiện hiện tại
                      $orderedGroupKeys = [];
                      foreach ($parts[1] as $q) {
                          $gk = $codeToGroup[$q['code']];
                          if (!in_array($gk, $orderedGroupKeys, true)) $orderedGroupKeys[] = $gk;
                      }

                      // tạo mảng groups => mỗi group là mảng question items (theo thứ tự hiện tại)
                      $groups = [];
                      foreach ($orderedGroupKeys as $gk) $groups[$gk] = [];
                      foreach ($parts[1] as $q) {
                          $groups[$codeToGroup[$q['code']]][] = $q;
                      }

                      // build map subcode -> instruction content để dễ lookup
                      $instrBySub = [];
                      foreach ($instructionBlocks as $ins) {
                          foreach (explode('-', $ins['code']) as $sub) {
                              $sub = trim($sub);
                              if ($sub !== '') $instrBySub[$sub] = $ins['content'];
                          }
                      }

                      // Hàm phụ để parse placeholder ?X.Y? => newOrder
                      $parseInstruction = function($raw) use ($orderMap) {
                          return preg_replace_callback(
                              '/\?([0-9]+\.[0-9]+)\?/',
                              function($m) use ($orderMap) {
                                  return isset($orderMap[$m[1]]) ? $orderMap[$m[1]] : $m[0];
                              },
                              $raw
                          );
                      };

                      // Render từng group
                      foreach ($groups as $gk => $gItems):
                          // có instruction nếu bất kỳ mã nào của group có trong instrBySub
                          $groupHasInstr = false;
                          $instrHtmlParts = [];
                          foreach ($gItems as $it) {
                              $code = $it['code'];
                                if (isset($instrBySub[$code])) {
                                    $groupHasInstr = true;
                                    // tìm exam context cho item $it (ưu tiên _exam_id, fallback _section_index -> examGroup)
                                    $contextExamId = null;
                                    if (!empty($it['_exam_id'])) {
                                        $contextExamId = $it['_exam_id'];
                                    } elseif (!empty($it['_section_index']) && !empty($examGroup['exams'])) {
                                        $idx = intval($it['_section_index']) - 1;
                                        if (isset($examGroup['exams'][$idx]['id'])) $contextExamId = $examGroup['exams'][$idx]['id'];
                                    }
                                    $instrHtmlParts[] = $parseInstruction($instrBySub[$code], $contextExamId);
                                }
                          }

                          // nếu group có instruction => layout two-column (desktop), stacked on mobile
                          if ($groupHasInstr):
                      ?>
                          <div class="group-with-instr" data-group-key="<?= htmlspecialchars($gk) ?>">
                              <div class="instr-col">
                                  <?php
                                  // nếu có nhiều instruction pieces, hiển thị từng cái (giữ nguyên nội dung HTML)
                                  foreach ($instrHtmlParts as $ih) {
                                      echo '<div class="instruction-card"><div class="instruction-content">' . $ih . '</div></div>';
                                  }
                                  ?>
                              </div>
                              
                              <div class="splitter" role="separator" aria-orientation="vertical" tabindex="0"></div>

                              <div class="q-col">
                                  <?php
                                  // render từng question trong nhóm (giữ nguyên cấu trúc question-card cũ)
                                  foreach ($gItems as $q):
                                      $code    = $q['code'];
                                      $decoded = html_entity_decode($q['content']);
                                      $content = $decoded;

                                      // Thay placeholder ?X.Y? trong phần nội dung câu hỏi (nếu có)
                                      if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                          $content = $parseInstruction($content);
                                      }

                                      $hasParsedOptions = false;
                                      $options = ['A'=>'','B'=>'','C'=>'','D'=>''];
                                      if (preg_match_all('#<([abcd])\.\s*>(.*?)</\1\.\s*>#isU', $decoded, $m)) {
                                        $hasParsedOptions = true;
                                        foreach ($m[1] as $i => $letter) {
                                            $optText = trim($m[2][$i]);
                                            // Thay placeholder ?X.Y? trong text đáp án (nếu có)
                                            if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                                $optText = $parseInstruction($optText);
                                            }
                                            $options[strtoupper($letter)] = $optText;
                                        }
                                        // Loại bỏ phần option markup khỏi content gốc rồi cũng parse lại content (phòng có placeholder còn sót)
                                        $content = preg_replace('#<([abcd])\.\s*>.*?</\1\.\s*>#isU', '', $decoded);
                                        if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                            $content = $parseInstruction($content);
                                        }
                                      }

                                      $lengths = array_filter(array_map('strlen', $options));
                                      $maxLen = !empty($lengths) ? max($lengths) : 0;
                                      if ($hasParsedOptions) {
                                          if ($maxLen <= 25) {
                                              $wrapperClass    = 'd-flex flex-row flex-wrap align-items-start';
                                              $btnWrapperStyle = 'width:25%;';
                                          } elseif ($maxLen <= 50) {
                                              $wrapperClass    = 'row row-cols-2 g-2';
                                              $btnWrapperStyle = 'width:50%;';
                                          } else {
                                              $wrapperClass    = 'd-flex flex-column';
                                              $btnWrapperStyle = 'width:100%;';
                                          }
                                      } else {
                                          $wrapperClass    = 'd-flex flex-wrap';
                                          $btnWrapperStyle = '';
                                      }

                                      $btnClass = $hasParsedOptions ? 'btn-option' : 'btn-option-old';
                                      $nameKey = str_replace('.', '_', $code);
                                      $seq     = intval(substr($code, strpos($code, '.')+1));
                                  ?>
                                      <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                          <div class="d-flex align-items-center justify-content-between">
                                              <h5>
                                                  Câu <?= $q['newOrder'] ?>
                                                  <small style="font-weight: normal; color: transparent; display: none;">
                                                      (Mã: <?= htmlspecialchars($q['code']) ?>)
                                                  </small>
                                              </h5>
                                              <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                                    $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                              <button type="button"
                                                    class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?>"
                                                    id="<?= htmlspecialchars($flagId) ?>"
                                                    onclick="toggleFlag('<?= htmlspecialchars($code) ?>')"
                                                    title="Gắn / gỡ cờ">
                                                <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                              </button>
                                          </div>
                                          <div class="question-content"><?= $content ?></div>

                                          <div class="<?= $wrapperClass ?>" data-maxlen="<?= $maxLen ?>">
                                              <?php
                                              $maxLen = $maxLen ?? 0;
                                              if ($hasParsedOptions) {
                                                  $displayKeys = array_keys($options);
                                                  shuffle($displayKeys);
                                                  $displayLabels = ['A','B','C','D'];
                                              } else {
                                                  $displayKeys = ['A','B','C','D'];
                                                  $displayLabels = ['A','B','C','D'];
                                              }
                                              for ($i = 0; $i < 4; $i++):
                                                  $origKey = isset($displayKeys[$i]) ? $displayKeys[$i] : '';
                                                  $dispLabel = $displayLabels[$i];
                                              ?>
                                                  <div class="p-1"<?php if (!empty($btnWrapperStyle)) echo " style=\"$btnWrapperStyle\""; ?>>
                                                      <div style="display: inline-flex;" class="mb-2 d-flex align-items-center">
                                                          <button
                                                              type="button"
                                                              class="btn <?= $btnClass ?>"
                                                              id="<?= $nameKey . '_' . $origKey ?>"
                                                              onclick="selectPart1('<?= $code ?>','<?= $origKey ?>')">
                                                              <b><?= $dispLabel ?></b>
                                                          </button>
                                                          <span style="margin-top: 0px; margin-left: 0px;">
                                                              <?= $hasParsedOptions && $origKey !== '' 
                                                                  ? preg_replace('#</?p\b[^>]*>#i', '', $options[$origKey]) 
                                                                  : '' ?>
                                                          </span>
                                                      </div>
                                                  </div>
                                              <?php endfor; ?>
                                          </div>

                                          <input type="hidden" name="<?= $nameKey ?>" id="input-<?= $nameKey ?>">
                                      </div>
                                  <?php endforeach; ?>
                              </div>
                          </div>

                      <?php
                          else:
                              // nếu group không có instruction => render như bình thường (mỗi question một card)
                              foreach ($gItems as $q):
                                  $code    = $q['code'];
                                  $decoded = html_entity_decode($q['content']);
                                  $content = $decoded;

                                  // Thay placeholder ?X.Y? trong phần nội dung câu hỏi (nếu có)
                                  if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                      $content = $parseInstruction($content);
                                  }

                                  $hasParsedOptions = false;
                                  $options = ['A'=>'','B'=>'','C'=>'','D'=>''];
                                  if (preg_match_all('#<([abcd])\.\s*>(.*?)</\1\.\s*>#isU', $decoded, $m)) {
                                        $hasParsedOptions = true;
                                        foreach ($m[1] as $i => $letter) {
                                            $optText = trim($m[2][$i]);
                                            // Thay placeholder ?X.Y? trong text đáp án (nếu có)
                                            if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                                $optText = $parseInstruction($optText);
                                            }
                                            $options[strtoupper($letter)] = $optText;
                                        }
                                        // Loại bỏ phần option markup khỏi content gốc rồi cũng parse lại content (phòng có placeholder còn sót)
                                        $content = preg_replace('#<([abcd])\.\s*>.*?</\1\.\s*>#isU', '', $decoded);
                                        if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                            $content = $parseInstruction($content);
                                        }
                                  }

                                  $lengths = array_filter(array_map('strlen', $options));
                                  $maxLen = !empty($lengths) ? max($lengths) : 0;
                                  if ($hasParsedOptions) {
                                      if ($maxLen <= 40) {
                                          $wrapperClass    = 'd-flex flex-row flex-wrap align-items-start';
                                          $btnWrapperStyle = 'width:25%;';
                                      } elseif ($maxLen <= 90) {
                                          $wrapperClass    = 'row row-cols-2 g-2';
                                          $btnWrapperStyle = 'width:50%;';
                                      } else {
                                          $wrapperClass    = 'd-flex flex-column';
                                          $btnWrapperStyle = 'width:100%;';
                                      }
                                  } else {
                                      $wrapperClass    = 'd-flex flex-wrap';
                                      $btnWrapperStyle = '';
                                  }

                                  $btnClass = $hasParsedOptions ? 'btn-option' : 'btn-option-old';
                                  $nameKey = str_replace('.', '_', $code);
                                  $seq     = intval(substr($code, strpos($code, '.')+1));
                      ?>
                                  <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                      <div class="d-flex align-items-center justify-content-between">
                                          <h5>
                                              Câu <?= $q['newOrder'] ?>
                                              <small style="font-weight: normal; color: transparent; display: none;">
                                                  (Mã: <?= htmlspecialchars($q['code']) ?>)
                                              </small>
                                          </h5>
                                          <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                                $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                          <button type="button"
                                                class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?>"
                                                id="<?= htmlspecialchars($flagId) ?>"
                                                onclick="toggleFlag('<?= htmlspecialchars($code) ?>')"
                                                title="Gắn / gỡ cờ">
                                            <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                          </button>
                                      </div>
                                      <div class="question-content"><?= $content ?></div>

                                      <div class=" <?= $wrapperClass ?>" data-maxlen="<?= $maxLen ?>">
                                          <?php
                                          $maxLen = $maxLen ?? 0;
                                          if ($hasParsedOptions) {
                                              $displayKeys = array_keys($options);
                                              shuffle($displayKeys);
                                              $displayLabels = ['A','B','C','D'];
                                          } else {
                                              $displayKeys = ['A','B','C','D'];
                                              $displayLabels = ['A','B','C','D'];
                                          }
                                          for ($i = 0; $i < 4; $i++):
                                              $origKey = isset($displayKeys[$i]) ? $displayKeys[$i] : '';
                                              $dispLabel = $displayLabels[$i];
                                          ?>
                                              <div class="p-1"<?php if (!empty($btnWrapperStyle)) echo " style=\"$btnWrapperStyle\""; ?>>
                                                  <div style="display: inline-flex;" class="mb-2 d-flex align-items-center">
                                                      <button
                                                          type="button"
                                                          class="btn <?= $btnClass ?>"
                                                          id="<?= $nameKey . '_' . $origKey ?>"
                                                          onclick="selectPart1('<?= $code ?>','<?= $origKey ?>')">
                                                          <b><?= $dispLabel ?></b>
                                                      </button>
                                                      <span style="margin-top: 0px; margin-left: 0px;">
                                                          <?= $hasParsedOptions && $origKey !== '' 
                                                              ? preg_replace('#</?p\b[^>]*>#i', '', $options[$origKey]) 
                                                              : '' ?>
                                                      </span>
                                                  </div>
                                              </div>
                                          <?php endfor; ?>
                                      </div>

                                      <input type="hidden" name="<?= $nameKey ?>" id="input-<?= $nameKey ?>">
                                  </div>

                      <?php
                              endforeach;
                          endif;
                      endforeach; // end groups
                      ?>

                    </div>
                    <?php endif; ?>


                    <!-- PHẦN 2 -->
                    <?php if (!empty($parts[2])): ?>
                    <div class="mb-4">
                        <?php if (!$isDGNL): ?>
                        	<h4 style="color: #003366; margin-bottom: 12px;"><b>Phần trắc nghiệm đúng sai</b></h4>
                        <?php endif; ?>
                        <?php foreach ($parts[2] as $q):
                            $code    = $q['code'];
                            // giải mã trước
                            $decoded = html_entity_decode($q['content']);
                            $content = $decoded;
                            $hasSubParsed = false;
                            $subContents  = ['a'=>'','b'=>'','c'=>'','d'=>''];

                            if (preg_match_all(
                                    '#<([abcd])\)\s*>(.*?)</\1\)\>#isU',
                                    $decoded, $m
                                )) {
                                $hasSubParsed = true;
                                foreach ($m[1] as $i => $letter) {
                                    $subContents[strtolower($letter)] = trim($m[2][$i]);
                                }
                                // bỏ luôn các tag đã bóc
                                $content = preg_replace(
                                    '#<([abcd])\)\s*>.*?</\1\)\>#isU',
                                    '',
                                    $decoded
                                );
                            }
                            $baseKey = str_replace('.', '_', $code);
                        ?>
                        <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                           	<div class="d-flex align-items-center justify-content-between">
                                <h5>
                                    Câu <?= $q['newOrder'] ?>
                                    <small style="font-weight: normal; color: transparent;display: none;">
                                        (Mã: <?= htmlspecialchars($q['code']) ?>)
                                    </small>
                                </h5>
                                <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                      $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                <button type="button"
                                          class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?> ms-3"
                                          id="<?= htmlspecialchars($flagId) ?>"
                                          onclick="toggleFlag('<?= htmlspecialchars($code) ?>')"
                                          title="Gắn / gỡ cờ">
                                    <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                </button>
                            </div>
                            
                            <div class="question-content"><?= $content ?></div>
                            <div class="mt-2 ps-2">
                            <?php foreach (['a','b','c','d'] as $sub):
                                $subKey = $baseKey . '_'. $sub;
                            ?>
                            <div class="text-tf mb-2  d-flex align-items-center">
                                <span class="me-2"><b><?= $sub ?>)</b></span>
                                <button type="button" class="btn tsa-btn"
                                        id="<?= $subKey.'_dung' ?>"
                                        onclick="selectPart2('<?= $code ?>','<?= $sub ?>','Đúng')">
                                Đúng
                                </button>
                                <button type="button" class="btn tsa-btn"
                                        id="<?= $subKey.'_sai' ?>"
                                        onclick="selectPart2('<?= $code ?>','<?= $sub ?>','Sai')">
                                Sai
                                </button>
                                <span class="me-2" style="margin-left: 0px; text-align: justify;">
                                    <?= $hasSubParsed 
                                        ? preg_replace('#</?p\b[^>]*>#i', '', $subContents[$sub]) 
                                        : '' ?>
                                </span>
                                <input type="hidden" name="<?= $subKey ?>" id="input-<?= $subKey ?>">
                            </div>
                            <?php endforeach ?>
                            </div>

                        </div>
                        <?php endforeach ?>
                    </div>
                    <?php endif; ?>

                    <!-- PHẦN 3 -->
                    <?php if (!empty($parts[3])): ?>
                    <div class="mb-4">
                        <?php if (!$isDGNL): ?>
                        	<h4 style="color: #003366; margin-bottom: 12px;"><b>Phần trắc nghiệm ghép cặp</b></h4>
                        <?php endif; ?>
                        <?php foreach ($parts[3] as $q):
                            $code = $q['code'];
                            $content = $q['content'];
                            $baseName = str_replace('.', '_', $code);
                        ?>
                        <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                           	<div class="d-flex align-items-center justify-content-between">
                                <h5>
                                    Câu <?= $q['newOrder'] ?>
                                    <small style="font-weight: normal; color: transparent; display: none;">
                                        (Mã: <?= htmlspecialchars($q['code']) ?>)
                                    </small>
                                </h5>
                            

                                <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                      $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                <button type="button"
                                          class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?> ms-3"
                                          id="<?= htmlspecialchars($flagId) ?>"
                                          onclick="toggleFlag('<?= htmlspecialchars($code) ?>')"
                                          title="Gắn / gỡ cờ">
                                    <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                </button>
                            </div>
                            <div class="question-content"><?= $content ?></div>
                            <div class="mt-2">
                                <?php for ($i = 1; $i <= 4; $i++):
                                    $subName = $baseName . '_' . $i;
                                ?>
                                <div class="mb-2 d-flex align-items-center">
                                    <label class="form-label me-2">Ý <?= $i ?> nối với:</label>
                                    <select class="form-select" name="<?= htmlspecialchars($subName) ?>"
                                            onchange="selectPart3or4('<?= $code ?>','<?= $subName ?>', this.value)">
                                        <option value="">-- Chọn --</option>
                                        <?php foreach (['a','b','c','d','e'] as $opt): ?>
                                        <option value="<?= $opt ?>"><?= $opt ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </div>
                                <?php endfor ?>
                            </div>
                        </div>
                        <?php endforeach ?>
                    </div>
                    <?php endif; ?>

                    <!-- PHẦN 4 -->
                    <?php if (!empty($parts[4])): ?>
                    <div class="mb-4">
                        <?php if (!$isDGNL): ?>
                        	<h4 style="color: #003366; margin-bottom: 12px;"><b>Phần trắc nghiệm yêu cầu trả lời ngắn</b></h4>
                        <?php endif; ?>

                        <?php
                        // --- TẠO NHÓM DỰA TRÊN permutation (pairs / fixed) giống Part 1 ---
                        $codeToGroup = [];
                        foreach ($pairs as $pairStr) {
                            $codes = explode('&', $pairStr);
                            sort($codes, SORT_STRING);
                            $key = 'pair:' . implode('&', $codes);
                            foreach ($codes as $c) $codeToGroup[trim($c)] = $key;
                        }
                        foreach ($fixed as $b) {
                            $codeToGroup[trim($b)] = 'block:' . $b;
                        }
                        foreach ($parts[4] as $q) {
                            $c = $q['code'];
                            if (!isset($codeToGroup[$c])) {
                                $codeToGroup[$c] = 'single:' . $c;
                            }
                        }

                        $orderedGroupKeys = [];
                        foreach ($parts[4] as $q) {
                            $gk = $codeToGroup[$q['code']];
                            if (!in_array($gk, $orderedGroupKeys, true)) $orderedGroupKeys[] = $gk;
                        }

                        $groups = [];
                        foreach ($orderedGroupKeys as $gk) $groups[$gk] = [];
                        foreach ($parts[4] as $q) {
                            $groups[$codeToGroup[$q['code']]][] = $q;
                        }

                        // build instruction map
                        $instrBySub = [];
                        foreach ($instructionBlocks as $ins) {
                            foreach (explode('-', $ins['code']) as $sub) {
                                $sub = trim($sub);
                                if ($sub !== '') $instrBySub[$sub] = $ins['content'];
                            }
                        }

                        $parseInstruction = function($raw) use ($orderMap) {
                            return preg_replace_callback(
                                '/\?([0-9]+\.[0-9]+)\?/',
                                function($m) use ($orderMap) {
                                    return isset($orderMap[$m[1]]) ? $orderMap[$m[1]] : $m[0];
                                },
                                $raw
                            );
                        };

                        // Render groups
                        foreach ($groups as $gk => $gItems):
                            // check if any item in group has instruction
                            $groupHasInstr = false;
                            $instrHtmlParts = [];
                            foreach ($gItems as $it) {
                                $code = $it['code'];
                                if (isset($instrBySub[$code])) {
                                    $groupHasInstr = true;
                                    $instrHtmlParts[] = $parseInstruction($instrBySub[$code]);
                                }
                            }

                            if ($groupHasInstr):
                        ?>
                        <div class="group-with-instr" data-group-key="<?= htmlspecialchars($gk) ?>">
                            <div class="instr-col">
                                <?php
                                foreach ($instrHtmlParts as $ih) {
                                    echo '<div class="instruction-card"><div class="instruction-content">' . $ih . '</div></div>';
                                }
                                ?>
                            </div>
                            
                            <div class="splitter" role="separator" aria-orientation="vertical" tabindex="0"></div>

                            <div class="q-col">
                                <?php
                                foreach ($gItems as $q):
                                    $code = $q['code'];
                                    // decode content để hiển thị HTML / img / KaTeX
                                    $decoded = html_entity_decode($q['content']);
                                    $content = $decoded;
                                    // Thay placeholder ?X.Y? trong nội dung câu hỏi (nếu có)
                                    if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                        $content = $parseInstruction($content);
                                    }
                                    $seq = intval(substr($code, strpos($code, '.') + 1));
                                    $name4 = str_replace('.', '_', $code);
                                ?>
                                <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <h5>
                                            Câu <?= $q['newOrder'] ?>
                                            <small style="font-weight: normal; color: transparent; display: none;">
                                                (Mã: <?= htmlspecialchars($q['code']) ?>)
                                            </small>
                                        </h5>
                                        <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                              $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                        <button type="button"
                                                  class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?> ms-3"
                                                  id="<?= htmlspecialchars($flagId) ?>"
                                                  onclick="toggleFlag('<?= htmlspecialchars($code) ?>')"
                                                  title="Gắn / gỡ cờ">
                                            <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                        </button>
                                    </div>
                                    <div class="question-content"><?= $content ?></div>
                                    <input class="form-control short-answer mt-2"
                                        type="text"
                                        name="<?= htmlspecialchars($name4) ?>"
                                        id="input-<?= htmlspecialchars($name4) ?>"
                                        placeholder="Nhập câu trả lời dạng số “1”,“1,5”,“-0,3”,…"
                                        oninput="selectPart3or4('<?= $code ?>','<?= $name4 ?>', this.value)">
                                </div>
                                <?php
                                endforeach;
                                ?>
                            </div>
                        </div>

                        <?php else: // nhóm không có instruction => render từng câu bình thường ?>

                            <?php foreach ($gItems as $q):
                                $code = $q['code'];
                                $decoded = html_entity_decode($q['content']);
                                $content = $decoded;
                                // Thay placeholder ?X.Y? trong nội dung câu hỏi (nếu có)
                                if (isset($parseInstruction) && is_callable($parseInstruction)) {
                                    $content = $parseInstruction($content);
                                }
                                $seq = intval(substr($code, strpos($code, '.') + 1));
                                $name4 = str_replace('.', '_', $code);
                            ?>
                            <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h5>
                                        Câu <?= $q['newOrder'] ?>
                                        <small style="font-weight: normal; color: transparent; display: none;">
                                            (Mã: <?= htmlspecialchars($q['code']) ?>)
                                        </small>
                                    </h5>                        	
                                    <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                          $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                    <button type="button"
                                              class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?> ms-3"
                                              id="<?= htmlspecialchars($flagId) ?>"
                                              onclick="toggleFlag('<?= htmlspecialchars($code) ?>')"
                                              title="Gắn / gỡ cờ">
                                        <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                    </button>
                                </div>
                                <div class="question-content"><?= $content ?></div>
                                <input class="form-control short-answer mt-2"
                                    type="text"
                                    name="<?= htmlspecialchars($name4) ?>"
                                    id="input-<?= htmlspecialchars($name4) ?>"
                                    placeholder="Nhập câu trả lời dạng số “1”,“1,5”,“-0,3”,…"
                                    oninput="selectPart3or4('<?= $code ?>','<?= $name4 ?>', this.value)">
                            </div>
                            <?php endforeach; ?>

                        <?php endif; endforeach; // end groups ?>

                    </div>
                    <?php endif; ?>

                    <!-- PHẦN V: TỰ LUẬN -->
                    <?php if (!empty($parts[5])): ?>
                    <div class="mb-4">
                        <h4 style="color: #003366; margin-bottom:12px;"><b>Phần tự luận</b></h4>
                        <?php foreach ($parts[5] as $q):
                        $code     = $q['code'];                   // ví dụ "5.1"
                        $nameBase = isset($item['__nameKey']) ? $item['__nameKey'] : str_replace('.', '_', $code); // "5_1"

                        // --- Bước 1: Chuẩn hóa & decode HTML một lần ---
                        $html = html_entity_decode(
                            str_replace('\/', '/', $q['content'])
                        );

                        // --- Bước 2: Bóc sub-item (an toàn, ungreedy) ---
                        $subItems = [];
                        if (preg_match_all(
                            '#<([a-z0-9]+)\)\s*>(.*?)</\1\)>#isU',
                            $html,
                            $matches,
                            PREG_SET_ORDER
                        )) {
                            // Loại bỏ chính xác từng đoạn đã match khỏi $html để tạo mainText,
                            // tránh việc preg_replace chung ăn sang các câu khác.
                            foreach ($matches as $m) {
                                $subItems[] = [
                                    'key'     => $m[1],
                                    'content' => trim($m[2]),
                                ];
                                // xóa đúng chuỗi vừa match (an toàn): $m[0] là toàn bộ đoạn <k)>...<\/k)>
                                $html = str_replace($m[0], '', $html);
                            }
                        }

                        // --- Bước 3: Phần chính sau khi đã loại bỏ sub-items ---
                        $mainText = trim($html);
                        ?>
                        <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
                            <div class="d-flex align-items-center justify-content-between">
                                <h5>Câu <?= $q['newOrder'] ?>
                                    <small style="font-weight: normal; color: transparent; display: none;">
                                        (Mã: <?= htmlspecialchars($q['code']) ?>)
                                    </small>
                                </h5>

                                <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                      $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                <button type="button"
                                          class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?> ms-3"
                                          id="<?= htmlspecialchars($flagId) ?>"
                                          onclick="toggleFlag('<?= htmlspecialchars($code) ?>')"
                                          title="Gắn / gỡ cờ">
                                    <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                </button>
                            </div>

                            <?php if ($mainText !== ''): ?>
                            <div class="question-content"><?= $mainText ?></div>
                            <?php endif; ?>

                            <?php if (!empty($subItems)): ?>
                            <?php foreach ($subItems as $sub): 
                                $dotKey  = $code . '.' . $sub['key'];         // "5.1.a" hoặc "5.1.1"
                                $fieldId = str_replace('.', '_', $dotKey);    // "5_1_a"
                            ?>
                                <div class="mb-3">
                                <div class="mb-1">
                                    <strong><?= $sub['key'] ?>)</strong> <?= $sub['content'] ?>
                                </div>
                                <div class="form-control mt-2 summernote"
                                     contenteditable="true"
                                     id="input-<?= $fieldId ?>"
                                     data-code="<?= $dotKey ?>"
                                     style="min-height:120px; overflow:auto;"
                                ><?= $resumeData['answers'][$dotKey] ?? '' ?></div>
                                </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <div class="form-control mt-2 summernote"
                                 contenteditable="true"
                                 id="input-<?= $nameBase ?>"
                                 data-code="<?= $code ?>"
                                 style="min-height:150px; overflow:auto;"
                            ><?= $resumeData['answers'][$code] ?? '' ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($parts[6])): ?>
                    <div class="mb-4">
                        <h4 ><b style="color: #003366; margin-bottom: 12px;">Phần trắc nghiệm kéo thả</b></h4>

                        <?php
                        // Lấy mảng answers đã lưu (nếu resume)
                        $saved = isset($resumeData['answers']) ? (array)$resumeData['answers'] : [];
                        ?>

                        <?php foreach ($parts[6] as $q): 
                            $code    = $q['code'];                  // ví dụ "6.1"
                            $nameKey = str_replace('.', '_', $code);
                            $decoded = html_entity_decode($q['content']);

                            // 1) Tách tất cả answers gốc (<1>…</1>, <2>…</2>, …)
                            preg_match_all('#<([0-9]+)>(.*?)</\1>#is', $decoded, $m);
                            $placeholders   = $m[1];                 // ['1','2',…]
                            $allAnswers     = $m[2];                 // ['123','552',…]

                            // 2) Tách đáp án nhiễu (<n>…</n>)
                            preg_match_all('#<n>(.*?)</n>#is', $decoded, $dn);
                            $distractors    = $dn[1];                // ['áp án nhiễu 1', 'áp án nhiễu 2', …]

                            // 3) Xác định answers đã dùng và lọc pool chính
                            $saved = isset($resumeData['answers']) ? (array)$resumeData['answers'] : [];
                            $used  = [];
                            foreach ($placeholders as $ph) {
                                $key = "{$nameKey}_{$ph}";
                                if (!empty($saved[$key])) {
                                    $used[] = $saved[$key];
                                }
                            }
                            $mainPool = array_values(array_diff($allAnswers, $used));

                            // 4) Gộp đáp án chính chưa dùng với đáp án nhiễu
                            $pool = array_merge($mainPool, $distractors);
                            shuffle($pool);

                            // 5) Loại bỏ thẻ <n>…</n> khỏi nội dung hiển thị,
                            //    chỉ để lại drop‑zone cho các <1>…</1>, <2>…</2>, …
                            $cleanContent = preg_replace('#<n>.*?</n>#is', '', $decoded);

                            // 6) Tạo nội dung với drop‑zones, prefill giá trị cũ
                            $contentHtml = preg_replace_callback(
                                '#<([0-9]+)>.*?</\1>#is',
                                function($m) use($nameKey, $saved) {
                                    $ph  = $m[1];
                                    $id  = "{$nameKey}_{$ph}";
                                    $val = isset($saved[$id]) ? $saved[$id] : '';
                                    $cls = $val ? 'drop-zone filled' : 'drop-zone';
                                    return "<span class=\"$cls\" "
                                        . "data-id=\"$id\" ondragover=\"allowDrop(event)\" ondrop=\"drop(event)\">"
                                        . htmlspecialchars($val)
                                        . "</span>";
                                },
                                $cleanContent
                            );
                        ?>

                        <div class="question-card" id="q-<?= htmlspecialchars($code) ?>">
							<div class="d-flex align-items-center justify-content-between">
                                <h5>
                                    Câu <?= $q['newOrder'] ?>
                                    <small style="font-weight: normal; color: transparent; display: none;">
                                        (Mã: <?= htmlspecialchars($q['code']) ?>)
                                    </small>
                                </h5>

                                <?php $flagId = 'flag-' . str_replace('.', '_', $code);
                                      $isFlagged = !empty($resumeData['flag'][$code]); ?>
                                <button type="button"
                                          class="btn btn-flag <?= $isFlagged ? 'flagged' : '' ?> ms-3"
                                          id="<?= htmlspecialchars($flagId) ?>"
                                          onclick="toggleFlag('<?= htmlspecialchars($code) ?>')"
                                          title="Gắn / gỡ cờ">
                                    <i class="bi bi-flag-fill" aria-hidden="true" style="<?= $isFlagged ? 'color:#ffc107;' : '' ?>"></i>
                                </button>
                            </div>
                            <div class="question-content"><?= $contentHtml ?></div>

                            <!-- Pool chứa cả đáp án chính và nhiễu -->
                            <div class="drag-pool"
                                id="pool-<?= $nameKey ?>"
                                ondragover="allowDrop(event)"
                                ondrop="handlePoolDrop(event)">
                                <?php foreach ($pool as $ans): ?>
                                <div class="drag-item"
                                    draggable="true"
                                    data-value="<?= htmlspecialchars($ans) ?>"
                                    ondragstart="drag(event)">
                                    <?= htmlspecialchars($ans) ?>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Hidden inputs để submit -->
                            <?php foreach ($placeholders as $ph): 
                                $id = "{$nameKey}_{$ph}";
                            ?>
                            <input type="hidden" 
                                name="<?= htmlspecialchars($id) ?>"
                                id="input-<?= htmlspecialchars($id) ?>"
                                value="<?= htmlspecialchars($saved[$id] ?? '') ?>">
                            <?php endforeach; ?>
                        </div>

                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php
                    // Hiển thị note2 (nếu có), rồi note3 (nếu có) ở cuối đề
                    if (!empty($notes['note2']) || !empty($notes['note3'])): ?>
                      <div class="mb-4">
                        <?php if (!empty($notes['note2'])): ?>
                          <?php foreach ($notes['note2'] as $n):
                              $noteHtml = html_entity_decode($n['content']);
                          ?>
                            <div style="width: 100%; color:#003366;">
                              <div class="note" style="width: 100%; text-align: center;"><?= $noteHtml ?></div>
                            </div>
                          <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($notes['note3'])): ?>
                          <?php foreach ($notes['note3'] as $n):
                              $noteHtml = html_entity_decode($n['content']);
                          ?>
                            <div>
                              <div class="note" style="color:#003366; margin-bottom: 0px;"><?= $noteHtml ?></div>
                            </div>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>

                </form>
                <div class="footer" > &copy; <?= date('Y') ?> – Thi thử Biology's Life <?= date('Y') ?></div>
            </div>
            <button id="mobile-toggle"
                    class="d-lg-none btn btn-sm"
                    style="background-color: #003366; color: #fff;">
                <i class="bi bi-list" style="font-size: 1.5rem;"></i>
            </button>
            
            
            <?php
            // --- Chuẩn bị dữ liệu cho sidebar progress (tương thích cả DGNL & non-DGNL)
            // Khởi tạo
            $sidebarParts = [1=>[],2=>[],3=>[],4=>[],5=>[],6=>[]];

            // Nếu isDGNL thì dùng $questions (đã permutation & có newOrder),
            // ngược lại dùng $parts (nhưng vẫn filter để chắc chắn).
            if (!empty($isDGNL)) {
                // $questions có thể là mixedQuestions hoặc $questions tuỳ chỗ bạn đặt biến.
                $src = isset($questions) ? $questions : (isset($mixedQuestions) ? $mixedQuestions : []);
                foreach ($src as $q) {
                    if (!isset($q['code']) || trim((string)$q['code']) === '') continue; // skip items without code
                    $partNum = intval(substr($q['code'], 0, 1));
                    if ($partNum < 1 || $partNum > 6) $partNum = 1; // fallback nếu code lạ
                    $sidebarParts[$partNum][] = $q;
                }
                // sort each part by newOrder asc (safety)
                foreach ($sidebarParts as $k => &$arr) {
                    usort($arr, function($a, $b){
                        $na = isset($a['newOrder']) ? intval($a['newOrder']) : 0;
                        $nb = isset($b['newOrder']) ? intval($b['newOrder']) : 0;
                        return $na <=> $nb;
                    });
                } unset($arr);
            } else {
                // non-DGNL: use existing $parts but filter empty/invalid items and ensure sorted by newOrder
                foreach ($parts as $pn => $items) {
                    $clean = array_values(array_filter($items, function($it){
                        return isset($it['code']) && trim((string)$it['code']) !== '';
                    }));
                    usort($clean, function($a, $b){
                        $na = isset($a['newOrder']) ? intval($a['newOrder']) : 0;
                        $nb = isset($b['newOrder']) ? intval($b['newOrder']) : 0;
                        return $na <=> $nb;
                    });
                    $sidebarParts[$pn] = $clean;
                }
            }
            ?>
            <!-- ========== CỘT SIDEBAR ========== -->
            <div class="sidebar-col">
                <div class="submit-box" style="margin-top: 0px;">
                  <div class="d-flex gap-2 align-items-center">
                    <button id="submitBtn" type="button" style="background-color: #003366; color: #fff;" class="btn flex-grow-1">
                        Nộp bài
                    </button>
                    <button id="fullscreenBtn"
                            style="background-color: #fff; color: #003366; padding: 0px;" class="btn"
                            aria-pressed="false"
                            aria-label="Toàn màn hình">
                        <i class="bi bi-fullscreen" style="font-size: 1.5rem;" aria-hidden="true"></i>
                    </button>
                  </div>
                </div>
                <div class="timer mb-3" style="text-align: center; margin-bottom: 0px; margin-top: 10px;">
                  <i class="bi bi-clock" style="font-size: 1.5rem; margin-bottom: 0px;"></i> <span id="countdown" style="font-size: 1.5rem; margin-bottom: 0px;"></span>
                  <div id="network-status">Đang kiểm tra kết nối...</div>
                </div>
                
                <div class="font-controls mt-3" aria-label="Điều chỉnh cỡ chữ" style="margin-top: 0px !important;">
                  <div class="d-flex align-items-center gap-2">
                    <button id="fontDecreaseBtn" class="btn btn-font btn-sm" type="button" title="Giảm cỡ chữ" aria-label="Giảm cỡ chữ">
                      <i class="bi bi-dash-lg"></i>
                    </button>
                    <button id="fontIncreaseBtn" class="btn btn-font btn-sm" type="button" title="Tăng cỡ chữ" aria-label="Tăng cỡ chữ">
                      <i class="bi bi-plus-lg"></i>
                    </button>
                    <div id="fontScaleDisplay" style="min-width:48px; text-align:center; font-weight:600;">100%</div>
                  </div>
                </div>
                
                <div class="progress-container mb-3" style="margin-top: 10px;">
                    <h5>Tiến trình làm bài</h5>
                    <ul class="list-group" id="progressList">
                    <?php
                    // Nếu DGNL: render grouped progress theo exam/section khi có examGroup
                    if (!empty($isDGNL)) {
                        // nguồn: $questions đã permutation & có newOrder (fallback $mixedQuestions)
                        $src = isset($questions) ? $questions : (isset($mixedQuestions) ? $mixedQuestions : []);
                        $flat = [];
                        foreach ($src as $q) {
                            if (!isset($q['code']) || trim((string)$q['code']) === '') continue;
                            $flat[] = $q;
                        }

                        // sort toàn bộ theo newOrder để tính toán sau
                        usort($flat, function($a, $b) {
                            $na = isset($a['newOrder']) ? intval($a['newOrder']) : PHP_INT_MAX;
                            $nb = isset($b['newOrder']) ? intval($b['newOrder']) : PHP_INT_MAX;
                            return $na <=> $nb;
                        });

                        // Nếu không có examGroup rõ ràng -> fallback: render phẳng như trước
                        $hasGroupInfo = !empty($examGroup) && !empty($examGroup['exams']) && is_array($examGroup['exams']);

                        if (!$hasGroupInfo) {
                            // fallback: như cũ (phẳng)
                            foreach ($flat as $qitem) {
                                $code = $qitem['code'];
                                $codeEsc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
                                $newOrder = isset($qitem['newOrder']) ? intval($qitem['newOrder']) : '?';
                                $isFlagged = !empty($resumeData['flag'][$code]);
                                $liId = 'prog-' . $codeEsc;
                                ?>
                                <li class="list-group-item<?= $isFlagged ? ' flagged' : '' ?>"
                                    id="<?= $liId ?>"
                                    onclick="scrollToQuestion('<?= $codeEsc ?>')">
                                    Câu <?= $newOrder ?>
                                    <?php if ($isFlagged): ?>
                                        <i class="bi prog-flag bi-flag-fill" style="margin-left:6px; color:#ffc107;"></i>
                                    <?php endif; ?>
                                </li>
                                <?php
                            }
                        } else {
                            // --- nhóm theo section index (secIndex) ---
                            $sections = [];
                            foreach ($flat as $q) {
                                $secIndex = 0;
                                if (!empty($q['_section_index'])) {
                                    $secIndex = intval($q['_section_index']);
                                } elseif (!empty($q['_exam_id'])) {
                                    // tìm index trong examGroup['exams']
                                    foreach ($examGroup['exams'] as $idx => $s) {
                                        if (isset($s['id']) && $s['id'] == $q['_exam_id']) {
                                            $secIndex = $idx + 1; // dùng 1-based index giống phần khác
                                            break;
                                        }
                                    }
                                }
                                // nhóm
                                if (!isset($sections[$secIndex])) $sections[$secIndex] = [];
                                $sections[$secIndex][] = $q;
                            }

                            // sắp xếp section keys để in theo thứ tự (0 cuối cùng nếu cần)
                            ksort($sections);

                            foreach ($sections as $secIndex => $items) {
                                // nếu có secIndex>0 thì in tiêu đề cho exam/section
                                if ($secIndex > 0) {
                                    // lấy tên section từ examGroup nếu có
                                    $title = '';
                                    if (isset($examGroup['exams'][$secIndex - 1]['name']) && trim($examGroup['exams'][$secIndex - 1]['name']) !== '') {
                                        $title = $examGroup['exams'][$secIndex - 1]['name'];
                                    } elseif (isset($examGroup['exams'][$secIndex - 1]['id'])) {
                                        $title = $examGroup['exams'][$secIndex - 1]['id'];
                                    }

                                    // tính phạm vi câu (min..max) dựa trên items của section này
                                    $minOrder = PHP_INT_MAX;
                                    $maxOrder = 0;
                                    foreach ($items as $it) {
                                        if (isset($it['newOrder'])) {
                                            $o = intval($it['newOrder']);
                                            if ($o < $minOrder) $minOrder = $o;
                                            if ($o > $maxOrder) $maxOrder = $o;
                                        }
                                    }
                                    // In tiêu đề section dưới dạng list-group-item part-header (giống phần)
                                    ?>
                                    <li class="list-group-item part-header">
                                        <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                                    </li>
                                    <?php
                                } else {
                                    // secIndex == 0: (không xác định) in một tiêu đề phụ nếu muốn — ở đây ta in "Câu khác" nếu có nhiều hơn 1 item
                                    if (count($items) > 1) {
                                        ?>
                                        <li class="list-group-item part-header">Các câu khác</li>
                                        <?php
                                    }
                                }

                                // sắp xếp items trong section theo newOrder rồi in
                                usort($items, function($a, $b){
                                    $na = isset($a['newOrder']) ? intval($a['newOrder']) : PHP_INT_MAX;
                                    $nb = isset($b['newOrder']) ? intval($b['newOrder']) : PHP_INT_MAX;
                                    return $na <=> $nb;
                                });

                                foreach ($items as $qitem) {
                                    $code = $qitem['code'];
                                    $codeEsc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
                                    $newOrder = isset($qitem['newOrder']) ? intval($qitem['newOrder']) : '?';
                                    $isFlagged = !empty($resumeData['flag'][$code]);
                                    $liId = 'prog-' . $codeEsc;
                                    ?>
                                    <li class="list-group-item<?= $isFlagged ? ' flagged' : '' ?>"
                                        id="<?= $liId ?>"
                                        onclick="scrollToQuestion('<?= $codeEsc ?>')">
                                        Câu <?= $newOrder ?>
                                        <?php if ($isFlagged): ?>
                                            <i class="bi prog-flag bi-flag-fill" style="margin-left:6px; color:#ffc107;"></i>
                                        <?php endif; ?>
                                    </li>
                                    <?php
                                }
                            } // end foreach sections
                        } // end hasGroupInfo
                    } else {
                        // Non-DGNL: render theo phần như trước (nhưng dùng filter + sort an toàn)
                        $partTitles = [
                            1 => 'trắc ngiệm nhiều phương án lựa chọn',
                            2 => 'trắc nghiệm đúng sai',
                            3 => 'trắc nghiệm ghép cặp',
                            4 => 'trắc nghiệm yêu cầu trả lời ngắn',
                            5 => 'tự luận',
                            6 => 'trắc nghiệm kéo thả',
                        ];

                        foreach ($parts as $partNum => $items) {
                            $clean = array_values(array_filter($items, function($it){
                                return isset($it['code']) && trim((string)$it['code']) !== '';
                            }));
                            if (empty($clean)) continue;

                            // sort by newOrder to be safe
                            usort($clean, function($a, $b){
                                $na = isset($a['newOrder']) ? intval($a['newOrder']) : PHP_INT_MAX;
                                $nb = isset($b['newOrder']) ? intval($b['newOrder']) : PHP_INT_MAX;
                                return $na <=> $nb;
                            });

                            $title = isset($partTitles[$partNum]) ? $partTitles[$partNum] : toRoman($partNum);
                            ?>
                            <li class="list-group-item part-header"> Phần <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php foreach ($clean as $qitem): 
                                $code = $qitem['code'];
                                $codeEsc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
                                $newOrder = isset($qitem['newOrder']) ? intval($qitem['newOrder']) : '?';
                                $isFlagged = !empty($resumeData['flag'][$code]);
                                $liId = 'prog-' . $codeEsc;
                            ?>
                                <li class="list-group-item<?= $isFlagged ? ' flagged' : '' ?>"
                                    id="<?= $liId ?>"
                                    onclick="scrollToQuestion('<?= $codeEsc ?>')">
                                    Câu <?= $newOrder ?>
                                    <?php if ($isFlagged): ?>
                                        <i class="bi prog-flag bi-flag-fill" style="margin-left:6px; color:#ffc107;"></i>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach;
                        }
                    }
                    ?>
                    </ul>
               </div>
            </div>
        </div>

        <!-- XÁC NHẬN NỘP BÀI Modal -->
        <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
            <div class="modal-header" style="background-color: #003366; color: #fff; text-align: center;">
                <h5 class="modal-title" id="guideOverlayLabel" style="text-align: center;">Xác nhận nộp bài thi</h5>
                <!-- Không cần nút đóng vì JS sẽ ẩn hẳn #guideOverlay -->
            </div>
            <div class="modal-body">
                <p>Thí sinh: <strong><?= htmlspecialchars($foundUser['Họ và tên']) ?></strong>
                    &ndash; Số báo danh: <strong><?= htmlspecialchars($foundUser['0000']) ?></strong>
                </p>
                Bạn có chắc chắn muốn nộp bài không?
            </div>
            <div class="modal-footer">
                <button type="button" style="background-color: red; color: #fff;" class="btn" data-bs-dismiss="modal">Hủy</button>
                <button type="button" style="background-color: #003366; color: #fff;" class="btn" id="confirmSubmitBtn">Xác nhận</button>
            </div>
            </div>
        </div>
        </div>
        
        <!-- VI PHẠM MODAL: hiển thị cảnh báo/đình chỉ (dùng Bootstrap Modal) -->
        <div class="modal fade" id="violationModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" >
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header" style="background-color:#dc3545; color:#fff;">
                <h5 class="modal-title">CẢNH BÁO!</h5>
              </div>
              <div class="modal-body" id="violationModalBody" style="color:#333; font-weight:600;">
                <!-- nội dung được set bởi JS -->
              </div>
              <div class="modal-footer" id="violationModalFooter">
                <!-- nút chính thay đổi theo mức -->
                <button type="button" id="violationPrimaryBtn" class="btn" style="background:#003366;color:#fff;"></button>
              </div>
            </div>
          </div>
        </div>

        <!-- Modal báo lỗi (đặt ngoài form, tốt nhất là trước </body>) -->
        <div class="modal fade" id="modal-report-error" tabindex="-1" aria-hidden="true" style="z-index: 9999;">
            <div class="modal-dialog">
                <form id="errorForm" class="modal-content">
                <div class="modal-header" style="background:#003366; color:#fff;">
                    <h5 class="modal-title">Báo lỗi đề thi</h5>
                    <button type="button" class="btn-close" style="background-color: #fff;" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                    <label class="form-label"><b>Email phản hồi (nếu cần):</b></label>
                    <input type="email" id="error-email" name="errorEmail" class="form-control" placeholder="Ví dụ: you@example.com">
                    </div>
                    <div class="mb-3">
                    <label class="form-label"><b>Mô tả lỗi (Ghi rõ nội dung Câu và Phần chứa Câu bị lỗi):</b></label>
                    <textarea id="error-content" name="errorContent" class="form-control" rows="5" placeholder="Mô tả lỗi bạn phát hiện..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button id="err-submit" class="btn" type="submit" style="background:#003366;color:#fff;width:100%;">Gửi</button>
                </div>
                </form>
            </div>
        </div>

        <!-- Spinner overlay (ngoài form) -->
        <div id="spinnerOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.7); z-index:9999;">
            <div class="d-flex justify-content-center align-items-center h-100" style="color:#003366;">
                <div class="spinner-border" role="status" style="width:4rem;height:4rem;color:#003366;">
                <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        </div>

        <!-- Bootstrap JS (nếu cần các component JS) -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
          // Khởi tạo Summernote cho mỗi div.summernote mà vẫn giữ innerHTML ban đầu
          // --- Khởi tạo Summernote cho mỗi div.summernote (sửa callbacks để autosave HTML + markAnswered)
            $('.summernote').each(function() {
              var $el = $(this);
              var initial = $el.html().trim();

              $el.summernote({
                placeholder: 'Soạn câu trả lời...',
                height: Math.max(120, $el.data('height') || 150),
                toolbar: [
                  ['style', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
                  ['font', ['superscript', 'subscript']],
                  ['para', ['ul', 'ol', 'paragraph']],
                  ['insert', ['link', 'picture', 'table', 'hr']],
                  ['view', ['codeview']]
                ],
                callbacks: {
                  onInit: function() {
                    // đặt nội dung ban đầu (nếu có)
                    $(this).summernote('code', initial);
                  },
                  // Ghi nhận thay đổi: cập nhật innerHTML của div gốc, autosave HTML, markAnswered
                  onChange: function(contents, $editable) {
                    // 1) cập nhật innerHTML của element gốc (giữ tương thích)
                    $el.html(contents);

                    // 2) Lấy mã câu (data-code) gắn trên div gốc, ví dụ "5.1" hoặc "5.1.a"
                    var dotCode = $el.attr('data-code') || $el.data('code');
                    if (!dotCode) return;

                    // 3) Gọi autosaveSingle với nội dung HTML (lưu dưới dạng "code view")
                    try {
                      if (typeof autosaveSingle === 'function') {
                        autosaveSingle(dotCode, contents);
                      }
                    } catch (e) {
                      console.warn('autosaveSingle không có sẵn hoặc lỗi', e);
                    }

                    // 4) Đánh dấu đã trả lời:
                    //  - Nếu là ý nhỏ (ví dụ "5.1.a"), kiểm tra tất cả ý nhỏ cùng parent đã có nội dung HTML (không rỗng) => mark parent
                    //  - Nếu là câu chính (ví dụ "5.1"), mark nếu nội dung không rỗng (loại bỏ thẻ HTML để check)
                    if (/^5\.\d+\.[a-z]$/i.test(dotCode)) {
                      var parent = dotCode.split('.').slice(0,2).join('.');
                      var subs = document.querySelectorAll('div[contenteditable][data-code^="' + parent + '."]');
                      if ([].every.call(subs, function(d){ return (d.innerHTML || '').trim() !== ''; })) {
                        markAnswered(parent);
                      }
                    } else if (/^5\.\d+$/.test(dotCode)) {
                      // loại bỏ thẻ HTML để kiểm tra có nội dung thật sự không
                      var plain = contents.replace(/<[^>]*>/g,'').trim();
                      if (plain !== '') markAnswered(dotCode);
                    } else {
                      // fallback: nếu có nội dung html thì markAnswered
                      if ((contents || '').replace(/<[^>]*>/g,'').trim() !== '') markAnswered(dotCode);
                    }
                  }
                }
              });
            });
            
          // Trước khi form được xử lý/submit (nếu trang bạn có form), copy nội dung hiện tại từ summernote vào innerHTML
          // để các logic JS/PHP cũ vẫn đọc được.
          $('form').on('submit', function() {
            $('.summernote').each(function() {
              var code = $(this).summernote ? $(this).summernote('code') : $(this).html();
              $(this).html(code);
            });
            // nếu bạn có hàm JS khác đọc innerHTML để lưu tạm, hãy gọi tương tự tại đó
          });
        });
        </script>
       	<script>
        (function(){
          const STORAGE_KEY = 'exam_font_scale_percent';
          const MIN_SCALE = 70;   // 70%
          const MAX_SCALE = 200;  // 200%
          const STEP = 10;        // mỗi lần tăng/giảm 10%

          let scale = parseInt(localStorage.getItem(STORAGE_KEY), 10);
          if (!scale || isNaN(scale)) scale = 100;

          const btnInc = document.getElementById('fontIncreaseBtn');
          const btnDec = document.getElementById('fontDecreaseBtn');
          const display = document.getElementById('fontScaleDisplay');

          function getBasePx() {
            const s = window.getComputedStyle(document.body).fontSize;
            const v = parseFloat(s);
            return isNaN(v) ? 16 : v;
          }

          // NEW: apply font-size to many selectors (buttons, spans, selects, drag items, etc.)
          function applyScaleToElements(s) {
            const base = getBasePx();
            const px = (base * s / 100).toFixed(2) + 'px';

            // selectors to update (inline style overrides anything else)
            const selectors = [
              '.question-content',
              '.instruction-content',
              '.question-card',
              '.question-card h5',
              '.options-wrapper',
              '.options-wrapper span',
              '.options-wrapper .btn',
              '.question-card .btn',
              '.question-card .btn *',
              '.tsa-btn',
              '.drop-zone',
              '.drag-item',
              '.form-control.short-answer',
              '.form-select',
              '.drag-pool',
              '.instruction-card .instruction-content'
            ];

            selectors.forEach(sel => {
              document.querySelectorAll(sel).forEach(el => {
                // set inline style (will override external CSS and many inline styles)
                el.style.fontSize = px;
                // small accessibility tweak: ensure line-height reasonable
                el.style.lineHeight = '1.5';
              });
            });

            // If options HTML contains nested tags with their own inline styles, try to normalize:
            // set fontSize on direct children too
            document.querySelectorAll('.options-wrapper').forEach(wrapper => {
              wrapper.querySelectorAll('*').forEach(ch => {
                // only override if child has no data-keep-font attribute (developer opt-out)
                if (!ch.hasAttribute('data-keep-font')) {
                  ch.style.fontSize = px;
                  ch.style.lineHeight = '1.5';
                }
              });
            });
          }

          function updateUI() {
            display.textContent = scale + '%';
            btnDec.disabled = scale <= MIN_SCALE;
            btnInc.disabled = scale >= MAX_SCALE;
          }

          function setScale(newScale) {
            scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, newScale));
            localStorage.setItem(STORAGE_KEY, String(scale));
            applyScaleToElements(scale);
            updateUI();
          }

          if (btnInc) btnInc.addEventListener('click', () => setScale(scale + STEP));
          if (btnDec) btnDec.addEventListener('click', () => setScale(scale - STEP));

          document.addEventListener('keydown', (e) => {
            const tag = (e.target && e.target.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) return;
            if (e.key === '+' || e.key === '=') { setScale(scale + STEP); }
            if (e.key === '-' || e.key === '_') { setScale(scale - STEP); }
          });

          document.addEventListener('DOMContentLoaded', function(){
            applyScaleToElements(scale);
            updateUI();
          });

          // If your app renders or replaces question HTML dynamically (AJAX), call:
          // window.applyExamFontScale && window.applyExamFontScale(scale)
          // To support that, expose a small helper:
          window.applyExamFontScale = function(s) { applyScaleToElements(s || scale); };

        })();
        </script>

        <!-- ========= JS xử lý client ========= -->
      	<script>
        (function(){
          const fsBtn = document.getElementById('fullscreenBtn');
          const fsIcon = () => fsBtn.querySelector('i.bi');
          const fsElement = document.documentElement;

          function isIOS() {
            return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
          }

          function enterFullscreen() {
            if (isIOS()) {
              document.body.classList.add('ios-fullscreen');
              setIcon(true);
              return;
            }
            const req = fsElement.requestFullscreen || fsElement.webkitRequestFullscreen || fsElement.mozRequestFullScreen || fsElement.msRequestFullscreen;
            if (req) {
              req.call(fsElement).catch(()=>{ /* fail silently */ });
            } else {
              document.body.classList.add('ios-fullscreen');
              setIcon(true);
            }
          }

          function exitFullscreen() {
            if (isIOS()) {
              document.body.classList.remove('ios-fullscreen');
              setIcon(false);
              return;
            }
            const exit = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen || document.msExitFullscreen;
            if (exit) {
              exit.call(document).catch(()=>{ /* fail silently */ });
            } else {
              document.body.classList.remove('ios-fullscreen');
              setIcon(false);
            }
          }

          function isCurrentlyFullscreen() {
            return !!(
              document.fullscreenElement ||
              document.webkitFullscreenElement ||
              document.mozFullScreenElement ||
              document.msFullscreenElement ||
              document.body.classList.contains('ios-fullscreen')
            );
          }

          function toggleFullscreen() {
            if (isCurrentlyFullscreen()) exitFullscreen();
            else enterFullscreen();
          }

          function setIcon(isFs) {
            const icon = fsIcon();
            if (!icon) return;
            if (isFs) {
              icon.classList.remove('bi-fullscreen');
              icon.classList.add('bi-fullscreen-exit');
              fsBtn.setAttribute('aria-pressed','true');
              fsBtn.setAttribute('aria-label','Thoát toàn màn hình');
            } else {
              icon.classList.remove('bi-fullscreen-exit');
              icon.classList.add('bi-fullscreen');
              fsBtn.setAttribute('aria-pressed','false');
              fsBtn.setAttribute('aria-label','Toàn màn hình');
            }
          }

          // Các event vendor-prefixed cho fullscreen change
          document.addEventListener('fullscreenchange', () => setIcon(isCurrentlyFullscreen()));
          document.addEventListener('webkitfullscreenchange', () => setIcon(isCurrentlyFullscreen()));
          document.addEventListener('mozfullscreenchange', () => setIcon(isCurrentlyFullscreen()));
          document.addEventListener('MSFullscreenChange', () => setIcon(isCurrentlyFullscreen()));

          // Click handler
          fsBtn && fsBtn.addEventListener('click', toggleFullscreen);

          // ESC fallback: nếu dùng pseudo-fullscreen trên iOS, ESC (hoặc back) sẽ tắt
          document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' || e.key === 'Esc') {
              if (document.body.classList.contains('ios-fullscreen')) {
                document.body.classList.remove('ios-fullscreen');
                setIcon(false);
              }
            }
          });

          // Khởi tạo trạng thái nút theo trạng thái hiện tại (nếu load lại trong fullscreen)
          setIcon(isCurrentlyFullscreen());
        })();
        </script>
        <script>
            // Dữ liệu resume từ PHP
            var resumeData = <?= $resumeJson ?>;

            // --- TỰ ĐỘNG CẬP NHẬT HASH (#mã đề) ---
            (function setExamHash() {
                try {
                    var idExam = '';
                    if (typeof resumeData !== 'undefined' && resumeData) {
                        // đảm bảo giữ nguyên số 0 ở đầu (ví dụ "0022")
                        idExam = (resumeData.idExam !== undefined && resumeData.idExam !== null)
                                ? String(resumeData.idExam)
                                : (resumeData['idExam'] ? String(resumeData['idExam']) : '');
                    }
                    if (idExam) {
                        // Giữ nguyên pathname và querystring, chỉ thay fragment
                        var base = window.location.protocol + '//' + window.location.host + window.location.pathname + window.location.search;
                        var newUrl = base + '#' + encodeURIComponent(idExam);
                        // Thay fragment mà không tạo history mới và không reload trang
                        history.replaceState(null, '', newUrl);
                        // debug (xóa nếu không cần)
                        if (window.console && window.console.log) {
                            console.log('[ExamHash] Hash set to #' + idExam);
                        }
                    }
                } catch (err) {
                    console.error('Error setting exam hash:', err);
                }
            })();

            // totalSeconds = resumeData.remainingSeconds hoặc timeLimit*60
            var totalSeconds = resumeData.remainingSeconds 
                                ? parseInt(resumeData.remainingSeconds) 
                                : <?= $timeMinutes * 60 ?>;

            // <<< BƯỚC 1: Khai báo biến đếm thoát màn hình & chụp màn hình
            let exitCount = 0;
            let screenshotCount = 0;

            // Format thời gian (phút, giây)
            function formatTime(s) {
                var m = Math.floor(s / 60);
                var sec = s % 60;
                return m + ":" + (sec < 10 ? "0" + sec : sec);
            }

            // Hiển thị và đếm ngược
            var countdownEl = document.getElementById('countdown');
            function startCountdown() {
                countdownEl.textContent = formatTime(totalSeconds);
                window.countdownTimer = setInterval(function() {
                    totalSeconds--;
                    if (totalSeconds < 0) {
                        clearInterval(window.countdownTimer);
                        alert("Đã hết thời gian! Hệ thống sẽ tự động nộp bài.");
                        document.getElementById('examForm').submit();
                    } else {
                        countdownEl.textContent = formatTime(totalSeconds);
                    }
                }, 1000);
            }

            // <<< BƯỚC 2: Đếm số lần thoát màn hình
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) exitCount++;
            });
            window.addEventListener('blur', function() {
                exitCount++;
            });

            // <<< BƯỚC 2: Đếm số lần chụp màn hình
            document.addEventListener('keydown', function(e) {
                if (e.key === 'PrintScreen') screenshotCount++;
            });

            // Đánh dấu tiến trình đã trả lời (tô xanh)
            function markAnswered(code) {
                var li = document.getElementById('prog-' + code);
                if (li && !li.classList.contains('answered')) {
                    li.classList.add('answered');
                }
            }

            // Scroll tới câu tương ứng
            function scrollToQuestion(code) {
                var container = document.querySelector('.content-col');
                var el = document.getElementById('q-' + code);
                if (!container || !el) return;

                // Tính vị trí của câu hỏi so với container
                var topPos = el.offsetTop;
                // Bạn có thể bù thêm padding/margin nếu cần, ví dụ -20px
                var scrollTarget = topPos - 20;

                container.scrollTo({
                    top: scrollTarget,
                    behavior: 'smooth'
                });
            }

            // Phần 1: Chọn button A/B/C/D → autosaveSingle + markAnswered
            function selectPart1(code, optionLetter) {
                var nameKey = code.replace('.', '_');
                ['A','B','C','D'].forEach(function(opt) {
                    var btn = document.getElementById(nameKey + '_' + opt);
                    if (!btn) return;
                    if (opt === optionLetter) {
                        btn.classList.add('btn-option-active');
                    } else {
                        btn.classList.remove('btn-option-active');
                    }
                });
                document.getElementById('input-' + nameKey).value = optionLetter;
                markAnswered(code);
                autosaveSingle(code, optionLetter);
            }

            // Phần 2: Chọn Đúng/Sai → autosaveSingle + kiểm tra đủ 4 ý → markAnswered
            function selectPart2(code, sub, value) {
                var nameKey = code.replace('.', '_') + '_' + sub;
                var btnDung = document.getElementById(nameKey + '_dung');
                var btnSai  = document.getElementById(nameKey + '_sai');
                if (!btnDung || !btnSai) return;
                if (value === 'Đúng') {
                    btnDung.classList.add('tsa-btn-active');
                    btnSai.classList.remove('tsa-btn-active');
                } else {
                    btnSai.classList.add('tsa-btn-active');
                    btnDung.classList.remove('tsa-btn-active');
                }
                document.getElementById('input-' + nameKey).value = value;
                autosaveSingle(code + '.' + sub, value);

                // Kiểm tra đủ 4 ý
                var allFilled = true;
                ['a','b','c','d'].forEach(function(subItem) {
                    var keyCheck = code.replace('.', '_') + '_' + subItem;
                    var inputEl = document.getElementById('input-' + keyCheck);
                    if (!inputEl || inputEl.value.trim() === '') {
                        allFilled = false;
                    }
                });
                if (allFilled) {
                    markAnswered(code);
                }
            }

            // Phần 3 & 4: onchange/select (Part 3) hoặc oninput (Part 4) → autosaveSingle + markAnswered
            function selectPart3or4(code, key, value) {
                markAnswered(code);
                // Chuyển key thành dạng có dấu chấm “3.x.y” hay “4.x”
                var dotKey = key.replace(/_/g, '.');
                autosaveSingle(dotKey, value);
            }

            // AutosaveSingle: gửi code/value lên server
            function autosaveSingle(code, value) {
                console.log('autosaveSingle INCOMING code:', code);

                let examName = '<?= $examName ?>'; // fallback từ server
                let actualCode = code;

                // nếu muốn sửa off-by-one cuối cùng (cẩn trọng). 0 = không sửa.
                const fixLastIndexBy = 0;

                // helper: split và loại bỏ empty
                const splitSeg = s => s.split(/[_\.\-]+/).filter(Boolean);

                if (/^exam[_\.\-]/.test(code)) {
                    // xử lý khi code có tiền tố exam_ / exam. / exam-
                    const withoutPrefix = code.replace(/^exam[_\.\-]/, '');
                    const segments = splitSeg(withoutPrefix);

                    // tìm token năm 4 chữ số >=2000 (ưu tiên)
                    let yearIdx = segments.findIndex(s => /^\d{4}$/.test(s) && Number(s) >= 2000);

                    if (yearIdx !== -1) {
                        // include tới yearIdx (bao gồm năm) vào examName
                        examName = segments.slice(0, yearIdx + 1).join('-').replace(/-+/g, '-');
                        actualCode = segments.slice(yearIdx + 1).join('.');
                    } else {
                        // tìm vị trí bắt đầu mã câu theo rule: x là 1 chữ số và phần còn lại có 1 hoặc 2 segment
                        let idx = -1;
                        for (let i = 0; i < segments.length; i++) {
                            const tailLen = segments.length - i;
                            if (/^[1-9]$/.test(segments[i]) && (tailLen === 2 || tailLen === 3)) {
                                idx = i;
                                break;
                            }
                        }
                        if (idx !== -1) {
                            examName = segments.slice(0, idx).join('-').replace(/-+/g, '-') || examName;
                            actualCode = segments.slice(idx).join('.');
                        } else {
                            // fallback: nếu last segments toàn số thì coi đó là actualCode
                            const k = segments.findIndex((s, i) => segments.slice(i).every(t => /^\d+$/.test(t)));
                            if (k !== -1) {
                                examName = segments.slice(0, k).join('-').replace(/-+/g, '-') || examName;
                                actualCode = segments.slice(k).join('.');
                            } else {
                                // không tách được -> chuẩn hoá underscores->dots
                                actualCode = withoutPrefix.replace(/[_\-]+/g, '.');
                            }
                        }
                    }
                } else {
                    // KHÔNG có tiền tố exam_ -> có thể có subject prefix như "Li.1.13" hoặc "1.2026.4.2"
                    const segments = splitSeg(code);

                    // Nếu bắt đầu bằng chữ (vd "Li") giữ chữ đó như subjectPrefix
                    let subjectPrefix = null;
                    if (segments.length > 0 && /^[A-Za-z]+$/.test(segments[0])) {
                        subjectPrefix = segments.shift();
                    }

                    // tìm vị trí bắt đầu mã câu trong segments: tìm i sao cho segments[i] là 1 chữ số (1..9)
                    // và phần còn lại có độ dài 1 hoặc 2 (tức x_y hoặc x_y_z)
                    let startIdx = -1;
                    for (let i = 0; i < segments.length; i++) {
                        const tailLen = segments.length - i;
                        if (/^[1-9]$/.test(segments[i]) && (tailLen === 2 || tailLen === 3)) {
                            startIdx = i;
                            break;
                        }
                    }

                    if (startIdx !== -1) {
                        // phần trước startIdx (nếu có) thuộc examName (append)
                        if (startIdx > 0) {
                            const append = segments.slice(0, startIdx).join('-');
                            examName = (examName ? (examName + '-') : '') + append;
                            examName = examName.replace(/-+/g, '-');
                        }
                        const tail = segments.slice(startIdx);
                        actualCode = tail.join('.');
                        // nếu có subject prefix chữ thì append vào examName (đây là chỗ quan trọng)
                        if (subjectPrefix) {
                            examName = (examName ? (examName + '-') : '') + subjectPrefix;
                            examName = examName.replace(/-+/g, '-');
                        }
                    } else {
                        // fallback: nếu segments có cấu trúc numeric hợp lý (<=3 phần) thì dùng nó
                        if (segments.length <= 3 && segments.every(s => /^\d+$/.test(s))) {
                            actualCode = segments.join('.');
                            if (subjectPrefix) {
                                // nếu có subject nhưng không tách theo rule, đặt subject vào examName
                                examName = (examName ? (examName + '-') : '') + subjectPrefix;
                                examName = examName.replace(/-+/g, '-');
                            }
                        } else if (subjectPrefix) {
                            // giữ dạng subjectPrefix + rest (chuẩn hoá separators) nhưng move subject vào examName nếu tail là numeric-like
                            const rest = segments.join('.');
                            const restSegs = rest.split(/[._\-]+/).filter(Boolean);
                            if (restSegs.length >= 2 && restSegs.length <= 3 && restSegs.every(s => /^\d+$/.test(s)) && /^[1-9]$/.test(restSegs[0])) {
                                examName = (examName ? (examName + '-') : '') + subjectPrefix;
                                actualCode = restSegs.join('.');
                            } else {
                                // không rõ -> giữ như cũ, subject kèm actualCode
                                actualCode = subjectPrefix + '.' + rest;
                            }
                        } else {
                            // cuối cùng: chỉ chuẩn hoá dấu _ -> .
                            actualCode = code.replace(/_/g, '.');
                        }
                    }
                }

                // --- thêm bước patch: nếu actualCode bắt đầu bằng subject (vd "Li.1.1"), move subject into examName ---
                if (actualCode) {
                    const m = actualCode.match(/^([A-Za-z]+)[\._\-](.+)$/);
                    if (m) {
                        const subj = m[1];
                        const rest = m[2];
                        const restSegs = rest.split(/[._\-]+/).filter(Boolean);
                        if (restSegs.length >= 2 && restSegs.length <= 3 && restSegs.every(s => /^\d+$/.test(s)) && /^[1-9]$/.test(restSegs[0])) {
                            // move subj into examName and keep numeric tail as actualCode
                            examName = (examName ? (examName + '-') : '') + subj;
                            examName = examName.replace(/-+/g, '-');
                            actualCode = restSegs.join('.');
                        }
                    }
                }

                // optional: fix last index (off-by-one) nếu bạn cần
                if (fixLastIndexBy !== 0 && actualCode) {
                    const parts = actualCode.split('.');
                    const last = parts[parts.length - 1];
                    if (/^\d+$/.test(last)) {
                        const newLast = Number(last) + fixLastIndexBy;
                        if (newLast >= 0) parts[parts.length - 1] = String(newLast);
                        actualCode = parts.join('.');
                    }
                }

                console.log('Parsed exam group => examName:', examName, ' actualCode:', actualCode);

                fetch('?action=autosaveSingle&examName=' + encodeURIComponent(examName) +
                    '&studentID=' + encodeURIComponent('<?= $studentID ?>'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        code: actualCode,
                        value: value,
                        exitCount: exitCount,
                        screenshotCount: screenshotCount,
                        ts: Date.now()
                    })
                })
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(resj => {
                    // console.log('saved single', resj);
                })
                .catch(err => console.warn('autosaveSingle lỗi:', err));
            }
            
            // --- Flag handling ---
            function setFlagUI(code, flag) {
                var key = code.replace(/\./g, '_');
                var btn = document.getElementById('flag-' + key);
                if (btn) {
                    btn.classList.toggle('flagged', !!flag);
                    var icon = btn.querySelector('i.bi');
                    if (icon) {
                        if (flag) icon.classList.add('text-warning'); else icon.classList.remove('text-warning');
                    }
                }

                // progress list: tô nền hoặc thêm icon
                var li = document.getElementById('prog-' + code);
                if (li) {
                    li.classList.toggle('flagged', !!flag);
                    var f = li.querySelector('.prog-flag');
                    if (!f) {
                        f = document.createElement('i');
                        f.className = 'bi prog-flag bi-flag-fill';
                        f.style.display = 'none';
                        f.style.marginLeft = '6px';
                        li.appendChild(f);
                    }
                    f.style.display = flag ? 'inline-block' : 'none';
                }
            }

            // Gọi ajax để toggle flag (optimistic UI)
            function toggleFlag(code) {
                let examName = '<?= $examName ?>';
                let actualCode = code;

                // Parse exam group format giống autosaveSingle
                if (code.startsWith('exam_')) {
                    const withoutPrefix = code.substring(5);
                    let lastUnderscoreIdx = -1;

                    for (let i = 0; i < withoutPrefix.length; i++) {
                        if (withoutPrefix[i] === '_' && 
                            i + 1 < withoutPrefix.length && 
                            /\d/.test(withoutPrefix[i + 1])) {
                            lastUnderscoreIdx = i;
                        }
                    }

                    if (lastUnderscoreIdx !== -1) {
                        examName = withoutPrefix.substring(0, lastUnderscoreIdx).replace(/_/g, '-');
                        actualCode = withoutPrefix.substring(lastUnderscoreIdx + 1);
                    }
                }

                var key = code.replace(/\./g, '_');
                var btn = document.getElementById('flag-' + key);
                var currently = btn && btn.classList.contains('flagged');
                var newFlag = !currently;

                // thay đổi UI ngay (optimistic)
                setFlagUI(code, newFlag);

                // gửi lên server với exam name và code đã parse
                fetch('?action=toggleFlag&examName=' + encodeURIComponent(examName) +
                    '&studentID=' + encodeURIComponent('<?= $studentID ?>'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        code: actualCode,
                        flag: newFlag
                    })
                })
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(json => {
                    // nếu server phản hồi lỗi hoặc flag không khớp, rollback
                    if (!json || json.status !== 'ok') {
                        // rollback UI
                        setFlagUI(code, !newFlag);
                        console.warn('toggleFlag server responded not ok', json);
                    }
                })
                .catch(err => {
                    // rollback UI
                    setFlagUI(code, !newFlag);
                    console.warn('toggleFlag lỗi:', err);
                });
            }

            // AutosaveTime mỗi 2s: chỉ gửi remainingSeconds
            function autosaveTime() {
                fetch('?action=autosaveTime&examName=' + encodeURIComponent(<?= json_encode($examName) ?>) +
                    '&studentID=' + encodeURIComponent(<?= json_encode($studentID) ?>), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        remainingSeconds: totalSeconds,
                        exitCount: exitCount,
                        screenshotCount: screenshotCount
                    })
                })
                .then(res => res.json())
                .then(resj => { /* console.log('saved time', resj); */ })
                .catch(err => console.warn('autosaveTime lỗi:', err));
            }

            // Cập nhật network status
            var netStatusEl = document.getElementById('network-status');
            function updateNetworkStatus() {
                if (!navigator.onLine) {
                    netStatusEl.innerHTML = '<i class="bi bi-circle-fill text-danger"></i> Mất kết nối';
                    netStatusEl.style.color = 'red';
                    return;
                }
                var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                if (connection && connection.effectiveType) {
                    var type = connection.effectiveType;
                    if (type === '4g') {
                        netStatusEl.innerHTML = '<i class="bi bi-circle-fill text-success"></i> Đang kết nối';
                        netStatusEl.style.color = 'green';
                    } else {
                        netStatusEl.innerHTML = '<i class="bi bi-circle-fill text-warning"></i> Kết nối yếu (' + type + ')';
                        netStatusEl.style.color = '#FFC107';
                        
                    }
                } else {
                    netStatusEl.innerHTML = '<i class="bi bi-circle-fill text-success"></i> Đang kết nối';
                    netStatusEl.style.color = 'green';
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                var ans = (resumeData.answers && typeof resumeData.answers === 'object') ? resumeData.answers : {};

                // Nhập dữ liệu đã lưu sẵn (nếu có)
                Object.keys(ans).forEach(function(code) {
                    var val = ans[code];
                    if (typeof val !== 'string' || val.trim() === "") return;

                    // Part 1: mã "1.x"
                    if (/^1\.\d+$/.test(code)) {
                        if (["A","B","C","D"].includes(val.toUpperCase())) {
                            selectPart1(code, val.toUpperCase());
                        }
                    }
                    // Part 2: mã "2.x.y"
                    else if (/^2\.\d+\.[a-d]$/i.test(code)) {
                        var parts2 = code.split('.');
                        var question = parts2[0] + '.' + parts2[1]; // "2.1"
                        var sub = parts2[2].toLowerCase();           // "a"…
                        if (val === "Đúng" || val === "Sai") {
                            selectPart2(question, sub, val);
                        }
                    }
                    // Part 3: mã "3.x.y"
                    else if (/^3\.\d+\.\d+$/.test(code)) {
                        var name3 = code.replace(/\./g, '_');               // "3_1_2"
                        var selectEl = document.getElementsByName(name3)[0];
                        if (selectEl) {
                            for (var i = 0; i < selectEl.options.length; i++) {
                                if (selectEl.options[i].value === val) {
                                    selectEl.selectedIndex = i;
                                    markAnswered(code.split('.').slice(0,2).join('.')); // "3.1"
                                    break;
                                }
                            }
                        }
                    }
                    // Part 4: mã "4.x"
                    else if (/^4\.\d+$/.test(code)) {
                        var name4 = code.replace(/\./g, '_');  // "4_1"
                        var input4 = document.getElementsByName(name4)[0];
                        if (input4) {
                            input4.value = val;
                            markAnswered(code);  // tô xanh
                        }
                    }
                    // ngay sau phần else if (/^5\.\d+$/)…
                    else if (/^5\.\d+\.[a-z]$/i.test(code)) {
                        // code ví dụ "5.1.a"
                        var fieldId = code.replace(/\./g, '_');          // "5_1_a"
                        var div = document.getElementById('input-' + fieldId);
                        if (div) {
                            div.innerHTML = val;
                            // nếu muốn đánh dấu parent khi đủ ý nhỏ thì gọi lại markAnswered
                            markAnswered(code.split('.').slice(0,2).join('.'));
                        }
                    }
                });

                // === Phần 5: Đánh dấu parent khi tất cả sub‑items đã trả lời ===
                // === Phần 5: Đánh dấu answered ===
                // 1) Với các câu có sub‑item (ví dụ 5.1 có 5.1.a,5.1.b,…)
                var editors5 = document.querySelectorAll('div[contenteditable][data-code^="5."]');
                var parents = new Set();
                editors5.forEach(function(div) {
                var code = div.dataset.code; 
                var parentCode = code.split('.').slice(0,2).join('.'); // "5.x"
                parents.add(parentCode);
                });
                parents.forEach(function(parentCode) {
                // chỉ xử lý nếu thực sự có sub‑item (subs.length>0)
                var subs = document.querySelectorAll(
                    'div[contenteditable][data-code^="' + parentCode + '."]'
                );
                if (subs.length === 0) return;  // bỏ qua các câu không có sub‑item
                var allFilled = Array.from(subs).every(function(div) {
                    return div.innerHTML.trim() !== '';
                });
                if (allFilled) {
                    markAnswered(parentCode);
                }
                });

                // 2) Với các câu không có sub‑item (chỉ có một ô contenteditable dữ liệu-code="5.x")
                // 2.1 Listener cho câu cha (giữ nguyên nếu bạn vẫn muốn lưu riêng)
                document.querySelectorAll('div[contenteditable][data-code^="5."]').forEach(function(div) {
                var code = div.dataset.code;
                if (/^5\.\d+$/.test(code)) {
                    div.addEventListener('input', function() {
                        markAnswered(code);
                        // lưu dưới dạng HTML để giữ thẻ <p>, <b>, ...
                        autosaveSingle(code, (div.innerHTML || '').trim());
                    });
                }
                });

                // 2.2 Listener cho ý nhỏ (5.x.a, 5.x.b,…)
                document.querySelectorAll('div[contenteditable][data-code^="5."]').forEach(function(div) {
                var code = div.dataset.code;
                if (/^5\.\d+\.[a-z]$/i.test(code)) {
                    div.addEventListener('input', function() {
                        // lưu đúng ý nhỏ (lưu HTML)
                        autosaveSingle(code, (div.innerHTML || '').trim());

                        // đánh dấu parent nếu đã đầy đủ (kiểm tra nội dung thực sự, bỏ tag)
                        var parent = code.split('.').slice(0,2).join('.');
                        var subs = document.querySelectorAll(
                            'div[contenteditable][data-code^="' + parent + '."]'
                        );
                        if ([].every.call(subs, function(d){
                            // remove HTML tags then trim để kiểm tra có text thật không
                            return ((d.innerHTML || '').replace(/<[^>]*>/g,'').trim() !== '');
                        })) {
                            markAnswered(parent);
                        }
                    });
                }
                });

                // === Phục hồi phần kéo-thả từ resumeData.answers ===
                if (resumeData.answers) {
                    Object.entries(resumeData.answers).forEach(([dotCode, val]) => {
                    if (!dotCode.startsWith('6.') || !val) return;
                    var id = dotCode.replace(/\./g, '_');                   // "6.1.2" → "6_1_2"
                    var zone = document.querySelector('.drop-zone[data-id="' + id + '"]');
                    if (zone) {
                        assignValue(zone, id, val);
                        // Xóa khỏi pool
                        var parts  = id.split('_');                           // ["6","1","2"]
                        removeFromPool('pool-' + parts[0] + '_' + parts[1], val);
                    }
                    });

                    // === Sau khi restore xong, tự động bôi xanh các câu đã đầy đủ ===
                    var seen = new Set();
                    Object.keys(resumeData.answers).forEach(dotCode => {
                    if (dotCode.startsWith('6.')) {
                        var q = dotCode.split('.').slice(0,2).join('.');     // "6.1"
                        seen.add(q);
                    }
                    });
                    seen.forEach(checkPart6Complete);
                }

                // Ẩn toàn bộ nhóm "Phần 0" và các câu hỏi bên dưới
                var progressList = document.getElementById('progressList');
                var headers = progressList.querySelectorAll('.part-header');
                headers.forEach(function(header) {
                    // Nếu đây là header "Phần" cần ẩn (ở đây regex bạn đang dùng là /^Phần\s*$/)
                    if (/^Phần\s*$/.test(header.textContent.trim())) {
                        // 1) Ẩn header
                        header.style.display = 'none';

                        // 2) Ẩn lần lượt tất cả các phần tử li (hoặc phần tử con) cho đến khi gặp header tiếp theo
                        var next = header.nextElementSibling;
                        while (next && !next.classList.contains('part-header')) {
                            next.style.display = 'none';
                            next = next.nextElementSibling;
                        }
                    }
                });

                // Khởi động countdown và autosaveTime
                startCountdown();
                setInterval(autosaveTime, 2000);
                updateNetworkStatus();
            });

            window.addEventListener('online', updateNetworkStatus);
            window.addEventListener('offline', updateNetworkStatus);
            if (navigator.connection) {
                navigator.connection.addEventListener('change', updateNetworkStatus);
            }

            document.getElementById('mobile-toggle').addEventListener('click', function(){
                document.querySelector('.sidebar-col').classList.toggle('show');
            });
            // Khi resize >1000px, đảm bảo sidebar luôn hiện
            window.addEventListener('resize', function(){
                if (window.innerWidth > 1000) {
                    document.querySelector('.sidebar-col').classList.remove('show');
                }
            });
        </script>
        <script>
        // Khởi tạo Bootstrap Modal
            var confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));

            // Khi click nút nộp bài → show modal
            document.getElementById('submitBtn').addEventListener('click', function() {
              confirmModal.show();
              exitFullscreen();
            });

            // Khi click “Xác nhận” trong modal → submit form
            // copy editors (Phần 5) vào hidden inputs trước khi submit
            document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
            var form = document.getElementById('examForm');
            document.querySelectorAll('div[contenteditable][data-code^="5."]').forEach(function(div) {
                // id của div đang là "input-5_1" hoặc "input-5_1_a" tùy code
                var id = div.id || '';
                var name = id.replace(/^input-/, ''); // -> "5_1" hoặc "5_1_a"
                if (!name) return;
                var value = (div.innerHTML || '').trim();

                var existing = form.querySelector('input[type="hidden"][name="' + name + '"]');
                if (!existing) {
                existing = document.createElement('input');
                existing.type = 'hidden';
                existing.name = name;
                form.appendChild(existing);
                }
                existing.value = value;
            });

            // bấm submit thực sự
            form.submit();
            });
        </script>
        <script>
            // 1) Chặn chuột phải
            document.addEventListener('contextmenu', e => e.preventDefault());

            // 2) Chặn copy (Ctrl+C, menu copy…)  
            document.addEventListener('copy', e => {
            e.preventDefault();
            });

            // 3) Chặn các tổ hợp phím Ctrl+P (in), Ctrl+U (view source), Ctrl+S (save), F12 (DevTools)
            document.addEventListener('keydown', e => {
            const forbidden = [
                { ctrl: true, key: 'p' },
                { ctrl: true, key: 'u' },
                { ctrl: true, key: 's' },
                { ctrl: true, key: 'c' }
            ];
            if (forbidden.some(f => e.ctrlKey === f.ctrl && e.key.toLowerCase() === f.key)) {
                e.preventDefault();
            }
            });

            // 4) Chặn lệnh in (kể cả từ menu trình duyệt)
            window.onbeforeprint = () => {
            return false;
            };
        </script>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
            if (window.renderMathInElement) {
                renderMathInElement(
                document.body,
                {
                    delimiters: [
                    {left: '$$', right: '$$', display: true},
                    {left: '$',  right: '$',  display: false}
                    ],
                    throwOnError: false
                }
                );
            }
            });
        </script>
        <script>
            // Cho phép drop ở mọi nơi
            function allowDrop(ev) {
                ev.preventDefault();
            }

            // Dùng chung cho cả drag-item lẫn drop-zone khi filled
            function drag(ev) {
                const tgt    = ev.target;
                const value  = tgt.getAttribute("data-value") ?? tgt.textContent.trim();
                const source = tgt.parentElement.id === ""
                            ? tgt.getAttribute("data-id")
                            : tgt.parentElement.id;
                ev.dataTransfer.setData("value",  value);
                ev.dataTransfer.setData("source", source);
            }

            function drop(ev) {
                ev.preventDefault();
                const value    = ev.dataTransfer.getData("value");
                const source   = ev.dataTransfer.getData("source");
                const zone     = ev.target.closest(".drop-zone");
                if (!zone) return;

                const targetId = zone.getAttribute("data-id");   // ex: "6_1_1"
                const parts    = targetId.split('_');            // ["6","1","1"]
                const question = parts[0] + '.' + parts[1];      // "6.1"
                const poolId   = "pool-" + parts[0] + "_" + parts[1];

                // 1) pool ➞ zone
                if (source.startsWith("pool-")) {
                const prev = zone.textContent.trim();
                if (prev) addToPool(poolId, prev);
                assignValue(zone, targetId, value);
                removeFromPool(source, value);

                checkPart6Complete(question);
                return;
                }

                // 2) swap zone ➞ zone
                if (!source.startsWith("pool-") && source !== targetId) {
                const srcZone = document.querySelector(`[data-id="${source}"]`);
                const srcVal  = srcZone.textContent.trim();
                const tgtVal  = zone.textContent.trim();

                assignValue(srcZone, source, tgtVal);
                assignValue(zone,   targetId, srcVal);

                checkPart6Complete(question);
                return;
                }

                // 3) các trường hợp khác (vd. zone ➞ zone cũ hoặc zone ➞ pool)
                checkPart6Complete(question);
            }

            // Hàm kiểm tra toàn bộ ô trống của câu part-6 đã được điền chưa
            function checkPart6Complete(questionCode) {
                // Lấy container bằng ID "q-6.1", "q-6.2",…
                var container = document.getElementById('q-' + questionCode);
                if (!container) return;

                // Lấy tất cả drop-zone bên trong
                var zones = container.querySelectorAll('.drop-zone');
                if (!zones.length) return;

                // Kiểm tra đã điền đầy đủ chưa
                var allFilled = Array.from(zones).every(z => z.textContent.trim() !== '');
                if (allFilled) {
                markAnswered(questionCode);
                }
            }

            // Xử lý drop lên pool để trả đáp án từ zone về đây
            function handlePoolDrop(ev) {
                ev.preventDefault();
                const value  = ev.dataTransfer.getData("value");
                const source = ev.dataTransfer.getData("source");
                const poolId = ev.currentTarget.id;
                if (!value || source.startsWith('pool-')) return;

                // Trả đáp án cũ về pool
                addToPool(poolId, value);

                // Xóa nội dung ở zone tương ứng
                const zone = document.querySelector(`[data-id="${source}"]`);
                if (zone) {
                assignValue(zone, source, '');
                // Có thể gọi lại checkPart6Complete nếu muốn remove highlight khi unfilled
                }
            }

            // Gán hoặc xóa value cho 1 drop-zone
            function assignValue(zoneEl, id, val) {
                // 1) Cập nhật UI và input
                zoneEl.textContent = val;
                var input = document.getElementById("input-" + id);
                input.value = val;

                // 2) Tự động lưu
                //    id hiện tại có dạng "6_1_1" → code cần gửi là "6.1.1"
                var dotCode = id.replace(/_/g, '.');
                autosaveSingle(dotCode, val);

                // 3) Thêm/draggable xử lý kéo tiếp
                if (val) {
                    zoneEl.classList.add("filled");
                    zoneEl.setAttribute("draggable", "true");
                    zoneEl.ondragstart = drag;
                } else {
                    zoneEl.classList.remove("filled");
                    zoneEl.removeAttribute("draggable");
                    zoneEl.ondragstart = null;
                }
            }

            // Xóa 1 item đã dùng khỏi pool
            function removeFromPool(poolId, val) {
                const pool = document.getElementById(poolId);
                if (!pool) return;
                pool.querySelectorAll(".drag-item").forEach(item => {
                if (item.getAttribute("data-value") === val) {
                    item.remove();
                }
                });
            }

            // Tạo một drag-item mới trong pool với giá trị val
            function addToPool(poolId, val) {
                const pool = document.getElementById(poolId);
                if (!pool) return;
                var item = document.createElement('div');
                item.className       = 'drag-item';
                item.setAttribute('draggable', 'true');
                item.setAttribute('data-value', val);
                item.ondragstart     = drag;
                item.textContent     = val;
                pool.appendChild(item);
            }
        </script>
        <script>
            // Lấy phần tử modal và tạo (hoặc lấy) instance Bootstrap Modal
            const reportModalEl = document.getElementById('modal-report-error');
            let reportModal = null;
            function getReportModal() {
                if (!reportModal && reportModalEl) {
                reportModal = (bootstrap.Modal && bootstrap.Modal.getOrCreateInstance)
                    ? bootstrap.Modal.getOrCreateInstance(reportModalEl)
                    : new bootstrap.Modal(reportModalEl);
                }
                return reportModal;
            }

            // Mở modal khi click, ngăn submit form cha
            const btnReport = document.getElementById('btn-report-error');
            if (btnReport) {
                btnReport.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const m = getReportModal();
                if (!m) {
                    console.warn('Modal báo lỗi không tìm thấy.');
                    return;
                }
                m.show();
                });
            }

            // Xử lý submit form báo lỗi
            const errorForm = document.getElementById('errorForm');
            if (errorForm) {
                errorForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const contentEl = document.getElementById('error-content');
                const emailEl = document.getElementById('error-email');
                const content = contentEl ? contentEl.value.trim() : '';
                const email = emailEl ? emailEl.value.trim() : '';

                if (!content) {
                    alert('Vui lòng mô tả lỗi');
                    return;
                }

                const spinner = document.getElementById('spinnerOverlay');
                if (spinner) spinner.style.display = 'block';

                // Lấy studentID và examName từ hidden inputs trên trang (nếu có)
                const studentIDEl = document.querySelector('input[name="studentID"]');
                const examNameEl = document.querySelector('input[name="examName"]');
                const studentID = studentIDEl ? studentIDEl.value : '';
                const exam = examNameEl ? examNameEl.value : '';

                const body = new URLSearchParams();
                body.append('studentID', studentID);
                body.append('exam', exam);
                body.append('error_content', content);
                body.append('email', email);

                fetch(location.pathname + '?reportError=1', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: body.toString()
                })
                .then(res => res.json())
                .then(json => {
                    if (spinner) spinner.style.display = 'none';
                    if (json && json.status === 'ok') {
                    alert('Cảm ơn bạn đã phản hồi lỗi!');
                    const m = getReportModal();
                    if (m) m.hide();
                    if (contentEl) contentEl.value = '';
                    if (emailEl) emailEl.value = '';
                    } else {
                    alert('Gửi không thành công: ' + (json && json.message ? json.message : 'Không rõ lỗi.'));
                    }
                })
                .catch(err => {
                    if (spinner) spinner.style.display = 'none';
                    console.error(err);
                    alert('Có lỗi khi gửi. Vui lòng thử lại.');
                });
                });
            }
        </script>
        <script>
        (function(){
          // Cập nhật đồng bộ, nhưng thay vì ép q-col có scrollbar riêng,
          // ta để content-col cuộn chính và làm instr-col sticky (desktop).
          function syncGroupHeights() {
            var groups = document.querySelectorAll('.group-with-instr');
            var isWide = window.innerWidth > 1000;

            groups.forEach(function(g){
              var instr = g.querySelector('.instr-col');
              var qcol  = g.querySelector('.q-col');
              if (!instr || !qcol) return;

              if (!isWide) {
                // mobile / stacked: bỏ mọi giới hạn
                instr.style.position = '';
                instr.style.top = '';
                qcol.style.maxHeight = '';
                qcol.style.overflowY = '';
                return;
              }

              // Desktop:
              // 1) đảm bảo q-col không có scrollbar riêng (để sticky hoạt động)
              qcol.style.overflowY = 'visible';
              qcol.style.maxHeight = '';

              // 2) đảm bảo instruction là sticky (nếu chưa set bằng CSS)
              instr.style.position = instr.style.position || 'sticky';
              instr.style.top = instr.style.top || '18px';
              instr.style.alignSelf = 'flex-start';

              // 3) (tùy chọn) nếu bạn muốn instruction có max-height = toàn bộ chiều cao của group,
              //    để tránh overflow nội dung instruction quá lớn, có thể giới hạn:
              // var groupRect = g.getBoundingClientRect();
              // instr.style.maxHeight = Math.max(groupRect.height - 24, 0) + 'px';
              // instr.style.overflowY = 'auto';
              //
              // Mình comment section này vì mặc định thường không cần, nhưng bạn có thể bật nếu instruction quá dài.
            });
          }

          // gọi sau DOM ready
          document.addEventListener('DOMContentLoaded', function(){
            // initial sync (sau 80ms để KaTeX/auto-render có thể bắt đầu)
            setTimeout(syncGroupHeights, 80);

            // Khi resize window => sync
            var debounced;
            window.addEventListener('resize', function(){
              clearTimeout(debounced);
              debounced = setTimeout(syncGroupHeights, 100);
            });

            // Nếu KaTeX hoặc các thay đổi nội dung render sau, gọi sync lại
            setTimeout(syncGroupHeights, 350);

            // ResizeObserver để cập nhật khi instr-col thay đổi kích thước (ví dụ KaTeX)
            try {
              var ro = new ResizeObserver(function(entries){
                clearTimeout(debounced);
                debounced = setTimeout(syncGroupHeights, 30);
              });
              document.querySelectorAll('.group-with-instr .instr-col').forEach(function(el){
                ro.observe(el);
              });

              // Quan sát khi group nội dung đổi (thêm/xóa câu) -> resync
              var gro = new MutationObserver(function(){
                clearTimeout(debounced);
                debounced = setTimeout(syncGroupHeights, 60);
              });
              document.querySelectorAll('.group-with-instr').forEach(function(el){
                gro.observe(el, { childList: true, subtree: true, characterData: true });
              });

            } catch (e) {
              // fallback: MutationObserver cho mọi instr-col
              var mo = new MutationObserver(function(){
                clearTimeout(debounced);
                debounced = setTimeout(syncGroupHeights, 100);
              });
              document.querySelectorAll('.group-with-instr .instr-col').forEach(function(el){
                mo.observe(el, { childList: true, subtree: true, characterData: true });
              });
            }
          });

          // Nếu bạn load questions động sau khi trang đã sẵn sàng, gọi window.syncGroupHeights()
          window.syncGroupHeights = syncGroupHeights;
        })();
            
        (function(){
          function initSplitters(){
            var groups = document.querySelectorAll('.group-with-instr');
            groups.forEach(function(g, idx){
              var instr = g.querySelector('.instr-col');
              var qcol  = g.querySelector('.q-col');
              if (!instr || !qcol) return;

              // đảm bảo flex behavior
              instr.style.flexShrink = '0';
              qcol.style.flexGrow = '1';

              // nếu splitter chưa tồn tại (đã chèn bằng PHP thì ok, nếu ko vẫn tạo)
              var splitter = g.querySelector('.splitter');
              if (!splitter) {
                splitter = document.createElement('div');
                splitter.className = 'splitter';
                splitter.setAttribute('role','separator');
                splitter.setAttribute('aria-orientation','vertical');
                splitter.tabIndex = 0;
                instr.after(splitter);
              }

              var minPx = 160;        // min width instr (px)
              var maxRatio = 0.8;     // max instr width relative to group

              // load saved width (percent)
              var key = 'instrWidth_' + (g.dataset.groupKey || idx);
              var saved = localStorage.getItem(key);
              function applySaved(){
                if (saved && window.innerWidth > 1000) {
                  var pct = parseFloat(saved);
                  if (!isNaN(pct)) {
                    instr.style.flex = '0 0 ' + pct + '%';
                    instr.style.width = pct + '%';
                    qcol.style.flex = '1 1 auto';
                  }
                } else {
                  instr.style.flex = '';
                  instr.style.width = '';
                  qcol.style.flex = '';
                }
              }
              applySaved();

              // pointer handling (mouse + touch unified)
              splitter.addEventListener('pointerdown', function(e){
                if (window.innerWidth <= 1000) return;
                e.preventDefault();
                splitter.setPointerCapture(e.pointerId);
                document.body.classList.add('dragging-splitter');

                var groupRect = g.getBoundingClientRect();
                var startX = e.clientX;
                var startW = instr.getBoundingClientRect().width;

                function onMove(ev){
                  var dx = ev.clientX - startX;
                  var newW = startW + dx;
                  newW = Math.max(minPx, Math.min(newW, groupRect.width * maxRatio));
                  var pct = (newW / groupRect.width) * 100;
                  instr.style.flex = '0 0 ' + pct + '%';
                  instr.style.width = pct + '%';
                  // qcol tự động chiếm phần còn lại nhờ flex
                }
                function onUp(ev){
                  try { splitter.releasePointerCapture(e.pointerId); } catch (err) {}
                  document.removeEventListener('pointermove', onMove);
                  document.removeEventListener('pointerup', onUp);
                  document.body.classList.remove('dragging-splitter');
                  // lưu percent (number)
                  var finalPct = parseFloat(instr.style.width);
                  if (!isNaN(finalPct)) localStorage.setItem(key, finalPct);
                }

                document.addEventListener('pointermove', onMove);
                document.addEventListener('pointerup', onUp);
              });

              // double-click để reset về 50%
              splitter.addEventListener('dblclick', function(){
                instr.style.flex = '0 0 50%';
                instr.style.width = '50%';
                localStorage.removeItem(key);
              });

              // 반응: khi resize window, ẩn/hiện/restore kích thước
              window.addEventListener('resize', function(){
                if (window.innerWidth <= 1000) {
                  splitter.style.display = 'none';
                  instr.style.flex = '';
                  instr.style.width = '';
                } else {
                  splitter.style.display = '';
                  // reapply saved after resize (thoải mái)
                  saved = localStorage.getItem(key);
                  applySaved();
                }
              }, {passive:true});
            });
          }

          document.addEventListener('DOMContentLoaded', function(){
            initSplitters();

            // nếu bạn thêm groups động sau khi trang load, expose hàm để gọi lại
            window.initSplitters = initSplitters;
          });
        })();
        </script>
        <script>
        (function(){
          // Áp layout cho mỗi .options-wrapper dựa trên data-maxlen, window.innerWidth hoặc width thực tế của question-card
          function applyPart1Layouts(){
            document.querySelectorAll('.options-wrapper').forEach(function(wrapper){
              var maxLen = parseInt(wrapper.getAttribute('data-maxlen') || '0', 10);

              // Lấy width thực tế của question-card chứa wrapper (nếu có)
              var cardEl = wrapper.closest('.question-card');
              var cardWidth = cardEl ? cardEl.getBoundingClientRect().width : Infinity;

              // Xác định xem coi như "small" khi window nhỏ hơn 700 OR card thực tế nhỏ hơn 700
              var isSmall = window.innerWidth < 700 || (cardWidth < 700);

              // Ngưỡng nhỏ / lớn dựa trên kích thước hiển thị
              var th1 = isSmall ? 15 : 40;
              var th2 = isSmall ? 30 : 90;

              var newWrapperClass, btnWidth;
              if (maxLen <= th1) {
                newWrapperClass = 'd-flex flex-row flex-wrap align-items-start';
                btnWidth = '25%';
              } else if (maxLen <= th2) {
                newWrapperClass = 'row row-cols-2 g-2';
                btnWidth = '50%';
              } else {
                newWrapperClass = 'd-flex flex-column';
                btnWidth = '100%';
              }

              // Gán class mới (giữ lớp cơ bản 'options-wrapper')
              wrapper.className = 'options-wrapper ' + newWrapperClass;

              // Áp style width cho từng .p-1 trực tiếp con (như PHP trước đó đã dùng)
              var items = Array.from(wrapper.querySelectorAll(':scope > .p-1'));
              if (items.length === 0) {
                // fallback nếu :scope không hỗ trợ - chọn con .p-1 sâu hơn
                items = Array.from(wrapper.getElementsByClassName('p-1'));
              }
              items.forEach(function(p){
                p.style.width = btnWidth;
              });
            });
          }

          // Debounce resize để tránh gọi quá nhiều lần
          var resizeTimer = null;
          window.addEventListener('resize', function(){
            if (resizeTimer) clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function(){
              applyPart1Layouts();
            }, 120);
          });

          // Chạy ngay khi DOM sẵn sàng
          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', applyPart1Layouts);
          } else {
            applyPart1Layouts();
          }

          // Nếu nội dung thay đổi động (ví dụ group đóng/mở) thì có thể gọi lại function:
          // document.addEventListener('someCustomEvent', applyPart1Layouts);

        })();
        </script>
        <script>
        /* ===== Toaster (temporary notifications) with ding sound ===== */
        (function(){
          // tạo container + style nếu chưa có
          if (!document.getElementById('examToastContainer')) {
            var style = document.createElement('style');
            style.id = 'examToastStyle';
            style.innerHTML = `
              #examToastContainer {
                position: fixed;
                top: 12px;
                left: 12px;
                z-index: 20000;
                display: flex;
                flex-direction: column;
                gap: 8px;
                align-items: flex-start;
                pointer-events: none;
              }
              .exam-toast {
                pointer-events: auto;
                background: rgba(0,51,102,0.75);
                color: #fff;
                padding: 8px 12px;
                border-radius: 6px;
                box-shadow: 0 6px 18px rgba(0,0,0,0.15);
                font-size: 0.95rem;
                max-width: 320px;
                opacity: 0;
                transform: translateY(-6px);
                transition: opacity .24s ease, transform .24s ease;
              }
              .exam-toast.show {
                opacity: 1;
                transform: translateY(0);
              }
              .exam-toast.warn { background: rgba(255,193,7, 0.75); color:#fff; }
              .exam-toast.crit { background: rgba(220,20,60,0.75); color:#fff; }
              .exam-toast.success { background: rgba(0,128,0,0.75); color:#fff; }
              @media (max-width:700px){
                #examToastContainer { left: 8px; top: 8px; }
                .exam-toast { max-width: calc(100vw - 32px); font-size:0.92rem; }
              }
            `;
            document.head.appendChild(style);

            var cont = document.createElement('div');
            cont.id = 'examToastContainer';
            document.body.appendChild(cont);
          }

          var toastContainer = document.getElementById('examToastContainer');

          // ===== sound helper =====
          // tiny wav beep (very short). If you want a different sound, replace data URI.
          // Note: autoplay policies may block sound until user interacts with page.
          function playDing(type) {
            // choose pitch by type: crit -> high, warn -> mid, default -> low
            var pitchHz = (type === 'crit') ? 880 : (type === 'warn') ? 660 : 440;

            // Try audio element first (data URI embedded)
            try {
              var audioEl = document.getElementById('examToastAudio');
              if (!audioEl) {
                // a very small generated click-free beep WAV (mono, 8-bit, 8000Hz) encoded base64
                // (kept short to minimize size)
                audioEl = new Audio(
                  'data:audio/mpeg;base64,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OM88zjzOPPrszzys4sHlg8sHGecVnljszjyvssHmccVrFpS0qbBactOmwBryweWDvKzis8zjywf5nnf5WeZ55WeVnFg4zuzPOM48sH//8Da1Q70GB3v/////////////////////Bgd6TEFNRTMuMTAwqgCM6wc18LDFtQNIA8wcDzHZkMTifzEwnMdmUwcDzHYOMCiYxMBTAgnKwuYWCwGSxgQTFgCeYEAgVBSnJgoForqNtX8sAAOARgEANUSNSQ9JJI1nb5KNIrKcepwo0o0o175M4TbfFnT5s4URQK////QKLTps//////lgWlg6GdF+Z0zZweDGEWhFhiqQagbawKEmDNAg5gFoF8YDoAWlYDqVgOhWAW/////+DAHhEB8DAeA8GAPgwB0IgOBgD+IriLCKBcOIrEVC4cRURcRWFwkRb+F1gw0MNhdaF1vC6wYYMPC6wYcLrwbB4YaGHCIGv//CIG/B//PkZPUjgbkAAnP20SkzYdQA9S68gGgMDQGsGAa///CILQiCyBi/PGBsJV+Bp2DqBgsDoDA6gwFv///oK1VTE+KaMT8T8wXgXjDZDZMNgF4ymhPzE+E/LAnxhshsmC8C8YLwLxWC8YLwLxgvgvFYOhgWAWGF+BYDLwMvBG8EbwRWga1YDOkGLQNasgxaDFgMWAxYDFgH1WAxbCKz+DL2BrFkIrANasBiwGLP////CJWKBlYhWN///////////gwBewYAvQYAvfhEBe///////+EQT4DBPgpoDFVApsDBPwT6TEFNRTMuMTAwqqqqqjhwoSwUBlCIJn4Rhg0DZimDZWMoGGAsDIYLkyVhiYLguYdgeWA6MDgPMGwbMGhTMGwaMBQnKwFLATmAgCFpECwMFhWCxadRssAWEAqWAKRUU4U4UbUaUaU59RsOANqxWALVCsAVSNVVL6pWqqkVKqf2qKkQKLTFpP9AtAstMmymyWl//////KxAK1fOHT+M/j/MV4h00V/eD0+jLMtsKAw/goSwCAWAQSwCB//////wiGwiGgiGvBgb8RYRQLhguFwuFEVEVEUiKCKCKRFYXCRFxFoiwiuFw4iwi8RcIgsReIoIqItiLBcOIphcIIoIoIqF//PkZPIjsbT+AHfV0CfrYdgA9S68wwMBwRB+EQf8Ig7/wiDoMB4GDgd///wiQQiQANQKEDr+8A68oAMgEADIBBK1VCsT/ywC+VhsGC8C8YwAWRjAhZFgLMw2A2TBfBfLALxgvgvGC+C+VgvFYWRWFn4GsWAxaEVgRWgfVaBrFgGtWBFYBrFgH0WAxYBrVgGsW4RWBFZ4MWQiPBg4DHDoRHhEcER3CK2EVgRWf4RWAaxaEVoMWf/8DDvB5UDa1B5QGB3n////////////////////+BgwISgBgwIMCBgwIFnVTEFNRTMuMTAwVQmv4MYsOh5tfnUF+YsFhnU6hDdMFB8wWCjD5HNVgsw+HzFosLAsM6iwrFhi0WmdBYYnIxgUCmBBMYFE6bIGMQEC/oFlYxQLTZKwugX4UBQVBanKKyK5YBanKnIQFFGgoCisFepz7VGriAAqmVMqZUrVv8sATysC+VgXzAgELAEKwIWAL///+WBYWBaWBYYsOpwdfHm4McGX5gqADqYfKE6HKJizRiFoBYYF8A6mA6gFhWAWf/lgAtKwC3///gwDQGBsDYGBsDUDA0BsGAbwiBr8LhBF8RYRULhcLhRFMRQReIqFw2IuIsItEUEUiKRFxFRFguGh//PkZPUlcbT6AXP20CVTaegA7mx8cIIqIqIviLBcMFw4ikLhhFP+EQHf/hEB0DAeA6DAHf//+BgsDqBh1RaBmaF+BgsBYDAW///+k5LkoxfF8rF8rF8sGyYvmx5mwLxi8LxWOhjqOpWFh2WFiw7LT7OM48zjyuz/8rOM48rPKzywd5WeVnmccZ53lg4rOKzvLB3+gUWnQLTZQKQKTY/0Ck2fTYLTlpPQL////gbvPKBHyoMd5/////4MAd+DAHBEB34MAfgwB/////////////AwWgsAwWC+Aw6gsCILQYCxCZ+Lea3u+aa7WZMFmY/kyZMnKY0hOYCBMYjBOY0GcZniMZFjSYpkYVg2YpkYWAPMDhkLAymMgHGHYHFYHGHYdFYNFgUisGvMGxTMJwELAClgJzEcJvMBQFRVRVRWRWKwKCAX8wOA4rA4sAf/lYH+VgcgUBQWQLTY/02CsF0C02PQLQK9NhNhNhNktL////5g0DflYNGKYNm7xGHtRGmt4pGDvgtxhCxxybrmg3GKzBfJg04NMYGIBilgCMMAbAUysBSKwBr//ysAbKADWDANYRA0BgbA2EQNgwDWDANhEDeDAN/xFBFcRTEUEVEXEUEWC4YRcRYRaFwgXDCKCKiK//PkZP8nXbT2AXf20Sd7YcQA9S7shcKIqFwn4XDiLCLiL4i4XDBcPxFBFxF////CID4RAeDAHf//8DA2FMDA2MQDLelADLcFIGAaBgGv///Sclz0Zi3C3mLeX4ViKmIqSOZI4ipl+i3mLeLeVi3lgbrzDPDOKxujG7DOKwzisJkwmBTysJjysJgsBM//+ViKeEdwHu3uB798I7gOdOA508IzwjPA588DnTgZPgzfhHf4MnYMnhGeDJwRnf4RAz//8Ig8gRB5QYDyAZPeb6AeRCf7gwXr////////9/////////////CISODARX///6U4dGkxHM4zPTM0REcrAQwFCcsDQViOVhOY0kUYjgIVhMYTgIYCAIYjBMYTAKYCiMWAFMBAFMBQnLACGI4TlYCmAgClgJzCYJysBTBYFisF02C0xaUsAsYAgD4gAD2rCAAWqqNorlYFIrlYFoqKNqNIF+gV6BSBfpsJsFp/QLLTJs+gX5aVAv/KwF8sAKWAELAClgBDAUBDCYJzCcaDM4RzhwzzM9VDARwhwwqUm5OCsC5zFIgh0wf0FUMDOAzzAJwGjywAClgAFKwATywAClYAJ/hECQYBAYBQiBQiBQMCAUIgUIgUIgSDAKDAIDAIDAID//PkZPEpTd76AHf10CLjXegA7mx8AIDALBgEFUKxFX+KzishqwVQrIrIrIrOKxG6N3G5G4N8UGNwbwoIbuKCG6N3G7FAxuA2D////DD///+DAKBgQTgasqgG7pmBu40gYmEwGBQKDAL//FXxVxVnO46mOgWGFpflgDzA8ZTJEOzHULDCwdDCwLTBcFgMZBaYrOM48zjis8sHH0eV2ldn/5XZ/+V2//li3y0hacDwlaxYWTZLToF/5aUtOWnQLLS+Zx/+Vnf/lZ/8IgPAwHAO//8Dd75UDd67z/////wuuF1//8Lr4XW+F1/wuvC6//////wiA////+DBZDgNBSMIwacwxSwTIXE0MIwW4wjQxDCNE1LAmhgNhGGCmCmYfgYphGgNlYKZgNANGCkCmVgNmEaCmYRoRpmjRWaOmaM0aM3TLBssGjNUzNUzNUzNGzNGjpG/LBowicwqYwiYsBCtMVhTThTChDChSwENMEMImKwhhAhhQpaYtKBlpactOWlQK8DLf8rHmPH+Vjv//Kx3BgA3BgCmBgDYA2DABoGAKYGBGARgGEogt4GHtgt4GEoAt4GDTil4GMkDsQHTmLooGNAiQgGC3gYoGC3ARgGANgRoGANgDQGApgDYGANgDYRA//PkZOYsAbTyAHtXXC2TYewA5i64GvCIA1CIA3wMAaAG4RAG4RAGwYANQiANAwAa4RAG4YaF1sGwZBsGQbBwXXhhwbBv8MPEUiLiKYiwiwi4i4i4ikRfxFYiwioXDiLCKcLrwuvwuv+F1/wusGHDDBhww3//8IgDYGANARgGBig0wGFggDQGC3AKUDAUgFI/8TiwTjXZOMnk4yeTzJ5PMRiIzGYysxmCCr5ggEmLwSYuBJggEFgRGYhEViMrQaUmhBpQWAGAJg6Vg8rD5YCYQGAHqMIBSxFAIgGUYK4f5WkrT//6jCjKiSiaiSjP+gGUY9RJRNRP/UTB4lGFGP/CICeBgJwF2EQE4DAuwLsDCag0UDMIhBwGAXUGAJ3////////xKomv8TTE0Eqia/EqiagwBO///////+BgCgCOBgIwDuDAEeo2ewjKA3M5xwBU4wURRIpGJySYWAhcMwcRzHpBCw0MHg8skYaB5aww0FzBI0AQTTEEgqpiX1RZEYFXeJAswEBlTNUaCu8mA0GuwIQC5ajbAWBMEau/LlsAa4wTFfrZX0ctylOaKgbZlEGxqD4OomWvyqunInS2RsrZvXZ5fZdxZIwUNTLoZAaeElyYhgqJlQV3H5iOWZUpPBhk//PkRJohofz+AHPV0EGb+gAo36ukA1mAWAUYJwE5fpdzZS/K7mye2ZdoxBdi7GLF0MUQXCxwYmMQQXF3GKLoXZKSVJUlJKEoOYS2OYOYSklSWkv5F+RbIuWC2RctlstliWix5a4/Y//+P38hf/F1/8QUBukBjsQgbKGIGMBqKKF5DE/////8fxQPcyDGVcznYBbEYMwkC+YAIGVD4OKk/SE3MBQMy5QURQQKBQQMfKgUfmAgIsGigAkaCAtczO10iQwJA7ZUqmdg4bLAKkktVnabatit8EKMoMv5CFJwQu1CW1VPlyHJk6jCiSPThSRU0mk7VnEZHDrqOi+D4vl7OPSOZ0XKMGKzTRMFqQtMmHQJGY9jNB+AhZGVOOQYVgLJgDgDGCAA+kkzp8UkWdPh75M5fJ8XxfP3wfNnaSD5f75M7fH3zfB8SUkqSpKSUJQcwlscwcwlJKktJfyL8i2RcsFsi5bLZbLEtFjy1x+x//8fv5C/+Nz/4oAMDAFHYDeBDAyEKwukGUG9/////4/1CdUE5s6Zmzu0WCMVgUsGgzSRjNInMCkYxMaCwJjE5HMCAUwKJjAgn8wKJzIwEMICAQgEQgDzE4EMCgQwIJ/MCiYQgEwiAA4ABwCMAAEQgFq3//PkZFEiaej8EHP10CpzYegA7m64iAACEAlYAVOqVAotMmygX6bJaRAtqrVmre1VqvhwAVImymwWmQK9NlAtAv02f///ywBfLAnKwIZHNJs5WHMncatVpgNAYQYj2D+msomGxg/gHeYKoBFGATAI5gNACMWAAUwCYAEKwAQrABSsAFKwAX/8RURYRSIqIqIrEWxFP8XOP0fh+IWPw/yFIQXMLkFyj9ISLnIWNzjeigsb43o3o3xufjdFAYYf///4Yf///wiJwMTEcDArvAyMBP//jcG7/jcPCH8Kzv8xAEAxAEArKErKExAKExBEExSFIsA2YNA0YTCMYTiOWAFLApmDQNFYNG02VtG02VteWGyw2VtFhsrF8sCeYs5iCeYghWL5WJ//5WIWBDFF/ysVFVFdRr1GlGvCpajf/5YE//MWYrF8xBP/hEDuwMLbC2gMvDC2//////BgAJ//wYAC+Fw8RbC4TEVEW8RbiK4XDcRX///////8GAAoGAjAEwMARz1JZjWcZTEpzjP4MTJgSjEoFwMShkwC5gsJRjKchaQwxEowXErzDEMDDEMTDAFjDEMPAgYJsmC4LlpTBYFywGJaQDBYBAWLTpsgUMAIC6BSKhYAtFcIBYIBZRtFf1OA//PkZF8jJcD6AHf00CyDYbgK9y56oDynBYAvywBSKgEBctOgX5aRAv/9ApNny0v/5YBYtN6BX/6bBaTy0qBQEBcxLGQzMDA3oGUz/RgwU0CyMHPSFzVVBSQxDwLrMBLAmDAsgH8CACxgGAAuWlQKLTf//8GwfhhsLrQusF1wbBv+N0bkb8bo3Bujeig43cb43xuigBvDcjeG4KBjfxvjd8bg343sb+N7G8Kx8ViKqGr//xWf+Kv4qviqDVoatAwF8DQIANAhAaBAwAKvFYishKfpLypvKneGd6d75kWo7GRaRaWEdiwRYWCLTKaKaMT4T4xPhPytZFazNZ4Ernxz+fnP58ayWZYWRWs/Ofz4rn/lc///8sT8rn5z+flhZ+WFkVrM1ksytZFayK1n/layK1kWFkWFn/+EQLOEQLP8IgWf/8GDLmEZIEHkgX/6///r4RAXv4MAX/gwBe8GAL//4MAX/wYAv/BgC/CKRn///r9///+EQYAGAwIGBZgWX///yao2aZsyZDE21DA20Jk1NP8CjKYYhiVjKYyBiBAXMmAwMSwXAglmGAYFYYGJQlmJQLgZMDBYMDBcSk2TDEFjBcMTDAF02U2C0wFBYtKWmAoLgQF0Cy0wFBdApAv02AMF//PkZF4lebT4AHf10CiTXeQA7i64haT0C0CkCi0qbBadAsrBctMgWgUgWWk9AtNhAv/9Avy06bCbCbKBSbHpsoFFgF0CywCxguGJiUPxlmf5ozG5iUGJgMoAsYzsKsG6bHoZgmQD+YGaBmmB/AMibIGAlDAMQDHy0xadAv//C68MPDDBdcMPC68MP/G8KAG4N8UAKCG+KBFBxvRvDdFBjfFADcxvRuDfG8KBjcig8b8bw3o343BvjfG7jejdFUKoVcVYq8VcVfFUKx/xWRVisxWBVf/+GHAFJYGMUyAMlAuuF1zsQ2PMXxe8sFl5YC00GC0wtC0wPGUyQA4rDsrDow6DswPDosBZ5WOhuebnlh/gS4HcWlLFwO0sPLDit3lhxucVuNzjc4rf5Yd5aZNhArwJctMBLoFFpi0haVNny0iBSbCBX+WHeWHlh3lh5W////wYHeAZbcHef////4YbDD8MOF1guv+F1vhdb/+F1oYb//hdbhh8MNDDf/8MMDYP//+EQCwAjjYRjaBoTQxPzaARzHZbisdzHoCTC8CCwURiOn5iMO5juCgNCYsAgDh7N0HMOGDO5lIxYKHHjGUKmvEmuEGIElgQWBJgDhYAmcAFZ0sHSwACwdMYLhwwYFyw//PkZFsmXdD4AndXTyPimegA7qx8WDmHDqJqJqJoBlEkAyiajJfdd5fVAn/rubIuz/KxH//lYgxAn//hEAVCIAoBgIwCOBgn4AqBhM4G0BgbQAqBgCgOmBhyKdIBod5GaBiXYFQBgbQJ+BgI4DsBgOwAqBgCoAoDABUIgCn+Hkw8weaHkh5g8nDyQ8+IK8XcQXEFRdC6F2ILjExdiC4gsMUYogvi7EFRdDFi6F0LsXX/F1GJGILoQWEqE1+JrE0iaCV+JrwxRE1/iaf4YrxNcSoTQDAFgG8DABwDoGACwYqBgAMJp/iVxNRNP//SeipOZdieYnl2YRhGYRjEYRFGYxBEYxlGYRhGYRFH5hEEZokZYxmiRGvXGvXGIEleMrRlhGWEfla4rEGJEFYjysT/lYkrEf5YE/5YEGJEKJoBkAyiYOQKJIBlEmyNnLINnXaX5Xau9s3///gwiuBnlvUB/sPL///////Dy4eXDyYeeHlw8n8LI/h58PJ+ETyVOqs8wKBTVt1MLBcyWMCwZTI4nMTicwIJzCxLMLhYwuFggeBBnMFAsyMJjAgEMTicwIJjEwEMTCYrEyBYEC5WFwKFysLoFlpQIFysLoFoFoqBUFIqIrKcFYL9qxWAGqhwjMAA//PkZGMjKez8AHP10CGSigAA5mw8BUjVA4AqmKwCqYQgD/9UjVCsC/5YAn/5gUClYF////LAE/ysCmBAIYFNJkZWGipkYmVpgugLqYl6MGmr2lDxhc4DSYAIA0mACgExWAjFYCOYBMACFgAEKwAT///FUKyKsVmKoVkVYrHFUKqKrjdjfG9G8NwbwZQb2N0bwoLG4N6Nz4/x+4uUhIuTIWP8XMP+LlFzxQX/G7G7jexvxvxQEbw3Mb0bn//hhoAiZAGMEMP///xVfFYOvKA38QCwQSsplgNlZSK5zFmLEynAQqiqVimIKYohYnKxCsVTlFf1G1OVOFOFOUVvLAnlgTywIWBfLApYE//xFxFhFRFsUAN3FARvDfG/hcIIv//+DAggwIAGEAIIGEEUAG/cf4GEAIH//xuRuRuxvjcG+Nzjd/+N2N+Nz/xvDexvf43fjdUwmM42WM8xoh00zO4xoEcwFCYxoGgwnCcrEYxHO4xGEcxGGkwFCcsAIYTBOYThOZFAKYTgIYTBOYCgIYTAIVgKVhgWnAgLgUFi0/gQFisFywC4GCwtIHAGIQRVIqQsAA1dqynAUAtFYsAWEAspwo0iugUBAWLToFlgFy0vlpU2PLAC///5WAhYAQrAX/////PkZI4kIbT4AHfV0CEKlfgA5qwc8wFAQsAIVgKWAFMJxGNEBHN/EzMzzuMVUj41Ivfzl2tMNQAIsxlwRjBoAnMEcCYsATmAIAL/lYAn///FWKwGrRVxVxVRWRVCsCrFZishq+KrFX4qsVgNWBq+KvjcG94343hvCgsbg3xvRvDfjexvigvxWMVmKvDVorOKyKuKz+KuKsVX//wMCmgDZ4nBgFBgFBgEPeqEyCoTIJA8rNGbpHHCGECmFCHbFGLPhU/5pghhQpmjXmaNGFCGFCFgJ4QXCgpRtRtThFb1OFOAoL8LhxFAuGEVEWxF8LhhFQuHiLYiwi4iwXCRFxFxFPxFBFRFv/+ESRgc4RBAwQf////+N8UBG/xv+N7/jf+NzG5jeFB8b4oDjcxuKgAKczPxpkygULGswuZLC4FGBYGBjElAYXmMQsBkuVhcIC4QPggKFpS0gFC4GFybJacwsFhAERAAWrlYBKwAHAJqypzAIBVI1dRF8GdJIpHJts4VOqRUwgAKpWqNXap/qn9q7VPVKqT1OfUa/1GlG1OP////LTf5YC4EJRhcllhMmZQsYWYGJlPJrH+8vqZdQJRhZgLgYMgDAxgYCwtP6BSBf//jcjfFAeN/G5jc43SUHOHO//PkZLMgLhEAA3PU0SArYgQA5mwYIAOaShLEtkuOYS2OcOeSo545/8lhz8c4lMc8lSVJeSw55LjnyUHOktIQfx+ISQuQvj9H+Lm5CELkLH7//8NWgOIAb4iKqKz//////6/KpwwKGRyMYVCpoTFhAGIG6AfYJW4Fx1OjWWU8p4sDqMKJoBlGFGUAiiSAVAIgFUSQDwxUJoJpE1FzC5ZCkJFyjEiCouhdBY4IKjFF0MQXUXQuxdxi///8GAUBgFMDAqQ0DWUKgDAqBX///////////+P3/IX/+P38hf///DyhZEHnMvJFN7A7MOhkK3tNai9MvBkMvC9MkA6M6BkMDhlMOwOKyQMDwOMkAPMZQ7MDg7MDhlKyQMDxkMZRkKxlMDgPKwnLACmAgCFYCmE4ClgDisDywBxWHZgeB5WHZh2B5YA8wPA8wPA4sAd5WBxaTzBcFy0vgYLQIC3oFAUFkCwIC/+WlLTf/lgDv//Kw7KwO8sAf//5WB5geBxYA7zA8DjDoOzWsZDptPjT4kTCGAT8xSkzQMPNQYzF1wnAwWoETMC9AkDADgDswA8A68wA8APKwA7///gwL//wYFhqwNXiqFWKyKsVgBoEGrBWBWBVCrgNA8NXisir8VkVgVYr//PkZPwoObb0AHf00ChjXewA7my8Aqg1fFWKxisBq+KyKyGrBVisCrFYFY4rENXCs4av+KqGrRVisYrMNXRWBVYauFWKuKrhdYLrcLrfwBSwGXlgwuF1wwwXXOJTlM5AjMIiiMIgiLBRFgYis5TGIYvKwiMIgjMIwjKxH8xGBQwjGP/OKM4ovLERYUK1CwqWFCtTyuM44yxH/oB/BiCjCiaARRgrBKwfKwCwAVglgH/QC+gEQC+ol/lgAsAFgHywD/lYBWD/////liP/LERxxnHGVxBEEYMHIB8sFGEQRAYIgRwiCMGAi////////////////////8IgVBgFKkxBTUUzLjEwMKqqqqqqqqqqqqox+jYwWDExkhwxlBYrGUwXBcCBgY/hgYliWYyFkBgvMZRLLAYFgMTBYFjEsZTDAFzEsZSshMAADERAwAQ8xYWLSgQXMXFy0haYCCxacsCxacIFlGywFoqGFhanKjZacDF/+gWWnTZAxcHADVGrKnVKIAH/ap6bBaVNlNn0C02S0ybH+gV/psFpECwILlgWLCWVixpeYBOUDAFwBcDA/xl0DYqxvYDE8QWYDBmgLMDAfwDAGwaEQBcDAFgBYMN4XWhq7FZ+KsVgVQq8NXcNWhq4//PkZNAjecz4AHd3Xh/ilfgA3ix4VmKx4rMVkNWCsisfFZFYjd4oEbkb43xuYoMbkbkUAKCFAjf+KwKoVYqoqoasisisirxWYqsNXxVCq/4i3/xFxF8RQBQA84i3+IvEUP+oSv+K6EsDRjQ2akpnGqZjQ2WFMwVGKyYrJgi6K5Ws97//LBSsnorFhanCK6KnlZCsn+WCKcqc+iqo0pwpypwpwpx6nCKiKynAXDiL+IuIsIpwuE4XChcP//4RNMDG1/////43sbuN7G5xufG+N4b8bnG/G9G9438bg3/xvDfqPOf3M/84NX84NvBANXxBMoRAMxSNMjCMMjQaMUzFMGgbKyMMjSNMGwbMUyMPJlN0PMe7PKOMcOKxxu3ZhExYCGnTGnTGnCf5jxxWPK3ZjhwELgZcVlwMuTYQLQLKxxjh5jhxjhxjh3lY8rdqNGKFKNIrqNorqcFYtFUsDisd/+Vj/N0OKx3//BgCDBgCCBgrwCCBhbQH+Bgr4K8BgUIT0BhPRAaBi6aWcBiWgT2BgUACABgIICCBgIICBBgCB/8LrBdcMOF1sLrBhwuthhwut4isRaFw8RQRQRURcRURSIsIqFwnC4XEXiKRFAuGiLRFoXD4i0LhhFhFBFguEEVi//PkZP8m6c7yAHdXTiVqjegA7my4LYXCwuGiKCKRFsRcRaFw/iLCLCKxFxFRFxFBFuIrC6/8MPhdYLrwwwNg0AUBLBsHQw38Lr4YeGHLBZFcoFZZmOoWmFgW+YdAcYdgcVgcYHB2YyAeYdgeYdAcYdB2YdEiYLAsBiVAwWFZ3ljrzOOM44+jjPOLBwFWLSga5NhNhNlAotImwgUgUmymygUWkTZLTgVdApNn/TZQLTYLSf/psFpE2fLSFpE2UCkCy03///wZ5X/////4XXC63hhv4XW4Yf4Yb4XXC63DD/hhgw874lAz8FIzFBs4WIwyNIwyNIwsEaYpmKZ+GKYptOWAbMUjFMDxkMZCQMOwPMxBTMxCMLANFikVmzpGyumVygJLPJkA5QCljHDjHDiw7K3ZjnZuhxWFMInKwhp0xpwhWENMFAyxNgrLpslZctMgUmwm2kg+DOy5IIDKIPmzv/BgA1+EQBrgYA0ANhEBSCICkBgKQCmBgYoA0Bgt4NMBhmYO8Bv7wnqBjCAWCBgmoCmBgRgA2BgYoA0BgDQA0BgDYA2DABv8IgAoGACgAgRABOEQAXwiAC/xFBFguGC4YRWFwoXD4ioiwiwigXDfwuEiLhcLC4SIoFwkLhguFEWx//PkZP0ncbzyAHdXWiaSVeQA7izUFwuFhcKItEWEWEWxFxFYi3xFxFfEV8RULhRF4imFw4XCeGHhdbwwwXW+GGC64GALgGIAwDAGHBsGhdYMNhhzM/kjbtuywZ3mGxulgNzG4bysbjG8bjIgifMNiIMRxHKxHMRxGMRxGMRwULAKf5YDYsBsdaHSh0p/ldPOlSxQsULFSvh9AVhMADAHzAAsHC5gscMYFzBrPU6Kw+YQFgH+WAf5YB/lgJhD5WAsB/ywH//CNGQYn7/////wxSJoGKhKwxSGKAxVEqDFYYoiaiaCViahiniaf8IgBTBUqSsFTaA2zgR0jEc2isqTQwdzNsdjHcRjEZDDKkqDEYRzHYdjHYdzNsFTHcRzHcRzBURzEcRisFDEYFTEcRvKxGMRhHKxHLAKlYKFgFDEcRjEYFPMRhGBoIlYTKMFgEFGQcECAQwAAHywABWABg6ABYAAwAB0wdABAgWTQJLtQJF+kCBfURAX5WChYBUrBX/LAKeYKAr5YBXysFf/zBQFCwChgoChWCpWIxWhpwKOxp8CpgOwfqYJ8KOGUTIwZgt4G0YFSCGmA7gIxgI4COVgCpgCoAqYAqAKFYAp///wYBCIGDAPCIH/xNRNYlYYrwxV//PkZPIoNczyAHf00CUq5ewA5qw8iaiaiaiVCVwxQJUJpxNQsgh5wsjDyQ8/Dzwsj/h5fDyxNcMViahigSoSuGKImoYpDFOJV8SqJWGKwxTDFAmn/+DAMIgQMAdAzncGAf/4RAnwpGcjQRoNBlgglggmQVAZqkdOmZqmWDRmzRWbOnTM1SKzRm6ZmjRmjfmbNFg35WaKzZWb8sGisKYUL5pwpYCFYXywFLATzChTCBfwiBr/4GAUAoRALwYAUGAE///4GO4d4Hi4d//////EVEXiLiKRFeIuItiKQuEEXiLfiK/xFOIqItEUxFoi/EVxFBFxFv/C4QLhFSv8jZZuDCbCSs7iwApgKVplYVpmcAhiMIxiOdxjQRZkUI5jQI5kWNBkWExiMApgKIxiONBo4KWCcycEKyY0YmNoJzBSYyZHKyY0cENGJzBCcycmMFBTJicrBSsEMmBSsFMmBCsEKwQwQEMEBCwCmTApggKYKCmCApWCemyWkTZLTpsFpC0yBZggL5YBTBAUwQFKwXzBQUsAv/5YBP8wUE8rBTBQUsAhYJzRyc61oMmzwMF0ARwMI+GUANfDCPwMR7AigMCKARwYBWgwAmAwCcAFAwAQAEBgAKEQAUGAAnww3hh8Lr/B//PkZOYoXdD0AHd3XiOzWfQA5mp8sGww8LhIioioiwi4XDRFfiKiKiL/EViqFVFYDV0VQq4rIrArIrArEViKyKoVkVkVeGrRF8RTxFMRcRf//8RSIrxFxF4i0RWItiKgYAUAFgKARwCgAvxFsRSIv+Isa7yRk4nFgnmIxEYiMZmMxFaiMRiIxGIjKoJKwQWASd5HmQQZN5kk+gGQCoBVEiskrJ8sEeowgH9Rj1ElElGEA/qMf6jKiSAb1E/USUT9dq7l2e2f13tkCyPh5sLIg8kPJ//hHyf///h5/8XfF3GIMQXUYgxcYuLuILf8XXF2MTEF4xRdfxdf///////hEEIDcZpzWYpIgYqMiKCYZARqGIJMBmKl5WDBh0lCFwEaDgwAGQ4rB0OkHqckgMhkmMp2oAutaKnaqi0lcuRBknbvF2ntMf5pzZvkkmfx/V2QZRw3BlH9DR0FDGKKhg76CMxiDP+DvcmD/cj3ILngYwNCLjCwYwVEYy3T89nzoxbAEKgYAgbgxy3IgyDv//8Z4nGMY4xE4zieXlBvLlist5TG5QbygyUKxHE4mG5QrGC8qXLRLjcrly5QqNI3lCpSV+WLeX/LcZjAxE/xng+ANBv+J/xMbBuRqAemOnGJKYyA//PkRN8aXdEGAG+n0DqDogAA55ugOgMHAEwgEszEJWMdg9s5kkGoTCAQmGQuYgBrEmzAUFNnKwqhWp0WSQIoECwBQEEUCIiChfZfS7GylYBWYqs+qsDlKxUfwZBzkOSspYRpCwPyZs8laVJJI/8nkjZvU6TFXk2T/bN67Wz+uz12AEFiRoM/C4xeGDA7CsMKceUyd0jjCTA7MBUA4wDwC2yLvXY2Rs3//+R+RSNkbkaRSPkWfnC/Pnjs95zL5wvzhGOHY7iIRS+cOyOfnT56PGXzufPlRWPSPeVFZVK/lhZ5b+WcjyMRpF+R+MMG6K/+RfyJNJ9TCAU1Z3OBljCzcz8VM+QBlDABAaEFiEbWMZUMAAMWOVA4iKTMwYeZDAABs4BGAEKtnMKGBIXXcJBIiBUqPbIYICrtbO2RTZTBKhsrbKNOWpw/CKkGMvg59HKjbluX9Gyhdi7WytlbO2dshfls7ZfbN///+u5spfYSMTTicBJwGAsBQGISF4H5g/wGZILgGF0BQGAoBYEgLgwBUQX8XYxSEE68foxf+QkgpAyxLRaLcsFiQUskWjdLBbJUtkXIp4f5Jz5/ioVc+fF/w1+LCcWD3/P8+cOHTh8/z/5w4KTh07zh3nedOHfz50w7//PkRO0daYr+AG7J1D4zXfwA55ugPTSZzMVHc1GNjBaXM1EYxQQDFQFAAtMCAsxuMxI3DIYQsEjeREwZB4cMjDQMXeVgAs0AiSVgAwUGDBYAMAAFTVTFs6AcSECYq7Uq0rhIFiICNnSuTlUactThVVFSDGWwcrE5SKjluX6nDKV2LtbK2Vs7Z2yF+Wztl9s3///67myl9hIYmThOAicYBYBRhCF5m8wP8YyQLhgugFGAUAWJALlYBXrv//9srZx0Cr8ZokP+Okc44iqWFhbKiqOcrHtOFRaRC0e49MiRJZaWx7D2lpaRscmRw5BHIvLZaVFRWVFpbLcqKh6lRWVyorlcrKivLSv//waAa0xBTUUzLjEwMFVVVVVVVVVVVVVVVVU83mjWZlA1kOZkows5Cs/GSlmBjEWCUWmAhKMLEsxgFgKSjJYxMYjAz8FzCwWMlDADC0CjAtIBmSBjF4EC4EGJYC6bAEC6BSbIGFiBSBRWFwMLE2C0qbAECybCBYEC5YC5aZApAswUCisFlYLUaU4U5RVU59NlApNlAtAv0CkCvTZLSoF+WnLS+mygUgUgWYwGBmUYm5T+ZkGBgygyGU8sYcPUF5k1ByAUBcDAXFpysBYCgLoF+gV6Bf+EfwDf//PkZL4gpbT4AHPN0CEyffAA5Oy4CI/wjYRIuBaBdC1RfFwXRfi+LsXhf4WsLVwtULXi6L4WoXhd+LvF+L+LkVATsE7iqKkE7xU/4qRU4r4rRX/hG/wiADdhGCMdut5sQNlgNGQSCWCAWFCZTDZlMNFgpGBQKVmgwKJysClgCGBBOWCCWCD8GbCOwjqB61gzQR14M2Fw4igClIiwClhF/+Fw4iuIsIpC4eDC+ESBEsGE//4MEEBugEH//xFRF/EX/iKY3xvCgxvDcjcG6N0booGN3igI3Y3hujf8Rf/+IuIsMQY1EwdAvzJ3QsMi0QYwLBmzEGEHML8QYwLAvzB1AtMVAHQsA6GKgDoVhfmF+BaYFgOhg6A6FYOhgWBflYFhgWBfGBaBYYFgFh9OpY6lhYV9TWrSx1LC0+q0rWlhafRYZo2VmzpGjp0zNGjpUjNGys2Zs2Zs35mzZWbLFIrNFgcbsd5jh5YHGPHGPdlborHFZssGzNmiwaKzZYNFg0Zo0VmiwaKzWDACz8IgOoGA6gXwGCogqAGAWAX4GCDAzYGLNnGAGv1lYIGLygFoMB4gYBfgwB0BgBb/wYAHQiAHwiAH/hEAPhEAP/DDBhgbBoYbDDcMOF1gw8MODYPhhgut//PkZP8trdDsAHtXaiZyFegA7mqYDDhhoXXC68LrBhgw8LrBdeF1sLrhh4NgwGwbhhsMOBgAgALBgAIBgAoALBgAKDAAQGAAnhEAF+DAAWEQASDAAQIgAoRABIMABAiAC+EQASEQAT/+BgAoBMBgE4BODAAUDABQAWDAAX4RABfwiACG67rHFYbGG5uGMQRGEQRmEZRmUYxmMQRGEYxmIwjGCojmCojmDoOmHYsmDodGC6WHTAcNVQ1VCwoaqpYuK7ysgrIKyCsgsElZBXeWCfLBPmST5YILBJkkmACWASsArBKwSwAWATBATGTEU+p9MRMdMRMZMTwiFf//gwbgwbAY2G4GiesB9aYgwb////h5IeYPN4efw8v+HlDz1TpKIDO4LzFWIDHoejWAyTFUozFQyTC4CDAgCTC5YDAkLjFQLiwE5h6CJgiHpWKhhcBBioF5ioF5heBPmBAEG46bnRugmC6brhYIMm4sXmSQWLywT5guFbhggGC6YIJWB5WCWASsAwQDBBMEErBMAErBKwCwCWATBBMEEwQSwCokoz6AdAKgGUSK0PUT/+DAAkDACAAgDACQC8DAVAAgDAogHoDALgAkDAogC4DC3xsMDI/xpoDCuQHsDAyAC4DAVQAk//PkZMImmdD2AHc3ah/q9gAA1VVMDACAAgDACQAkGABAMACAMAJACf/ia4mnE1EqErE0DFcMUcTTEqDFQlQmsTQTQTSJqJp8TXErEqDFAmkTSGKRNMSrxKsTWJViaeJXEqwxXE1EriVCVCa4muJViV4lcSrEr4mniVYmglQDAAZiacSuJX/E1MUKM8yCRpiz4QXO0fNmeM+fCCxYtFYoz4tRoIKqNGLFGLFFgX/hBZFVThFVFb/U5RVUbRV9TlqzV2rtXas1T2qNWaq1RU6pFTKl/iKf4in/EWEXxFAuGEWgKBYD4ZGBgfiKxFIi38GfgyHQ7Dn//Dnw54dgz+DP////8Of8GYMKM6YZMO1rNa0+NPgPMZRlNE0+NP0+MZA78zvK0rIowEGgyRGQsAeZIh2YyjKYHgeWA6MOw7MZQ6LAHGMoHmecffZYPLBx9dFg4z+ix0Z55nHGceZx/mecVn+Z55Y6LAhziFc5YFMUTznE8tIgWWFi06BSbCBRWsWm/ys4zjys8rPM48sHFZxYO/4GAHAB+EQA8DADwDoDAOgGUDA6AL0DAZQDoDBEwDoDDoBzMDLHBwYDCxgOkDAZQDoDAOgA8GAB4MADv/BsGYYfC68MP/xF8RYRTEWxFRFR//PkZNgk2dDyAHc3aiA6jgTC3ipcFhFwuFiLRFhFoXDCKYigikLhIi3EUC4cRbEXxFxF4i8RYRURWIqIr/EU4XCRFhFxFQuE4XDf4XW/DDBdbhdaGHBsGhdcLr4Yb+GHC62GHBDAy+jMJLzLi8wABLACY6OlZMDiJAOI1l9jWsriokokFrFgwYz1E1E0ApWAsAKwlYfTGU+p9Mb1O12tlbK2Zdhfhd7kQdB8GwdBzkwdIUf4/D+LnIUhP4lf/+DARhEXgbJPQMBH/+P/4/f/5Cx+8hB/yEx+H4f/8lSV453JeS3krSs2DktzzF82TVQXjNgXywbJi8L5WL5WLxkYt5pqRhikKZYI0yMI0zEIwwaI0xTI0yMFMxTIwrBowaBorFMxw4x7oxzoxw4rHGbNGbpGbNFg2V0is35WPKx5uh3nkd+WB5WOKxxYHGOHFgcVjiwPNMmMJHKwpWFLAUwgXzCBCsIVjiscWB/lgcVjiwPKx/+Vjv/BgC+BgbIC+BgLwC8EQF8DCxiIwDMZBakDDQAF8GAbIMAXv/C64Ng4MNhdcMNhdcMOGHBsGBdfhdeIuIoFwoigXCiLCLiLCLCLCLBcIIrxFRF8RXhdcGwbww34Yfhhww3DD8RQRWIvEVC4//PkZPsl3dDwAHdXaicijegA7iy4WFwwi+IsIvwuHEWEUxFxF4XDCLiLCKiLRFoYYLrhdfBsHcLrww/BsHg2Dv+DYPhhsMNDDGwUVGGyYGG4bGGwbGRJElgiSs3DIgNjDYNjLAOysHTBwHTB0OiwDhg6DpiwHRg6DnldCxXyxU+gPnCsJ8AVh86VK6nWpYqV1MAfLAP8rB5YB5YAWAf5YCYQlYTAHysJhAWAFgHlYVO1PKfCx1PemOGN9MRT///Bifv/////8TQTUTQTSJWGKomomgmoYqiVxKxNf8IgA+DAAf4MACo3XwDjA6CQMWsL0yRAvTBlDpKwkTBlAOML0DowkQOjA7HAMDoGQwOgDzCQA6MDsOkwOgDyt0eV2WB5uh5YHm7H+Y8eVuivIY90Y8ebocY8ceUcboeeV0WHZhQhYT+WAhhAppghWFLA4x48rHFgcY4eVjit0Y4cWAphQhYCGFCFYQrClgIWAvlZYtIWCxaYDLy0iBQEL+WkQLgwBwMAfBgDuEQHAYDgHgYOwyAY6ReAYOgygwSAGnGaAH7fWIGWsSAMAeDAHgYOgHgwB/gwB8IgOxFsLhxFhFhFYioXCCKYioiniKwuEiLCL/iLiLRFxFBFhFxFguGEVEXw//PkZPomncjyAHtWTieCdewA7qocuFEVEWEXiKCKxFoimIsIuIrC4cRTiKiLiKCKYioXDhcLiKiLxF/EX//EWhdb/wutwusF1sLrYXX/C65xKchnKkphEMZXjLGI8eI3Zwzvc3Ts0eI0aMsIjRIytF5lCplYxlSpYEFgQViSsSWCpYKFgp5YKlZUsFTKFfKyhYKBEEQYCYMBIRBMPOAaEAsiAMCIWRB5Q84eeHmCyEPMFkcPKFkYeUPKHn8PKAcIoeULIP/gwRwiIwMRCID0jlAxGIv/4ef/h5/w88TWJqJr+JpE08TWJp8PN//DyysP4yHQoTG8UVMP4tsw/gQCwH8YgwzZhfA6GDoBaYOoOpgWA6mDqF+YFgFpYC/LAOphQh/mH+CCYr4IJYBlMGQA8sAymB0DIYUAIJggAgFYUJWCCYUIIJYCgKwQDChBA8wQQQTBBBAMBsBorAaMBsFMwGwGvKwGywA3/mA2A2YDQKZWA2WAG/MBsBsrAaMBoBsrAb8wGwG/LADRYAaKwGzAbAbLADZWA0WAG/KwGywA0YDYDRYAaLADf//////lgEErBAMKAEArBAMEAEEw/4az8ZP2KzOTCgBAKwQSsED/////+EZCNgdsI0IwGUGXCN+D//PkZPIpBejsAHoy6CNqjfAA5mxcJCNhGwZAjQOSByQZIRkDs8GSDIEaEZ+DL+EZA7YMoRkDtBlBkCNwZQjIRgRvBlhG8GQDs/CMBk4MkGUGWEbhGwjAZMGTA5QO0GUGXBlCN+DL+Ef/8I8fiqJ5s6GvxaVg8sDoweOiwDjSI6Mdg88VwPCBrQIuBrwIsZ3RYOKzvKzys8sdiFAQgqkao1RNkrXTYQLAixadUghQauqYODVP6bCBRaZAr0Cv8MMF1/8MN4YYMOF1v/4XXC62DEW//xWYauDV/FY4rMVgNWirFV/DV4q8VeKvw1Z8Vn+KzFVxV/FUKqKtORHsNE0SMvcfNwy9Nl2XMvRkNExkMZA6MvRlKyQMZBkLB0GHQHGHQdmMhIFeU8rs8mU8g43TosOzdDzdjiwOLDorHmOHlh35YdG6HljL5p05YTmECmFTlhOYQIVhCtMWE5WE8sBSsKYUKWAhWKRURVRVRUU5CotRpTkwgQsBSsKWAhWEKwphAhhAphAvlgJCIDoRAd4MAcBgPAcBg6B2Bi8DKBhlB0Bg7IkBlqaSB2qywBgOAeBg7DIBgOAeEQHAwB0IgP8GAPww4XWDDA2DoYeDYP4Yf4XXxFoXCiKhcLC4WIoItC4e//PkZOcmlczyAHdWTiWykewA7iq4IoFwkLhRF4iwisRT4igiwimIuIqIoFwoi2IqIuItiLQuG4iuItiKeIpiKYikRSIvEXiL8RTEUC4QReIvEViLCLCKQuGiL/EU4ioi2FwpsDKBsAWZWWZgeHRgcMhjISBWbBmwLxWLxh0HRjIB5gcMpYCzywOpiWGAGCwwwBcreb3lbvAlk2S0oHeWm8sOK3Fhxvcb3IFliyBSbCBSBaBaBaBSBXlpi0ibHtUVN6pGqlYFSqk9U7VmrKkKwqnVOHCVI1VUv//A5/Pv/////iris4rAqw1aKvFWKzirFZisCr////C66kxBTUWqqqo+cSzMnPPaOU7OZDGEYAxiMYGUDTM0wZDJZLMLMwCBcwsMQMLTGBKAyUMymUwuZDC4WLSFpjC5LAwsMLjADC0tOmyWmKxgYXGAGMAGFgGF4ECybCbJafy03gQLAULlpE2QMLy0iBZaT02UC0Ci03oFpsoFIF+Wm8tJ5WFysL+mx/oFIFIFoFJseWkQLLAWKwuYxC5hYLAYMswSyajfGBlMWYJgrAxMDABZNn/TZ9NlAv/QKwiAj+EfgG+ERCNhGFcVgTiKgrisCdxVxUFUVsVBXFfxWFYVBUFWK2K8VBUi//PkZN8iQfj2AHPN0CYqifAA5iq8pFXxXFYVhWxWwTvioK0VwTkE4iv8VxVFYVuKwq/hHCOEQEfCNCMEbCP/hH/hEwiP//hGLFfNQqEyCQDDYaMNFIsFIx0OzMg7MHmQxOJysTGJwKVlIsFMsBsyCQf8sbK9+V7MpDKUrIVk83uK3eWHFh3oqoroq/5WtTnysnlgnlgpWUrKo2o2ir6jSK6nKKnoqKNqNqcKNqcIq+pz//6bCBX//pspslpE2ECoRf4HXiADCB/+KoVUVX/8VQqvxFsRT/EW/xV+Kx8VX4rNNrAWowDhEzMeKaMhkcIxwwvDC9DpMWoA8wZADzDpA6MJAGQwDwZTCRC8MOkA4wDgOzDoC9MA4A8wDwZDCQC9NlDjOpEzo78w4PMOZSwdFgOM6OysP8rOys6LB0WDowQFMFBSsFMFJysFMEBDBQQwUFMmJzBSYsApYBTBAQwUELAIYICGCk3+YKClYKVghYBSsFLAKVghYBCwCGCApWCmTAnlYKWAQrBDDg4sBxhwf5WHFgP//MODzZQ4+kOM6OwMSBPwMSLdAOlOhQMiQkAMMgDwMBwDgYA4GAP/wusGGC6wYeF1ww4Ng7ww3DDhhwbB4iwioiwi4i0LhBFBFBFR//PkZP8rKcrwAHt2Xicq1eQA5Ky8FAiAsRTwuHEWEVxFgutww+F1gbB+F1oXWwuvC6/DD/EUEXEXC4YReIuIrEXEXEUEVEWiKiLwuFC4cRYRcRaIv/C6/4XXC6wXWDDeDYMC6/hhwuuGHww4YeF1jSFqNeRIsGQx2DzB4PK3QYPBxxMdmDjKY6HRg4HlgHmZB0YOHRWkDBxlMHmUwcDgP/A/4D/gP+CPAf8B94H/Af8B/4H/gzwj8I+EUBigaIBqgMUDVQYkGfCP+DFCKgxQYsIr/wj4M7/8I9CP4GGQMgGkQiYMAd/+F14XWwbB38MN8IgE/+EQCf//ww0MP8MPhdf/hdYLrYYb4XXDDDm1+zFV+zYb6jHoozAg7jFUojTsVTAgoisVTTtOjAkCDFU7zHoezAkCCwURhcPZiqPRmSBJiqBJgSKhgQKhhePZheBJheKpYAkwvAkwJC8ruMkg77zJIMkgrJN1w+nTBAMEAwXT7BPpxRMGogxFAMgGB5oMQLCJYJK7ywQWCfKyTJILBJYJMkgsElZJYI8rJO8grJLBBWR5WQVkmSSVklZJkE+WCSsgySSsg7rjuJAwC4B6AwAkB8Ayz0FhAwfsDvAwKIAuAwAkAIgwAJ/wMABAAQiA//PkZNQpaf7wAHc3eh+6UgQq3mgcAYRAAYMAAAiAAAwABAwAEAAwYAAAwABgwABBgAD8LIQ84eYPIHmw88LIcPLDz/h54WR8PP4eSHnDzhZCHn4eWHlDyh5/DzYeQPIHkhZD4eXw8geUPLw8weaHmCyKEQAD/CIACDAAEGAAH/BgAB/4MAAP///hEAJFCBjkmYCdlY6YABgdmA6VjGusFlz1YNgtAggEUTB0YNQB0foBkAqiaAVMRTyYinaYzZmzLubMu3xKxNQxRE0DFAmouhBYQWGL4dGLlFzELkIIoLkIQfx/i5h+H4fxcshP/gwDgYHsB2ToMA//x+//yE+LnH/ITISQn5CD9/5C1TCcaTj8ijdvbDhxljAUaDEYBDAQrDO8ijCcrTGhVTGkijEYRzGkRjM8JzCZEDIoBDK0aTEYijJgQ4tGNHBDaEcydpMERjJwUwUFKwQ0YnNGJzJwQ0ZHNGJ/8rJzJgQyYE8wUEKyYwUm8ycE8wQnMEJzBQQrBCwCGCExggJ5WTlYJ5WCGCApYBDBAUsApWCGCghggKYICFZP/lYIWAUrBSsEKwX/8wQE8wUFNoBDzgQ2kEAwjgmAx3wVA3YVkAzLjPAwCBGAwTAFBgBYRAIBgEAJ+GGD//PkZNQnUcrwAHd2XiEKVfgA3mpcDwusGGC68MPg2DoXX8GwcF1sRYRQRURYRQRURSFw4ioXCiLRFsRfC4URQRXC4QRcRXEVEWEUxFBF4ioXDhcPC4fxFPww8Gwbww/+DYNhhsMN8MP+GGDDhdYLrhdbwuuF18GwZ/4YczpvPHOjgQYzsHMpkzXF0MLgyYMWBjWpTGMZYsDmOuWFw3dTwYYp5TwYb5jjFY6nkxExvU96YxWN6Y6nisdT/+p5T3qeU7TEU/6ngxWGKQxWJoJViaiVYlQmsTUSsTXia8SoMVYlQlYYoDFAlYCwHAxSXQOPoAGAfiV///E1//////+JqkxBTUUzLjEwMKqqqqqqqqqqqqqqqqozMU04cLMyZJg20LMxkP8zMGQzMJkDEsBAXMmEYMZQWMFx/QLMZAwMSxlAwxgUmDBcZQKCyBQGGIwXEsCAuYYgsYlAsBQwLTmCwLFpCsF02DDAMQIC6bBaUwXBcrBYtKmyBgsLALAUF02C05YBctJ4GC3wIC5aYCgv6BabBaZNgtIgWgWgUmz5hgC5actKmwgWmwgUWk9Av02f9AowXBcChiVgsmwYjBKJwXD0GLMBgYMoC4GBgLTlpC06Bf+gV//AswLGAByBY8Cw//PkZMojAd70AHfN0B9i5gmI02kQBa+CcCtFQV4JxipisKsV+KorAnIqxV4J1isKv+KmCc4rCpFbBOYrCsKuCd/wTnFUV/xWhEYRgiIRgj8I0IiEQAboR4RHwjeEaEf4FuBaACwFBgV57H5oCJWgEBEwJAQgDQAQ5GYBcHAhCA8OBCEAFRZn2YVFemyWmLSqnVO1VUypmqKlVP6pmqCsKgrRVgnYqRXFQE6FcV8XxcFyL+L4vBaMXBdBFxfF7/4JzFYAyE8DXLgYAFX//4/D//4/f//kL/x///yE///H/8hKMywsosC3mAokSZlwVBhUl+GAqG0VhtmDuCMYhoIxjQkbmAqCOYIwI5i3hUGCMG2YOwbRgjDQmIaDsYbQIxg7gKGAqCOYCoI5YBGMBUEcwdwRzAVAUMKkEYwdgRjDaB3MBUBUrAVMBUHcwRwRywAqVgjmAoAqWAFSwCMYOwCpgKAKlYCpgKgKmCOCOVgKGCMCN5YAULAChYAUKwFCwAoVgKlgBQwFQFDAVAUMBUBUrAU/BgFAiBUDAoBUIgVBgRwYBWEQKhECgGBQCuBgUAoBgUArwMCoFAiBQDFSBUDIaHcIgVAxUgVA1ldwA0z5HA0zB2AxUh3AwjAUAwjgVBgF//PkZP8xadjqAHrTtCPCIfAA3mq4AMCoFeDAK4RApgaUgxOEUAaEhFARRCKfCKAiiBgABgAEQgwAMBwYCBh6EQAwAGAIMCDAwiCBhCEQ8GABgQMIAiEIhCIQiGDAhEIMAEQBEMDAAIgAwBBgYRCDABEAMDCIYMSBpQDEgxMIoBiQNKf8Ip4MRBieEU/CKPwij/4MRhFMIpwYgIpwimEUcI1M76DvQ4w9lMsLfMtvyt1Ky0yx1LDKVhxWHGHB5WHmyh4ESkCjFhYsHlg/ywcWMECwNcWkLTlZ5Wf5YOLBxYPLSIFemymygUmwqRUrV1TKnEIKpFTtVVK1X2rtVVI1VUypFTNVaoqdqjVGrtVaqqX//gyff/4q4auirFWKx+KvFV/xVCsBq4VkVQrOKwKqKuo1WQmTD/EZApG4EAwMH8P4wSgMTAwAWAwzJgYAYGEwDKYGAC5glALmEyAuYC4CxgYglGD8AsYJQC4GBgAqxaXwNYeGJXggWB4wPCa+B4LlhcDX+Bry0qBSbB4rIFngumwmwBrgNcay5rrGusgUBrQoWpyELIrIrBQpRoIWUaLS/5aVApNgCroFIFpsJsBdcLr8GwcGGwbB4AgFgMC4Sgw4AwlAMza0APyaUQBj/Bda//PkZK8jSfz0AHs2XB+CHgDg3mgcGGDD/C6/C64Yf/4XX+KyKqKyKqKyKsVkNXcNWhq+KyKwKsVj4i2ItEWEViLYikReIpEVEWiKiLhcPxWYqorMNXCrisfFZDVmKyKuKrirFZFUIrEWxFxFPEU4iwXD+It/iLRFsMP//C6/4XWBgAsXpnTIYedga8CrGsuWlLCxrr+mwBFk2CtY8MDzeCFjKLEIJggFhEOCDgWrtUVI1VUogAVOqUrAaoKzFVCIEVYqw1cGrQ1fiqx+Fzi5R/IUhBc4mg/Cq4qhVgNAMVf/wuuF18I3gjf/////yFkLH8fhc8hI/kLITFyj+LnqTEFNRYAhQ7EUosF8ZVq+aHksZ9C2Y3COYtiMENOYPjeYFiMYPAUYZC2EIOVhkYFl+YZiMYPi0WAeMBAEMJgEMJgEMBRHNSjV41qCikVDUs1LNSlGytRY8VrU4CiwopFZFYIoFFBFPK1FhSjanKjZaXy0npsAdxaZNhNlThRpRtTlFRFQKLU4U5U4UbEWhcPEWEXEUxFQYB4DC0B4GAfASEYDIe1EDHOcIGCrAUAoEQFiKBcOIt/FUKxDV4ashq0VcVQrGKrFYFYw1fDV/hqziqFWKwKyKsNWCsQ1fDVsbw3x//PkZN0lWgj4JXcWah+CdgQq3moYvY3I3RvRujcxujfG4Nwbo3IoMUFjfFZFYxWIqxWYrIq4qhWRViqiqFWKoVgVYrAqxWPxWf+GrYrHhq/xV8Vn4rEVeKx/isis4quKr+KvgcGxURYY/8rjOOM7ySu4yCVGVEixMZJBYJKyQYiDEUAyYiY5WOGGJjFY6YqYqnYmomglYlWLoXYxBiiCwuoxBii7GILrxBUXYxYuxd8XcQViC+Lv//4MTP/8fvx+yFH4hPj+PwuXkJH4hOP4/Y/j+PxCyEj/8fyEkKP3kLH+NatCgy50aDDkGkM+cGMyZxZDEkHkKyZjCjAjMKIKIwYxJTBiGlMCMGMwoxJTAiDkMKMOQwogojBiCjMSQOQwYwozBiAiMKMCIwYw5DBiBiLAEZgxgRFYMRgxgxmBEBEWAYzBiBjMCMCMrBiMCMCIrBiKwIzAiBjKwIysGIwIwYywDEYEYEXmBEDEYEQMX+YEQMRWDEVgRFgCIwIgIjBjAjKwYjAjAjMCMCMrAiKwIv/ysCIrAjLAERgxARmBGBF/lYEX/5YAjMCICPywBGYEQEX+WAYzBiDlLAERhyhymdMrSfmitBhyARGBGBGVgxFYEfmBGBH////+DCn/4RKQ//PkZP8vRgDoAHqT4iQyleQA3irUYVAypUGCAYJgwQDBAMEhEQERMIiQYI/A1wmERARE4GEIRCBgAEQBEARAEQAwAGAEIgBgIMCDAQYHwYGEQQMAQNCIRRCKYMRwikDSnBiQNKQYnCKAYiBpSDEAxGDEgxEDSkGJgaUwij/BiPgxH8GI8IogxAMTwZT//+Eam6lhlrobrfGvLxY2TXl8ztkOQOzZWUzoPKw8w86LAcYeHmHh5hwcZ0yGdB5YLPMsLfN7vN7ze70Ci0xXc7XLFi0nlpQO8DsK7oFlpCt3+WHlb/LD///9AtApAotP/lh5vf/lh5W8rf////wjPv///wuuF1ww8MPhhguuGHC6/hdcLrwwwYb/ww3//DDww38LryxNZn8chplKBsCWZsAwJgsPxkyP4EGUx+GUx/BYxlH4wxGQx/DAxlH4wxEsrGQwwH5Ng5TECFisuBZYGwAVigUbBiWmLBYCliwXMuwKy3lpwMtLSFgsWkNiXTYKyxacyxdApNgrLFpk2SsumymwBl5adNgtIgUmwmwgUmz6bJYLgZamyWkLToFFpi0qBX+Wk//TYK2J5S6bIGBYC4GLKEQGtAzYGLIPwRBiDALADAvDDYYeGGww4i4i4i/hcLEU//PkZL8jLer0AHdWXh7qjfwg3mo0C4YRaIrEUEViLxWBWIasFZFYFUKoVYavFZDVgqxVisBq0NXxVisisisCL+IqIp+Iv8RfEXxFRFvEViLiKYiuIqIv+IviKiLf/EX4iv4inEW+Ir//EWEW4i4iwTiu86ytMmBCwCmTk5o4KWDyvs+zgrmELlZZaZAo11jfeMosKloreioWC02ECk2S0vhdYLrQut/CIEhdeDYO4XWBsGigBQA3BujcG7G4N/G8KCFBDdDBUbsbn//Bi+Bgs//8Vf8VX8bsbkbn/jd43/G743fxvRuDdG743DA3a6MdYdczJEHjGEExMN0iowiRhTGCA2MYQdYwNwNzIqJ+LARBhEiYmG6BsYbgmBgbhEGG6EQWANiwESYGwG5hEhEmESEQYbgRJYCJKw3DCJDdMN0DYwNwNisIgwNwNzA3CJKwiTCIA3MDYDcwRgRisBQwFAFSsEcwFQRjBHAVMEYBQwNgiDA2A2MDcDcsAbmBsBuWANjA3A3KwiDAVBGKwFTAVAUMBUEYwFQFDAUBGMBQBXzAVAULAMZYAi8rAiLAEZYBjMGICIrBjKwI/LAEf+VgbFYG/lgDfysDfysDYwNwiDCJDcMIgIksBumZKg8f7LCZ//PkZPUt4ermAHp09iULBfAA5KrYhEAbmG4ESVgb+VgblYG3///5WBsVgReYEQEf///5YAi//KwI////CKIGhIRQBpQEU4GlEGIBiQiiBpQEUgaUhFMIpBiPgynhGnhGngyoRrgygHWmB0rBlQZThGvwjWEaeEagynCNQjX4Rp/wjUGV+EaeEagyv///CKM1Z/DNIFM0gQrIxYNBYI5kY0maQIZoExkY0GBBMYEExgUCGJwKYnI5WBTEwmMjgRAotN5WFwigMUGKDFBiwNEBiAxcRYRcRUGUAq4igXDhFeDEBiwioYcLrBhgw8MOGHDD4XC+Fw4i3/8GKDFhEChEjAZGVv//4XDCKRFoikRXEW8RURb8RSIp////EV///xFP//iLKjJBlzZY6DDqRDT4DisZTJEDzGQOzA4kTA4kDL0OzDsDzA8DzJEDiwSJjKXhp9Bpo5pkx5HZjhxW6N0PAy5NgrLFpE2TTBTTBSwnLAU04Q04UDLwIXAhcDLy0oGWIFFguWlA2FNkClisuWkLSGKFhBdFcILIq+iuFRX+gV6bKBZYLgQsmwWk9AoGAO/4MAcDAdAYOwHAYDgHAYOmkgbzSJAYkRegYDgHBEB//iKCLCLiKhcKIqIrC4XxFsRf//PkZLwjVeL0AHdWTiVznfAA7Cr0FZFYFYFVFViqDVwauxVCrxVRVcVgVgVQavDVwrIrAq4rEVXFYiqFWKzFXis4q4qxWRWPw1YKyGrRVCsishq8VgVgNXhq8NWCscVeKr8RfiKwuEEX+Iv4iniK/4i/iKnNa/mAIdFYsmDgOmDhJGAAAmAIdmLAdFYsmHYdmDgOmAIAFgACwDhg6ABh0AJg6DhYAEwBAAsAAYAgCWAd8rB3zAAADAEAQYBFA1hEgwCJAxwYgwwigxBgBiEUIsDAIgMQi/CLwNcGP/8IsIjoDk4BBgd//h5MLIv/8PP8PJ/h5Ph5v/////////////Dyw8sPLw80PNU7yA45Fe05wcArewy8OgyRDs4ZcIsDIYdAcVp8aJjKaJl6Vh0aJjKYdl6Z0DKYyDKVh0YedlgOMOZTZGUw5lM7OisO8sHZhx2Z2HmynRsgcbIdGHhxhx2Z2HFgPLAcYeHFZ0Vh5hweVhxYDjDw8rO/MPOywHmCApgoKWAQrJiwTGCApgoKYIClgEQKTYLTIFoFFgXApiWkQKLSJs//+Vh3mHh3lgPMPDjOjs5A7M7OgMBlA6AMFqBEwMhkC8gMC9AkQYAygwAOBgAf4RADvhh8LrBhgbB///PkZNYnkdruAHd3XiJrDfgA3ihUDD/DDQusF1ww4YcLrA2DQuvhdaF1ww8MNg2DINgzDDhhww0LrA2DIYcMODYOC64YcMOGHBgAuF1/4XX8LrhhoXWDDQusGH8Lrhh4Yf4XW8MNDDQusF1v/hEAEBgAJBgAL///+EQA8/xSONGjGlMyZHLAIZMjmNxhxg2VjRqUisFFgdx2uWLGUpWQyECL+o0pyWC+WCFZPNzvK3f5ufC4aIoIoIqIqDAPAcAFUKwKwN8booCN8booEUAN3FVFYFZCIAVgVmKz//hFaBren/////jeG5G/jdje43BuY343Y3RV//FYisf//FZFY///isxVqhnDJIGHbLG9rgmMh0mHQdFYyGSBelgDzGUDzRMZCsDzGQOjJEZTDoZTL0kCwBxjIBxh0SJushjnRYHlh2eR2VuvMcPN27LDs3Y4x7s8rsxw8sDgIXKywFLlpC0wELAUsYUIYUKYUKVhSwEMKE8rClYQrClgL5hAvmEClYTywKUbU4U4CgorFBDz0VUVUV/+DAHAwB4MB3CIZQMSKGAOPQkQMSAkAMHYO4MAd/4ioi8RSIqItEWEUiLCLiLxFcVYqg1aGrBWA1eKoNXRWRWMViKoVYrArArENWis//PkZNomDfr0AXdWXB+KjfgA5mo0is4rIasFZFZDV4rIrAauFZFV+KyKuKoVgVj4ivEXEX/8ReIoIoItxFP8LhQuHiLBcIFwgXDhcOFwniL8RYRYRcRYRXEWC4bEXEX4YcMN+F1oYYLr/DDGAFidiOxgAslgAmAQ6YBLJgOFfRW6VglhwsOFgE3QDcAMHs3XDAABqKiajKAULDKdlgZMYMNwYAQiAOHlh5oWR4lYmglcTUMU/Dz+MWLsXUG6cQVGKLr//gYAHYGzg4DAB//xifi6GL/xdiCnjFxieLvGL+MX8XYuvGLi64uqN2BRk3YBMTEwRzKy0DDcIqMIkisx1wiTJ/CIMNwDczyjeTCIDdMTEIgwiQNjEwGFMtEYUwiQ3TJ+HXMIgIgsBulgDcwNwNzA2ExMN0N0w3QNisIkwiANysDcw3QNjCJA2LARJWJiYG4RBYA3MNwDYrCJMIgDYrA2LARJhugbmBsBuYGwGxWEQWANjA3CJLAGxYA28rCJMCIGIrBjLAMRYBjMCICIwYgIisCMrAiKwIiwBsWAN/KwNysDfywER/lgDYsAbf5WBuWANysDf/KwNiwBsVgbGESBsYmIRBhuBEmJgJiY65Px/sqMmMEMIWAiTA3A2KwN//PkZPYuXd7iAHs04iayiewA7Oq4v//KwNvKwNvLAG5Y2/yvf///LG3+V7eV7//+Vq+aqhqKlapWr5YVLChYVK1CwoaqpYVK1DUULCpqKeWFTVV/wYi4RRfA0SIGIv/4MRYRKgwoDCmESkGFQiUBhWESkGFQYUBhTAypWESvwMoU4Rb///CLYIt//4MbeEW2EW5YhQz7YcsDcYjgqYKgqYKjuZEhuVm75iMI5WCpYBQsAqYjgoYKgoYAg4YsgAYAA6B9AEQgzoR4EegYegYAgYQgYAgYQAzgMAEQBEMTUMUiViVgMNAYcGKoRDCIAiEGA4MAEQgwP4GAGDABEIRD//+EWcBs5n//+JqJr+JoJrhivwxViVCV+JUJrE1E1/+JViaeJX8TSJUJWJqJrTWPkjJMzzUS4TScsDDoWTDssTO0zjB0HTFgATLE7DJMWDDodiwDpWAJg4SZkkLBWHZjuDoOnKJmQIlZEzh0zpwrdFZwzpwrOm7OHZOmcOGdOGBdGAAFg4ZwAYEAZ04WAPlYErAeYACYAB5WBMCAMAAKwBgQBgQP+VgfLAArAlgAVgTAgSsCWDhYAlgCVgSsD/+VgSsCVgCsD5YA/5nABW6M6AAwOgcAwOCTA56hYAwsgdAw//PkZLMk7fD0AHdWXh/rBgAA3igMAAAAwAgBCIAAYAEGAA/w88PMHmwsgh5oeSHm4eSJXiVxNRNMTSJoJrxNBNYmolcMV8TQMURKhNBKhNRKoYqiVYmolfE1EqE0E1E0E1iV/ia8SrErxNBKsSoTUSv+Hmh54eT/4eQPPh5oeQPPw83Dz//+EQAG0o597QVgpaQDuAtzUo/+NfwnyKnhwDAEwgAwiaBhAgqhWRVwuGEVEVEVEVC64XXDDQw8LhQuHhcLiKBq3isCrisj8LmFyi5hcshBcxCjcG5G4N2NyNwbvxV+KwKsVjAwsYD0pwYF/////xuYoHjc///G5/xu//8b43Ru///43TJjoTGUFjBcfjbUfzH4mQMshj+CxguPxaQxKLMCEyYLgsYLhgBRKAwxmMgYgUMTH4ZQMFphiMphgGBWC5hgC4FBcwXBYrBcDBYWkAoYAUMTBYFjEoFgMF4FDAtOWnLTgYLywC5aUwXBdAsCAsWmLTAQF/LToqIqIqFgC1OUVUViwBQQCxadNhNjy0haRNhAry0n+gX5aT0CvTZTYTZKwWKxKAwXFpDEtMz+5MjDEFwICybJaRNj/QKTY//TYCJhEBHwjwiADfgG8EYIgIjhEQToVYr4rAnQ//PkZNclHfj0AHetbiAaifwA22jUBMKwCHBO8VxXFTBOIrioK0VxXFUVgTgVxUxXFUVIqCpFeKgqisEb4RIRIRvgG+Ab3hEhH4RARgjQjwjBGCJCP4Rv4RMI2EbCP/CIhHCN///AsH9GR6Q+WDIzMeMKHgiOLCOZkFmFBYQeIrhAoEM5WFoqBUzRVRUU5LAUpz4RAREI0A3wiAjwjQDdCNCICIAN7BOxVFaK2K4JzFYVRVFYVwTkVhUisKorAE4q//4XCYGLjgduMDBeIp///xWOKzFZjfG6N3G6Nz//43Bvf43sbn431TnU1iNv5v42/DcDb9IENCaR8yujcTWTFPMJggUxTgmTNxNwMJkgUzcRTjFOIEMrogUsECFgU8yuxTzFOFPMJkJkyBQmDIECZMJkJgyugmSsU4wmAmDCZCZLAp5hMhMmEyEwYTApxYCYMJgU8wmQmTCZCZMJgU/zCYCZMU4JkrCZKwmDCYCYKwmTCYCY8wmRTjCZCZKwmSwEz5WCcVhdGCcCeYJ4J5YBP/ysE4sA3eWAbjBvBv8rBvMPsG8rBv//LATJWEwWAmSsJj///KwmCsJkrFPKwmAiBMgYV0FdgbGACngYEwBMBECZ/+DJ0IzgjP+DJwMnBGcB//PkZPgq6dLcAHr00CTbFewA5qpYzp8GTgjO8ItoG2bhFtBjcItoRbwNu2gxtCLfCLYIt/BjYGNgY34MbQY2A27eEWwRbAxuDG4RbeEW/CLcIzsIzgZPhGfwZO/4RnfwOfP/Bk7gyfwjO/Bk8Iz/wjO/wjvLDZPntksF4rL5l5sFZfMymQ0gDzMgOAjEsMUCjHDzHDjHjwNgA2IDlTHjjHDyseY8eWFn+WFnoFJsFpgMsLSFpyscWB3lgcWB/lgdCIOwYDvC64YcLrww4XXhdfC68LrhhgiFgw3/+DAdhFZgayWX/8MP//hdbisiqisRWMNWxWBWA1aKuKvisYrP/////////C6yTEFNRTMuMTAwqqqqqjx96QNtRYjY2bDEwwLIwwH4yYLIyZH8CiWYyDKYYEwWnTZOXKMvlA/wDLzysTYsTYMCwWA8gyxYrLgbEVlgMuK5RlixsS4FLmWLlZYtKBS5YYpsgZabEuWk8tKBSybJlyxly/gQso2FRZYFKNhD1TlTn0VBFYisRYBICwuEC4QRfhh8MPg2DwuuDYNAGBcDAlgDAtCISwMTCAgNdY5QBQYACAXBsHBh/hhuFw2Fw+IrxFAuG4XC4XCCrw1bFZDV2KwKwKxhqwVWKxDV//PkZMsh/dT0AHdWPiGiegAC0+kKoavFZishq0NWCsis4rAqorIrOGrxVYrHisRFBF4igioi8RfEWEU4i2ItiKCL/iKCLiK+ItiL4ivEW4i/iLYiwi4iv8MNAr7mU7FgoVgTAgSwdN0cKzhnbJhlBWGTHLAdMQwygsBkxgwYmMmMFw6nan//0xVO1PKdBYMmOp1x7EePfhqSPTRpphN9MptMdMJs0zTTSaNH4uQfx+H4OnH7//4R3Azd//5CeP8fx/kKQvi54/i5h+FzEIP0hR+5CyEH4f8f/j+P2Qn+Qo/VTEFNRTMuMTAwVVVVVVVaCHKBmGzYyGcs1GPxZGZoYmCwylgmTH4FzEs/zOUZUCzH4ZTH8ZTDESgMMRsGIE/GxLGXYlpy05lmBhAphUxpwpp0xpgoElmWlFpU2TYly0xWFNOELAUrClgIYQIYQKaYIWExhQpYCFYQsBTThfLSemyVli0hWWLS+gX5lyxadNjywXKy6BXpspsfhh4YYMODYMAwLAWBgFwMGAZAMZomAOeISgMGASwbBgXX4YbhhoXWEWC4QRcRULhxFIiwi+IrhcN/DVwqxVRWIrIauiris8VkVjxFoiwigivEVxFhF4i0RQRQRX4XCiLwbBgYYLrY//PkZPEkpd70FXdWTiXyXegA5OrQYcLrhh+GGhh4XXhhww2F1sMN+KoViKzFZFZFVFYFYirFYxVCsw1d4rIqoq8NXhq/C6/ww5nSon4qiYsOhjoymOkiYOMphsNGjSmYbDRiw6+Vi0wcDiwOysyGJgIYnExkYCGJgIZHAnmJwJBmsGa4R3ww4YcASwYYGweF1gYWESAwgRLhEuEXfwbBoXXC6wXWDDg2DMLrA2DIXW+DYPhhwwwNg7AxadANfnUGC3/+IrEVC4XwuF+IqFwmFwoioi0RSIriKfEV+Ip/+EQINH9Ws68BujVqOSO9wbs4LBuzOSDPMqcqYypzkzOTG7MbpWowzwzzG6OSKwzjG6OSMbpWswmUJisJkwmSBDIUBvKyFTD6D6MG8G8wzxujG7DPMM8M4rDO8xuhuzDPG6LAZ5jdBnlYZ5jdDdeYwwN5YD6MG4Pswbg+zBvGG8wbwbzBuBuMG8PrywDeYNwfRg3g3+YN4N5g3g3+Vg3lYfZg3A3FYN5g3A3FYNxYBuKwbisG4wbgbysG4wbg+ysPosA3FYN3//4MAzgYDdAwDPAwM8G6Awqc6hAyvAR+AwboKnAwM8DO/+DG4Mb/BjYItwi2CLeEW8DbNgi3BjcDbNwN//PkZP8tPeTaAHr0wifSfewA5mqYs34Rngc+fCM8Iz4RnQjPCM8IzwOfOCM/Bk+Bz50GT4MbAxuDG4G3b8GNwi2BjbBjYGNgY3gbdthFvCLbBjYGT4MngycEZ3//CM74RneDJ8GTvwjO+Bzp+Bzp4RncIz/4Rn/hGcDJ+DJ2EZ5wfxFgWGdTqaQHZWDzHYOLAsMWnQzoLDB5kMdg8rHRYFpYFhnU6GeeZ55WeVilYhWKWBAIugWB4Swv5Xb///+WnQLLSFpkC02U2EC0CvQLQLLTFp0VUV0VVGlOVOVGvU54XXC68MP8Lhv+FwmETqBr8WAwW//iK/iKxFxF/hcLhq8VUVYrGGroavFZFYirisRViq+IvEVEXiK+It+IuIpVM/zMMzCyOAyYOAgXMszkAg/FgmTH4FzEofjGU5QMfxguGAGMswXLIxkGUyZBYwxBYwWBcDwgXEsYAa01ljWWPDAsYgTA8VgJh4FwLTgawtMmwVrIFFpS04FxLCxYWA16BQEWLTFpC0ybBactP5aUtMmyWnQKRVUaKy0VlOfUaUaRUUbU5TYTY9ApNn02fLToFFpwKuBVgBQLgDGYA5eCZAGEsDALwuv/DDBdcReIvxF4i4i0RbEVEUEVEUisCqFY//PkZMAjNeL0AHc2XiBykgTq02j0xVw1diqir4qoqxWBWBVRWBVirxVirFYFZhq4VkVQqxVxVBq8VQq4qsVmKxiq8ViKoNWisCrFXFXFZFYFUKvFZFYDVgqsNXYigXD4ivEU+Ip//EWiKf8LrwwwNIYgbBiZZgWC5YLmwlIFFpS0gGXCBC1UOBlp0Cy06bBaUtOFRanAQVUaTYLTlpy0ybIFoCzAtAWorAnQqCrFfFbirBOARAHeLgvC+Fqi4FpB2DpHQdB1AR0Zx1EYF2L38XQtODYNA2DD/////8hB/H/kKP0f5CELIUhP///4/D+PykxBTUUzLjEwMKqqqqqqqqqqqqqqqqqqgj5iUWZn8MphhNZn8GJj8JQEJkxKRgxlH8xkDAzkRgrGUwxBcyYDADBcYLjImweVgWJRlyxli4FLGXYAQsBlqBfgZcBsZlyxaQy5cCSiwWQKLTJsJsAZcBCxaRNhAsrLFpgMtQLQL/y0iBQGWlpPTZQKTZLTAZd6BRWWApYrLJs+gWBl+GHwwwYcMPhdcAYMQAwLQbBoGDFNQGJkWYGBcMgYcMNDDf8RULhguF+Ip8RaIrhq6GrxVBqwVUVYrMNWBq8VmKyKyKxhq8VjEUEV8LhBF4XD/EVx//PkZNghZe72FXdWTiYTQfQA3mp0FIXDRFRFIisLh+ItxFfEViLxFYXDfiKiKRFxFsLhcRbxFP/iKRF4imIriKfEV+IriKCKHWtJWTmCExk6OZOClZMZOCFdYVkxk6MYICmjAhizmLOc05WIYk/gRZNhArywKYs5YFLAhWKWF0Cy06bJaf/QLQKQKTYQL8LhwEgvEUEUEXiKCKCLQuHEWxFBWRVCrDV0GAAVYat//+ERMBqwCgwCf/4qxWRWeKyKyKrxWBViq/FXiq/xWBWYq/4qoq+Kr/xV//////+KvxV1MfnpA7rG2lomTBZGTBMmMglGzQymWRmGC5MGZqzlY/GWYLmZhmmCwlmMgyGTI/GMpMAZyzBYzSwGJgsGJjKJZl2JYYAcsZZgZf8VlywW82P42JYDlwNhAyw5TErLGwLIFoFgbEBlhsSwGWgQuZYsWm/0CgKXLBYDLk2U2CwWQLLSGWL+WlAhcCMU2fQKLTFpkCkCvTZLTlZb02UCi04ELAWUWlAwLBlAyMj/A7gj+AwLAwAGDADALhdbhhww4XWDDhhsMOF14YeF1wwwXXhhoYYGwZ/hhww4XXww/DD8MPC62GGC68RcRWIqFw4ikRWIpiLhcPEVwuGiKwuF4i4i//PkZP8nkfjwAHdWbCdDYewA5miYuIqFwsRaIqItEViLcRWFwuItEVEU+IriKCLiKiK4i4XDCLxF4iwigi/xFuFw+IuIrEX/C6+F1ww2GG+DYNOzGU2YFzJSZM/mUCpkxgFjCwWK0wVjAtOVjADCwDJZNgCDECrlpCtcCYFpSwsBrSwuWmNZdNlAtNhNk11gKuBry0ybJacDXJspsoFJsoFoFpsIFIF/6BaBSbJaRAv02P/4Ybg2DgusF1oYcMOGHwusDYMhhuF1ww8MOAKWA/+ULrf/4XW/8Lr////hh4XW///////8LrcMP////hhlML2tMLpGMyZtOIRUMLwuMLyiNYTJMVRVKxVMVE6MyB7MVSiMVCiKwvMeiiMehVKwIMLiiKwAMHQBMHQdMHBYNxwsdlgEsAFgE7iDJIO+8sEHcQZJBYvMggySCwSZF5YJ8rIMm8rJKySwSZJJWQZJBYuKyCskyCSwQVklZBWSZJJgAlYBYdMBwwQTBAMAAsAGAD5gA4MAT8GAIgYCAEgYVAEwiFQDHcy0DHeToDCoC8IgugwBOEQEf4eaHlw8kPIHkCyAPKHlDzwsjwxUJpE0EqEqE1E1E0DFAmgYqE1E1E1hisMVBisMVwxWDADh5w8v//PkZPAmoc7wAHc2aiarfewA5mpc4eUPLh5sPPDyB5PCyLDyw82Hl4eWHkDzQsiDzQ84eaHmDz/DyB5cPJh5oeSHkw8gWQh54efw8oeQPNh5cPOHlh54eeHmP9xQxcyTKguMqgkz0ezKoJM9u4yoCTF4IKyTJIO4kruMgkyLzcAN0DzIJ8rILBJkkFZB3kGQT/mSQVk+WCTJJLABYAKwCwAWAf//MAD/MEArAMAAPMHnh5w8oeUPLh5MPLDzB5oeT8PL4eX4MZIGCQT//4molYmmJX4lcMVeJoJX4mviVCaCaiV////4ef///////w8//w83DyJAtmXjLmiQHnIh0HDIyGdAymHQdmBwylgOjA8ZDLwkTA8ZDDoDywSJWB5WMhjh5jh5jnZjh5jhxj3ZYdmECmECmEjmECGFTmPHGPHlY8rHmOHFbstIWnLTIFlguWmKy5hQhhAhWEKwpWFMKEMIEMKEAy5AstMgX5YLlpy0xaUtOWm/0C02Csumymwmx/BgDvwiA8DAcA8DAeA4DAeDoDF4LwDdAJEDAcA8IgOwYA7/C4YLhwuEEUC4SFwwi3C4YRURQLhRFBFYXChcJEViLiKRFAuEEVxFf+IuIrxF+IuIsFwsRWIoIrhcN4iw//PkZOsjCez2CXdWTieCoegA5KjMi4i/EU8Rb8RfEUiLfxF4i3iL/EViLxFvEWEWiKYisRTEVEWEXiLfC62F1/hhjimtPqi8xcejBBVMqTorPZsgXmyReYuF5gkEGCAQYJBJhwdGAB2YADhnsEGCQQYJBBWL/MXgksAiDIgcyEZwZIMiEYgcQDIBkgcwEZgyQZAMiDIA4gGRDyQsgDyQ84BhDw80LIg84eYPPCyKDAGEQIMAQYA8GAMIrwP1UAxIn//hZD8PN/4eTw8mHkDz4eTh5w8v4RA//gwB4RAfwYAqEFlQj9SHDGQSjWaHANGZjKZpYBYwwGQx+DEx+EsytO4wnAUxHCYDBeYYAsBhgMfwxMmCZMMRlApmAYlAIJRiWGBymB5GIGXIFgQsZdiBCxsSxsS5aYyzEsJiwnOMnMKnKwhhQvpsps/5aYy5cCFzYFzChDChCsIacIacKYQKYQKVpjChfLAQ0wQwgUwoQwoUsBSsL5hQvpslp/9NlNhAr02UCy0xsSwELgYMAYgYs0OAZ6CZgDCWAGBaGGhhv4YcGweDYMg2DYYeGH4XXhdYGwaF1gwwXWC6wXWhhuDYMhhwuvDDBdfww4YYMPDD8Lr4Yf4YcMOF1guthhoYcGwY//PkZP8obgDyZndWbCa6JeQA7mq4GGhhwuv/DDhdeGHwbBnww0Lrhh/wbBuGH+F1vwusGGhh4XX+GH/8MODACwMAgBAiAQGAFCIBYMAKDACfgwAnwYAWDACGzWoFY6mFoWmOgWlY6mFgWmOg6Gg46FgdSsLCsdPMZA7MOxkMOgOMLB1MdQsKwt8zjzPP8+zjPOM88zjiwcVneWDis7/LB5nHFZ5WeWD///8sH+VnGcd5ab/LSpsf5actJ6bBadNnwNcmwmwWkTZTY//4Mn3/8NWhq8NXhqwVgVYrIrAqorENXxVisiqFYDV3hhuGGC634YfhdapMQTzl6TH4MTH+6jDAMQKMpYBcx/GUrH4xkBcwwGUx+GQxLLMDBeYLDIYYCUWnMmRkMmAwA2EyxdAs5ZYtMgUZcuBSyBZli5y2AGXgVgBWPgQuWGKbCBZWWTYAy1NgDYi0voFgZcBC6bJYLJsIFFZYtKgUmyWkQKLSFp/QLLTFp/QLTZQKTYTZ9NhNlNj/TYTZQLLTAZcWlAGBaBh/D+Bu7D8AMJYLrBhsMN4XXxWIauw1dhq8VUVgVeKwKoNXCs+GrBVishqwViKxirFZxWYqhVxWBFxF+IuIsIuIrEWEU/xF+IrEV4i//4io//PkZOkjNgT0AHdWXibKjfAA5qgYi/EXEViK+IqIt+FwginiLCKiKCKiL//EVhcNiLRV/FWKxFZxWIrIasxVirxWQ1dFWbl8pqIxmIjEcaOZWOcYoVxyvuccoa9cViDELjXiTECCteeJF5okZYAlgCWAJYAmAAGcAGBAeWAEIiAYJCInDFARDAMDgGBolYYrhEADAIGBAwiBwYAErhikSrDFIlYmomolcSoIhwFgwYoEqiVf/8IogNGj//xKolfwxQJrwxV8QXjEGIMQXXF1/GJGJGJE0E08TUTSJV/xNcSuMBOOMJysNVNMOHBoNVWXMiiKMrBGMJgENMwmNdAENEAnMBRHMBQmMJisMJwFMBRGMaSLMBStNGRjRmgycmMmJzBEYyYENGaDaAQyYnOKBDR2kwQnNGRzRgQ0dHKwUwVGMnBDBQQyYFMEBDJgUwUFMnJywClYIWAUsAhYBSsEMEBCwClgFKwQrBCwCmCkxYBDBCYwUFLAIVghYBSsFMFJzBAUwQFLAL/mCghYBPKwQrBP8rBSsF8rJitoKycGAnAxFG5A2mnaAxWBHAwTgmAwCAFBgBQYAXhEAuEQCgwAgYeF1ww/DDwuvC6wYcLrg2DAwwYfEWEWwuFEUEUiLYig//PkZP8rbejuAHd2XihaFegA5mq0ioXCBcPiKcRQLhoYYLrwusF1wuv/hdcLrwbB+F1gbBwYaGHCIBPwiASDACQYAXwMAgBOEQC/BgBfBgBfwuuGHDDBh4XXBsG8LreF1oNg/hh4YcMPhdaDACf+EQCGTl0cmJxk4nFZOLBOMnrozGYzMYiLAjMXC8wQLzBIuMRmIrEZWIzF4JMEHowSCTJIKySwT5kEGSSZN5YIMkgySTIJKyTJJKySwQDo1GEAxWh6jKjJYJMgksEFgkrI/yslAKDoPQCqMg6D1ElE/BgIAwQCPh5YecPMHkh5uHkDzh5cGRX/+HnwsgDz/wsgw8+HnDy+FkGHkDzYeYPOHkDzB5JMQU1FMy4xMDCqqqqqqqqqqqqqqqqqqghCkEoqB0+acYmQp5pg8Z8XByEHARn4iqcOY2gGJB4JCk8kiZMlWWoeoFBqR9dAIlb1nD4qk2sZuzF2j+5C1nbzzXiXwcpDkkGqomIp3g1p7HpfBnbl23v/BjL/4MccjsHuRCIAw5tqdJLLHFjrDqnXYIgAwERMfPTvA8BBIBARGAt833F3tTz1SUlgEBbggKAQ4fFYrFYfD5UViuA4CB7wGBYPfisV/i53nYYLc1DLBAlbFgQF//PkZLYh2h0Ie20v6ByadiAEwZsQvMHw7UMD8MF+NAhOcMGh2c587PmiqOA5ge/Fjn4anotzpo1nFob+Lf+YHDQIaHQ76HghPwHgc//47MdnOd/e5X3NXFKa+uat73vgxX79/Hvy0pvEB5TWbxwbJOGRYWSnaaWLECrU+FYVY02mXMlf9qjwxWndR841RRug/UbiMZfGjdDyQKUUUCkAtuF44dyJZSiBRXKV96+UtZedozxEd7u0AJmBMKaab6aehnDme8/Pl3zm6H7etVB85/03OfP+s//9v+t8R//V6fRVTEFNRTMuMTAwVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVSgFAQrnY+FhjdgAVwUTN5lBGZQLqRaZljlV4lHF3Q2w1+a1M4TLnqWNF3ZnL8Z1VoZ39/cjVfGVX6tn8dU3ct8iUWlL+y25jKYzZ5TQ0/098qdphzjRFlIEHARqHM4LQVIbbQQlC1bUxUVXWgFyXdh2zwiAMGWYEQJBppMBRU1qYpDJLABEQVE4kSCkjSRJLyaCkcNIkc9EiRKCRIksFCZn5zSKJxIlPYkS2Zmq7mkcqjUcokl204kk5Eij6mZ9VVVJRJJ5kjLzJs1VVLVVd5mtkjnwgVgKfkNxBcgU2EF//PkROAcBd7+AGUm4LLz+gQEyYfJVWAFzYVNqc4gzBWNEIAhIlmIauNvXefWQUcMuTCXZiEXWFg5/qOga1RWqBhzrRF/Yy/s9VlMMy3WUqhqzW7yVRqmzhmXZf+4Zh29TSmMzuqaNRqNV6aVP8/08+rWUeUrUOJnIl3DLGTWp2ss5ZzPQzGaWzjyiRFFjiRLXNqt5pGWJEcnKJEkiKTa8/5ztxwVRxwMo6jkvJqOvnlFkt85TfNmZgoCXFElGbjQCFVVWN1Y34YBBARqWpfqrHxfjeqswMBRv+rG/aCvBSQSTEFNRTMuMTAwqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq'
                );
                audioEl.id = 'examToastAudio';
                audioEl.preload = 'auto';
                audioEl.style.display = 'none';
                document.body.appendChild(audioEl);
              }
              // try to set playbackRate to approximate pitch
              try { audioEl.playbackRate = Math.max(0.4, Math.min(2.4, pitchHz / 440)); } catch(e){}
              audioEl.currentTime = 0;
              var p = audioEl.play();
              if (p && typeof p.catch === 'function') {
                p.catch(function(){ tryOscillator(); });
              }
              return;
            } catch (e) {
              // fallback to oscillator
              tryOscillator();
            }

            function tryOscillator() {
              try {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                if (!window.__examAudioCtx) window.__examAudioCtx = new Ctx();
                var ctx = window.__examAudioCtx;

                // resume if suspended (may require user gesture)
                if (ctx.state === 'suspended' && typeof ctx.resume === 'function') {
                  ctx.resume().catch(function(){ /* ignore */ }).then(playOsc);
                } else {
                  playOsc();
                }

                function playOsc() {
                  try {
                    var o = ctx.createOscillator();
                    var g = ctx.createGain();
                    o.type = 'sine';
                    o.frequency.value = pitchHz;
                    g.gain.setValueAtTime(0.0001, ctx.currentTime);
                    g.gain.linearRampToValueAtTime(0.12, ctx.currentTime + 0.01);
                    g.gain.linearRampToValueAtTime(0.0001, ctx.currentTime + 0.15);
                    o.connect(g);
                    g.connect(ctx.destination);
                    o.start();
                    setTimeout(function(){ try { o.stop(); } catch(e){} }, 160);
                  } catch (err) { /* ignore */ }
                }
              } catch (err) { /* ignore */ }
            }
          }

          function showToast(message, type) {
            type = type || ''; // '', 'warn', 'crit'
            var t = document.createElement('div');
            t.className = 'exam-toast ' + (type ? type : '');
            t.textContent = message;
            toastContainer.appendChild(t);
            // force reflow -> add show class
            void t.offsetWidth;
            t.classList.add('show');

            // play ding
            try { playDing(type); } catch(e){ console.warn('playDing failed', e); }

            // remove after 5s
            setTimeout(function(){
              t.classList.remove('show');
              setTimeout(function(){ t.remove(); }, 260);
            }, 5000);
            return t;
          }

          /* ===== Network-change notifications ===== */
          var lastNetworkState = null;
            function getNetworkStateString() {
              if (!navigator.onLine) return 'offline';
              var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
              if (c && c.effectiveType) {
                return 'online-' + c.effectiveType; // e.g. online-4g, online-3g
              }
              return 'online';
            }

            // ensure lastNetworkState exists
            if (typeof lastNetworkState === 'undefined') {
              var lastNetworkState = null;
            }

            // wrap existing updateNetworkStatus to emit toast on change
            var originalUpdateNetworkStatus = window.updateNetworkStatus || function(){};
            window.updateNetworkStatus = function() {
              try {
                // call original behavior first (so DOM netStatusEl is updated)
                originalUpdateNetworkStatus();
              } catch(e) { console.warn(e); }

              try {
                var state = getNetworkStateString();
                if (state !== lastNetworkState) {
                  var msg = '';
                  var toastType = '';

                  if (state === 'offline') {
                    msg = 'Mất kết nối — bạn đang ngoại tuyến';
                    toastType = 'crit';
                  } else {
                    // online or online-<type>
                    var parts = state.split('-');
                    if (parts.length > 1) {
                      var eff = parts[1]; // e.g. '4g', '3g', '2g', 'slow-2g'
                      if (eff === '4g') {
                        msg = 'Kết nối mạnh';
                        toastType = 'success'; // neutral / success
                      } else if (eff === '3g') {
                        msg = 'Kết nối yếu';
                        toastType = 'warn';
                      } else if (eff === '2g' || eff === 'slow-2g') {
                        msg = 'Kết nối rất yếu';
                        toastType = 'crit';
                      } else {
                        // unknown effective type
                        msg = 'Đã kết nối lại';
                        toastType = 'success';
                      }
                    } else {
                      msg = 'Đã kết nối lại';
                      toastType = 'success';
                    }
                  }

                  showToast(msg, toastType);
                  lastNetworkState = state;
                }
              } catch (err) {
                console.error('toast network error', err);
              }
            };

          /* listen to native events and call updateNetworkStatus so our wrapper runs */
          window.addEventListener('online', function(){ window.updateNetworkStatus(); });
          window.addEventListener('offline', function(){ window.updateNetworkStatus(); });
          try {
            if (navigator.connection) navigator.connection.addEventListener('change', window.updateNetworkStatus);
          } catch(e){ /* ignore */ }

          // initialize lastNetworkState to current so we don't fire at load
          lastNetworkState = getNetworkStateString();

          /* ===== Countdown milestone notifications ===== */
          var milestoneThresholds = [
            {s: 15*60, label: '15:00'}, // 15 phút
            {s: 5*60,  label: '5:00'},  // 5 phút
            {s: 60,    label: '1:00'},  // 1 phút
            {s: 30,    label: '0:30'},  // 30 giây
            {s: 5,     label: '0:05'}   // 5 giây
          ];
          var triggeredMilestones = new Set();

            function checkCountdownMilestones(remainingSeconds) {
              try {
                // đảm bảo là số nguyên
                remainingSeconds = Number(remainingSeconds);
                if (Number.isNaN(remainingSeconds)) return;

                // nếu đồng hồ có thể tăng (ví dụ resume hoặc reset), ta không reset triggeredMilestones autom; 
                // tuy nhiên nếu remainingSeconds > max threshold + 60 thì reset (tức là bài mới/bắt đầu lại)
                var maxThresh = milestoneThresholds[0].s;
                if (remainingSeconds > maxThresh + 60) {
                  triggeredMilestones.clear();
                }

                // CHỈ thông báo khi remainingSeconds === th.s (bằng chính xác)
                milestoneThresholds.forEach(function(th){
                  var thr = Number(th.s);
                  if (remainingSeconds === thr && !triggeredMilestones.has(thr)) {
                    // show toast
                    var prefix = 'Thời gian còn lại: ';
                    var msg = '';
                    if (thr >= 60) {
                      var m = Math.floor(thr / 60);
                      msg = prefix + m + ' phút';
                    } else {
                      // dùng số giây (không dùng '0:05' từ th.label)
                      msg = prefix + thr + ' giây';
                    }

                    // các ngưỡng nhỏ (30s,5s) là cảnh báo mạnh
                    var type = (thr <= 30) ? 'crit' : (thr <= 60 ? 'warn' : '');
                    showToast(msg, type);
                    // optionally also play a soft beep? (handled inside showToast)
                    triggeredMilestones.add(thr);
                  }
                });
              } catch (e) {
                console.error('checkCountdownMilestones error', e);
              }
            }

          // Hook vào startCountdown: nếu startCountdown đã tồn tại, wrap it so each tick calls our checker.
          if (window.startCountdown && typeof window.startCountdown === 'function') {
            var origStart = window.startCountdown;
            window.startCountdown = function() {
              origStart();

              if (window.countdownTimer) {
                clearInterval(window.countdownTimer);
              }
              if (typeof totalSeconds === 'undefined') totalSeconds = 0;

              try {
                var el = document.getElementById('countdown');
                if (el) el.textContent = (typeof formatTime === 'function') ? formatTime(totalSeconds) : totalSeconds;
              } catch(e){}

              window.countdownTimer = setInterval(function(){
                totalSeconds--;
                if (totalSeconds < 0) {
                  clearInterval(window.countdownTimer);
                  alert("Đã hết thời gian! Hệ thống sẽ tự động nộp bài.");
                  try { document.getElementById('examForm').submit(); } catch(e){ /* fallback */ }
                } else {
                  try {
                    var el = document.getElementById('countdown');
                    if (el && typeof formatTime === 'function') {
                      el.textContent = formatTime(totalSeconds);
                    } else if (el) {
                      el.textContent = totalSeconds;
                    }
                  } catch(e){}
                  // call our milestone checker
                  checkCountdownMilestones(totalSeconds);
                }
              }, 1000);
            };
          } else {
            var attachTimer = setInterval(function(){
              if (window.startCountdown && typeof window.startCountdown === 'function') {
                clearInterval(attachTimer);
                var tmp = window.startCountdown;
                tmp();
              }
            }, 200);
            setTimeout(function(){ clearInterval(attachTimer); }, 5000);
          }

          // Nếu countdown đang chạy, kiểm tra ngay (sẽ chỉ hiển thị nếu bằng chính xác ngưỡng)
          try {
            if (typeof totalSeconds !== 'undefined' && totalSeconds !== null) {
              checkCountdownMilestones(totalSeconds);
            }
          } catch(e){}
        })();
        </script>
        <script>
        (() => {
          function convertSVGtoIMG(svg) {
            try {
              const s = new XMLSerializer().serializeToString(svg);
              const svgText = /xmlns=/.test(s) ? s : s.replace('<svg','<svg xmlns="http://www.w3.org/2000/svg"');
              const base64 = btoa(unescape(encodeURIComponent(svgText)));
              const img = document.createElement('img');
              img.src = 'data:image/svg+xml;base64,' + base64;

              // --- Lấy viewBox nếu có ---
              let vbW = null, vbH = null;
              try {
                if (svg.viewBox && svg.viewBox.baseVal && svg.viewBox.baseVal.width && svg.viewBox.baseVal.height) {
                  vbW = svg.viewBox.baseVal.width;
                  vbH = svg.viewBox.baseVal.height;
                } else if (svg.hasAttribute && svg.hasAttribute('viewBox')) {
                  const vb = svg.getAttribute('viewBox').trim().split(/[\s,]+/);
                  if (vb.length >= 4) {
                    vbW = parseFloat(vb[2]);
                    vbH = parseFloat(vb[3]);
                  }
                }
              } catch (e) {
                // ignore parse errors, tiếp tục với fallback
                vbW = vbH = null;
              }

              // --- Nếu có viewBox thì set kích thước dựa trên viewBox * scale ---
              if (vbW && vbH) {
                const w = Math.round(vbW * 2);
                const h = Math.round(vbH * 2);
                // đặt thuộc tính width/height (px)
                img.setAttribute('width', String(w));
                img.setAttribute('height', String(h));
                // vẫn cho responsive bằng CSS
                img.style.maxWidth = '100%';
                img.style.height = 'auto';
                img.style.display = 'block';
              } else {
                // fallback: giữ hành vi cũ nếu không có viewBox
                if (!img.getAttribute('width') && !img.getAttribute('height')) {
                  img.style.maxWidth = '100%';
                  img.style.height = 'auto';
                  img.style.display = 'block';
                }
              }

              // --- copy một số attribute thông thường ---
              ['style','title','alt','aria-label','role'].forEach(a=>{
                if (svg.hasAttribute && svg.hasAttribute(a)) img.setAttribute(a, svg.getAttribute(a));
              });
              // copy class
              const cls = svg.getAttribute && svg.getAttribute('class');
              if (cls) img.setAttribute('class', cls);

              // replace
              svg.parentNode.replaceChild(img, svg);
              return true;
            } catch (e) {
              console.error('convertSVGtoIMG error', e, svg);
              return false;
            }
          }

          document.addEventListener('DOMContentLoaded', () => {
            // CHỈ convert svg có data-convert="true"
            const targets = Array.from(document.querySelectorAll('svg[data-convert="true"]'));
            let c = 0;
            targets.forEach(svg => { if (convertSVGtoIMG(svg)) c++; });
            if (c) console.log('Converted', c, 'marked SVG(s).');
          });
        })();
        </script>
        <script>
        /* --- Violation check client-side (kiểm tra định kỳ mỗi 20s) - kiểm tra file JSON public --- */
        (function(){
          var alreadyShownLevels = {}; // tránh hiện nhiều lần cùng mức trong 1 session

          // PHP-inserted values
          var examName = <?= json_encode($examName) ?>;
          var studentID = <?= json_encode($studentID) ?>;

          function buildJsonUrl() {
            // thêm param _ để tránh cache (cache-buster)
            return 'https://biolifethithu.wuaze.com/bai-thi/' +
                   encodeURIComponent(examName) + '/' +
                   encodeURIComponent(studentID) + '.json?_=' + Date.now();
          }

          function checkErrorFile() {
            var url = buildJsonUrl();
            fetch(url, {
              method: 'GET',
              cache: 'no-store', // cố gắng tránh cache
              mode: 'cors' // nếu server cho phép CORS
            })
            .then(function(r){
              if (!r.ok) {
                // không bắt buộc phải log lỗi lên console nhưng hữu ích để debug
                console.warn('Không thể tải file JSON lỗi, status:', r.status);
                return null;
              }
              return r.json().catch(function(err){
                console.warn('JSON parse lỗi từ', url, err);
                return null;
              });
            })
            .then(function(json){
              if (!json) return;

              // kỳ vọng json có cấu trúc giống ví dụ: { ..., "error": { "1": "no", "2": "yes", ... } }
              var errs = json.error || {};
              var pendingLevels = Object.keys(errs).filter(function(k){
                return String(errs[k]).toLowerCase() === 'no';
              }).map(function(k){ return parseInt(k,10); }).filter(Boolean);

              if (!pendingLevels || pendingLevels.length === 0) return;

              // chọn mức nặng nhất hiện có
              var maxLevel = Math.max.apply(null, pendingLevels);

              // nếu đã hiển thị cho mức này trong session này rồi thì bỏ qua
              if (alreadyShownLevels[maxLevel]) return;

              // hiển thị modal
              showViolationModal(maxLevel);
              alreadyShownLevels[maxLevel] = true;
            })
            .catch(function(err){
              console.warn('checkErrorFile lỗi:', err);
            });
          }

          function ackErrorOnServer(level) {
            // giữ nguyên call ack tới endpoint server cũ (nếu bạn muốn thông báo lại)
            fetch('?action=ackError&examName=' + encodeURIComponent(examName) +
                  '&studentID=' + encodeURIComponent(studentID), {
              method: 'POST',
              headers: {'Content-Type':'application/json'},
              body: JSON.stringify({ level: String(level), ts: Date.now() })
            })
            .then(function(r){ return r.json().catch(function(){ return null; }); })
            .then(function(json){
              console.log('ackError result', json);
            })
            .catch(function(err){ console.warn('ackError lỗi:', err); });
          }

          // --- Thêm/Thay thế: khai báo timer (đặt ngay dưới alreadyShownLevels) ---
            var modalCountdownTimer = null;
            var modalRemaining = 0;
            var modalTotalSeconds = 20; // đổi đây nếu muốn thời gian khác (ví dụ 15)

            // format mm:ss
            function formatTime(seconds) {
              var m = Math.floor(Math.max(0, seconds) / 60);
              var s = Math.max(0, seconds) % 60;
              return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            }

            // --- Thay thế hàm showViolationModal bằng hàm này ---
            function showViolationModal(level) {
              var bodyEl = document.getElementById('violationModalBody');
              var primBtn = document.getElementById('violationPrimaryBtn');
              var modalEl = document.getElementById('violationModal');
              if (!bodyEl || !primBtn || !modalEl) return;

              var message = '';
              var primText = 'Tiếp tục';
              if (level === 1) {
                message = 'Bạn có thao tác không hợp lệ và vi phạm mức độ 1:<br></br><div style="width:100%; color:red; text-align:center; font-size:20px;">Khiển trách</div><br>Hãy bấm nút \"Tiếp tục\" để tiếp tục làm bài thi của bạn.';
                primText = 'Tiếp tục';
                primBtn.className = 'btn';
                primBtn.style.background = '#003366';
                primBtn.style.color = '#fff';
              } else if (level === 2) {
                message = 'Bạn có thao tác không hợp lệ và vi phạm mức độ 2:<br></br><div style="width:100%; color:red; text-align:center; font-size:20px;">Cảnh cáo</div><br>Hãy bấm nút \"Tiếp tục\" để tiếp tục làm bài thi của bạn.';
                primText = 'Tiếp tục';
                primBtn.className = 'btn btn-warning';
                primBtn.style.background = '#ffc107';
                primBtn.style.color = '#000';
              } else if (level === 3) {
                message = 'Bạn có thao tác không hợp lệ và vi phạm mức độ 3:<br></br><div style="width:100%; color:red; text-align:center; font-size:20px;">Đình chỉ</div><br>Hãy bấm nút \"Nộp bài\" ngay lập tức.';
                primText = 'Nộp bài';
                primBtn.className = 'btn btn-danger';
                primBtn.style.background = 'red';
                primBtn.style.color = '#fff';
              } else {
                message = 'Phát hiện thao tác không hợp lệ. Vui lòng đọc thông báo.';
                primText = 'Đóng';
                primBtn.className = 'btn btn-secondary';
                primBtn.style.background = '';
                primBtn.style.color = '';
              }

              // set nội dung modal
              bodyEl.innerHTML = '<div style="font-weight:700; color:#333; font-size:1rem;">' + message + '</div>';
              primBtn.textContent = primText;

              // clear countdown cũ nếu có
              function clearModalCountdown() {
                if (modalCountdownTimer) {
                  clearInterval(modalCountdownTimer);
                  modalCountdownTimer = null;
                }
                modalRemaining = 0;
                var oldSpan = document.getElementById('violationCountdownSpan');
                if (oldSpan && oldSpan.parentNode) oldSpan.parentNode.removeChild(oldSpan);
              }

              clearModalCountdown();

              // tạo span hiển thị ở BÊN TRÁI nút (format MM:SS)
              var span = document.createElement('span');
              span.id = 'violationCountdownSpan';
              span.setAttribute('aria-live', 'polite');
              span.style.marginRight = '10px';         // khoảng cách sang phải (sang nút)
              span.style.fontWeight = '700';
              span.style.fontSize = '1rem';
              span.style.verticalAlign = 'middle';
              span.style.color = '#003366';            // màu yêu cầu
              span.textContent = formatTime(modalTotalSeconds);

              // chèn span trước nút (bên trái nút)
              if (primBtn.parentNode) {
                primBtn.parentNode.insertBefore(span, primBtn);
              } else {
                // fallback: append vào footer
                var footer = document.getElementById('violationModalFooter');
                if (footer) footer.appendChild(span);
              }

              // show modal
              var bm = bootstrap.Modal.getOrCreateInstance(modalEl);
              bm.show();

              // ack server ngay lúc hiện modal (nếu cần)
              ackErrorOnServer(level);

              // handler nút chính: dọn timer + hành động tương ứng
              primBtn.onclick = function() {
                clearModalCountdown();
                try { bm.hide(); } catch(e) {}
                if (level === 3) {
                  var form = document.getElementById('examForm');
                  if (form) form.submit();
                }
              };

              // nếu modal bị ẩn theo cách khác -> clear timer
              modalEl.addEventListener('hidden.bs.modal', function onHidden() {
                clearModalCountdown();
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
              });

              // bắt đầu countdown
              modalRemaining = modalTotalSeconds;
              modalCountdownTimer = setInterval(function() {
                modalRemaining -= 1;
                var sp = document.getElementById('violationCountdownSpan');
                if (sp) sp.textContent = formatTime(modalRemaining);

                if (modalRemaining <= 0) {
                  // hết thời gian -> clear + auto click nút
                  clearModalCountdown();
                  try {
                    primBtn.click();
                  } catch (e) {
                    try { bm.hide(); } catch(err) {}
                  }
                }
              }, 1000);
            }

          // Start periodic check every 20 seconds
          var CHECK_INTERVAL_MS = 20000;
          var violationCheckerTimer = setInterval(checkErrorFile, CHECK_INTERVAL_MS);

          // Run one check immediately (optional)
          checkErrorFile();

          // Dọn dẹp khi unload trang
          window.addEventListener('beforeunload', function(){
            clearInterval(violationCheckerTimer);
          });
        })();
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ------------------ BƯỚC 4: HIỂN THỊ MẶC ĐỊNH ------------------

if ($studentID === 'dethi') {
        if (!empty($_GET['studentID'])) {
            // Lọc chỉ lấy số, tránh ký tự lạ
            $inputID = preg_replace('/\D+/', '', $_GET['studentID']);
            // Chuyển hướng về URL /bai-thi/{examName}/{inputID}
            header('Location: /bai-thi/' . urlencode($examName) . '/' . urlencode($inputID));
            exit;
        }
    }
$displayExamName = $timeData['realNameExam'] ?: $examName;
$note     = trim($timeData['note'] ?? '');
$hasNote  = $note !== '';
$hasNote = trim($timeData['note'] ?? '') !== '';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Thi thử - <?= htmlspecialchars($displayExamName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon-32x32.png?v=2" type="image/png" sizes="32x32">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- html2pdf.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fa;
            height: 100vh;
            margin: 0;
            overscroll-behavior-y: none; 
            touch-action: pan-y;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background-color: #003366;
            color: #fff;
            border-top-left-radius: 12px;
            font-size: 1.5rem;
            border-top-right-radius: 12px;
            text-align: center;
            font-weight: bold;
        }
        .card-body {
            padding: 2rem;
        }
        .btn-primary {
            background-color: #003366;
            border-color: #003366;
        }
        .btn-primary:hover {
            background-color: #002244;
            border-color: #002244;
        }
        #clock {
            font-size: 1rem;
            font-weight: bold;
        }
        .error {
            color: #d9534f;
            font-weight: bold;
        }
        .info {
            margin-top: 1.5rem;
            text-align: center;
        }
        .note {
            color: #003366;
            padding: 10px;
            border-radius: 5px;
            margin: 0 10px;
            text-align: left;
            font-size: 1rem;
        }
        .wide-card {
        max-width: 90% !important;
      }
        .footer { text-align: center; padding: 1rem 0; padding-top: 0px; font-size: 0.9rem; color: #666; }
           .hero-left{
      padding-top: 10px;
      color: #ffffff;
      align-self: start;
      text-align: center;
      margin-bottom:30px;
    }
    .subtitle{
      font-weight:700;
      letter-spacing:2px;
      margin:0 0 15px 0;
      font-size:28px;
      opacity:0.95;
    }
    .title{
      margin: 0;
      font-weight:800;
      font-size:40px;            /* very large like the screenshot */
      line-height:0.9;
      transform: translateY(-6px);
      text-transform: uppercase;
      -webkit-font-smoothing:antialiased;
    }
    /* responsive */
    @media (max-width: 980px){
      .hero-left{ margin-top:30px;}
      .title{ font-size:40px; }
    }
    @media (max-width:720px){
      .title{ font-size:35px; }
      .hero-left{ margin-top:30px;}
      .subtitle{ font-size:25px; }
    }
    /* Contact box */
    #contact-box {
      position: fixed;
      left: 1rem;
      bottom: 1rem;
      z-index: 99999;
      background: rgba(0, 51, 102, 0.95); /* màu chính */
      color: #ffffff;
      padding: 0.6rem 0.85rem;
      border-radius: 0.6rem;
      box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
      max-width: 320px;
      font-size: 0.92rem;
      line-height: 1.2;
      backdrop-filter: blur(4px);
    }

    #contact-box a { color: #fff; text-decoration: underline; }
    #contact-box .contact-row { display: flex; gap: .5rem; align-items: center; margin-bottom: 0.35rem; }
    #contact-box .contact-row:last-child { margin-bottom: 0; }

    #contact-box .icon {
      min-width: 28px;
      text-align: center;
      font-size: 1.05rem;
      opacity: 0.95;
    }

    #contact-box .close-btn {
      position: absolute;
      top: 0.25rem;
      right: 0.35rem;
      background: transparent;
      border: none;
      color: #ffffff;
      font-size: 1.05rem;
      cursor: pointer;
    }
        *{
            scrollbar-width: auto;      
            scrollbar-color: #003366 transparent; /* thumb color | track color */
        }
        #sidebar::-webkit-scrollbar { width: 10px; }
        #sidebar::-webkit-scrollbar-track { background: transparent; }
        #sidebar::-webkit-scrollbar-thumb {
            background: #003366;
            border-radius: 6px;
            border: 2px solid rgba(0,0,0,0); /* khoảng đệm mượt */
        }
        #sidebar::-webkit-scrollbar-thumb:hover { background: #002244; }
    /* Small screens: giảm kích thước, hoặc ẩn nếu muốn */
    @media (max-width: 420px) {
      #contact-box { left: 0.5rem; bottom: 0.5rem; right: 0.5rem; max-width: calc(100% - 1rem); padding: 0.5rem; font-size: 0.88rem; }
    }
    </style>
</head>
<body>
<div id="contact-box" role="region" aria-label="Thông tin liên hệ">
  <div class="contact-row">
    <div class="icon"><i class="bi bi-envelope-fill" aria-hidden="true"></i></div>
    <div><strong>Gmail: </strong><a href="mailto:biologyslife@gmail.com">biologyslife@gmail.com</a></div>
  </div>

  <div class="contact-row">
    <div class="icon"><i class="bi bi-tiktok" aria-hidden="true"></i></div>
    <div><strong>Tiktok: </strong><a href="https://www.tiktok.com/@sinh.hc60" target="_blank" rel="noopener noreferrer">@sinh.hc60</a></div>
  </div>

  <div class="contact-row">
    <div class="icon"><i class="bi bi-facebook" aria-hidden="true"></i></div>
    <div><strong>Facebook: </strong><a href="https://www.facebook.com/people/Biologys-Life/61563180031291/" target="_blank" rel="noopener noreferrer">Biology's Life</a></div>
  </div>
</div>
    <div class="d-flex justify-content-center align-items-center h-100">
        <div id="exam-card" class="card w-100" style="max-width: 500px;">
            <div class="card-header">
                Đề thi: <?= htmlspecialchars($displayExamName) ?>
            </div>
            <div class="card-body" style="padding: 10px; margin-top: 10px;">
                <?php
                // Nếu $studentID rỗng hoặc bằng "0000" → hiển thị form nhập số báo danh theo thời gian
                if (empty($studentID) || $studentID === 'dethi'):?>
                    <!-- Thông báo chưa bắt đầu -->
                    <div id="msg-before" class="alert alert-danger text-center" style="display:<?= empty($startExam) ? 'none' : 'block' ?>;">
                        Thời gian làm bài chưa bắt đầu.<br>
                        <?php if (! empty($startExam)): ?>
                            Bắt đầu từ: <?= $startExam->format('H:i d/m/Y') ?>
                        <?php endif; ?>
                    </div>

                    <!-- Thông báo đã kết thúc -->
                    <div id="msg-after" class="alert alert-danger text-center" style="display:<?= empty($endExam) ? 'none' : 'block' ?>;">
                        Thời gian làm bài đã kết thúc.<br>
                        <?php if (! empty($endExam)): ?>
                            Kết thúc vào: <?= $endExam->format('H:i d/m/Y') ?>
                        <?php endif; ?>
                    </div>

                    <!-- Form nhập số báo danh luôn có trong DOM -->
                    <div id="form-entry" class="px-4">
                    <p class="text-center">Vui lòng nhập số báo danh để bắt đầu làm bài.</p>
                    <form method="get" action="">
                        <div class="mb-3">
                        <label for="studentID" class="form-label">Số báo danh:</label>
                        <input type="text" class="form-control" id="studentID" name="studentID"
                                placeholder="Nhập số báo danh..." required pattern="\d+" title="Chỉ nhập số">
                        </div>
                        <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Xác nhận</button>
                        </div>
                        <div class="d-flex justify-content-between" style="margin-top: 5px;">
                        	<a href="#" data-bs-toggle="modal" data-bs-target="#registerModal" style="font-size:0.75rem;color:#003366">Chưa có số báo danh?</a>
                        	<a href="https://www.biolifethithu.wuaze.com/huong-dan-thi-thu" target="_blank" style="font-size:0.75rem;color:#003366">Hướng dẫn</a>
                        </div>
                    </form>
                    </div>
                    <div class="d-flex justify-content-center mt-3">
                        <span>Thời gian hiện tại: </span>
                        <span id="clock" class="ms-2">--:--:--</span><br>
                    </div>
                    <div class="note">
                        <p><strong>Lưu ý:</strong></p>
                        <ul style="text-align: justify;">
                            <li>Không giới hạn số lượt thi, điểm thi được lấy ở lượt thi cuối cùng.</li>
                            <li>Đề của page đều chưa qua phản biện đề nên có thể xảy ra một số sai sót.</li>
                            <li>Đề thi này yêu cầu trên điểm liệt (1 điểm) để xem được đáp án khi tra cứu điểm.</li>
                        </ul>
                    </div>

                    <!-- Modal Đăng ký -->
                    <div class="modal fade" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                        <div class="modal-header" style="background-color: #003366; color: #fff;">
                            <h5 class="modal-title" id="registerModalLabel">Bạn chưa có số báo danh?</h5>
                            <button type="button" style="background-color: #fff;" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                        </div>
                        <div class="modal-body text-center">
                            <p>Chọn một trong hai hình thức đăng ký:</p>
                            <div class="d-grid gap-2">
                            <a href="https://biolifethithu.wuaze.com/user/user.php?thi-thu" target="_blank" class="btn btn-primary">
                                Đăng ký số báo danh lần thi thử hiện tại
                            </a>
                            <a href="https://biolifethithu.wuaze.com/user/user.php?tai-khoan-thi-thu" target="_blank" class="btn btn-primary">
                                Đăng ký số báo danh tài khoản thi thử
                            </a>
                            <p>Số báo danh của tài khoản thi thử vẫn có thể sử dụng để tham gia các lần thi thử page <a style="color: #003366;"><b>Biology's Life</b></a> đăng.</p>
                            </div>
                        </div>
                        </div>
                    </div>
                    </div>
                <?php 
                    else:
                        // Nếu đã có $studentID (không rỗng và không phải "0000")
                        if ($foundUser):

                        // ─── THÊM ĐOẠN KIỂM TRA QUYỀN TRUY CẬP ───
                        $classFile = __DIR__ . '/class.json';
                        $classData = file_exists($classFile)
                            ? json_decode(file_get_contents($classFile), true)
                            : [];
                        $allowed = true;
                        $matched = false;
                        foreach ($classData as $entry) {
                            if (($entry['idExam'] ?? '') === $examName) {
                                $matched = true;
                                $sbd = trim(strtolower($entry['SBD'] ?? ''));
                                if ($sbd !== 'all') {
                                    $list    = array_map('trim', explode(',', $entry['SBD']));
                                    $allowed = in_array($studentID, $list, true);
                                }
                                break;
                            }
                        }
                        // Nếu không tìm thấy mã đề trong file, vẫn cho phép
                        // Nếu không được phép ⇒ in thông báo và đóng đúng wrapper rồi exit
                        if (!$allowed): ?>
                            <p class="error text-center">Bạn không thể truy cập đề thi này.</p>
                            <p class="text-center">Vui lòng inbox cho page <a href="https://www.facebook.com/share/1CwPFoZysQ/?mibextid=wwXIfr" target="_blank" rel="noopener noreferrer" style="color: #003366; cursor: pointer"><strong>Biology's Life</strong></a> để được xử lý.</p>
                            </div> <!-- đóng .card-body -->
                            <div class="footer">&copy; <?= date('Y') ?> – Thi thử Biology's Life <?= date('Y') ?></div>
                            </div> <!-- đóng #exam-card -->
                            </div> <!-- đóng container -->
                        <?php
                            exit;
                        endif;
                        // ─── KẾT THÚC ĐOẠN KIỂM TRA ───


                        // nếu được phép thì hiển thị phần thông tin & nút start/continue như cũ
                ?>
                    <p class="text-center text-dark">Xin chào <strong><?= htmlspecialchars($foundUser['Họ và tên']) ?></strong></p>
                    <p class="text-center text-dark">
                        Số báo danh: <strong><?= htmlspecialchars($studentID) ?></strong> 
                        – Trường: <strong><?= htmlspecialchars($foundUser['Trường']) ?></strong>
                    </p>
                    <div class="d-flex justify-content-center mt-2">
                        <span>Thời gian hiện tại: </span>
                        <span id="clock" class="ms-2">--:--:--</span>
                    </div>
                    <div id="start-area" style="margin-top: 15px;">
                        <?php if ($startExam && $now < $startExam): ?>
                        <p class="alert alert-danger text-center">
                            Thời gian làm bài chưa bắt đầu.<br>
                            Bắt đầu từ: <?= $startExam->format('H:i d/m/Y') ?>
                        </p>
                        <?php elseif ($endExam && $now > $endExam): ?>
                        <p class="alert alert-danger text-center">
                            Thời gian làm bài đã kết thúc.<br>
                            Kết thúc vào: <?= $endExam->format('H:i d/m/Y') ?>
                        </p>
                        <?php else: ?>
                            <!-- TRƯỜNG HỢP BÌNH THƯỜNG: nếu không có note hoặc note rỗng thì hiện luôn Start -->
                            <?php             if ($hasNote): ?>
                <!-- NẾU CÓ NOTE → LUÔN HIỆN NÚT TIẾP TỤC -->
                <div class="info text-center mt-3">
                  <a href="?note" class="btn btn-primary btn-lg" style="font-size:1rem;">
                    Tiếp tục
                  </a>
                </div>
            <?php else: ?>
                <!-- NẾU KHÔNG CÓ NOTE → HIỆN NÚT BẮT ĐẦU -->
                <div class="info text-center mt-3">
                  <a href="?action=start">
                    <button class="btn btn-primary btn-lg" style="font-size:1rem;">
                      Bắt đầu làm bài
                    </button>
                  </a>
                </div>
            <?php endif;
                        endif; ?>
                    </div>
                <?php 
                        else: 
                ?>
                    <p class="error text-center">Không tồn tại thí sinh. Hãy tải lại trang để thử lại.</p>
                    <p class="text-center">Vui lòng inbox cho page <a href="https://www.facebook.com/share/1CwPFoZysQ/?mibextid=wwXIfr" target="_blank" rel="noopener noreferrer" style="color: #003366; cursor: pointer"><strong>Biology's Life</strong></a> để được xử lý.</p>
                    <div class="d-flex justify-content-center mt-2">
                        <span>Thời gian hiện tại: </span>
                        <span id="clock" class="ms-2">--:--:--</span>
                    </div>
                <?php 
                        endif; 
                    endif; 
                ?>
            </div><div class="footer" > &copy; <?= date('Y') ?> – Thi thử Biology's Life <?= date('Y') ?></div>
        </div>
    </div>

    <!-- Bootstrap JS (tùy chọn nếu cần các component JS) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Biến PHP đã in ra dưới dạng JSON
    const examStart = <?= json_encode($timeData['timeStartExam'] ?: null) ?><!-- e.g. "2025-07-10T08:00:00" hoặc null -->;
    const examEnd   = <?= json_encode($timeData['timeEndExam']   ?: null) ?>;
    const hasNote   = <?= json_encode($hasNote) ?>; // true/false
    const noteHtml  = <?= json_encode($note) ?>;

    // Chuyển đổi string sang Date nếu không null
    const startDate = examStart ? new Date(examStart) : null;
    const endDate   = examEnd   ? new Date(examEnd)   : null;

    let didContinue = false;

    function updateClock() {
        const now = new Date();
        document.getElementById('clock').textContent = now.toTimeString().slice(0,8);
    }

    function refreshEntryForm() {
        const now  = new Date();
        const before = document.getElementById('msg-before');
        const after  = document.getElementById('msg-after');
        const form   = document.getElementById('form-entry');
        if (!form) return;

        if (startDate && now < startDate) {
            before.style.display = 'block';
            after.style.display  = 'none';
            form.style.display   = 'none';
        } else if (endDate && now > endDate) {
            before.style.display = 'none';
            after.style.display  = 'block';
            form.style.display   = 'none';
        } else {
            before.style.display = 'none';
            after.style.display  = 'none';
            form.style.display   = 'block';
        }
    }

    function refreshStartArea() {
        const now = new Date();
        const area = document.getElementById('start-area');
        const card = document.getElementById('exam-card');
        if (!area) return;

        // Chọn nội dung hiển thị
        let html = '';
        if (startDate && now < startDate) {
            html = `<p class="alert alert-danger text-center">
                        Thời gian làm bài chưa bắt đầu.<br>
                        Bắt đầu từ: ${startDate.toLocaleString('vi-VN',{
                            day:'2-digit',month:'2-digit',year:'numeric',
                            hour:'2-digit',minute:'2-digit'
                        })}
                    </p>`;
        } else if (endDate && now > endDate) {
            html = `<p class="alert alert-danger text-center">
                        Thời gian làm bài đã kết thúc.<br>
                        Kết thúc vào: ${endDate.toLocaleString('vi-VN',{
                            day:'2-digit',month:'2-digit',year:'numeric',
                            hour:'2-digit',minute:'2-digit'
                        })}
                    </p>`;
        } else {
            // Trong khung giờ làm bài
            if (hasNote && !didContinue) {
                html = `<div class="info text-center mt-3">
                  <a href="?note" class="btn btn-primary btn-lg" style="font-size:1rem;">
                    Tiếp tục
                  </a>
                </div>`;
            } else {
                // Nếu đã bấm Continue thì show note
                if (hasNote && didContinue) {
                    html += `<div class="note mb-3">${noteHtml}</div>`;
                }
                html += `<div class="info text-center mt-3">
                  <a href="?action=start">
                    <button class="btn btn-primary btn-lg" style="font-size:1rem;">
                      Bắt đầu làm bài
                    </button>
                  </a>
                </div>`;
            }
        }

        // Cập nhật DOM nếu khác
        if (area.innerHTML.trim() !== html.trim()) {
            area.innerHTML = html;
            // Gắn lại sự kiện cho nút Continue
            const btn = document.getElementById('continue-btn');
            if (btn) {
                btn.addEventListener('click', () => {
                    didContinue = true;
                    refreshStartArea();
                });
            }
        }

        // Điều chỉnh độ rộng card nếu đã Continue
        if (hasNote && didContinue) {
            card.classList.add('wide-card');
        } else {
            card.classList.remove('wide-card');
        }
    }

    // Chạy ngay khi load xong
    updateClock();
    refreshEntryForm();
    refreshStartArea();

    // Lặp mỗi giây
    setInterval(() => {
        updateClock();
        refreshEntryForm();
        refreshStartArea();
    }, 1000);
});
</script>

    <script>
        // Hàm ping để update session/user online (gọi cả update.php và update1.php)
        (function ping() {
            const ts = Date.now();

            // Gọi update.php
            const img1 = new Image();
            img1.src = 'https://biolifethithu.wuaze.com/user/update.php?ts=' + ts;

            // Gọi update1.php
            const img2 = new Image();
            img2.src = 'https://biolifethithu.wuaze.com/user/update1.php?ts=' + ts;

            console.log('Đã ping update.php & update1.php lúc ' + new Date().toLocaleTimeString());
            setTimeout(ping, 1000);
        })();

        // Hàm cập nhật đồng hồ mỗi giây
        function updateClock() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('clock').textContent = `${h}:${m}:${s}`;
        }

        updateClock();
        setInterval(updateClock, 1000);
    </script>
</body>
</html>