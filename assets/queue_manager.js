/**
 * 큐 관리자 JavaScript - 2행 테이블 레이아웃 버전
 * 어필리에이트 상품 자동 발행 시스템
 */

// 전역 변수
let allQueues = [];
let filteredQueues = [];
let currentFilter = 'all';
let currentSort = 'created_at';

// DOM이 준비되면 초기화
$(document).ready(function() {
    console.log('큐 관리자 JavaScript 초기화 시작');
    
    // 초기 데이터 로드
    loadQueues();
    
    // 이벤트 리스너 등록
    setupEventListeners();
    
    // 주기적 업데이트 (1분마다)
    setInterval(loadQueues, 60000);
    
    console.log('큐 관리자 JavaScript 초기화 완료');
});

/**
 * 이벤트 리스너 설정
 */
function setupEventListeners() {
    // 정렬 변경
    $('#sortBy').change(function() {
        currentSort = $(this).val();
        sortQueues();
        displayFilteredQueues();
    });
    
    $('#sortOrder').change(function() {
        sortQueues();
        displayFilteredQueues();
    });
    
    // 모달 외부 클릭 시 닫기
    $(window).click(function(event) {
        if (event.target.id === 'editModal') {
            closeEditModal();
        }
    });
}

/**
 * 큐 데이터 로드
 */
function loadQueues() {
    console.log('큐 데이터 로드 시작');
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'get_queue_list'
        },
        dataType: 'json',
        success: function(response) {
            console.log('큐 데이터 로드 성공:', response);
            
            if (response.success) {
                allQueues = response.queue || [];
                filterQueues();
                updateStatistics();
            } else {
                console.error('큐 데이터 로드 실패:', response.message);
                showNotification('큐 데이터를 불러오는데 실패했습니다.', 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('큐 데이터 로드 AJAX 오류:', error);
            showNotification('서버 연결에 실패했습니다.', 'error');
        }
    });
}

/**
 * 큐 필터링
 */
function filterQueues() {
    // 현재는 모든 큐 표시 (필터 기능 추후 구현)
    filteredQueues = [...allQueues];
    sortQueues();
    displayFilteredQueues();
}

/**
 * 큐 정렬
 */
function sortQueues() {
    const sortOrder = $('#sortOrder').val() || 'desc';
    
    filteredQueues.sort((a, b) => {
        let result = 0;
        
        switch (currentSort) {
            case 'created_at':
                result = new Date(b.created_at || 0) - new Date(a.created_at || 0);
                break;
            case 'title':
                result = (a.title || '').localeCompare(b.title || '');
                break;
            case 'status':
                const statusOrder = { pending: 1, processing: 2, completed: 3, failed: 4 };
                result = (statusOrder[a.status] || 5) - (statusOrder[b.status] || 5);
                break;
            case 'priority':
                result = (a.priority || 0) - (b.priority || 0);
                break;
            default:
                result = new Date(b.created_at || 0) - new Date(a.created_at || 0);
        }
        
        return sortOrder === 'asc' ? result : -result;
    });
}

/**
 * 필터링된 큐 목록 표시 (2행 테이블 구조)
 */
