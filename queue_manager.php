<?php
/**
 * 큐 관리 시스템 - 새로운 2단계 시스템 (pending/completed)
 * 버전: v4.0 (queue_manager_plan.md 기반 재구현)
 */
require_once('/var/www/novacents/wp-config.php');
require_once __DIR__ . '/queue_utils.php';

if (!current_user_can('manage_options')) { wp_die('접근 권한이 없습니다.'); }

// 🗑️ 자동 정리 시스템 (queue_manager_plan.md 116-121줄, 165-174줄 요구사항)
// queue_manager.php 접속 시 5% 확률로 completed 상태 큐 파일 자동 정리
if (!isset($_POST['action']) && rand(1, 100) <= 5) {
    if (function_exists('cleanup_completed_queues_split')) {
        try {
            $cleaned_count = cleanup_completed_queues_split(7); // 7일 후 자동 삭제
            if ($cleaned_count > 0) {
                error_log("Queue Manager Auto Cleanup: {$cleaned_count} completed queues older than 7 days were automatically cleaned up");
            }
        } catch (Exception $e) {
            error_log("Queue Manager Auto Cleanup Error: " . $e->getMessage());
        }
    }
}

function get_category_name($category_id) {
    $categories = ['354' => 'Today\'s Pick', '355' => '기발한 잡화점', '356' => '스마트 리빙', '12' => '우리잇템'];
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

function get_prompt_type_name($prompt_type) {
    $prompt_types = ['essential_items' => '필수템형 🎯', 'friend_review' => '친구 추천형 👫', 'professional_analysis' => '전문 분석형 📊', 'amazing_discovery' => '놀라움 발견형 ✨'];
    return $prompt_types[$prompt_type] ?? '기본형';
}

function get_queue_summary($queues) {
    $summary = [
        'total' => count($queues),
        'pending' => 0,
        'completed' => 0
    ];
    
    foreach ($queues as $queue) {
        if (isset($queue['status'])) {
            switch ($queue['status']) {
                case 'pending':
                    $summary['pending']++;
                    break;
                case 'completed':
                    $summary['completed']++;
                    break;
            }
        }
    }
    
    return $summary;
}

function get_keywords_count($keywords) {
    return is_array($keywords) ? count($keywords) : 0;
}

function get_products_count($keywords) {
    $count = 0;
    if (is_array($keywords)) {
        foreach ($keywords as $keyword) {
            if (isset($keyword['products_data']) && is_array($keyword['products_data'])) {
                $count += count($keyword['products_data']);
            }
            if (isset($keyword['aliexpress']) && is_array($keyword['aliexpress'])) {
                $count += count($keyword['aliexpress']);
            }
            if (isset($keyword['coupang']) && is_array($keyword['coupang'])) {
                $count += count($keyword['coupang']);
            }
        }
    }
    return $count;
}

// AJAX 요청 처리
if (isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'get_queues':
                $status = $_POST['status'] ?? 'pending'; // 기본값: pending
                $search = $_POST['search'] ?? '';
                
                // 해당 상태의 큐 목록 가져오기
                if ($status === 'pending') {
                    $queues = get_pending_queues_split();
                } elseif ($status === 'completed') {
                    $queues = get_completed_queues_split();
                } else {
                    $queues = [];
                }
                
                // 검색 필터링
                if (!empty($search)) {
                    $queues = array_filter($queues, function($queue) use ($search) {
                        $searchLower = mb_strtolower($search, 'UTF-8');
                        
                        // 제목 검색
                        if (isset($queue['title']) && mb_strpos(mb_strtolower($queue['title'], 'UTF-8'), $searchLower) !== false) {
                            return true;
                        }
                        
                        // 키워드 검색
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
                
                // 최신순 정렬 (생성일/수정일 기준)
                usort($queues, function($a, $b) {
                    $timeA = $a['modified_at'] ?? $a['created_at'] ?? '0000-00-00 00:00:00';
                    $timeB = $b['modified_at'] ?? $b['created_at'] ?? '0000-00-00 00:00:00';
                    return strcmp($timeB, $timeA); // 최신순
                });
                
                echo json_encode(['success' => true, 'queues' => array_values($queues)]);
                exit;
                
            case 'delete_queue':
                $queue_ids = $_POST['queue_ids'] ?? [];
                if (!is_array($queue_ids)) {
                    $queue_ids = [$queue_ids];
                }
                
                $success_count = 0;
                $total_count = count($queue_ids);
                
                foreach ($queue_ids as $queue_id) {
                    if (remove_queue_split($queue_id)) {
                        $success_count++;
                    }
                }
                
                echo json_encode([
                    'success' => $success_count > 0,
                    'message' => "{$success_count}/{$total_count}개 항목이 삭제되었습니다.",
                    'success_count' => $success_count,
                    'total_count' => $total_count
                ]);
                exit;
                
            case 'change_status':
                $queue_ids = $_POST['queue_ids'] ?? [];
                $new_status = $_POST['new_status'] ?? '';
                
                if (!is_array($queue_ids)) {
                    $queue_ids = [$queue_ids];
                }
                
                if (!in_array($new_status, ['pending', 'completed'])) {
                    echo json_encode(['success' => false, 'message' => '유효하지 않은 상태입니다.']);
                    exit;
                }
                
                $success_count = 0;
                $total_count = count($queue_ids);
                
                foreach ($queue_ids as $queue_id) {
                    if (update_queue_status_split_v2($queue_id, $new_status)) {
                        $success_count++;
                    }
                }
                
                $status_name = $new_status === 'pending' ? '대기중' : '완료됨';
                echo json_encode([
                    'success' => $success_count > 0,
                    'message' => "{$success_count}/{$total_count}개 항목이 {$status_name} 상태로 변경되었습니다.",
                    'success_count' => $success_count,
                    'total_count' => $total_count
                ]);
                exit;
                
            case 'immediate_publish':
                $queue_id = $_POST['queue_id'] ?? '';
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                // 큐 항목 로드
                $queue_item = load_queue_split($queue_id);
                if (!$queue_item) {
                    echo json_encode(['success' => false, 'message' => '큐 항목을 찾을 수 없습니다.']);
                    exit;
                }
                
                // pending 상태만 발행 가능
                if (isset($queue_item['status']) && $queue_item['status'] !== 'pending') {
                    echo json_encode(['success' => false, 'message' => '대기중 상태의 큐만 발행할 수 있습니다.']);
                    exit;
                }
                
                // auto_post_products.py 호출을 위한 데이터 준비
                $publish_data = [
                    'title' => $queue_item['title'],
                    'category' => $queue_item['category_id'],
                    'prompt_type' => $queue_item['prompt_type'],
                    'keywords' => json_encode($queue_item['keywords']),
                    'user_details' => json_encode($queue_item['user_details'] ?? []),
                    'thumbnail_url' => $queue_item['thumbnail_url'] ?? '',
                    'publish_mode' => 'immediate'
                ];
                
                // keyword_processor.php 호출
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'];
                $script_dir = dirname($_SERVER['SCRIPT_NAME']);
                $processor_url = $protocol . $host . $script_dir . '/keyword_processor.php';
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $processor_url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($publish_data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);
                
                if ($curl_error) {
                    echo json_encode(['success' => false, 'message' => 'cURL 오류: ' . $curl_error]);
                    exit;
                }
                
                if ($http_code !== 200) {
                    echo json_encode(['success' => false, 'message' => 'HTTP 오류: ' . $http_code]);
                    exit;
                }
                
                // 발행 성공 시 completed 상태로 변경
                if (strpos($response, '워드프레스 발행 성공:') !== false) {
                    update_queue_status_split_v2($queue_id, 'completed');
                    
                    // URL 추출
                    preg_match('/워드프레스 발행 성공: (https?:\/\/[^\s]+)/', $response, $matches);
                    $post_url = $matches[1] ?? '';
                    
                    echo json_encode([
                        'success' => true,
                        'message' => '글이 성공적으로 발행되었습니다!',
                        'post_url' => $post_url
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => '발행 처리 중 오류가 발생했습니다.']);
                }
                exit;
                
            case 'get_stats':
                $pending_queues = get_pending_queues_split();
                $completed_queues = get_completed_queues_split();
                
                $stats = [
                    'total' => count($pending_queues) + count($completed_queues),
                    'pending' => count($pending_queues), 
                    'completed' => count($completed_queues)
                ];
                
                echo json_encode(['success' => true, 'stats' => $stats]);
                exit;
                
            default:
                echo json_encode(['success' => false, 'message' => '알 수 없는 액션입니다.']);
                exit;
        }
    } catch (Exception $e) {
        error_log("큐 관리 시스템 오류: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '서버 오류가 발생했습니다: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>큐 관리 시스템 - 노바센트</title>
<link rel="stylesheet" href="assets/queue_manager.css?v=<?php echo time(); ?>">
</head>
<body>
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-content">
        <div class="loading-spinner"></div>
        <div class="loading-text">처리 중입니다...</div>
        <div style="margin-top: 10px; color: #666; font-size: 14px;">잠시만 기다려주세요.</div>
    </div>
</div>

<div class="main-container">
    <div class="header-section">
        <h1>📋 큐 관리 시스템</h1>
        <p class="subtitle">큐에 저장된 항목들을 관리하고 즉시 발행할 수 있습니다 (v4.0 - 2단계 시스템)</p>
        <div class="header-actions">
            <a href="affiliate_editor.php" class="btn btn-primary">📝 새 글 작성</a>
            <button type="button" class="btn btn-secondary" onclick="refreshQueues()">🔄 새로고침</button>
        </div>
    </div>

    <!-- 상단 메뉴 -->
    <div class="top-menu">
        <div class="menu-buttons">
            <button type="button" class="btn menu-btn active" data-status="pending" onclick="switchStatus('pending')">
                📝 대기중 큐 보기
            </button>
            <button type="button" class="btn menu-btn" data-status="completed" onclick="switchStatus('completed')">
                ✅ 완료됨 큐 보기
            </button>
        </div>
        <div class="bulk-actions">
            <button type="button" class="btn btn-danger" id="bulkDeleteBtn" onclick="bulkDelete()" disabled>
                🗑️ 일괄삭제
            </button>
            <button type="button" class="btn btn-secondary" id="bulkStatusBtn" onclick="bulkChangeStatus()" disabled>
                🔄 일괄상태변경
            </button>
        </div>
        <div class="search-section">
            <input type="text" id="searchInput" placeholder="🔍 제목/키워드 검색" onkeypress="if(event.key==='Enter') searchQueues()">
            <button type="button" class="btn btn-small" onclick="searchQueues()">검색</button>
            <button type="button" class="btn btn-small btn-secondary" onclick="clearSearch()">초기화</button>
        </div>
    </div>

    <!-- 통계 영역 -->
    <div class="stats-section" id="statsSection">
        <div class="stat-card">
            <div class="stat-number" id="totalCount">0</div>
            <div class="stat-label">전체 항목</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" id="pendingCount">0</div>
            <div class="stat-label">대기중</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" id="completedCount">0</div>
            <div class="stat-label">완료됨</div>
        </div>
    </div>

    <!-- 큐 목록 영역 -->
    <div class="queue-section">
        <div class="queue-header">
            <div class="select-all-section">
                <label class="checkbox-container">
                    <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()">
                    <span class="checkmark"></span>
                    전체 선택
                </label>
            </div>
            <div class="queue-info" id="queueInfo">
                대기중 큐 목록
            </div>
        </div>
        
        <div class="queue-list" id="queueList">
            <div class="empty-state">
                <h3>📦 큐 파일이 없습니다</h3>
                <p>해당 상태의 큐 파일이 없습니다.</p>
                <a href="affiliate_editor.php" class="btn btn-primary">새 글 작성하기</a>
            </div>
        </div>
    </div>
</div>

<script src="assets/queue_manager.js?v=<?php echo time(); ?>"></script>
</body>
</html>