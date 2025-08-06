<?php
// 간단한 큐 진단 도구
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
require_once __DIR__ . '/queue_utils.php';

echo "=== 큐 시스템 진단 ===\n";

// 디렉토리 초기화
$init = initialize_queue_directories();
echo "디렉토리 초기화: " . ($init ? "성공" : "실패") . "\n";

// 큐 개수 확인
$pending = get_pending_queues_split();
$completed = get_completed_queues_split();

echo "Pending 큐: " . count($pending) . "개\n";
echo "Completed 큐: " . count($completed) . "개\n";

if (count($pending) === 0 && count($completed) === 0) {
    echo "\n⚠️ 큐 파일이 전혀 없습니다!\n";
    echo "affiliate_editor.php에서 큐를 생성해야 합니다.\n";
}

echo "\nPending 큐 샘플:\n";
foreach (array_slice($pending, 0, 2) as $queue) {
    echo "- " . ($queue['title'] ?? 'N/A') . "\n";
}
?>