function displayFilteredQueues() {
    const container = $('#queueTableBody');
    const emptyState = $('#emptyState');
    const queueTable = $('#queueTable');
    
    if (filteredQueues.length === 0) {
        // 빈 상태 표시
        queueTable.hide();
        emptyState.show();
        return;
    }
    
    // 테이블 표시 및 빈 상태 숨김
    queueTable.show();
    emptyState.hide();
    
    // 2행 테이블 HTML 생성
    let html = '';
    filteredQueues.forEach(item => {
        const thumbnailHtml = item.thumbnail_url ? 
            `<img src="${item.thumbnail_url}" class="thumbnail-preview" alt="썸네일" onerror="this.parentElement.innerHTML='<div class=\\"no-thumbnail\\">📷</div>'">` : 
            '<div class="no-thumbnail">📷</div>';
            
        const statusClass = `status-${item.status || 'pending'}`;
        const statusText = getStatusText(item.status);
        const categoryText = getCategoryText(item.category_id);
        const promptText = getPromptTypeText(item.prompt_type);
        const keywordCount = item.keywords ? item.keywords.length : 0;
        const productCount = calculateProductCount(item.keywords);
        const keywordsList = getKeywordsList(item.keywords);
        
        // 첫 번째 행 (메인 정보)
        html += `
            <tr class="queue-row-main ${statusClass}">
                <td rowspan="2" class="thumbnail-cell">${thumbnailHtml}</td>
                <td><span class="status-badge ${item.status || 'pending'}">${statusText}</span></td>
                <td>${categoryText}</td>
                <td>${promptText}</td>
                <td>${keywordCount}개</td>
                <td>${productCount}개</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-btn edit" onclick="editQueue('${item.id || item.queue_id}')">편집</button>
                        <button class="action-btn publish" onclick="immediatePublish('${item.id || item.queue_id}')">즉시 발행</button>
                        <button class="action-btn delete" onclick="deleteQueue('${item.id || item.queue_id}')">삭제</button>
                        <button class="action-btn move" onclick="moveQueueStatus('${item.id || item.queue_id}', event)">이동</button>
                    </div>
                </td>
            </tr>
        `;
        
        // 두 번째 행 (키워드 목록)
        html += `
            <tr class="queue-row-keywords ${statusClass}">
                <td colspan="6" class="keywords-list">${keywordsList}</td>
            </tr>
        `;
    });
    
    container.html(html);
    
    console.log(`${filteredQueues.length}개의 큐 표시 완료 (2행 테이블)`);
}

/**
 * Move 버튼 클릭 처리
 */
async function moveQueueStatus(queueId, event) {
    // 중복 클릭 방지
    const button = event.target;
    if (button.disabled) return;
    
    // 버튼 비활성화
    button.disabled = true;
    button.textContent = '처리중...';
    
    try {
        console.log('Move 버튼 클릭:', queueId);
        
        // 서버에 상태 변경 요청
        const response = await $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'move_queue_status',
                queue_id: queueId
            },
            dataType: 'json',
            timeout: 15000
        });
        
        console.log('Move 응답:', response);
        
        if (response.success) {
            showNotification(response.message || '큐 상태가 변경되었습니다.', 'success');
            
            // 큐 데이터 새로고침
            loadQueues();
            
            // 버튼 텍스트 복원
            setTimeout(() => {
                button.disabled = false;
                button.textContent = '이동';
            }, 1000);
            
        } else {
            console.error('Move 실패:', response.message);
            showNotification(response.message || '큐 상태 변경에 실패했습니다.', 'error');
            
            // 버튼 복원
            button.disabled = false;
            button.textContent = '이동';
        }
        
    } catch (error) {
        console.error('Move 오류:', error);
        
        let errorMessage = '큐 상태 변경 중 오류가 발생했습니다.';
        if (error.statusText === 'timeout') {
            errorMessage = '요청 시간이 초과되었습니다. 다시 시도해주세요.';
        } else if (error.status === 0) {
            errorMessage = '서버에 연결할 수 없습니다.';
        }
        
        showNotification(errorMessage, 'error');
        
        // 버튼 복원
        button.disabled = false;
        button.textContent = '이동';
    }
}

/**
 * 상태 텍스트 변환
 */
function getStatusText(status) {
    const statusMap = {
        'pending': '🟡 대기중',
        'processing': '🔵 처리중',
        'completed': '🟢 완료',
        'failed': '🔴 실패'
    };
    return statusMap[status] || '🟡 대기중';
}

/**
 * 카테고리 텍스트 변환
 */
function getCategoryText(categoryId) {
    const categoryMap = {
        '354': "Today's Pick",
        '355': '기발한 잡화점',
        '356': '스마트 리빙',
        '12': '우리잇템'
    };
    return categoryMap[categoryId] || '알 수 없는 카테고리';
}

/**
 * 프롬프트 타입 텍스트 변환
 */
function getPromptTypeText(promptType) {
    const promptMap = {
        'essential_items': '필수템형 🎯',
        'friend_review': '친구 추천형 👫',
        'professional_analysis': '전문 분석형 📊',
        'amazing_discovery': '놀라움 발견형 ✨'
    };
    return promptMap[promptType] || '기본형';
}

