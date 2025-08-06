<?php
// 에러 표시 활성화
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>WordPress 경로 테스트</h2>";
echo "<pre>";

// 1. 서버 환경 변수 확인
echo "1. 서버 환경 변수:\n";
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "SCRIPT_FILENAME: " . $_SERVER['SCRIPT_FILENAME'] . "\n";
echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "__DIR__: " . __DIR__ . "\n\n";

// 2. WordPress 파일 존재 확인
echo "2. WordPress 파일 존재 확인:\n";
$paths_to_check = [
    $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php',
    '/var/www/novacents/wp-config.php',
    dirname(__DIR__) . '/wp-config.php',
    dirname(dirname(__DIR__)) . '/wp-config.php'
];

foreach ($paths_to_check as $path) {
    echo "Path: " . $path . "\n";
    echo "  Exists: " . (file_exists($path) ? "YES" : "NO") . "\n";
    echo "  Readable: " . (is_readable($path) ? "YES" : "NO") . "\n\n";
}

// 3. WordPress 로드 시도
echo "3. WordPress 로드 시도:\n";
$wp_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php';
if (file_exists($wp_path)) {
    echo "wp-config.php 파일을 찾았습니다: " . $wp_path . "\n";
    
    // WordPress 로드 시도
    try {
        require_once($wp_path);
        echo "WordPress 로드 성공!\n";
        echo "ABSPATH: " . (defined('ABSPATH') ? ABSPATH : 'Not defined') . "\n";
        
        // 현재 사용자 권한 확인
        if (function_exists('current_user_can')) {
            echo "current_user_can 함수 사용 가능\n";
            echo "관리자 권한: " . (current_user_can('manage_options') ? "YES" : "NO") . "\n";
        } else {
            echo "current_user_can 함수를 찾을 수 없습니다\n";
        }
    } catch (Exception $e) {
        echo "WordPress 로드 중 오류: " . $e->getMessage() . "\n";
    }
} else {
    echo "wp-config.php 파일을 찾을 수 없습니다!\n";
}

// 4. queue_utils.php 테스트
echo "\n4. queue_utils.php 테스트:\n";
$queue_utils_path = __DIR__ . '/queue_utils.php';
if (file_exists($queue_utils_path)) {
    echo "queue_utils.php 존재함\n";
    
    // ABSPATH가 이미 정의되어 있는지 확인
    echo "ABSPATH 정의 상태 (queue_utils.php 로드 전): " . (defined('ABSPATH') ? "정의됨 - " . ABSPATH : "정의 안됨") . "\n";
    
    // queue_utils.php 로드
    require_once $queue_utils_path;
    
    echo "ABSPATH 정의 상태 (queue_utils.php 로드 후): " . (defined('ABSPATH') ? "정의됨 - " . ABSPATH : "정의 안됨") . "\n";
    
    // 함수 확인
    echo "cleanup_completed_queues_split 함수: " . (function_exists('cleanup_completed_queues_split') ? "존재" : "없음") . "\n";
} else {
    echo "queue_utils.php 파일을 찾을 수 없습니다\n";
}

echo "</pre>";
?>