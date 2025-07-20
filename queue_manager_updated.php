<?php
/**
 * 저장된 정보 관리 페이지 - 썸네일 URL 기능 추가 버전
 * 버전: v2.5 (썸네일 URL 지원)
 */
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');
if (!current_user_can('manage_options')) { wp_die('접근 권한이 없습니다.'); }

define('QUEUE_FILE', '/var/www/novacents/tools/product_queue.json');

function load_queue() {
    if (!file_exists(QUEUE_FILE)) return [];
    $content = file_get_contents(QUEUE_FILE);
    if ($content === false) return [];
    $queue = json_decode($content, true);
    return is_array($queue) ? $queue : [];
}

function save_queue($queue) {
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
                            'url' => $product_data['url'] ?? '',
                            'thumbnail_url' => $product_data['thumbnail_url'] ?? ''  // 썸네일 URL 추가
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
    
    switch ($action) {
        case 'get_queue_list':
            echo json_encode(['success' => true, 'queue' => load_queue()]);
            exit;
            
        case 'delete_queue_item':
            $queue_id = $_POST['queue_id'] ?? '';
            $queue = load_queue();
            $found = false;
            foreach ($queue as $index => $item) {
                if ($item['queue_id'] === $queue_id) {
                    unset($queue[$index]);
                    $queue = array_values($queue);
                    $found = true;
                    break;
                }
            }
            echo json_encode($found && save_queue($queue) ? 
                ['success' => true, 'message' => '항목이 삭제되었습니다.'] : 
                ['success' => false, 'message' => '삭제에 실패했습니다.']
            );
            exit;
            
        case 'get_queue_item':
            $queue_id = $_POST['queue_id'] ?? '';
            $queue = load_queue();
            
            foreach ($queue as $item) {
                if ($item['queue_id'] === $queue_id) {
                    echo json_encode(['success' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
            
            echo json_encode(['success' => false, 'message' => '항목을 찾을 수 없습니다.']);
            exit;
            
        case 'update_queue_item':
            $queue_id = $_POST['queue_id'] ?? '';
            $updated_data = json_decode($_POST['data'] ?? '{}', true);
            $queue = load_queue();
            $found = false;
            
            foreach ($queue as $index => $item) {
                if ($item['queue_id'] === $queue_id) {
                    // 기본 정보 업데이트
                    $queue[$index]['title'] = $updated_data['title'] ?? $item['title'];
                    $queue[$index]['category_id'] = $updated_data['category_id'] ?? $item['category_id'];
                    $queue[$index]['category_name'] = get_category_name($updated_data['category_id'] ?? $item['category_id']);
                    $queue[$index]['prompt_type'] = $updated_data['prompt_type'] ?? $item['prompt_type'];
                    $queue[$index]['prompt_type_name'] = get_prompt_type_name($updated_data['prompt_type'] ?? $item['prompt_type']);
                    
                    // 키워드 데이터 완전 교체 (전체 구조 보존)
                    if (isset($updated_data['keywords']) && is_array($updated_data['keywords'])) {
                        $queue[$index]['keywords'] = $updated_data['keywords'];
                    }
                    
                    // 사용자 세부사항 업데이트
                    $queue[$index]['user_details'] = $updated_data['user_details'] ?? $item['user_details'] ?? [];
                    $queue[$index]['has_user_details'] = !empty($updated_data['user_details']);
                    
                    // 상품 데이터 존재 여부 확인
                    $has_product_data = false;
                    if (isset($queue[$index]['keywords']) && is_array($queue[$index]['keywords'])) {
                        foreach ($queue[$index]['keywords'] as $keyword) {
                            if (isset($keyword['products_data']) && is_array($keyword['products_data']) && count($keyword['products_data']) > 0) {
                                $has_product_data = true;
                                break;
                            }
                        }
                    }
                    $queue[$index]['has_product_data'] = $has_product_data;
                    
                    $queue[$index]['updated_at'] = date('Y-m-d H:i:s');
                    $found = true;
                    break;
                }
            }
            
            echo json_encode($found && save_queue($queue) ? 
                ['success' => true, 'message' => '항목이 업데이트되었습니다.'] : 
                ['success' => false, 'message' => '업데이트에 실패했습니다.']
            );
            exit;
            
        case 'reorder_queue':
            $new_order = json_decode($_POST['order'] ?? '[]', true);
            if (empty($new_order)) {
                echo json_encode(['success' => false, 'message' => '순서 데이터가 없습니다.']);
                exit;
            }
            
            $queue = load_queue();
            $reordered_queue = [];
            foreach ($new_order as $queue_id) {
                foreach ($queue as $item) {
                    if ($item['queue_id'] === $queue_id) {
                        $reordered_queue[] = $item;
                        break;
                    }
                }
            }
            echo json_encode(count($reordered_queue) === count($queue) && save_queue($reordered_queue) ? 
                ['success' => true, 'message' => '순서가 변경되었습니다.'] : 
                ['success' => false, 'message' => '순서 변경에 실패했습니다.']
            );
            exit;
            
        case 'immediate_publish':
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
            
            $publish_data = [
                'title' => $selected_item['title'],
                'category' => $selected_item['category_id'],
                'prompt_type' => $selected_item['prompt_type'],
                'keywords' => json_encode($selected_item['keywords']),
                'user_details' => json_encode($selected_item['user_details']),
                'publish_mode' => 'immediate'
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'keyword_processor.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($publish_data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code === 200 && $response) {
                $result = json_decode($response, true);
                echo $result && isset($result['success']) ? $response : json_encode(['success' => false, 'message' => '발행 처리 중 오류가 발생했습니다.']);
            } else {
                echo json_encode(['success' => false, 'message' => '발행 요청 전송에 실패했습니다.']);
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
<style>
/* 썸네일 URL 관련 추가 스타일 */
.thumbnail-url-field {
    margin-bottom: 15px;
}

.thumbnail-url-field label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
    font-size: 14px;
}

.thumbnail-url-field input[type="url"] {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    box-sizing: border-box;
}

.thumbnail-preview {
    display: none;
    margin-top: 10px;
    max-width: 200px;
    max-height: 200px;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.thumbnail-preview.show {
    display: block;
}

.product-detail-field.thumbnail-field {
    grid-column: 1 / -1; /* 전체 너비 사용 */
}
</style>
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
        <p class="subtitle">큐에 저장된 항목들을 관리하고 즉시 발행할 수 있습니다 (v2.5 - 썸네일 URL 지원)</p>
        <div class="header-actions">
            <a href="affiliate_editor_optimized.php" class="btn btn-primary">📝 새 글 작성</a>
            <button type="button" class="btn btn-secondary" onclick="refreshQueue()">🔄 새로고침</button>
        </div>
    </div>

    <div class="main-content">
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

        <div class="queue-list" id="queueList">
            <div class="empty-state">
                <h3>📦 저장된 정보가 없습니다</h3>
                <p>아직 저장된 큐 항목이 없습니다.</p>
                <a href="affiliate_editor_optimized.php" class="btn btn-primary">첫 번째 글 작성하기</a>
            </div>
        </div>
    </div>
</div>

<script>
// 썸네일 URL 미리보기 업데이트 함수 추가
function updateThumbnailPreview(keywordIndex, platform, productIndex) {
    const thumbnailInput = document.getElementById(`pd-thumbnail-url-${keywordIndex}-${platform}-${productIndex}`);
    const thumbnailPreview = document.getElementById(`pd-thumbnail-preview-${keywordIndex}-${platform}-${productIndex}`);
    
    if (thumbnailInput && thumbnailPreview) {
        const url = thumbnailInput.value.trim();
        if (url && (url.startsWith('http://') || url.startsWith('https://'))) {
            thumbnailPreview.src = url;
            thumbnailPreview.classList.add('show');
        } else {
            thumbnailPreview.src = '';
            thumbnailPreview.classList.remove('show');
        }
    }
}

// 기존 generateProductDetailsForm 함수를 오버라이드하여 썸네일 URL 필드 추가
function generateProductDetailsFormWithThumbnail(keywordIndex, platform, productIndex, existingDetails) {
    const specs = existingDetails?.specs || {};
    const efficiency = existingDetails?.efficiency || {};
    const usage = existingDetails?.usage || {};
    const benefits = existingDetails?.benefits || {};
    const advantages = benefits.advantages || [];
    const thumbnailUrl = existingDetails?.thumbnailUrl || '';
    
    return `
        <div class="product-detail-field thumbnail-field">
            <label>썸네일 이미지 URL</label>
            <input type="url" id="pd-thumbnail-url-${keywordIndex}-${platform}-${productIndex}" placeholder="예: https://example.com/image.jpg" value="${thumbnailUrl}" oninput="updateThumbnailPreview(${keywordIndex}, '${platform}', ${productIndex})">
            <img id="pd-thumbnail-preview-${keywordIndex}-${platform}-${productIndex}" class="thumbnail-preview" alt="썸네일 미리보기" style="display: none;">
        </div>
        <div class="product-detail-field"><label>주요 기능</label><input type="text" id="pd-main-function-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 자동 압축, 물 절약" value="${specs.main_function || ''}"></div>
        <div class="product-detail-field"><label>크기/용량</label><input type="text" id="pd-size-capacity-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 30cm × 20cm" value="${specs.size_capacity || ''}"></div>
        <div class="product-detail-field"><label>색상</label><input type="text" id="pd-color-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 화이트, 블랙" value="${specs.color || ''}"></div>
        <div class="product-detail-field"><label>재질/소재</label><input type="text" id="pd-material-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 스테인리스 스틸" value="${specs.material || ''}"></div>
        <div class="product-detail-field"><label>전원/배터리</label><input type="text" id="pd-power-battery-${keywordIndex}-${platform}-${productIndex}" placeholder="예: USB 충전" value="${specs.power_battery || ''}"></div>
        <div class="product-detail-field"><label>해결하는 문제</label><input type="text" id="pd-problem-solving-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 설거지 시간 오래 걸림" value="${efficiency.problem_solving || ''}"></div>
        <div class="product-detail-field"><label>시간 절약 효과</label><input type="text" id="pd-time-saving-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 10분 → 3분" value="${efficiency.time_saving || ''}"></div>
        <div class="product-detail-field"><label>공간 활용</label><input type="text" id="pd-space-efficiency-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 50% 공간 절약" value="${efficiency.space_efficiency || ''}"></div>
        <div class="product-detail-field"><label>비용 절감</label><input type="text" id="pd-cost-saving-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 월 전기료 30% 절약" value="${efficiency.cost_saving || ''}"></div>
        <div class="product-detail-field"><label>주요 사용 장소</label><input type="text" id="pd-usage-location-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 주방, 욕실" value="${usage.usage_location || ''}"></div>
        <div class="product-detail-field"><label>사용 빈도</label><input type="text" id="pd-usage-frequency-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 매일" value="${usage.usage_frequency || ''}"></div>
        <div class="product-detail-field"><label>적합한 사용자</label><input type="text" id="pd-target-users-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 1인 가구" value="${usage.target_users || ''}"></div>
        <div class="product-detail-field"><label>핵심 장점 1</label><input type="text" id="pd-advantage1-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 설치 간편함" value="${advantages[0] || ''}"></div>
        <div class="product-detail-field"><label>핵심 장점 2</label><input type="text" id="pd-advantage2-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 유지비 저렴함" value="${advantages[1] || ''}"></div>
        <div class="product-detail-field"><label>핵심 장점 3</label><input type="text" id="pd-advantage3-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 내구성 뛰어남" value="${advantages[2] || ''}"></div>
        <div class="product-detail-field"><label>주의사항</label><input type="text" id="pd-precautions-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 물기 주의" value="${benefits.precautions || ''}"></div>`;
}
</script>
<script src="assets/queue_manager.js?v=<?php echo time(); ?>"></script>
</body>
</html>