/**
 * 상품 수 계산
 */
function calculateProductCount(keywords) {
    if (!keywords || !Array.isArray(keywords)) return 0;
    
    let total = 0;
    keywords.forEach(keyword => {
        if (keyword.products_data && Array.isArray(keyword.products_data)) {
            total += keyword.products_data.length;
        }
        if (keyword.aliexpress && Array.isArray(keyword.aliexpress)) {
            total += keyword.aliexpress.length;
        }
        if (keyword.coupang && Array.isArray(keyword.coupang)) {
            total += keyword.coupang.length;
        }
    });
    return total;
}

/**
 * 키워드 목록 문자열 생성
 */
function getKeywordsList(keywords) {
    if (!keywords || !Array.isArray(keywords) || keywords.length === 0) {
        return '키워드 없음';
    }
    
    const keywordNames = keywords.map(keyword => keyword.name || keyword).filter(name => name);
    return keywordNames.length > 0 ? keywordNames.join(', ') : '키워드 없음';
}

/**
 * 통계 업데이트
 */
function updateStatistics() {
    const stats = {
        total: 0,
        pending: 0,
        processing: 0,
        completed: 0,
        failed: 0
    };
    
    allQueues.forEach(queue => {
        stats.total++;
        const status = queue.status || 'pending';
        if (stats.hasOwnProperty(status)) {
            stats[status]++;
        }
    });
    
    // 통계 카드 업데이트
    $('#totalCount').text(stats.total);
    $('#pendingCount').text(stats.pending);
    $('#processingCount').text(stats.processing);
    $('#completedCount').text(stats.completed);
    
    console.log('통계 업데이트:', stats);
}

/**
 * 큐 새로고침
 */
function refreshQueue() {
    console.log('수동 새로고침 요청');
    showNotification('큐 목록을 새로고침합니다...', 'info');
    loadQueues();
}

/**
 * 큐 삭제
 */
function deleteQueue(queueId) {
    if (!confirm('정말로 이 큐를 삭제하시겠습니까?')) {
        return;
    }
    
    console.log('큐 삭제 요청:', queueId);
    showLoading(true);
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'delete_queue_item',
            queue_id: queueId
        },
        dataType: 'json',
        success: function(response) {
            showLoading(false);
            
            if (response.success) {
                showNotification('큐가 성공적으로 삭제되었습니다.', 'success');
                loadQueues();
            } else {
                showNotification(response.message || '큐 삭제에 실패했습니다.', 'error');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('큐 삭제 오류:', error);
            showNotification('큐 삭제 중 오류가 발생했습니다.', 'error');
        }
    });
}

/**
 * 큐 편집
 */
function editQueue(queueId) {
    console.log('큐 편집 요청:', queueId);
    showLoading(true);
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'get_queue_item',
            queue_id: queueId
        },
        dataType: 'json',
        success: function(response) {
            showLoading(false);
            
            if (response.success) {
                populateEditModal(response.item);
                $('#editModal').show();
            } else {
                showNotification(response.message || '큐 데이터를 불러오는데 실패했습니다.', 'error');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('큐 편집 오류:', error);
            showNotification('큐 데이터를 불러오는데 실패했습니다.', 'error');
        }
    });
}

/**
 * 편집 모달 채움
 */
function populateEditModal(queueData) {
    $('#editTitle').val(queueData.title || '');
    $('#editCategory').val(queueData.category_id || '356');
    $('#editPromptType').val(queueData.prompt_type || 'essential_items');
    $('#editThumbnailUrl').val(queueData.thumbnail_url || '');
    
    // 키워드 목록 표시
    const keywordsList = $('#keywordList');
    keywordsList.empty();
    
    if (queueData.keywords && queueData.keywords.length > 0) {
        queueData.keywords.forEach(keyword => {
            keywordsList.append(`
                <div class="keyword-item">
                    ${keyword.name || '키워드'}
                </div>
            `);
        });
    } else {
        keywordsList.append('<p style="color: #6b7280;">키워드가 없습니다.</p>');
    }
    
    // 큐 ID 저장 (숨겨진 필드)
    window.currentEditQueueId = queueData.id || queueData.queue_id;
}

