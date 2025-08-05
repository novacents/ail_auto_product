<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

require_once(ABSPATH . 'queue_utils.php');
require_once(ABSPATH . 'keyword_processor.php');

// WordPress 기능이 아닌 환경에서도 사용할 수 있도록 함수 정의
if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true; // 개발 환경에서는 모든 권한 허용
    }
}

// 세션 시작
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 보안 검사
if (!current_user_can('manage_options')) {
    wp_die('권한이 없습니다.');
}

// AJAX 요청 처리
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'get_queues':
                $queues = get_all_queues_split();
                echo json_encode([
                    'success' => true,
                    'data' => $queues
                ]);
                exit;
                
            case 'delete_queue':
                $queue_id = $_POST['queue_id'] ?? '';
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                $result = remove_queue_split($queue_id);
                echo json_encode([
                    'success' => $result,
                    'message' => $result ? '큐가 성공적으로 삭제되었습니다.' : '큐 삭제에 실패했습니다.'
                ]);
                exit;
                
            case 'edit_queue':
                $queue_id = $_POST['queue_id'] ?? '';
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                $queue_data = load_queue_split($queue_id);
                echo json_encode([
                    'success' => true,
                    'data' => $queue_data
                ]);
                exit;
                
            case 'update_queue':
                $queue_id = $_POST['queue_id'] ?? '';
                $title = $_POST['title'] ?? '';
                $keywords = json_decode($_POST['keywords'] ?? '[]', true);
                $category_id = $_POST['category_id'] ?? '356';
                $thumbnail_url = $_POST['thumbnail_url'] ?? '';
                $prompt_type = $_POST['prompt_type'] ?? 'essential_items';
                
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                // 기존 큐 데이터 로드
                $queue_data = load_queue_split($queue_id);
                if (!$queue_data) {
                    echo json_encode(['success' => false, 'message' => '큐 데이터를 찾을 수 없습니다.']);
                    exit;
                }
                
                // 데이터 업데이트
                $queue_data['title'] = $title;
                $queue_data['keywords'] = $keywords;
                $queue_data['category_id'] = $category_id;
                $queue_data['thumbnail_url'] = $thumbnail_url;
                $queue_data['prompt_type'] = $prompt_type;
                $queue_data['updated_at'] = date('Y-m-d H:i:s');
                
                $result = update_queue_split($queue_id, $queue_data);
                echo json_encode([
                    'success' => $result,
                    'message' => $result ? '큐가 성공적으로 업데이트되었습니다.' : '큐 업데이트에 실패했습니다.'
                ]);
                exit;
                
            case 'duplicate_queue':
                $queue_id = $_POST['queue_id'] ?? '';
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                $original_data = load_queue_split($queue_id);
                if (!$original_data) {
                    echo json_encode(['success' => false, 'message' => '원본 큐 데이터를 찾을 수 없습니다.']);
                    exit;
                }
                
                // 새로운 큐 ID 생성
                $new_queue_id = 'queue_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
                
                // 제목에 [복사] 추가
                $original_data['title'] = '[복사] ' . $original_data['title'];
                $original_data['queue_id'] = $new_queue_id;
                $original_data['status'] = 'pending';
                $original_data['created_at'] = date('Y-m-d H:i:s');
                $original_data['updated_at'] = date('Y-m-d H:i:s');
                
                $new_queue_id = add_queue_split($original_data);
                echo json_encode([
                    'success' => (bool)$new_queue_id,
                    'message' => $new_queue_id ? '큐가 성공적으로 복사되었습니다.' : '큐 복사에 실패했습니다.',
                    'queue_id' => $new_queue_id
                ]);
                exit;
                
            case 'immediate_publish':
                $queue_id = $_POST['queue_id'] ?? '';
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                // 큐 데이터 로드
                $queue_data = load_queue_split($queue_id);
                if (!$queue_data) {
                    echo json_encode(['success' => false, 'message' => '큐 데이터를 찾을 수 없습니다.']);
                    exit;
                }
                
                // keyword_processor.php의 process_immediate_publish 함수 호출
                $result = process_immediate_publish($queue_data);
                
                if ($result['success']) {
                    echo json_encode([
                        'success' => true,
                        'message' => '즉시 발행이 완료되었습니다.',
                        'post_url' => $result['post_url'] ?? ''
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => $result['message'] ?? '즉시 발행에 실패했습니다.'
                    ]);
                }
                exit;
                
            case 'move_queue_status':
                $queue_id = $_POST['queue_id'] ?? '';
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                // 🔒 보안 강화된 Move 버튼 처리 (만료된 락/트랜잭션 정리 포함)
                cleanup_expired_locks();
                cleanup_expired_transactions();
                
                $result = process_move_queue_status($queue_id);
                echo json_encode($result);
                exit;
                
            default:
                echo json_encode(['success' => false, 'message' => '알 수 없는 액션입니다.']);
                exit;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '오류가 발생했습니다: ' . $e->getMessage()
        ]);
        exit;
    }
}

