<?php
// 에러 표시 활성화
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>queue_manager.php 디버깅</h2>";
echo "<pre>";

// 1단계: WordPress 로드
echo "1단계: WordPress 로드\n";
try {
    require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
    echo "✅ WordPress 로드 성공\n";
    echo "ABSPATH: " . ABSPATH . "\n\n";
} catch (Exception $e) {
    echo "❌ WordPress 로드 실패: " . $e->getMessage() . "\n\n";
    die();
}

// 2단계: queue_utils.php 로드
echo "2단계: queue_utils.php 로드\n";
$queue_utils_path = __DIR__ . '/queue_utils.php';
if (!file_exists($queue_utils_path)) {
    echo "❌ queue_utils.php 파일이 없습니다: " . $queue_utils_path . "\n";
    die();
}

try {
    require_once $queue_utils_path;
    echo "✅ queue_utils.php 로드 성공\n\n";
} catch (Exception $e) {
    echo "❌ queue_utils.php 로드 실패: " . $e->getMessage() . "\n";
    die();
}

// 3단계: 권한 확인
echo "3단계: 권한 확인\n";
if (function_exists('current_user_can')) {
    echo "✅ current_user_can 함수 사용 가능\n";
    
    // 현재 사용자 정보
    $current_user = wp_get_current_user();
    if ($current_user->ID > 0) {
        echo "현재 사용자: " . $current_user->user_login . " (ID: " . $current_user->ID . ")\n";
        echo "관리자 권한: " . (current_user_can('manage_options') ? "YES" : "NO") . "\n";
    } else {
        echo "로그인되지 않음\n";
    }
} else {
    echo "❌ current_user_can 함수를 찾을 수 없습니다\n";
}
echo "\n";

// 4단계: queue_utils.php 함수 확인
echo "4단계: queue_utils.php 함수 확인\n";
$functions_to_check = [
    'cleanup_completed_queues_split',
    'get_pending_queues_split',
    'get_completed_queues_split',
    'update_queue_status_split_v2',
    'load_queue_split',
    'remove_queue_split'
];

foreach ($functions_to_check as $func) {
    echo $func . ": " . (function_exists($func) ? "✅ 존재" : "❌ 없음") . "\n";
}
echo "\n";

// 5단계: 디렉토리 확인
echo "5단계: 큐 디렉토리 확인\n";
$dirs_to_check = [
    '/var/www/novacents/tools/queues',
    '/var/www/novacents/tools/queues/pending',
    '/var/www/novacents/tools/queues/completed'
];

foreach ($dirs_to_check as $dir) {
    echo $dir . ":\n";
    echo "  존재: " . (is_dir($dir) ? "✅" : "❌") . "\n";
    echo "  쓰기가능: " . (is_writable($dir) ? "✅" : "❌") . "\n";
}
echo "\n";

// 6단계: 상수 정의 확인
echo "6단계: 상수 정의 확인\n";
$constants_to_check = [
    'QUEUE_BASE_DIR',
    'QUEUE_SPLIT_DIR', 
    'QUEUE_PENDING_DIR',
    'QUEUE_COMPLETED_DIR',
    'QUEUE_INDEX_FILE'
];

foreach ($constants_to_check as $const) {
    if (defined($const)) {
        echo $const . ": " . constant($const) . "\n";
    } else {
        echo $const . ": ❌ 정의되지 않음\n";
    }
}
echo "\n";

// 7단계: 실제 큐 함수 테스트
echo "7단계: 실제 큐 함수 테스트\n";
try {
    // 디렉토리 초기화 시도
    if (function_exists('initialize_queue_directories')) {
        $result = initialize_queue_directories();
        echo "initialize_queue_directories: " . ($result ? "✅ 성공" : "❌ 실패") . "\n";
    }
    
    // pending 큐 가져오기 시도
    if (function_exists('get_pending_queues_split')) {
        $pending = get_pending_queues_split();
        echo "get_pending_queues_split: ✅ 실행됨 (큐 개수: " . count($pending) . ")\n";
    }
    
    // completed 큐 가져오기 시도
    if (function_exists('get_completed_queues_split')) {
        $completed = get_completed_queues_split();
        echo "get_completed_queues_split: ✅ 실행됨 (큐 개수: " . count($completed) . ")\n";
    }
} catch (Exception $e) {
    echo "❌ 함수 실행 중 오류: " . $e->getMessage() . "\n";
}

echo "\n8단계: PHP 오류 로그 확인\n";
$error = error_get_last();
if ($error) {
    echo "마지막 오류:\n";
    print_r($error);
}

echo "</pre>";

// 권한이 없는 경우 wp_die 테스트
if (!current_user_can('manage_options')) { 
    echo "<p style='color: red;'>⚠️ 관리자 권한이 없습니다. WordPress 관리자로 로그인이 필요합니다.</p>";
    // wp_die는 실행하지 않고 경고만 표시
}
?>