/**
 * 큐 업데이트
 */
function saveEditedQueue() {
    const formData = {
        action: 'update_queue_item',
        queue_id: window.currentEditQueueId,
        data: JSON.stringify({
            title: $('#editTitle').val(),
            category_id: $('#editCategory').val(),
            prompt_type: $('#editPromptType').val(),
            thumbnail_url: $('#editThumbnailUrl').val()
        })
    };
    
    console.log('큐 업데이트 요청:', formData);
    showLoading(true);
    
    $.ajax({
        url: '',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            showLoading(false);
            
            if (response.success) {
                showNotification('큐가 성공적으로 업데이트되었습니다.', 'success');
                closeEditModal();
                loadQueues();
            } else {
                showNotification(response.message || '큐 업데이트에 실패했습니다.', 'error');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('큐 업데이트 오류:', error);
            showNotification('큐 업데이트 중 오류가 발생했습니다.', 'error');
        }
    });
}

/**
 * 편집 모달 닫기
 */
function closeEditModal() {
    $('#editModal').hide();
    window.currentEditQueueId = null;
}

/**
 * 즉시 발행
 */
function immediatePublish(queueId) {
    if (!confirm('이 큐를 즉시 발행하시겠습니까?')) {
        return;
    }
    
    console.log('즉시 발행 요청:', queueId);
    showLoading(true);
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'immediate_publish',
            queue_id: queueId
        },
        dataType: 'json',
        timeout: 60000, // 60초 타임아웃
        success: function(response) {
            showLoading(false);
            
            if (response.success) {
                let message = '즉시 발행이 완료되었습니다.';
                if (response.post_url) {
                    message += `\n발행된 글: ${response.post_url}`;
                }
                showNotification(message, 'success');
                loadQueues();
            } else {
                showNotification(response.message || '즉시 발행에 실패했습니다.', 'error');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('즉시 발행 오류:', error);
            
            let errorMessage = '즉시 발행 중 오류가 발생했습니다.';
            if (status === 'timeout') {
                errorMessage = '발행 처리 시간이 초과되었습니다. 잠시 후 큐 목록을 확인해주세요.';
            }
            
            showNotification(errorMessage, 'error');
        }
    });
}

/**
 * 로딩 상태 표시/숨김
 */
function showLoading(show) {
    if (show) {
        $('#loadingOverlay').show();
    } else {
        $('#loadingOverlay').hide();
    }
}

/**
 * 알림 메시지 표시
 */
function showNotification(message, type = 'info') {
    // 간단한 알림 표시 (실제 구현에서는 더 정교한 알림 시스템 사용)
    console.log(`알림 (${type}): ${message}`);
    
    // 브라우저 기본 알림 사용
    if (type === 'error') {
        alert('오류: ' + message);
    } else if (type === 'success') {
        alert('성공: ' + message);
    }
}

/**
 * 정렬 함수
 */
function sortQueue() {
    currentSort = $('#sortBy').val();
    sortQueues();
    displayFilteredQueues();
}

/**
 * 드래그 정렬 토글
 */
function toggleDragSort() {
    console.log('드래그 정렬 기능은 추후 구현됩니다.');
    showNotification('드래그 정렬 기능은 추후 구현됩니다.', 'info');
}

/**
 * 전역 함수들 (HTML에서 직접 호출)
 */
window.refreshQueue = refreshQueue;
window.deleteQueue = deleteQueue;
window.editQueue = editQueue;
window.immediatePublish = immediatePublish;
window.closeEditModal = closeEditModal;
window.moveQueueStatus = moveQueueStatus;
window.saveEditedQueue = saveEditedQueue;
window.sortQueue = sortQueue;
window.toggleDragSort = toggleDragSort;

// 개발자 도구용 디버그 함수들
window.debugQueue = {
    getAllQueues: () => allQueues,
    getFilteredQueues: () => filteredQueues,
    getCurrentFilter: () => currentFilter,
    getCurrentSort: () => currentSort,
    reloadQueues: loadQueues
};

console.log('큐 관리자 JavaScript 로드 완료 (2행 테이블 버전)');