<?php
/**
 * queue_manager.php 오류 테스트 파일
 */

// WordPress 설정 로드 테스트
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');
    echo "✅ WordPress 로드 성공\n";
} catch (Exception $e) {
    echo "❌ WordPress 로드 실패: " . $e->getMessage() . "\n";
    exit;
}

// queue_utils.php 로드 테스트
try {
    require_once __DIR__ . '/queue_utils.php';
    echo "✅ queue_utils.php 로드 성공\n";
} catch (Exception $e) {
    echo "❌ queue_utils.php 로드 실패: " . $e->getMessage() . "\n";
    exit;
}

// 함수 존재 확인
$functions_to_check = [
    'get_all_queues_split',
    'load_queue_split', 
    'update_queue_status_split',
    'remove_queue_split',
    'update_queue_data_split'
];

foreach ($functions_to_check as $func) {
    if (function_exists($func)) {
        echo "✅ {$func}() 함수 존재\n";
    } else {
        echo "❌ {$func}() 함수 없음\n";
    }
}

// 간단한 함수 호출 테스트
try {
    $queues = get_all_queues_split();
    echo "✅ get_all_queues_split() 호출 성공, 큐 개수: " . count($queues) . "\n";
} catch (Exception $e) {
    echo "❌ get_all_queues_split() 호출 실패: " . $e->getMessage() . "\n";
}

echo "테스트 완료\n";
?>