// 큐 통계 계산
$queue_stats = get_queue_statistics();
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>큐 관리자 - 어필리에이트 상품 자동 발행 시스템</title>
    
    <!-- CSS 파일들 -->
    <link rel="stylesheet" href="assets/queue_manager.css">
    
    <!-- 폰트 -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <div class="container">
        <!-- 헤더 -->
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <h1 class="page-title">
                        <span class="title-icon">📋</span>
                        큐 관리자
                    </h1>
                    <p class="page-subtitle">어필리에이트 상품 자동 발행 큐를 관리합니다</p>
                </div>
                <div class="header-right">
                    <div class="header-actions">
                        <button class="btn btn-secondary" onclick="refreshQueues()">
                            <span class="btn-icon">🔄</span>
                            새로고침
                        </button>
                        <a href="affiliate_editor.php" class="btn btn-primary">
                            <span class="btn-icon">➕</span>
                            새 큐 추가
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- 통계 대시보드 -->
        <section class="dashboard">
            <div class="stats-grid">
                <div class="stat-card stat-pending">
                    <div class="stat-icon">🟡</div>
                    <div class="stat-content">
                        <h3 class="stat-title">대기중</h3>
                        <p class="stat-number" id="pendingCount"><?php echo $queue_stats['pending'] ?? 0; ?></p>
                        <p class="stat-label">처리 대기</p>
                    </div>
                </div>
                
                <div class="stat-card stat-processing">
                    <div class="stat-icon">🔵</div>
                    <div class="stat-content">
                        <h3 class="stat-title">처리중</h3>
                        <p class="stat-number" id="processingCount"><?php echo $queue_stats['processing'] ?? 0; ?></p>
                        <p class="stat-label">현재 처리</p>
                    </div>
                </div>
                
                <div class="stat-card stat-completed">
                    <div class="stat-icon">🟢</div>
                    <div class="stat-content">
                        <h3 class="stat-title">완료</h3>
                        <p class="stat-number" id="completedCount"><?php echo $queue_stats['completed'] ?? 0; ?></p>
                        <p class="stat-label">처리 완료</p>
                    </div>
                </div>
                
                <div class="stat-card stat-failed">
                    <div class="stat-icon">🔴</div>
                    <div class="stat-content">
                        <h3 class="stat-title">실패</h3>
                        <p class="stat-number" id="failedCount"><?php echo $queue_stats['failed'] ?? 0; ?></p>
                        <p class="stat-label">처리 실패</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- 필터 및 검색 -->
        <section class="filters">
            <div class="filter-group">
                <label class="filter-label">상태 필터:</label>
                <div class="filter-buttons">
                    <button class="filter-btn active" data-status="all">전체</button>
                    <button class="filter-btn" data-status="pending">🟡 대기중</button>
                    <button class="filter-btn" data-status="processing">🔵 처리중</button>
                    <button class="filter-btn" data-status="completed">🟢 완료</button>
                    <button class="filter-btn" data-status="failed">🔴 실패</button>
                </div>
            </div>
            
            <div class="search-group">
                <input type="text" id="searchInput" class="search-input" placeholder="제목 또는 키워드로 검색...">
                <button class="search-btn" onclick="filterQueues()">
                    <span class="search-icon">🔍</span>
                </button>
            </div>
        </section>

        <!-- 큐 목록 -->
        <section class="queue-section">
            <div class="section-header">
                <h2 class="section-title">큐 목록</h2>
                <div class="section-actions">
                    <select id="sortSelect" class="sort-select" onchange="sortQueues()">
                        <option value="newest">최신순</option>
                        <option value="oldest">오래된순</option>
                        <option value="title">제목순</option>
                        <option value="status">상태순</option>
                    </select>
                </div>
            </div>
            
            <!-- 테이블 레이아웃으로 변경 -->
            <div class="queue-table" id="queueList">
                <div class="table-header">
                    <div class="col-thumbnail">썸네일</div>
                    <div class="col-status">큐 상태</div>
                    <div class="col-category">카테고리</div>
                    <div class="col-prompt">프롬프트 스타일</div>
                    <div class="col-keywords">키워드 수</div>
                    <div class="col-products">상품 수</div>
                    <div class="col-actions">작업</div>
                </div>
                <div class="table-body" id="queueTableBody">
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <h3 class="empty-title">큐가 없습니다</h3>
                        <p class="empty-message">새로운 큐를 추가하여 시작하세요.</p>
                        <a href="affiliate_editor.php" class="btn btn-primary">
                            <span class="btn-icon">➕</span>
                            첫 번째 큐 추가하기
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- 큐 편집 모달 -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">큐 편집</h2>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            
            <form id="editForm" class="modal-form">
                <input type="hidden" id="editQueueId" name="queue_id">
                
                <div class="form-group">
                    <label for="editTitle" class="form-label">제목</label>
                    <input type="text" id="editTitle" name="title" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label for="editCategoryId" class="form-label">카테고리</label>
                    <select id="editCategoryId" name="category_id" class="form-select">
                        <option value="356">스마트 리빙</option>
                        <option value="357">패션 & 뷰티</option>
                        <option value="358">전자기기</option>
                        <option value="359">스포츠 & 레저</option>
                        <option value="360">홈 & 가든</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="editPromptType" class="form-label">프롬프트 타입</label>
                    <select id="editPromptType" name="prompt_type" class="form-select">
                        <option value="essential_items">필수템형</option>
                        <option value="friend_review">친구 추천형</option>
                        <option value="professional_analysis">전문 분석형</option>
                        <option value="amazing_discovery">놀라움 발견형</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="editThumbnailUrl" class="form-label">썸네일 URL</label>
                    <input type="url" id="editThumbnailUrl" name="thumbnail_url" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">키워드 목록</label>
                    <div id="editKeywordsList" class="keywords-list">
                        <!-- 키워드 목록이 여기에 동적으로 추가됩니다 -->
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">취소</button>
                    <button type="submit" class="btn btn-primary">저장</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 로딩 오버레이 -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p class="loading-text">처리 중...</p>
        </div>
    </div>

    <!-- 알림 메시지 -->
    <div id="notification" class="notification">
        <div class="notification-content">
            <span class="notification-icon"></span>
            <span class="notification-message"></span>
        </div>
    </div>

    <!-- JavaScript 파일들 -->
    <script src="assets/queue_manager.js"></script>
</body>
</html>