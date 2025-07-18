<?php
/**
 * 저장된 정보 관리 페이지 - 완전한 편집 기능이 포함된 큐 관리 시스템
 * 저장된 큐 항목들을 확인하고 수정/삭제/즉시발행할 수 있는 관리 페이지
 */
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');
if (!current_user_can('manage_options')) { wp_die('접근 권한이 없습니다.'); }

// 큐 파일 경로
define('QUEUE_FILE', __DIR__ . '/product_queue.json');

// 큐 로드 함수
function load_queue() {
    if (!file_exists(QUEUE_FILE)) {
        return [];
    }
    
    $content = file_get_contents(QUEUE_FILE);
    if ($content === false) {
        return [];
    }
    
    $queue = json_decode($content, true);
    return is_array($queue) ? $queue : [];
}

// 큐 저장 함수
function save_queue($queue) {
    $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json_data = json_encode($queue, $json_options);
    
    if ($json_data === false) {
        return false;
    }
    
    return file_put_contents(QUEUE_FILE, $json_data, LOCK_EX) !== false;
}

// AJAX 요청 처리
if (isset($_POST['action']) && $_POST['action'] === 'get_queue_list') {
    header('Content-Type: application/json');
    $queue = load_queue();
    echo json_encode(['success' => true, 'queue' => $queue]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_queue_item') {
    header('Content-Type: application/json');
    $queue_id = $_POST['queue_id'] ?? '';
    $queue = load_queue();
    
    $found = false;
    foreach ($queue as $index => $item) {
        if ($item['queue_id'] === $queue_id) {
            unset($queue[$index]);
            $queue = array_values($queue); // 배열 인덱스 재정렬
            $found = true;
            break;
        }
    }
    
    if ($found && save_queue($queue)) {
        echo json_encode(['success' => true, 'message' => '항목이 삭제되었습니다.']);
    } else {
        echo json_encode(['success' => false, 'message' => '삭제에 실패했습니다.']);
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'get_queue_item') {
    header('Content-Type: application/json');
    $queue_id = $_POST['queue_id'] ?? '';
    $queue = load_queue();
    
    foreach ($queue as $item) {
        if ($item['queue_id'] === $queue_id) {
            echo json_encode(['success' => true, 'item' => $item]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => '항목을 찾을 수 없습니다.']);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'update_queue_item') {
    header('Content-Type: application/json');
    $queue_id = $_POST['queue_id'] ?? '';
    $updated_data = json_decode($_POST['data'] ?? '{}', true);
    
    $queue = load_queue();
    $found = false;
    
    foreach ($queue as $index => $item) {
        if ($item['queue_id'] === $queue_id) {
            // 기존 항목 업데이트
            $queue[$index]['title'] = $updated_data['title'] ?? $item['title'];
            $queue[$index]['category_id'] = $updated_data['category_id'] ?? $item['category_id'];
            $queue[$index]['category_name'] = get_category_name($updated_data['category_id'] ?? $item['category_id']);
            $queue[$index]['prompt_type'] = $updated_data['prompt_type'] ?? $item['prompt_type'];
            $queue[$index]['prompt_type_name'] = get_prompt_type_name($updated_data['prompt_type'] ?? $item['prompt_type']);
            $queue[$index]['keywords'] = $updated_data['keywords'] ?? $item['keywords'];
            $queue[$index]['user_details'] = $updated_data['user_details'] ?? $item['user_details'];
            $queue[$index]['has_user_details'] = !empty($updated_data['user_details']);
            $queue[$index]['updated_at'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    
    if ($found && save_queue($queue)) {
        echo json_encode(['success' => true, 'message' => '항목이 업데이트되었습니다.']);
    } else {
        echo json_encode(['success' => false, 'message' => '업데이트에 실패했습니다.']);
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'reorder_queue') {
    header('Content-Type: application/json');
    $new_order = json_decode($_POST['order'] ?? '[]', true);
    
    if (empty($new_order)) {
        echo json_encode(['success' => false, 'message' => '순서 데이터가 없습니다.']);
        exit;
    }
    
    $queue = load_queue();
    $reordered_queue = [];
    
    // 새로운 순서에 따라 큐 재정렬
    foreach ($new_order as $queue_id) {
        foreach ($queue as $item) {
            if ($item['queue_id'] === $queue_id) {
                $reordered_queue[] = $item;
                break;
            }
        }
    }
    
    if (count($reordered_queue) === count($queue) && save_queue($reordered_queue)) {
        echo json_encode(['success' => true, 'message' => '순서가 변경되었습니다.']);
    } else {
        echo json_encode(['success' => false, 'message' => '순서 변경에 실패했습니다.']);
    }
    exit;
}

// 즉시 발행 처리
if (isset($_POST['action']) && $_POST['action'] === 'immediate_publish') {
    header('Content-Type: application/json');
    $queue_id = $_POST['queue_id'] ?? '';
    $queue = load_queue();
    
    $selected_item = null;
    foreach ($queue as $item) {
        if ($item['queue_id'] === $queue_id) {
            $selected_item = $item;
            break;
        }
    }
    
    if (!$selected_item) {
        echo json_encode(['success' => false, 'message' => '선택된 항목을 찾을 수 없습니다.']);
        exit;
    }
    
    // keyword_processor.php로 즉시 발행 요청
    $publish_data = [
        'title' => $selected_item['title'],
        'category' => $selected_item['category_id'],
        'prompt_type' => $selected_item['prompt_type'],
        'keywords' => json_encode($selected_item['keywords']),
        'user_details' => json_encode($selected_item['user_details']),
        'publish_mode' => 'immediate'
    ];
    
    // cURL로 keyword_processor.php 호출
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'keyword_processor.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($publish_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5분 타임아웃
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $result = json_decode($response, true);
        if ($result && isset($result['success'])) {
            echo $response; // 그대로 전달
        } else {
            echo json_encode(['success' => false, 'message' => '발행 처리 중 오류가 발생했습니다.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => '발행 요청 전송에 실패했습니다.']);
    }
    exit;
}

// 상품 분석 처리
if (isset($_POST['action']) && $_POST['action'] === 'analyze_product') {
    header('Content-Type: application/json');
    $url = $_POST['url'] ?? '';
    
    if (empty($url)) {
        echo json_encode(['success' => false, 'message' => '상품 URL을 입력해주세요.']);
        exit;
    }
    
    // product_analyzer_v2.php 호출
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'product_analyzer_v2.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action' => 'analyze_product',
        'url' => $url,
        'platform' => 'aliexpress'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        echo $response; // 그대로 전달
    } else {
        echo json_encode(['success' => false, 'message' => '상품 분석 요청에 실패했습니다.']);
    }
    exit;
}

// 유틸리티 함수들
function get_category_name($category_id) {
    $categories = [
        '354' => 'Today\'s Pick',
        '355' => '기발한 잡화점',
        '356' => '스마트 리빙',
        '12' => '우리잇템'
    ];
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

function get_prompt_type_name($prompt_type) {
    $prompt_types = [
        'essential_items' => '필수템형 🎯',
        'friend_review' => '친구 추천형 👫',
        'professional_analysis' => '전문 분석형 📊',
        'amazing_discovery' => '놀라움 발견형 ✨'
    ];
    return $prompt_types[$prompt_type] ?? '기본형';
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>저장된 정보 관리 - 노바센트</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;padding:20px;background-color:#f5f5f5;min-width:1200px;color:#1c1c1c}
.main-container{max-width:1800px;margin:0 auto;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);overflow:hidden}
.header-section{padding:30px;border-bottom:1px solid #e0e0e0;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white}
.header-section h1{margin:0 0 10px 0;font-size:28px}
.header-section .subtitle{margin:0 0 20px 0;opacity:0.9}
.header-actions{display:flex;gap:10px;margin-top:20px}
.btn{padding:12px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.3s;text-decoration:none;display:inline-block}
.btn-primary{background-color:#007bff;color:white}
.btn-primary:hover{background-color:#0056b3}
.btn-secondary{background-color:#6c757d;color:white}
.btn-secondary:hover{background-color:#545b62}
.btn-success{background-color:#28a745;color:white}
.btn-success:hover{background-color:#1e7e34}
.btn-danger{background-color:#dc3545;color:white}
.btn-danger:hover{background-color:#c82333}
.btn-warning{background-color:#ffc107;color:#212529}
.btn-warning:hover{background-color:#e0a800}
.btn-orange{background:#ff9900;color:white}
.btn-orange:hover{background:#e68a00}
.btn-small{padding:6px 12px;font-size:12px;margin:0 2px}
.btn-large{padding:15px 30px;font-size:16px}
.main-content{padding:30px}
.queue-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:30px}
.stat-card{background:#f8f9fa;padding:20px;border-radius:8px;text-align:center;border:1px solid #e9ecef}
.stat-number{font-size:24px;font-weight:bold;color:#007bff}
.stat-label{margin-top:5px;color:#666;font-size:14px}
.queue-list{display:grid;gap:20px}
.queue-item{background:white;border:1px solid #e9ecef;border-radius:12px;padding:25px;box-shadow:0 2px 8px rgba(0,0,0,0.05);transition:all 0.3s}
.queue-item:hover{box-shadow:0 4px 16px rgba(0,0,0,0.1);transform:translateY(-2px)}
.queue-header{display:flex;justify-content:space-between;align-items:start;margin-bottom:15px}
.queue-title{font-size:18px;font-weight:600;color:#1c1c1c;margin:0 0 5px 0}
.queue-meta{font-size:14px;color:#666;margin:0}
.queue-actions{display:flex;gap:5px}
.queue-content{margin:15px 0}
.queue-info{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px}
.info-item{background:#f8f9fa;padding:12px;border-radius:6px;text-align:center}
.info-value{font-size:16px;font-weight:600;color:#007bff}
.info-label{font-size:12px;color:#666;margin-top:2px}
.keywords-preview{margin-top:15px}
.keywords-preview h4{margin:0 0 10px 0;font-size:14px;color:#333}
.keyword-tags{display:flex;gap:5px;flex-wrap:wrap}
.keyword-tag{background:#e3f2fd;color:#1976d2;padding:4px 8px;border-radius:12px;font-size:12px}
.status-badge{padding:4px 8px;border-radius:12px;font-size:12px;font-weight:600}
.status-pending{background:#fff3cd;color:#856404}
.status-processing{background:#d4edda;color:#155724}
.status-completed{background:#cce5ff;color:#004085}
.status-failed{background:#f8d7da;color:#721c24}
.empty-state{text-align:center;padding:60px 20px;color:#666}
.empty-state h3{margin:0 0 15px 0;font-size:24px;color:#999}
.empty-state p{margin:0 0 20px 0;font-size:16px}
.loading-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:none;align-items:center;justify-content:center}
.loading-content{background:white;border-radius:10px;padding:40px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.3)}
.loading-spinner{display:inline-block;width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #ff9900;border-radius:50%;animation:spin 1s linear infinite;margin-bottom:20px}
.loading-text{font-size:18px;color:#333;font-weight:600}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
.sort-controls{display:flex;gap:10px;margin-bottom:20px;align-items:center}
.sort-controls label{font-weight:600;color:#333}
.sort-controls select{padding:8px;border:1px solid #ddd;border-radius:4px}
.queue-item.dragging{opacity:0.5}
.queue-item.drag-over{border-color:#007bff;box-shadow:0 0 10px rgba(0,123,255,0.3)}

/* 편집 모달 스타일 */
.modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10001;display:none;align-items:center;justify-content:center}
.modal-content{background:white;border-radius:12px;max-width:1200px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 10px 30px rgba(0,0,0,0.3)}
.modal-header{padding:20px 30px;border-bottom:1px solid #e0e0e0;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;border-radius:12px 12px 0 0}
.modal-title{margin:0;font-size:24px}
.modal-close{position:absolute;top:20px;right:30px;background:none;border:none;color:white;font-size:24px;cursor:pointer;padding:0;width:30px;height:30px;display:flex;align-items:center;justify-content:center}
.modal-body{padding:30px}
.form-section{margin-bottom:30px;padding:20px;background:#f8f9fa;border-radius:8px}
.form-section h3{margin:0 0 20px 0;color:#333;font-size:18px;padding-bottom:10px;border-bottom:2px solid #e0e0e0}
.form-row{display:grid;gap:15px;margin-bottom:15px}
.form-row.two-col{grid-template-columns:1fr 1fr}
.form-row.three-col{grid-template-columns:1fr 1fr 1fr}
.form-field{margin-bottom:15px}
.form-field label{display:block;margin-bottom:5px;font-weight:600;color:#333;font-size:14px}
.form-field input,.form-field textarea,.form-field select{width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box}
.form-field textarea{min-height:60px;resize:vertical}
.modal-footer{padding:20px 30px;border-top:1px solid #e0e0e0;display:flex;gap:10px;justify-content:flex-end}

/* 키워드 관리 */
.keyword-manager{margin-bottom:30px}
.keyword-list{display:grid;gap:15px}
.keyword-item{background:white;border:1px solid #e0e0e0;border-radius:8px;padding:15px}
.keyword-item-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.keyword-item-title{font-weight:600;color:#333}
.keyword-item-actions{display:flex;gap:5px}
.product-list{margin-top:10px}
.product-item-edit{background:#f8f9fa;padding:10px;border-radius:4px;margin-bottom:10px;border:1px solid #e0e0e0}
.product-item-edit-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.product-url-input{flex:1;margin-right:10px}
.add-product-section{margin-top:15px;padding:15px;background:#f0f8ff;border-radius:6px;border:1px solid #b3d9ff}
.add-keyword-section{margin-top:20px;padding:15px;background:#f0f8ff;border-radius:6px;border:1px solid #b3d9ff}

/* 사용자 상세 정보 */
.user-details-section{margin-bottom:30px}
.advantages-list{list-style:none;padding:0;margin:0}
.advantages-list li{margin-bottom:10px}
.advantages-list input{width:100%;padding:8px;border:1px solid #ddd;border-radius:4px}

/* 상품 분석 결과 */
.analysis-result{margin-top:15px;padding:15px;background:#f1f8ff;border-radius:6px;border:1px solid #b3d9ff}
.product-preview{display:grid;grid-template-columns:150px 1fr;gap:15px;align-items:start}
.product-preview img{width:100%;border-radius:6px}
.product-info{font-size:14px;color:#333}
.product-info h4{margin:0 0 10px 0;font-size:16px;color:#1c1c1c}
.product-info p{margin:5px 0}
</style>
</head>
<body>
<!-- 로딩 오버레이 -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-content">
        <div class="loading-spinner"></div>
        <div class="loading-text">처리 중입니다...</div>
        <div style="margin-top: 10px; color: #666; font-size: 14px;">잠시만 기다려주세요.</div>
    </div>
</div>

<!-- 편집 모달 -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">큐 항목 편집</h2>
            <button class="modal-close" onclick="closeEditModal()">×</button>
        </div>
        <div class="modal-body">
            <!-- 기본 정보 -->
            <div class="form-section">
                <h3>기본 정보</h3>
                <div class="form-row three-col">
                    <div class="form-field">
                        <label for="editTitle">글 제목</label>
                        <input type="text" id="editTitle" placeholder="글 제목을 입력하세요">
                    </div>
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
                </div>
            </div>

            <!-- 키워드 관리 -->
            <div class="form-section">
                <h3>키워드 관리</h3>
                <div class="keyword-manager">
                    <div class="keyword-list" id="keywordList">
                        <!-- 키워드 항목들이 여기에 동적으로 추가됩니다 -->
                    </div>
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

            <!-- 사용자 상세 정보 -->
            <div class="form-section">
                <h3>사용자 상세 정보</h3>
                <div class="user-details-section">
                    <div class="form-section">
                        <h4>기능 및 스펙</h4>
                        <div class="form-row">
                            <div class="form-field">
                                <label>주요 기능</label>
                                <input type="text" id="editMainFunction" placeholder="예: 자동 압축, 물 절약, 시간 단축 등">
                            </div>
                        </div>
                        <div class="form-row two-col">
                            <div class="form-field">
                                <label>크기/용량</label>
                                <input type="text" id="editSizeCapacity" placeholder="예: 30cm × 20cm, 500ml 등">
                            </div>
                            <div class="form-field">
                                <label>색상</label>
                                <input type="text" id="editColor" placeholder="예: 화이트, 블랙, 실버 등">
                            </div>
                        </div>
                        <div class="form-row two-col">
                            <div class="form-field">
                                <label>재질/소재</label>
                                <input type="text" id="editMaterial" placeholder="예: 스테인리스 스틸, 실리콘 등">
                            </div>
                            <div class="form-field">
                                <label>전원/배터리</label>
                                <input type="text" id="editPowerBattery" placeholder="예: USB 충전, 건전지 등">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4>효율성 분석</h4>
                        <div class="form-row">
                            <div class="form-field">
                                <label>해결하는 문제</label>
                                <input type="text" id="editProblemSolving" placeholder="예: 설거지 시간 오래 걸림">
                            </div>
                        </div>
                        <div class="form-row two-col">
                            <div class="form-field">
                                <label>시간 절약 효과</label>
                                <input type="text" id="editTimeSaving" placeholder="예: 기존 10분 → 3분으로 단축">
                            </div>
                            <div class="form-field">
                                <label>공간 활용</label>
                                <input type="text" id="editSpaceEfficiency" placeholder="예: 50% 공간 절약">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-field">
                                <label>비용 절감</label>
                                <input type="text" id="editCostSaving" placeholder="예: 월 전기료 30% 절약">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4>사용 시나리오</h4>
                        <div class="form-row two-col">
                            <div class="form-field">
                                <label>주요 사용 장소</label>
                                <input type="text" id="editUsageLocation" placeholder="예: 주방, 욕실, 거실 등">
                            </div>
                            <div class="form-field">
                                <label>사용 빈도</label>
                                <input type="text" id="editUsageFrequency" placeholder="예: 매일, 주 2-3회 등">
                            </div>
                        </div>
                        <div class="form-row two-col">
                            <div class="form-field">
                                <label>적합한 사용자</label>
                                <input type="text" id="editTargetUsers" placeholder="예: 1인 가구, 맞벌이 부부 등">
                            </div>
                            <div class="form-field">
                                <label>사용법 요약</label>
                                <input type="text" id="editUsageMethod" placeholder="간단한 사용 단계">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4>장점 및 주의사항</h4>
                        <div class="form-row">
                            <div class="form-field">
                                <label>핵심 장점 3가지</label>
                                <ol class="advantages-list">
                                    <li><input type="text" id="editAdvantage1" placeholder="예: 설치 간편함"></li>
                                    <li><input type="text" id="editAdvantage2" placeholder="예: 유지비 저렴함"></li>
                                    <li><input type="text" id="editAdvantage3" placeholder="예: 내구성 뛰어남"></li>
                                </ol>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-field">
                                <label>주의사항</label>
                                <textarea id="editPrecautions" placeholder="예: 물기 주의, 정기 청소 필요 등"></textarea>
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
        <p class="subtitle">큐에 저장된 항목들을 관리하고 즉시 발행할 수 있습니다</p>
        <div class="header-actions">
            <a href="affiliate_editor.php" class="btn btn-primary">📝 새 글 작성</a>
            <button type="button" class="btn btn-secondary" onclick="refreshQueue()">🔄 새로고침</button>
        </div>
    </div>

    <div class="main-content">
        <!-- 큐 통계 -->
        <div class="queue-stats" id="queueStats">
            <div class="stat-card">
                <div class="stat-number" id="totalCount">0</div>
                <div class="stat-label">전체 항목</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="pendingCount">0</div>
                <div class="stat-label">대기 중</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="processingCount">0</div>
                <div class="stat-label">처리 중</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="completedCount">0</div>
                <div class="stat-label">완료</div>
            </div>
        </div>

        <!-- 정렬 컨트롤 -->
        <div class="sort-controls">
            <label for="sortBy">정렬 기준:</label>
            <select id="sortBy" onchange="sortQueue()">
                <option value="created_at">등록일시</option>
                <option value="title">제목</option>
                <option value="status">상태</option>
                <option value="priority">우선순위</option>
            </select>
            <select id="sortOrder" onchange="sortQueue()">
                <option value="desc">내림차순</option>
                <option value="asc">오름차순</option>
            </select>
            <button type="button" class="btn btn-secondary btn-small" onclick="toggleDragSort()">
                <span id="dragToggleText">드래그 정렬 활성화</span>
            </button>
        </div>

        <!-- 큐 목록 -->
        <div class="queue-list" id="queueList">
            <div class="empty-state">
                <h3>📦 저장된 정보가 없습니다</h3>
                <p>아직 저장된 큐 항목이 없습니다.</p>
                <a href="affiliate_editor.php" class="btn btn-primary">첫 번째 글 작성하기</a>
            </div>
        </div>
    </div>
</div>

<script>
let currentQueue = [];
let dragEnabled = false;
let currentEditingQueueId = null;
let currentEditingData = null;

// 페이지 로드 시 큐 데이터 로드
document.addEventListener('DOMContentLoaded', function() {
    loadQueue();
});

// 큐 데이터 로드
async function loadQueue() {
    try {
        showLoading();
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=get_queue_list'
        });
        
        const result = await response.json();
        
        if (result.success) {
            currentQueue = result.queue;
            updateQueueStats();
            displayQueue();
        } else {
            alert('큐 데이터를 불러오는데 실패했습니다.');
        }
    } catch (error) {
        console.error('큐 로드 오류:', error);
        alert('큐 데이터를 불러오는 중 오류가 발생했습니다.');
    } finally {
        hideLoading();
    }
}

// 큐 통계 업데이트
function updateQueueStats() {
    const stats = {
        total: currentQueue.length,
        pending: currentQueue.filter(item => item.status === 'pending').length,
        processing: currentQueue.filter(item => item.status === 'processing').length,
        completed: currentQueue.filter(item => item.status === 'completed').length
    };
    
    document.getElementById('totalCount').textContent = stats.total;
    document.getElementById('pendingCount').textContent = stats.pending;
    document.getElementById('processingCount').textContent = stats.processing;
    document.getElementById('completedCount').textContent = stats.completed;
}

// 큐 목록 표시
function displayQueue() {
    const queueList = document.getElementById('queueList');
    
    if (currentQueue.length === 0) {
        queueList.innerHTML = `
            <div class="empty-state">
                <h3>📦 저장된 정보가 없습니다</h3>
                <p>아직 저장된 큐 항목이 없습니다.</p>
                <a href="affiliate_editor.php" class="btn btn-primary">첫 번째 글 작성하기</a>
            </div>
        `;
        return;
    }
    
    let html = '';
    currentQueue.forEach(item => {
        const keywordCount = item.keywords ? item.keywords.length : 0;
        const totalLinks = item.keywords ? item.keywords.reduce((sum, k) => sum + (k.coupang?.length || 0) + (k.aliexpress?.length || 0), 0) : 0;
        const statusClass = `status-${item.status}`;
        const statusText = getStatusText(item.status);
        
        html += `
            <div class="queue-item" data-queue-id="${item.queue_id}" draggable="${dragEnabled}">
                <div class="queue-header">
                    <div>
                        <h3 class="queue-title">${item.title}</h3>
                        <p class="queue-meta">
                            ${item.category_name} | ${item.prompt_type_name || '기본형'} | 
                            ${item.created_at} | 
                            <span class="status-badge ${statusClass}">${statusText}</span>
                        </p>
                    </div>
                    <div class="queue-actions">
                        <button class="btn btn-primary btn-small" onclick="editQueue('${item.queue_id}')">✏️ 편집</button>
                        <button class="btn btn-orange btn-small" onclick="immediatePublish('${item.queue_id}')">🚀 즉시발행</button>
                        <button class="btn btn-danger btn-small" onclick="deleteQueue('${item.queue_id}')">🗑️ 삭제</button>
                    </div>
                </div>
                
                <div class="queue-content">
                    <div class="queue-info">
                        <div class="info-item">
                            <div class="info-value">${keywordCount}</div>
                            <div class="info-label">키워드</div>
                        </div>
                        <div class="info-item">
                            <div class="info-value">${totalLinks}</div>
                            <div class="info-label">총 링크</div>
                        </div>
                        <div class="info-item">
                            <div class="info-value">${item.priority || 1}</div>
                            <div class="info-label">우선순위</div>
                        </div>
                        <div class="info-item">
                            <div class="info-value">${item.has_user_details ? 'O' : 'X'}</div>
                            <div class="info-label">상세정보</div>
                        </div>
                    </div>
                    
                    ${item.keywords && item.keywords.length > 0 ? `
                        <div class="keywords-preview">
                            <h4>키워드:</h4>
                            <div class="keyword-tags">
                                ${item.keywords.map(k => `<span class="keyword-tag">${k.name}</span>`).join('')}
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    queueList.innerHTML = html;
    
    // 드래그 앤 드롭 이벤트 추가
    if (dragEnabled) {
        addDragEvents();
    }
}

// 상태 텍스트 변환
function getStatusText(status) {
    const statusMap = {
        'pending': '대기 중',
        'processing': '처리 중',
        'completed': '완료',
        'failed': '실패',
        'immediate': '즉시발행'
    };
    return statusMap[status] || status;
}

// 큐 정렬
function sortQueue() {
    const sortBy = document.getElementById('sortBy').value;
    const sortOrder = document.getElementById('sortOrder').value;
    
    currentQueue.sort((a, b) => {
        let aValue = a[sortBy];
        let bValue = b[sortBy];
        
        // 문자열 비교
        if (typeof aValue === 'string' && typeof bValue === 'string') {
            aValue = aValue.toLowerCase();
            bValue = bValue.toLowerCase();
        }
        
        if (sortOrder === 'asc') {
            return aValue < bValue ? -1 : aValue > bValue ? 1 : 0;
        } else {
            return aValue > bValue ? -1 : aValue < bValue ? 1 : 0;
        }
    });
    
    displayQueue();
}

// 드래그 정렬 토글
function toggleDragSort() {
    dragEnabled = !dragEnabled;
    const toggleText = document.getElementById('dragToggleText');
    toggleText.textContent = dragEnabled ? '드래그 정렬 비활성화' : '드래그 정렬 활성화';
    
    // 모든 큐 아이템의 draggable 속성 업데이트
    document.querySelectorAll('.queue-item').forEach(item => {
        item.draggable = dragEnabled;
    });
    
    if (dragEnabled) {
        addDragEvents();
    }
}

// 드래그 이벤트 추가
function addDragEvents() {
    const items = document.querySelectorAll('.queue-item');
    
    items.forEach(item => {
        item.addEventListener('dragstart', handleDragStart);
        item.addEventListener('dragover', handleDragOver);
        item.addEventListener('drop', handleDrop);
        item.addEventListener('dragend', handleDragEnd);
    });
}

let draggedItem = null;

function handleDragStart(e) {
    draggedItem = this;
    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/html', this.outerHTML);
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    this.classList.add('drag-over');
}

function handleDrop(e) {
    e.preventDefault();
    this.classList.remove('drag-over');
    
    if (draggedItem !== this) {
        const draggedId = draggedItem.dataset.queueId;
        const targetId = this.dataset.queueId;
        
        // 배열에서 순서 변경
        const draggedIndex = currentQueue.findIndex(item => item.queue_id === draggedId);
        const targetIndex = currentQueue.findIndex(item => item.queue_id === targetId);
        
        const draggedElement = currentQueue.splice(draggedIndex, 1)[0];
        currentQueue.splice(targetIndex, 0, draggedElement);
        
        // 순서 변경 서버에 저장
        saveQueueOrder();
        
        // 화면 업데이트
        displayQueue();
    }
}

function handleDragEnd(e) {
    this.classList.remove('dragging');
    document.querySelectorAll('.queue-item').forEach(item => {
        item.classList.remove('drag-over');
    });
}

// 큐 순서 저장
async function saveQueueOrder() {
    try {
        const order = currentQueue.map(item => item.queue_id);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `action=reorder_queue&order=${encodeURIComponent(JSON.stringify(order))}`
        });
        
        const result = await response.json();
        
        if (!result.success) {
            alert('순서 저장에 실패했습니다: ' + result.message);
            loadQueue(); // 실패 시 다시 로드
        }
    } catch (error) {
        console.error('순서 저장 오류:', error);
        alert('순서 저장 중 오류가 발생했습니다.');
        loadQueue(); // 오류 시 다시 로드
    }
}

// 큐 삭제
async function deleteQueue(queueId) {
    if (!confirm('정말로 이 항목을 삭제하시겠습니까?')) {
        return;
    }
    
    try {
        showLoading();
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `action=delete_queue_item&queue_id=${encodeURIComponent(queueId)}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('항목이 삭제되었습니다.');
            loadQueue(); // 삭제 후 다시 로드
        } else {
            alert('삭제에 실패했습니다: ' + result.message);
        }
    } catch (error) {
        console.error('삭제 오류:', error);
        alert('삭제 중 오류가 발생했습니다.');
    } finally {
        hideLoading();
    }
}

// 즉시 발행
async function immediatePublish(queueId) {
    if (!confirm('선택한 항목을 즉시 발행하시겠습니까?')) {
        return;
    }
    
    try {
        showLoading();
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `action=immediate_publish&queue_id=${encodeURIComponent(queueId)}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ 글이 성공적으로 발행되었습니다!');
            if (result.post_url) {
                window.open(result.post_url, '_blank');
            }
            loadQueue(); // 발행 후 다시 로드
        } else {
            alert('발행에 실패했습니다: ' + result.message);
        }
    } catch (error) {
        console.error('발행 오류:', error);
        alert('발행 중 오류가 발생했습니다.');
    } finally {
        hideLoading();
    }
}

// 큐 편집 모달 열기
async function editQueue(queueId) {
    try {
        showLoading();
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `action=get_queue_item&queue_id=${encodeURIComponent(queueId)}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            currentEditingQueueId = queueId;
            currentEditingData = result.item;
            populateEditModal(result.item);
            document.getElementById('editModal').style.display = 'flex';
        } else {
            alert('항목을 불러오는데 실패했습니다: ' + result.message);
        }
    } catch (error) {
        console.error('편집 데이터 로드 오류:', error);
        alert('편집 데이터를 불러오는 중 오류가 발생했습니다.');
    } finally {
        hideLoading();
    }
}

// 편집 모달에 데이터 채우기
function populateEditModal(item) {
    // 기본 정보
    document.getElementById('editTitle').value = item.title || '';
    document.getElementById('editCategory').value = item.category_id || '356';
    document.getElementById('editPromptType').value = item.prompt_type || 'essential_items';
    
    // 키워드 목록 표시
    displayKeywords(item.keywords || []);
    
    // 사용자 상세 정보
    const userDetails = item.user_details || {};
    
    // 기능 및 스펙
    const specs = userDetails.specs || {};
    document.getElementById('editMainFunction').value = specs.main_function || '';
    document.getElementById('editSizeCapacity').value = specs.size_capacity || '';
    document.getElementById('editColor').value = specs.color || '';
    document.getElementById('editMaterial').value = specs.material || '';
    document.getElementById('editPowerBattery').value = specs.power_battery || '';
    
    // 효율성 분석
    const efficiency = userDetails.efficiency || {};
    document.getElementById('editProblemSolving').value = efficiency.problem_solving || '';
    document.getElementById('editTimeSaving').value = efficiency.time_saving || '';
    document.getElementById('editSpaceEfficiency').value = efficiency.space_efficiency || '';
    document.getElementById('editCostSaving').value = efficiency.cost_saving || '';
    
    // 사용 시나리오
    const usage = userDetails.usage || {};
    document.getElementById('editUsageLocation').value = usage.usage_location || '';
    document.getElementById('editUsageFrequency').value = usage.usage_frequency || '';
    document.getElementById('editTargetUsers').value = usage.target_users || '';
    document.getElementById('editUsageMethod').value = usage.usage_method || '';
    
    // 장점 및 주의사항
    const benefits = userDetails.benefits || {};
    const advantages = benefits.advantages || [];
    document.getElementById('editAdvantage1').value = advantages[0] || '';
    document.getElementById('editAdvantage2').value = advantages[1] || '';
    document.getElementById('editAdvantage3').value = advantages[2] || '';
    document.getElementById('editPrecautions').value = benefits.precautions || '';
}

// 키워드 목록 표시
function displayKeywords(keywords) {
    const keywordList = document.getElementById('keywordList');
    let html = '';
    
    keywords.forEach((keyword, index) => {
        const aliexpressLinks = keyword.aliexpress || [];
        const coupangLinks = keyword.coupang || [];
        
        html += `
            <div class="keyword-item" data-keyword-index="${index}">
                <div class="keyword-item-header">
                    <input type="text" class="keyword-item-title" value="${keyword.name}" placeholder="키워드 이름">
                    <div class="keyword-item-actions">
                        <button type="button" class="btn btn-danger btn-small" onclick="removeKeyword(${index})">삭제</button>
                    </div>
                </div>
                
                <div class="product-list">
                    <h5>알리익스프레스 상품 (${aliexpressLinks.length}개)</h5>
                    <div class="aliexpress-products">
                        ${aliexpressLinks.map((url, urlIndex) => `
                            <div class="product-item-edit">
                                <div class="product-item-edit-header">
                                    <input type="url" class="product-url-input" value="${url}" placeholder="상품 URL">
                                    <button type="button" class="btn btn-secondary btn-small" onclick="analyzeProduct(${index}, 'aliexpress', ${urlIndex})">분석</button>
                                    <button type="button" class="btn btn-danger btn-small" onclick="removeProduct(${index}, 'aliexpress', ${urlIndex})">삭제</button>
                                </div>
                                <div class="analysis-result" id="analysis-${index}-aliexpress-${urlIndex}"></div>
                            </div>
                        `).join('')}
                    </div>
                    
                    <div class="add-product-section">
                        <div style="display: flex; gap: 10px;">
                            <input type="url" class="new-product-url" placeholder="새 알리익스프레스 상품 URL">
                            <button type="button" class="btn btn-success btn-small" onclick="addProduct(${index}, 'aliexpress')">추가</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    keywordList.innerHTML = html;
}

// 키워드 추가
function addKeyword() {
    const nameInput = document.getElementById('newKeywordName');
    const name = nameInput.value.trim();
    
    if (!name) {
        alert('키워드 이름을 입력해주세요.');
        return;
    }
    
    if (!currentEditingData.keywords) {
        currentEditingData.keywords = [];
    }
    
    currentEditingData.keywords.push({
        name: name,
        aliexpress: [],
        coupang: []
    });
    
    displayKeywords(currentEditingData.keywords);
    nameInput.value = '';
}

// 키워드 제거
function removeKeyword(index) {
    if (confirm('이 키워드를 삭제하시겠습니까?')) {
        currentEditingData.keywords.splice(index, 1);
        displayKeywords(currentEditingData.keywords);
    }
}

// 상품 추가
function addProduct(keywordIndex, platform) {
    const keywordItem = document.querySelector(`[data-keyword-index="${keywordIndex}"]`);
    const urlInput = keywordItem.querySelector('.new-product-url');
    const url = urlInput.value.trim();
    
    if (!url) {
        alert('상품 URL을 입력해주세요.');
        return;
    }
    
    if (!currentEditingData.keywords[keywordIndex][platform]) {
        currentEditingData.keywords[keywordIndex][platform] = [];
    }
    
    currentEditingData.keywords[keywordIndex][platform].push(url);
    displayKeywords(currentEditingData.keywords);
}

// 상품 제거
function removeProduct(keywordIndex, platform, urlIndex) {
    if (confirm('이 상품을 삭제하시겠습니까?')) {
        currentEditingData.keywords[keywordIndex][platform].splice(urlIndex, 1);
        displayKeywords(currentEditingData.keywords);
    }
}

// 상품 분석
async function analyzeProduct(keywordIndex, platform, urlIndex) {
    const url = currentEditingData.keywords[keywordIndex][platform][urlIndex];
    
    if (!url) {
        alert('분석할 상품 URL이 없습니다.');
        return;
    }
    
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `action=analyze_product&url=${encodeURIComponent(url)}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            displayAnalysisResult(keywordIndex, platform, urlIndex, result.data);
        } else {
            alert('상품 분석에 실패했습니다: ' + result.message);
        }
    } catch (error) {
        console.error('상품 분석 오류:', error);
        alert('상품 분석 중 오류가 발생했습니다.');
    }
}

// 분석 결과 표시
function displayAnalysisResult(keywordIndex, platform, urlIndex, data) {
    const resultDiv = document.getElementById(`analysis-${keywordIndex}-${platform}-${urlIndex}`);
    
    if (!resultDiv) return;
    
    const formattedPrice = data.price || '가격 정보 없음';
    const ratingDisplay = data.rating_display || '평점 정보 없음';
    
    resultDiv.innerHTML = `
        <div class="product-preview">
            <img src="${data.image_url}" alt="${data.title}" onerror="this.style.display='none'">
            <div class="product-info">
                <h4>${data.title}</h4>
                <p><strong>가격:</strong> ${formattedPrice}</p>
                <p><strong>평점:</strong> ${ratingDisplay}</p>
                <p><strong>판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}</p>
            </div>
        </div>
    `;
}

// 편집된 큐 저장
async function saveEditedQueue() {
    try {
        // 폼 데이터 수집
        const updatedData = {
            title: document.getElementById('editTitle').value.trim(),
            category_id: parseInt(document.getElementById('editCategory').value),
            prompt_type: document.getElementById('editPromptType').value,
            keywords: collectEditedKeywords(),
            user_details: collectEditedUserDetails()
        };
        
        // 유효성 검사
        if (!updatedData.title || updatedData.title.length < 5) {
            alert('제목은 5자 이상이어야 합니다.');
            return;
        }
        
        if (!updatedData.keywords || updatedData.keywords.length === 0) {
            alert('최소 하나의 키워드가 필요합니다.');
            return;
        }
        
        showLoading();
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `action=update_queue_item&queue_id=${encodeURIComponent(currentEditingQueueId)}&data=${encodeURIComponent(JSON.stringify(updatedData))}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('항목이 성공적으로 업데이트되었습니다.');
            closeEditModal();
            loadQueue(); // 저장 후 다시 로드
        } else {
            alert('업데이트에 실패했습니다: ' + result.message);
        }
    } catch (error) {
        console.error('저장 오류:', error);
        alert('저장 중 오류가 발생했습니다.');
    } finally {
        hideLoading();
    }
}

// 편집된 키워드 데이터 수집
function collectEditedKeywords() {
    const keywords = [];
    const keywordItems = document.querySelectorAll('.keyword-item');
    
    keywordItems.forEach(item => {
        const nameInput = item.querySelector('.keyword-item-title');
        const name = nameInput.value.trim();
        
        if (name) {
            const aliexpressUrls = [];
            const coupangUrls = [];
            
            // 알리익스프레스 URL 수집
            const aliexpressInputs = item.querySelectorAll('.aliexpress-products .product-url-input');
            aliexpressInputs.forEach(input => {
                const url = input.value.trim();
                if (url) aliexpressUrls.push(url);
            });
            
            keywords.push({
                name: name,
                aliexpress: aliexpressUrls,
                coupang: coupangUrls
            });
        }
    });
    
    return keywords;
}

// 편집된 사용자 상세 정보 수집
function collectEditedUserDetails() {
    const details = {};
    
    // 기능 및 스펙
    const specs = {};
    addIfNotEmpty(specs, 'main_function', 'editMainFunction');
    addIfNotEmpty(specs, 'size_capacity', 'editSizeCapacity');
    addIfNotEmpty(specs, 'color', 'editColor');
    addIfNotEmpty(specs, 'material', 'editMaterial');
    addIfNotEmpty(specs, 'power_battery', 'editPowerBattery');
    if (Object.keys(specs).length > 0) details.specs = specs;
    
    // 효율성 분석
    const efficiency = {};
    addIfNotEmpty(efficiency, 'problem_solving', 'editProblemSolving');
    addIfNotEmpty(efficiency, 'time_saving', 'editTimeSaving');
    addIfNotEmpty(efficiency, 'space_efficiency', 'editSpaceEfficiency');
    addIfNotEmpty(efficiency, 'cost_saving', 'editCostSaving');
    if (Object.keys(efficiency).length > 0) details.efficiency = efficiency;
    
    // 사용 시나리오
    const usage = {};
    addIfNotEmpty(usage, 'usage_location', 'editUsageLocation');
    addIfNotEmpty(usage, 'usage_frequency', 'editUsageFrequency');
    addIfNotEmpty(usage, 'target_users', 'editTargetUsers');
    addIfNotEmpty(usage, 'usage_method', 'editUsageMethod');
    if (Object.keys(usage).length > 0) details.usage = usage;
    
    // 장점 및 주의사항
    const benefits = {};
    const advantages = [];
    ['editAdvantage1', 'editAdvantage2', 'editAdvantage3'].forEach(id => {
        const value = document.getElementById(id)?.value.trim();
        if (value) advantages.push(value);
    });
    if (advantages.length > 0) benefits.advantages = advantages;
    addIfNotEmpty(benefits, 'precautions', 'editPrecautions');
    if (Object.keys(benefits).length > 0) details.benefits = benefits;
    
    return details;
}

// 값이 있으면 객체에 추가하는 유틸리티 함수
function addIfNotEmpty(obj, key, elementId) {
    const value = document.getElementById(elementId)?.value.trim();
    if (value) obj[key] = value;
}

// 편집 모달 닫기
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    currentEditingQueueId = null;
    currentEditingData = null;
}

// 큐 새로고침
function refreshQueue() {
    loadQueue();
}

// 로딩 표시/숨김
function showLoading() {
    document.getElementById('loadingOverlay').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loadingOverlay').style.display = 'none';
}

// 모달 외부 클릭 시 닫기
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});

// ESC 키로 모달 닫기
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('editModal').style.display === 'flex') {
        closeEditModal();
    }
});
</script>
</body>
</html>