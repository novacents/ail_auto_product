<?php
/**
 * 저장된 정보 관리 페이지 - 큐 관리 시스템
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
            $queue[$index]['prompt_type'] = $updated_data['prompt_type'] ?? $item['prompt_type'];
            $queue[$index]['keywords'] = $updated_data['keywords'] ?? $item['keywords'];
            $queue[$index]['user_details'] = $updated_data['user_details'] ?? $item['user_details'];
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

// 큐 편집 (향후 구현 예정)
function editQueue(queueId) {
    alert('편집 기능은 향후 구현 예정입니다.\n현재는 affiliate_editor.php에서 새로 작성해주세요.');
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
</script>
</body>
</html>