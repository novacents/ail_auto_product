/**
 * 큐 관리자 JavaScript
 * 어필리에이트 상품 자동 발행 시스템
 */

// 전역 변수
let allQueues = [];
let filteredQueues = [];
let currentFilter = 'all';
let currentSort = 'newest';

// DOM이 준비되면 초기화
$(document).ready(function() {
    console.log('큐 관리자 JavaScript 초기화 시작');
    
    // 초기 데이터 로드
    loadQueues();
    
    // 이벤트 리스너 등록
    setupEventListeners();
    
    // 주기적 업데이트 (30초마다)
    setInterval(loadQueues, 30000);
    
    console.log('큐 관리자 JavaScript 초기화 완료');
});

/**
 * 이벤트 리스너 설정
 */
function setupEventListeners() {
    // 필터 버튼 클릭
    $('.filter-btn').click(function() {
        const status = $(this).data('status');
        setFilter(status);
    });
    
    // 검색 입력
    $('#searchInput').on('input', debounce(filterQueues, 300));
    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) { // Enter 키
            filterQueues();
        }
    });
    
    // 정렬 변경
    $('#sortSelect').change(function() {
        currentSort = $(this).val();
        sortQueues();
        displayFilteredQueues();
    });
    
    // 편집 폼 제출
    $('#editForm').submit(function(e) {
        e.preventDefault();
        updateQueue();
    });
    
    // 모달 외부 클릭 시 닫기
    $(window).click(function(event) {
        if (event.target.id === 'editModal') {
            closeEditModal();
        }
    });
}

/**
 * 디바운스 함수
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
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
            action: 'get_queues'
        },
        dataType: 'json',
        success: function(response) {
            console.log('큐 데이터 로드 성공:', response);
            
            if (response.success) {
                allQueues = response.data || [];
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
    const searchTerm = $('#searchInput').val().toLowerCase();
    
    filteredQueues = allQueues.filter(queue => {
        // 상태 필터
        if (currentFilter !== 'all' && queue.status !== currentFilter) {
            return false;
        }
        
        // 검색어 필터
        if (searchTerm) {
            const title = (queue.title || '').toLowerCase();
            const keywordText = queue.keywords ? 
                queue.keywords.map(k => k.name || '').join(' ').toLowerCase() : '';
            
            if (!title.includes(searchTerm) && !keywordText.includes(searchTerm)) {
                return false;
            }
        }
        
        return true;
    });
    
    sortQueues();
    displayFilteredQueues();
}

/**
 * 필터 설정
 */
function setFilter(status) {
    currentFilter = status;
    
    // 필터 버튼 활성화 상태 변경
    $('.filter-btn').removeClass('active');
    $(`.filter-btn[data-status="${status}"]`).addClass('active');
    
    filterQueues();
    
    console.log('필터 변경:', status);
}

/**
 * 큐 정렬
 */
function sortQueues() {
    filteredQueues.sort((a, b) => {
        switch (currentSort) {
            case 'newest':
                return new Date(b.created_at || 0) - new Date(a.created_at || 0);
            case 'oldest':
                return new Date(a.created_at || 0) - new Date(b.created_at || 0);
            case 'title':
                return (a.title || '').localeCompare(b.title || '');
            case 'status':
                const statusOrder = { pending: 1, processing: 2, completed: 3, failed: 4 };
                return (statusOrder[a.status] || 5) - (statusOrder[b.status] || 5);
            default:
                return 0;
        }
    });
}

/**
 * Move 버튼 클릭 처리 (보안 강화)
 */
async function moveQueueStatus(queueId, event) {
    // 1. 중복 클릭 방지
    const button = event.target;
    if (button.disabled) return;
    
    // 버튼 비활성화
    button.disabled = true;
    button.textContent = '처리중...';
    
    try {
        console.log('Move 버튼 클릭:', queueId);
        
        // 2. 서버에 상태 변경 요청
        const response = await $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'move_queue_status',
                queue_id: queueId
            },
            dataType: 'json',
            timeout: 15000 // 15초 타임아웃
        });
        
        console.log('Move 응답:', response);
        
        if (response.success) {
            // 3. 성공 시 UI 업데이트
            showNotification(response.message || '큐 상태가 변경되었습니다.', 'success');
            
            // 큐 데이터 새로고침
            loadQueues();
            
            // 4. 버튼 텍스트 복원 (새로운 상태에 맞게)
            setTimeout(() => {
                button.disabled = false;
                button.textContent = 'Move';
            }, 1000);
            
        } else {
            // 5. 실패 시 오류 메시지 표시
            console.error('Move 실패:', response.message);
            showNotification(response.message || '큐 상태 변경에 실패했습니다.', 'error');
            
            // 버튼 복원
            button.disabled = false;
            button.textContent = 'Move';
        }
        
    } catch (error) {
        // 6. 네트워크 오류 등 예외 처리
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
        button.textContent = 'Move';
    }
}

/**
 * 필터링된 큐 목록 표시
 */
