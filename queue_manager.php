<?php
/**
 * 저장된 정보 관리 페이지 - 분할 시스템 적용 버전
 * 버전: v4.0 (Phase 2 UI/UX 개선 완료)
 */
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');
require_once __DIR__ . '/queue_utils.php';

if (!current_user_can('manage_options')) { wp_die('접근 권한이 없습니다.'); }

define('QUEUE_FILE', '/var/www/novacents/tools/product_queue.json');

// 레거시 호환용 함수들 (하위 호환성 유지)
function load_queue() {
    // 기존 시스템과의 호환성을 위해 유지
    // 내부적으로는 분할 시스템 사용
    return get_all_queues_split();
}

function save_queue($queue) {
    // 기존 시스템과의 호환성을 위해 유지
    // 단일 파일 시스템으로 저장 (레거시 지원)
    if (!file_exists(QUEUE_FILE)) return false;
    $json_data = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json_data !== false && file_put_contents(QUEUE_FILE, $json_data, LOCK_EX) !== false;
}

function get_category_name($category_id) {
    $categories = ['354' => 'Today\'s Pick', '355' => '기발한 잡화점', '356' => '스마트 리빙', '12' => '우리잇템'];
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

function get_prompt_type_name($prompt_type) {
    $prompt_types = ['essential_items' => '필수템형 🎯', 'friend_review' => '친구 추천형 👫', 'professional_analysis' => '전문 분석형 📊', 'amazing_discovery' => '놀라움 발견형 ✨'];
    return $prompt_types[$prompt_type] ?? '기본형';
}

function get_products_summary($keywords) {
    $total_products = 0; $products_with_data = 0; $product_samples = [];
    if (!is_array($keywords)) return ['total_products' => 0, 'products_with_data' => 0, 'product_samples' => []];
    
    foreach ($keywords as $keyword) {
        if (isset($keyword['products_data']) && is_array($keyword['products_data'])) {
            foreach ($keyword['products_data'] as $product_data) {
                $total_products++;
                if (!empty($product_data['analysis_data'])) {
                    $products_with_data++;
                    if (count($product_samples) < 3) {
                        $analysis = $product_data['analysis_data'];
                        $product_samples[] = [
                            'title' => $analysis['title'] ?? '상품명 없음',
                            'image_url' => $analysis['image_url'] ?? '',
                            'price' => $analysis['price'] ?? '가격 정보 없음',
                            'url' => $product_data['url'] ?? ''
                        ];
                    }
                }
            }
        }
        if (isset($keyword['aliexpress']) && is_array($keyword['aliexpress'])) $total_products += count($keyword['aliexpress']);
        if (isset($keyword['coupang']) && is_array($keyword['coupang'])) $total_products += count($keyword['coupang']);
    }
    return ['total_products' => $total_products, 'products_with_data' => $products_with_data, 'product_samples' => $product_samples];
}

// AJAX 요청 처리
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'get_queue_list':
                // 분할 시스템 사용
                $queues = get_all_queues_split();
                echo json_encode(['success' => true, 'queue' => $queues]);
                exit;
                
            case 'delete_queue_item':
                $queue_id = $_POST['queue_id'] ?? '';
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                // 분할 시스템 사용
                $result = remove_queue_split($queue_id);
                
                echo json_encode($result ? 
                    ['success' => true, 'message' => '항목이 삭제되었습니다.'] : 
                    ['success' => false, 'message' => '삭제에 실패했습니다.']
                );
                exit;
                
            case 'get_queue_item':
                $queue_id = $_POST['queue_id'] ?? '';
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                // 분할 시스템 사용
                error_log("큐 항목 검색 시작 (분할 시스템): " . $queue_id);
                $item = load_queue_split($queue_id);
                
                if ($item) {
                    error_log("큐 항목 찾음 (분할 시스템): " . $queue_id);
                    if (isset($item['keywords']) && is_array($item['keywords'])) {
                        error_log("키워드 수: " . count($item['keywords']));
                        foreach ($item['keywords'] as $kIndex => $keyword) {
                            $productsCount = isset($keyword['products_data']) ? count($keyword['products_data']) : 0;
                            $aliexpressCount = isset($keyword['aliexpress']) ? count($keyword['aliexpress']) : 0;
                            error_log("키워드 {$kIndex} '{$keyword['name']}': products_data={$productsCount}, aliexpress={$aliexpressCount}");
                        }
                    }
                    
                    echo json_encode(['success' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);
                } else {
                    error_log("큐 항목을 찾을 수 없음 (분할 시스템): " . $queue_id);
                    echo json_encode(['success' => false, 'message' => '항목을 찾을 수 없습니다.']);
                }
                exit;
                
            case 'update_queue_item':
                $queue_id = $_POST['queue_id'] ?? '';
                $updated_data = json_decode($_POST['data'] ?? '{}', true);
                
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                if (!is_array($updated_data)) {
                    echo json_encode(['success' => false, 'message' => '업데이트 데이터가 유효하지 않습니다.']);
                    exit;
                }
                
                // 기존 큐 항목 로드
                $existing_item = load_queue_split($queue_id);
                if (!$existing_item) {
                    echo json_encode(['success' => false, 'message' => '업데이트할 항목을 찾을 수 없습니다.']);
                    exit;
                }
                
                // 기본 정보 업데이트
                $updated_item = $existing_item;
                $updated_item['title'] = $updated_data['title'] ?? $existing_item['title'];
                $updated_item['category_id'] = $updated_data['category_id'] ?? $existing_item['category_id'];
                $updated_item['category_name'] = get_category_name($updated_data['category_id'] ?? $existing_item['category_id']);
                $updated_item['prompt_type'] = $updated_data['prompt_type'] ?? $existing_item['prompt_type'];
                $updated_item['prompt_type_name'] = get_prompt_type_name($updated_data['prompt_type'] ?? $existing_item['prompt_type']);
                
                // 썸네일 URL 업데이트
                $updated_item['thumbnail_url'] = $updated_data['thumbnail_url'] ?? $existing_item['thumbnail_url'] ?? null;
                $updated_item['has_thumbnail_url'] = !empty($updated_data['thumbnail_url']);
                
                // 키워드 데이터 완전 교체 (전체 구조 보존)
                if (isset($updated_data['keywords']) && is_array($updated_data['keywords'])) {
                    $updated_item['keywords'] = $updated_data['keywords'];
                    error_log("키워드 데이터 업데이트 완료 (분할 시스템): " . count($updated_data['keywords']) . "개");
                }
                
                // 사용자 세부사항 업데이트
                $updated_item['user_details'] = $updated_data['user_details'] ?? $existing_item['user_details'] ?? [];
                $updated_item['has_user_details'] = !empty($updated_data['user_details']);
                
                // 상품 데이터 존재 여부 확인
                $has_product_data = false;
                if (isset($updated_item['keywords']) && is_array($updated_item['keywords'])) {
                    foreach ($updated_item['keywords'] as $keyword) {
                        if (isset($keyword['products_data']) && is_array($keyword['products_data']) && count($keyword['products_data']) > 0) {
                            $has_product_data = true;
                            break;
                        }
                    }
                }
                $updated_item['has_product_data'] = $has_product_data;
                $updated_item['updated_at'] = date('Y-m-d H:i:s');
                
                // 분할 시스템으로 업데이트
                $result = update_queue_data_split($queue_id, $updated_item);
                
                if ($result) {
                    error_log("큐 항목 업데이트 완료 (분할 시스템): " . $queue_id);
                    echo json_encode(['success' => true, 'message' => '항목이 업데이트되었습니다.']);
                } else {
                    echo json_encode(['success' => false, 'message' => '업데이트에 실패했습니다.']);
                }
                exit;
                
            case 'reorder_queue':
                $new_order = json_decode($_POST['order'] ?? '[]', true);
                if (empty($new_order)) {
                    echo json_encode(['success' => false, 'message' => '순서 데이터가 없습니다.']);
                    exit;
                }
                
                // 분할 시스템 사용
                $result = reorder_queues_split($new_order);
                
                echo json_encode($result ? 
                    ['success' => true, 'message' => '순서가 변경되었습니다.'] : 
                    ['success' => false, 'message' => '순서 변경에 실패했습니다.']
                );
                exit;
                
            case 'immediate_publish':
                $queue_id = $_POST['queue_id'] ?? '';
                if (empty($queue_id)) {
                    echo json_encode(['success' => false, 'message' => '큐 ID가 제공되지 않았습니다.']);
                    exit;
                }
                
                // 분할 시스템으로 큐 항목 로드
                $selected_item = load_queue_split($queue_id);
                
                if (!$selected_item) {
                    echo json_encode(['success' => false, 'message' => '선택된 항목을 찾을 수 없습니다.']);
                    exit;
                }
                
                // 디버깅 로그 추가
                error_log("즉시 발행 시작 (분할 시스템): " . $selected_item['title']);
                error_log("카테고리: " . $selected_item['category_id']);
                error_log("프롬프트 타입: " . $selected_item['prompt_type']);
                error_log("키워드 수: " . count($selected_item['keywords']));
                
                // 발행 데이터 준비
                $publish_data = [
                    'title' => $selected_item['title'],
                    'category' => $selected_item['category_id'],
                    'prompt_type' => $selected_item['prompt_type'],
                    'keywords' => json_encode($selected_item['keywords']),
                    'user_details' => json_encode($selected_item['user_details']),
                    'thumbnail_url' => $selected_item['thumbnail_url'] ?? '',
                    'publish_mode' => 'immediate'
                ];
                
                // 절대 경로로 수정 및 에러 처리 강화
                $ch = curl_init();
                
                // 프로토콜과 호스트 정보 추출
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'];
                $script_dir = dirname($_SERVER['SCRIPT_NAME']);
                
                // keyword_processor.php의 전체 URL 구성
                $processor_url = $protocol . $host . $script_dir . '/keyword_processor.php';
                
                error_log("keyword_processor.php URL: " . $processor_url);
                
                curl_setopt($ch, CURLOPT_URL, $processor_url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($publish_data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL 인증서 검증 비활성화 (개발 환경용)
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                
                // 프로덕션 모드: 디버깅 출력 비활성화
                // curl_setopt($ch, CURLOPT_VERBOSE, true);
                // $verbose = fopen('php://temp', 'w+');
                // curl_setopt($ch, CURLOPT_STDERR, $verbose);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                $curl_info = curl_getinfo($ch);
                
                // 프로덕션 모드: 상세 디버깅 로그 비활성화 (필요시에만 활성화)
                // rewind($verbose);
                // $verboseLog = stream_get_contents($verbose);
                // error_log("CURL Verbose Log: " . $verboseLog);
                error_log("HTTP Code: " . $http_code);
                error_log("CURL Error: " . $curl_error);
                error_log("Response length: " . strlen($response));
                // error_log("Response (first 500 chars): " . substr($response, 0, 500));
                
                curl_close($ch);
                
                // 응답 처리 개선
                if ($curl_error) {
                    echo json_encode(['success' => false, 'message' => 'cURL 오류: ' . $curl_error]);
                    exit;
                }
                
                if ($http_code !== 200) {
                    echo json_encode(['success' => false, 'message' => 'HTTP 오류: ' . $http_code]);
                    exit;
                }
                
                if (!$response) {
                    echo json_encode(['success' => false, 'message' => '응답이 비어있습니다.']);
                    exit;
                }
                
                // JSON 디코딩 시도
                $result = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("JSON 디코딩 오류: " . json_last_error_msg());
                    error_log("전체 응답 내용: " . $response);
                    
                    // keyword_processor.php의 JSON 응답 패턴 확인
                    if (preg_match('/\{"success":(true|false).*?\}$/s', $response, $json_matches)) {
                        $json_part = $json_matches[0];
                        error_log("JSON 부분 발견: " . $json_part);
                        $result = json_decode($json_part, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            echo json_encode($result);
                            exit;
                        }
                    }
                    
                    // Python 스크립트 출력에서 성공 메시지 찾기
                    if (strpos($response, '워드프레스 발행 성공:') !== false) {
                        // URL 추출 시도
                        preg_match('/워드프레스 발행 성공: (https?:\/\/[^\s]+)/', $response, $matches);
                        $post_url = $matches[1] ?? '';
                        
                        echo json_encode([
                            'success' => true, 
                            'message' => '글이 성공적으로 발행되었습니다!',
                            'post_url' => $post_url
                        ]);
                    } else {
                        echo json_encode(['success' => false, 'message' => '발행 처리 중 오류가 발생했습니다. (응답 파싱 실패)']);
                    }
                    exit;
                }
                
                // 정상적인 JSON 응답 처리
                if ($result && isset($result['success'])) {
                    echo json_encode($result);
                } else {
                    echo json_encode(['success' => false, 'message' => '발행 처리 중 오류가 발생했습니다.']);
                }
                exit;
                
            case 'analyze_product':
                $url = $_POST['url'] ?? '';
                if (empty($url)) {
                    echo json_encode(['success' => false, 'message' => '상품 URL을 입력해주세요.']);
                    exit;
                }
                
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $absolute_url = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/product_analyzer_v2.php';
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $absolute_url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => 'analyze_product', 'url' => $url, 'platform' => 'aliexpress']));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);
                
                if ($curl_error) {
                    echo json_encode(['success' => false, 'message' => 'cURL 오류: ' . $curl_error]);
                } elseif ($http_code === 200 && $response) {
                    echo $response;
                } else {
                    echo json_encode(['success' => false, 'message' => '상품 분석 요청에 실패했습니다. HTTP 코드: ' . $http_code]);
                }
                exit;
                
            default:
                echo json_encode(['success' => false, 'message' => '알 수 없는 액션입니다.']);
                exit;
        }
    } catch (Exception $e) {
        error_log("AJAX 요청 처리 중 오류 발생: " . $e->getMessage());
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
<title>저장된 정보 관리 - 노바센트</title>
<link rel="stylesheet" href="assets/queue_manager.css">
</head>
<body>
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-content">
        <div class="loading-spinner"></div>
        <div class="loading-text">처리 중입니다...</div>
        <div style="margin-top: 10px; color: #666; font-size: 14px;">잠시만 기다려주세요.</div>
    </div>
</div>

<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">큐 항목 편집</h2>
            <button class="modal-close" onclick="closeEditModal()">×</button>
        </div>
        <div class="modal-body">
            <div class="form-section">
                <h3>기본 정보</h3>
                <div class="form-row">
                    <div class="form-field">
                        <label for="editTitle">글 제목</label>
                        <input type="text" id="editTitle" placeholder="글 제목을 입력하세요">
                    </div>
                </div>
                <div class="form-row three-col">
                    <div class="form-field">
                        <label for="editCategory">카테고리</label>
                        <select id="editCategory">
                            <option value="356">스마트 리빙</option>
                            <option value="355">기발한 잡화점</option>
                            <option value="354">Today's Pick</option>
                            <option value="12">우리잇템</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="editPromptType">프롬프트 스타일</label>
                        <select id="editPromptType">
                            <option value="essential_items">주제별 필수템형</option>
                            <option value="friend_review">친구 추천형</option>
                            <option value="professional_analysis">전문 분석형</option>
                            <option value="amazing_discovery">놀라움 발견형</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="editThumbnailUrl">썸네일 이미지 URL</label>
                        <input type="url" id="editThumbnailUrl" placeholder="썸네일 이미지 URL을 입력하세요">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>키워드 관리</h3>
                <div class="keyword-manager">
                    <div class="keyword-list" id="keywordList"></div>
                    <div class="add-keyword-section">
                        <div class="form-row">
                            <div class="form-field">
                                <label>새 키워드 추가</label>
                                <div style="display: flex; gap: 10px;">
                                    <input type="text" id="newKeywordName" placeholder="키워드 이름을 입력하세요">
                                    <button type="button" class="btn btn-success" onclick="addKeyword()">추가</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeEditModal()">취소</button>
            <button type="button" class="btn btn-primary" onclick="saveEditedQueue()">저장</button>
        </div>
    </div>
</div>

<div class="main-container">
    <div class="header-section">
        <h1>📋 저장된 정보 관리</h1>
        <p class="subtitle">큐에 저장된 항목들을 관리하고 즉시 발행할 수 있습니다 (v4.0 - Phase 2 UI/UX 개선 완료)</p>
        <div class="header-actions">
            <a href="affiliate_editor.php" class="btn btn-primary">📝 새 글 작성</a>
            <button type="button" class="btn btn-secondary" onclick="refreshQueue()">🔄 새로고침</button>
        </div>
    </div>

    <div class="main-content">
        <!-- 📊 향상된 대시보드 영역 -->
        <div class="dashboard-section">
            <div class="dashboard-header">
                <h2>📊 큐 관리 대시보드</h2>
                <div class="dashboard-meta">
                    <span class="last-updated">마지막 업데이트: <span id="lastUpdated">-</span></span>
                </div>
            </div>
            
            <div class="queue-stats" id="queueStats">
                <div class="stat-card stat-total">
                    <div class="stat-icon">📋</div>
                    <div class="stat-info">
                        <div class="stat-number" id="totalCount">0</div>
                        <div class="stat-label">전체 항목</div>
                    </div>
                </div>
                <div class="stat-card stat-pending">
                    <div class="stat-icon">🟡</div>
                    <div class="stat-info">
                        <div class="stat-number" id="pendingCount">0</div>
                        <div class="stat-label">대기 중</div>
                    </div>
                </div>
                <div class="stat-card stat-processing">
                    <div class="stat-icon">🔵</div>
                    <div class="stat-info">
                        <div class="stat-number" id="processingCount">0</div>
                        <div class="stat-label">처리 중</div>
                    </div>
                </div>
                <div class="stat-card stat-completed">
                    <div class="stat-icon">🟢</div>
                    <div class="stat-info">
                        <div class="stat-number" id="completedCount">0</div>
                        <div class="stat-label">완료</div>
                    </div>
                </div>
                <div class="stat-card stat-failed">
                    <div class="stat-icon">🔴</div>
                    <div class="stat-info">
                        <div class="stat-number" id="failedCount">0</div>
                        <div class="stat-label">실패</div>
                    </div>
                </div>
            </div>
            
            <!-- 📈 오늘/이번주 통계 -->
            <div class="period-stats">
                <div class="period-card">
                    <div class="period-icon">📈</div>
                    <div class="period-info">
                        <div class="period-number" id="todayProcessed">0</div>
                        <div class="period-label">오늘 처리</div>
                    </div>
                </div>
                <div class="period-card">
                    <div class="period-icon">📅</div>
                    <div class="period-info">
                        <div class="period-number" id="weekProcessed">0</div>
                        <div class="period-label">이번주 처리</div>
                    </div>
                </div>
                <div class="period-card">
                    <div class="period-icon">⚡</div>
                    <div class="period-info">
                        <div class="period-number" id="avgProcessTime">-</div>
                        <div class="period-label">평균 처리시간</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="filter-controls">
            <div class="status-filters">
                <label>📊 상태 필터:</label>
                <div class="filter-buttons">
                    <button type="button" class="filter-btn active" data-status="all" onclick="filterByStatus('all')">전체</button>
                    <button type="button" class="filter-btn" data-status="pending" onclick="filterByStatus('pending')">🟡 대기중</button>
                    <button type="button" class="filter-btn" data-status="processing" onclick="filterByStatus('processing')">🔵 처리중</button>
                    <button type="button" class="filter-btn" data-status="completed" onclick="filterByStatus('completed')">🟢 완료</button>
                    <button type="button" class="filter-btn" data-status="failed" onclick="filterByStatus('failed')">🔴 실패</button>
                </div>
            </div>
            
            <div class="search-controls">
                <label for="searchInput">🔍 검색:</label>
                <input type="text" id="searchInput" placeholder="제목 또는 키워드로 검색..." onkeyup="searchQueues()">
                <button type="button" class="btn btn-secondary btn-small" onclick="clearSearch()">지우기</button>
            </div>
            
            <div class="sort-controls">
                <label for="sortBy">📊 정렬:</label>
                <select id="sortBy" onchange="sortQueue()">
                    <option value="created_at">📅 등록일시</option>
                    <option value="title">📝 제목</option>
                    <option value="status">⚡ 상태</option>
                    <option value="priority">⭐ 우선순위</option>
                </select>
                <select id="sortOrder" onchange="sortQueue()">
                    <option value="desc">⬇️ 내림차순</option>
                    <option value="asc">⬆️ 오름차순</option>
                </select>
                <button type="button" class="btn btn-secondary btn-small" onclick="toggleDragSort()">
                    <span id="dragToggleText">🔄 드래그 정렬 활성화</span>
                </button>
            </div>
        </div>

        <div class="queue-list" id="queueList">
            <div class="empty-state">
                <h3>📦 저장된 정보가 없습니다</h3>
                <p>아직 저장된 큐 항목이 없습니다.</p>
                <a href="affiliate_editor.php" class="btn btn-primary">첫 번째 글 작성하기</a>
            </div>
        </div>
    </div>
</div>

<!-- 🔔 알림 모달 -->
<div class="notification-container" id="notificationContainer"></div>

<script src="assets/queue_manager.js?v=<?php echo time(); ?>"></script>
<script src="assets/queue_manager_enhanced.js?v=<?php echo time(); ?>"></script>
<script>
// 🔄 마지막 업데이트 시간 업데이트
function updateLastUpdated() {
    const now = new Date();
    const timeString = now.toLocaleString('ko-KR');
    document.getElementById('lastUpdated').textContent = timeString;
}

// 📊 기간별 통계 업데이트 (모의 데이터)
function updatePeriodStats() {
    // TODO: 실제 API에서 데이터 가져오기
    document.getElementById('todayProcessed').textContent = '5';
    document.getElementById('weekProcessed').textContent = '23';
    document.getElementById('avgProcessTime').textContent = '2.5분';
}

// 🔍 검색 엔터 키 처리
function handleSearchKeyPress(event) {
    if (event.key === 'Enter') {
        performSearch();
    }
}

// 🔍 검색 수행
function performSearch() {
    const searchTerm = document.getElementById('searchInput').value;
    console.log('검색 수행:', searchTerm);
    // TODO: 실제 검색 로직 구현
}

// 📅 기간 필터
function filterByPeriod() {
    const period = document.getElementById('periodFilter').value;
    console.log('기간 필터:', period);
    // TODO: 기간별 필터링 로직 구현
}

// 📂 카테고리 필터
function filterByCategory() {
    const category = document.getElementById('categoryFilter').value;
    console.log('카테골리 필터:', category);
    // TODO: 카테고리별 필터링 로직 구현
}

// 📊 뷰 변경
function changeView(viewType) {
    const viewBtns = document.querySelectorAll('.view-btn');
    viewBtns.forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[data-view="${viewType}"]`).classList.add('active');
    
    const queueList = document.getElementById('queueList');
    queueList.className = `queue-list view-${viewType}`;
    console.log('뷰 변경:', viewType);
}

// ☑️ 전체 선택
function selectAll() {
    console.log('전체 선택');
    // TODO: 전체 선택 로직 구현
}

// 🛠️ 대량 작업
function bulkAction(action) {
    console.log('대량 작업:', action);
    // TODO: 대량 작업 로직 구현
}

// 🔔 알림 표시
function showNotification(message, type = 'info') {
    const container = document.getElementById('notificationContainer');
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <span class="notification-message">${message}</span>
        <button class="notification-close" onclick="this.parentElement.remove()">×</button>
    `;
    container.appendChild(notification);
    
    // 5초 후 자동 제거
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

// 🔄 페이지 로드 시 초기화
document.addEventListener('DOMContentLoaded', function() {
    updateLastUpdated();
    updatePeriodStats();
    
    // 30초마다 통계 업데이트
    setInterval(() => {
        updatePeriodStats();
        updateLastUpdated();
    }, 30000);
});
</script>
</body>
</html>