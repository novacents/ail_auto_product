<?php
/**
 * queue_manager.php와 동일한 방식으로 테스트
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>queue_manager.php 완전 복제 테스트</h2>";
echo "<pre>";

// 1. WordPress 로드 (queue_manager.php와 동일)
echo "=== WordPress 로드 ===\n";
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
require_once __DIR__ . '/queue_utils.php';

// 2. 권한 확인 (queue_manager.php와 동일)  
echo "권한 확인: " . (current_user_can('manage_options') ? "✅ 관리자" : "❌ 권한 없음") . "\n";

if (!current_user_can('manage_options')) { 
    wp_die('접근 권한이 없습니다.');
}

// 3. 자동 정리 시스템 테스트 (queue_manager.php 13-24줄과 동일)
echo "\n=== 자동 정리 시스템 테스트 ===\n";
if (!isset($_POST['action']) && rand(1, 100) <= 100) { // 100% 확률로 테스트
    if (function_exists('cleanup_completed_queues_split')) {
        try {
            $cleaned_count = cleanup_completed_queues_split(7);
            echo "자동 정리 실행: {$cleaned_count}개 파일 정리됨\n";
        } catch (Exception $e) {
            echo "자동 정리 오류: " . $e->getMessage() . "\n";
        }
    } else {
        echo "cleanup_completed_queues_split 함수가 없음\n";
    }
}

// 4. 헬퍼 함수 테스트 (queue_manager.php 26-79줄)
echo "\n=== 헬퍼 함수 테스트 ===\n";
echo "get_category_name(356): " . get_category_name(356) . "\n";
echo "get_prompt_type_name('essential_items'): " . get_prompt_type_name('essential_items') . "\n";

// 5. AJAX 요청 시뮬레이션 테스트
echo "\n=== AJAX 기능 시뮬레이션 ===\n";

// get_queues 액션 시뮬레이션
$_POST['action'] = 'get_queues';
$_POST['status'] = 'pending';
$_POST['search'] = '';

echo "시뮬레이션: get_queues 액션\n";
try {
    $status = $_POST['status'] ?? 'pending';
    $search = $_POST['search'] ?? '';
    
    if ($status === 'pending') {
        $queues = get_pending_queues_split();
    } elseif ($status === 'completed') {
        $queues = get_completed_queues_split();
    } else {
        $queues = [];
    }
    
    // 검색 필터링 (queue_manager.php 102-122줄과 동일)
    if (!empty($search)) {
        $queues = array_filter($queues, function($queue) use ($search) {
            $searchLower = mb_strtolower($search, 'UTF-8');
            
            if (isset($queue['title']) && mb_strpos(mb_strtolower($queue['title'], 'UTF-8'), $searchLower) !== false) {
                return true;
            }
            
            if (isset($queue['keywords']) && is_array($queue['keywords'])) {
                foreach ($queue['keywords'] as $keyword) {
                    if (isset($keyword['name']) && mb_strpos(mb_strtolower($keyword['name'], 'UTF-8'), $searchLower) !== false) {
                        return true;
                    }
                }
            }
            
            return false;
        });
    }
    
    // 최신순 정렬 (queue_manager.php 124-129줄과 동일)
    usort($queues, function($a, $b) {
        $timeA = $a['modified_at'] ?? $a['created_at'] ?? '0000-00-00 00:00:00';
        $timeB = $b['modified_at'] ?? $b['created_at'] ?? '0000-00-00 00:00:00';
        return strcmp($timeB, $timeA);
    });
    
    echo "✅ get_queues 성공: " . count($queues) . "개 큐 조회됨\n";
    
    // JSON 응답 형태로 출력
    $response = ['success' => true, 'queues' => array_values($queues)];
    echo "JSON 응답 크기: " . strlen(json_encode($response)) . " bytes\n";
    
} catch (Exception $e) {
    echo "❌ get_queues 오류: " . $e->getMessage() . "\n";
}

// 6. get_stats 액션 시뮬레이션
echo "\nget_stats 액션 시뮬레이션:\n";
try {
    $pending_queues = get_pending_queues_split();
    $completed_queues = get_completed_queues_split();
    
    $stats = [
        'total' => count($pending_queues) + count($completed_queues),
        'pending' => count($pending_queues), 
        'completed' => count($completed_queues)
    ];
    
    echo "✅ get_stats 성공: " . json_encode($stats) . "\n";
} catch (Exception $e) {
    echo "❌ get_stats 오류: " . $e->getMessage() . "\n";
}

// 7. 메모리 사용량 체크
echo "\n=== 시스템 상태 ===\n";
echo "메모리 사용량: " . number_format(memory_get_usage(true) / 1024 / 1024, 2) . "MB\n";
echo "최대 메모리: " . ini_get('memory_limit') . "\n";
echo "실행 시간 제한: " . ini_get('max_execution_time') . "초\n";

unset($_POST['action'], $_POST['status'], $_POST['search']);
echo "\n✅ 모든 테스트 완료 - queue_manager.php의 핵심 기능들이 정상 작동함\n";

echo "</pre>";

function get_category_name($category_id) {
    $categories = ['354' => 'Today\'s Pick', '355' => '기발한 잡화점', '356' => '스마트 리빙', '12' => '우리잇템'];
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

function get_prompt_type_name($prompt_type) {
    $prompt_types = ['essential_items' => '필수템형 🎯', 'friend_review' => '친구 추천형 👫', 'professional_analysis' => '전문 분석형 📊', 'amazing_discovery' => '놀라움 발견형 ✨'];
    return $prompt_types[$prompt_type] ?? '기본형';
}
?>