function displayFilteredQueues() {
    const container = $('#queueTableBody');
    
    if (filteredQueues.length === 0) {
        // 빈 상태 표시
        container.html(`
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <h3 class="empty-title">해당하는 큐가 없습니다</h3>
                <p class="empty-message">필터를 변경하거나 새로운 큐를 추가해보세요.</p>
                <a href="affiliate_editor.php" class="btn btn-primary">
                    <span class="btn-icon">➕</span>
                    새 큐 추가하기
                </a>
            </div>
        `);
        return;
    }
    
    // 큐 목록 HTML 생성 (테이블 행 형태)
    let html = '';
    filteredQueues.forEach(item => {
        const thumbnailHtml = item.thumbnail_url ? 
            `<img src="${item.thumbnail_url}" alt="썸네일" onerror="this.style.display='none'">` : 
            '📷';
            
        const statusClass = `status-${item.status}`;
        const statusText = getStatusText(item.status);
        const categoryText = getCategoryText(item.category_id);
        const promptText = getPromptTypeText(item.prompt_type);
        const keywordCount = item.keywords ? item.keywords.length : 0;
        const productCount = item.keywords ? 
            item.keywords.reduce((total, keyword) => total + (keyword.products_data ? keyword.products_data.length : 0), 0) : 0;
        
        html += `
            <div class="queue-row ${statusClass}">
                <div class="queue-thumbnail">${thumbnailHtml}</div>
                <div class="queue-status">
                    <span class="status-badge ${item.status}">${statusText}</span>
                </div>
                <div class="queue-category">${categoryText}</div>
                <div class="queue-prompt">${promptText}</div>
                <div class="queue-keywords">${keywordCount}개</div>
                <div class="queue-products">${productCount}개</div>
                <div class="queue-actions">
                    <button class="action-btn edit" onclick="editQueue('${item.queue_id}')">Edit</button>
                    <button class="action-btn publish" onclick="immediatePublish('${item.queue_id}')">Immediate Publish</button>
                    <button class="action-btn move" onclick="moveQueueStatus('${item.queue_id}', event)">Move</button>
                    <button class="action-btn delete" onclick="deleteQueue('${item.queue_id}')">Delete</button>
                </div>
            </div>
        `;
    });
    
    container.html(html);
    
    console.log(`${filteredQueues.length}개의 큐 표시 완료`);
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
    return statusMap[status] || status;
}

/**
 * 카테고리 텍스트 변환
 */
function getCategoryText(categoryId) {
    const categoryMap = {
        '356': '스마트 리빙',
        '357': '패션 & 뷰티',
        '358': '전자기기',
        '359': '스포츠 & 레저',
        '360': '홈 & 가든'
    };
    return categoryMap[categoryId] || '기타';
}

/**
 * 프롬프트 타입 텍스트 변환
 */
function getPromptTypeText(promptType) {
    const promptMap = {
        'essential_items': '필수템형',
        'friend_review': '친구 추천형',
        'professional_analysis': '전문 분석형',
        'amazing_discovery': '놀라움 발견형'
    };
    return promptMap[promptType] || '기본형';
}

/**
 * 통계 업데이트
 */
function updateStatistics() {
    const stats = {
        pending: 0,
        processing: 0,
        completed: 0,
        failed: 0
    };
    
    allQueues.forEach(queue => {
        if (stats.hasOwnProperty(queue.status)) {
            stats[queue.status]++;
        }
    });
    
    // 통계 카드 업데이트
    $('#pendingCount').text(stats.pending);
    $('#processingCount').text(stats.processing);
    $('#completedCount').text(stats.completed);
    $('#failedCount').text(stats.failed);
    
    console.log('통계 업데이트:', stats);
}

/**
 * 큐 새로고침
 */
function refreshQueues() {
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
            action: 'delete_queue',
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
            action: 'edit_queue',
            queue_id: queueId
        },
        dataType: 'json',
        success: function(response) {
            showLoading(false);
            
            if (response.success) {
                populateEditModal(response.data);
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
    $('#editQueueId').val(queueData.queue_id);
    $('#editTitle').val(queueData.title || '');
    $('#editCategoryId').val(queueData.category_id || '356');
    $('#editPromptType').val(queueData.prompt_type || 'essential_items');
    $('#editThumbnailUrl').val(queueData.thumbnail_url || '');
    
    // 키워드 목록 표시
    const keywordsList = $('#editKeywordsList');
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
}

/**
 * 큐 업데이트
 */
function updateQueue() {
    const formData = {
        action: 'update_queue',
        queue_id: $('#editQueueId').val(),
        title: $('#editTitle').val(),
        category_id: $('#editCategoryId').val(),
        prompt_type: $('#editPromptType').val(),
        thumbnail_url: $('#editThumbnailUrl').val(),
        keywords: JSON.stringify([]) // 키워드는 현재 편집 불가
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
    $('#editForm')[0].reset();
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
    const notification = $('#notification');
    const iconMap = {
        'success': '✅',
        'error': '❌',
        'info': 'ℹ️',
        'warning': '⚠️'
    };
    
    notification
        .removeClass('success error info warning')
        .addClass(type);
    
    notification.find('.notification-icon').text(iconMap[type] || 'ℹ️');
    notification.find('.notification-message').text(message);
    
    notification.addClass('show');
    
    // 5초 후 자동 숨김
    setTimeout(() => {
        notification.removeClass('show');
    }, 5000);
    
    console.log(`알림 (${type}):`, message);
}

/**
 * 전역 함수들 (HTML에서 직접 호출)
 */
window.refreshQueues = refreshQueues;
window.deleteQueue = deleteQueue;
window.editQueue = editQueue;
window.immediatePublish = immediatePublish;
window.closeEditModal = closeEditModal;
window.moveQueueStatus = moveQueueStatus;
window.filterQueues = filterQueues;
window.sortQueues = function() {
    currentSort = $('#sortSelect').val();
    sortQueues();
    displayFilteredQueues();
};

// 개발자 도구용 디버그 함수들
window.debugQueue = {
    getAllQueues: () => allQueues,
    getFilteredQueues: () => filteredQueues,
    getCurrentFilter: () => currentFilter,
    getCurrentSort: () => currentSort,
    reloadQueues: loadQueues
};

console.log('큐 관리자 JavaScript 로드 완료');