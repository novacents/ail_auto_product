/**
 * 큐 관리 시스템 JavaScript - queue_manager_plan.md v2.0 완전 준수
 * 기존 시스템과 호환성 유지
 */

// 전역 변수
let currentStatus = 'pending'; // 계획서 91줄: 기본값 pending
let currentSearch = '';
let allQueues = [];
let selectedQueues = [];

// jQuery DOM 준비 완료 시 초기화
$(document).ready(function() {
    console.log('큐 관리 시스템 초기화 시작');
    
    // 초기 데이터 로드 (계획서 152줄: pending 기본 표시)
    loadQueues();
    loadStats();
    
    console.log('큐 관리 시스템 초기화 완료');
});

/**
 * 큐 목록 로드 (계획서 요구사항 + 기존 PHP 호환)
 */
function loadQueues() {
    console.log('큐 데이터 로드 시작 - 상태:', currentStatus);
    $('#loadingOverlay').show();
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'get_queues',
            status: currentStatus,
            search: currentSearch
        },
        dataType: 'json',
        success: function(response) {
            $('#loadingOverlay').hide();
            console.log('큐 데이터 로드 성공:', response);
            console.log('response.success:', response.success);
            console.log('response.queues:', response.queues);
            console.log('response.queues 타입:', typeof response.queues);
            console.log('response.queues 길이:', response.queues ? response.queues.length : 'null/undefined');
            
            if (response.success) {
                allQueues = response.queues || [];
                console.log('allQueues 할당 후:', allQueues);
                displayQueues();
                updateUI();
            } else {
                console.error('큐 로드 실패:', response.message);
                showEmptyState();
            }
        },
        error: function(xhr, status, error) {
            $('#loadingOverlay').hide();
            console.error('큐 로드 AJAX 오류:', error);
            showEmptyState();
        }
    });
}

/**
 * 통계 로드
 */
function loadStats() {
    $.ajax({
        url: '',
        method: 'POST',
        data: { action: 'get_stats' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                updateStats(response.stats);
            }
        },
        error: function(xhr, status, error) {
            console.error('통계 로드 오류:', error);
        }
    });
}

/**
 * 통계 업데이트
 */
function updateStats(stats) {
    $('#totalCount').text(stats.total || 0);
    $('#pendingCount').text(stats.pending || 0);
    $('#completedCount').text(stats.completed || 0);
}

/**
 * 큐 목록 표시 (계획서 66-78줄 레이아웃)
 */
function displayQueues() {
    console.log('displayQueues 함수 호출됨');
    console.log('allQueues:', allQueues);
    console.log('allQueues 길이:', allQueues ? allQueues.length : 'null/undefined');
    
    const container = $('#queueList');
    console.log('container 요소:', container.length);
    
    if (!allQueues || allQueues.length === 0) {
        console.log('큐가 없어서 빈 상태 표시');
        showEmptyState();
        return;
    }
    
    console.log(`${allQueues.length}개의 큐를 표시 시작`);
    let html = '';
    allQueues.forEach((queue, index) => {
        console.log(`큐 ${index + 1} 처리 중:`, queue);
        const queueId = queue.queue_id || queue.id;
        const title = queue.title || '제목 없음';
        const thumbnailUrl = queue.thumbnail_url || '';
        const categoryName = getCategoryName(queue.category_id);
        const promptTypeName = getPromptTypeName(queue.prompt_type);
        const keywordCount = getKeywordsCount(queue.keywords);
        const productCount = getProductsCount(queue.keywords);
        const keywordsList = getKeywordsList(queue.keywords);
        const status = queue.status || 'pending';
        
        // 썸네일 HTML (계획서 82줄: 2열에 걸쳐 표시 - 크기 증가)
        const thumbnailHtml = thumbnailUrl ? 
            `<img src="${thumbnailUrl}" class="thumbnail-preview" alt="썸네일" style="width:150px;height:150px;object-fit:cover;border:1px solid #ddd;">` :
            '<div class="no-thumbnail" style="width:150px;height:150px;display:flex;align-items:center;justify-content:center;border:1px solid #ddd;background:#f5f5f5;flex-direction:column;"><div style="font-size:24px;">📷</div><div style="font-size:12px;">썸네일</div></div>';
        
        // 버튼 HTML (계획서 84줄: completed는 즉시발행 비활성화)
        const buttonHtml = status === 'pending' ? `
            <button style="background: #28a745; color: white; padding: 6px 12px; border: none; border-radius: 4px; margin: 0 2px; cursor: pointer; font-size: 12px;" onclick="immediatePublish('${queueId}')">즉시 발행</button>
            <button style="background: #dc3545; color: white; padding: 6px 12px; border: none; border-radius: 4px; margin: 0 2px; cursor: pointer; font-size: 12px;" onclick="deleteQueue('${queueId}')">삭제</button>
            <button style="background: #6c757d; color: white; padding: 6px 12px; border: none; border-radius: 4px; margin: 0 2px; cursor: pointer; font-size: 12px;" onclick="changeQueueStatus('${queueId}', 'completed')">완료처리</button>
        ` : `
            <button style="background: #dc3545; color: white; padding: 6px 12px; border: none; border-radius: 4px; margin: 0 2px; cursor: pointer; font-size: 12px;" onclick="deleteQueue('${queueId}')">삭제</button>
            <button style="background: #6c757d; color: white; padding: 6px 12px; border: none; border-radius: 4px; margin: 0 2px; cursor: pointer; font-size: 12px;" onclick="changeQueueStatus('${queueId}', 'pending')">대기처리</button>
        `;
        
        // 계획서 66-78줄 정확한 레이아웃 구현 - 안정적인 Flexbox + 2행 구조
        html += `
            <div class="queue-item" data-queue-id="${queueId}" style="border: 1px solid #ddd; margin-bottom: 15px; padding: 15px; border-radius: 8px; background: #fafafa;">
                <!-- 버튼 영역 (계획서 68,75줄: 맨 위 오른쪽에 배치) -->
                <div class="queue-actions-row" style="text-align: right; margin-bottom: 15px;">
                    ${buttonHtml}
                </div>
                
                <!-- 한 줄 레이아웃: 체크박스 + 썸네일 + 정보 (모두 같은 줄에) -->
                <div class="queue-main-row" style="display: flex; gap: 15px; align-items: flex-start;">
                    <!-- 체크박스 -->
                    <div class="checkbox-area" style="width: 30px; display: flex; justify-content: center; align-items: flex-start; padding-top: 10px;">
                        <input type="checkbox" class="queue-checkbox" value="${queueId}" onchange="updateSelectedQueues()" style="transform: scale(1.2);">
                    </div>
                    
                    <!-- 썸네일 (작은 크기) -->
                    <div class="thumbnail-area" style="width: 120px; flex-shrink: 0;">
                        ${thumbnailHtml.replace('width:150px;height:150px', 'width:120px;height:120px')}
                    </div>
                    
                    <!-- 큐 정보 (썸네일 옵에 배치) -->
                    <div class="info-area" style="flex: 1; min-width: 0; padding-top: 5px;">
                        <div class="queue-title" style="font-size: 18px; font-weight: bold; margin-bottom: 8px; color: #333;">
                            ${title}
                        </div>
                        <div class="queue-details" style="font-size: 14px; line-height: 1.5; margin-bottom: 8px;">
                            <span class="status-badge" style="background: ${status === 'pending' ? '#ffc107' : '#28a745'}; color: white; padding: 3px 6px; border-radius: 3px; margin-right: 8px; font-size: 12px;">${getStatusText(status)}</span>
                            <span style="margin-right: 12px;">📂 ${categoryName}</span>
                            <span style="margin-right: 12px;">${promptTypeName}</span>
                            <span style="margin-right: 12px;">키워드 ${keywordCount}개</span>
                            <span>상품 ${productCount}개</span>
                        </div>
                        <div class="queue-keywords" style="color: #666; font-size: 13px;">
                            <strong style="color: #444;">키워드:</strong> ${keywordsList}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    console.log('생성된 HTML 길이:', html.length);
    console.log('생성된 HTML 미리보기:', html.substring(0, 200));
    
    container.html(html);
    console.log(`${allQueues.length}개의 큐 표시 완료`);
    console.log('컴테이너 내용 확인:', container.children().length, '개 자식 요소');
}

/**
 * 빈 상태 표시
 */
function showEmptyState() {
    console.log('showEmptyState 함수 호출됨');
    const emptyHtml = `
        <div class="empty-state" style="text-align: center; padding: 50px;">
            <h3>📦 큐 파일이 없습니다</h3>
            <p>해당 상태의 큐 파일이 없습니다.</p>
            <a href="affiliate_editor.php" class="btn btn-primary">새 글 작성하기</a>
        </div>
    `;
    $('#queueList').html(emptyHtml);
    console.log('빈 상태 HTML 설정 완료');
}

/**
 * UI 업데이트 (계획서 기준)
 */
function updateUI() {
    // 상태 버튼 활성화
    $('.menu-btn').removeClass('active');
    $(`.menu-btn[data-status="${currentStatus}"]`).addClass('active');
    
    // 큐 정보 텍스트
    const queueInfo = currentStatus === 'pending' ? '대기중 큐 목록' : '완룼됨 큐 목록';
    $('#queueInfo').text(queueInfo);
    
    // 일괄 작업 버튼 상태
    updateBulkButtons();
}

/**
 * 선택된 큐 업데이트
 */
function updateSelectedQueues() {
    selectedQueues = [];
    $('.queue-checkbox:checked').each(function() {
        selectedQueues.push($(this).val());
    });
    console.log('updateSelectedQueues 호출됨, selectedQueues:', selectedQueues);
    updateBulkButtons();
}

/**
 * 일괄 작업 버튼 상태 업데이트 (계획서 209-210줄)
 */
function updateBulkButtons() {
    const hasSelection = selectedQueues.length > 0;
    $('#bulkDeleteBtn').prop('disabled', !hasSelection);
    $('#bulkStatusBtn').prop('disabled', !hasSelection);
    
    // 버튼 텍스트 변경
    if (hasSelection) {
        if (currentStatus === 'pending') {
            $('#bulkStatusBtn').text('🔄 선택항목 완료처리');
        } else {
            $('#bulkStatusBtn').text('🔄 선택항목 대기처리');
        }
    } else {
        $('#bulkStatusBtn').text('🔄 일괄상태변경');
    }
}

/**
 * 상태 전환 (계획서 91-93줄)
 */
function switchStatus(status) {
    currentStatus = status;
    currentSearch = '';
    $('#searchInput').val('');
    loadQueues();
}

/**
 * 검색 실행 (계획서 204줄)
 */
function searchQueues() {
    currentSearch = $('#searchInput').val().trim();
    loadQueues();
}

/**
 * 검색 초기화
 */
function clearSearch() {
    currentSearch = '';
    $('#searchInput').val('');
    loadQueues();
}

/**
 * 새로고침 (계획서 195줄: 항상 pending으로 초기화)
 */
function refreshQueues() {
    currentStatus = 'pending';
    currentSearch = '';
    $('#searchInput').val('');
    loadQueues();
    loadStats();
}

/**
 * 전체 선택 토글
 */
function toggleSelectAll() {
    const selectAllChecked = $('#selectAllCheckbox').prop('checked');
    $('.queue-checkbox').prop('checked', selectAllChecked);
    updateSelectedQueues();
}

/**
 * 일괄 삭제
 */
function bulkDelete() {
    if (selectedQueues.length === 0) return;
    
    if (!confirm(`선택된 ${selectedQueues.length}개 항목을 삭제하시겠습니까?`)) {
        return;
    }
    
    $('#loadingOverlay').show();
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'delete_queue',
            queue_ids: selectedQueues
        },
        dataType: 'json',
        success: function(response) {
            $('#loadingOverlay').hide();
            
            if (response.success) {
                alert(`${response.success_count || selectedQueues.length}개 항목이 삭제되었습니다.`);
                selectedQueues = [];
                $('#selectAllCheckbox').prop('checked', false);
                loadQueues();
                loadStats();
            } else {
                alert('삭제 실패: ' + (response.message || '알 수 없는 오류'));
            }
        },
        error: function(xhr, status, error) {
            $('#loadingOverlay').hide();
            console.error('일괄 삭제 오류:', error);
            alert('삭제 중 오류가 발생했습니다.');
        }
    });
}

/**
 * 일괄 상태 변경
 */
function bulkChangeStatus() {
    console.log('bulkChangeStatus 호출됨');
    console.log('selectedQueues:', selectedQueues);
    console.log('selectedQueues.length:', selectedQueues.length);
    
    if (selectedQueues.length === 0) {
        alert('선택된 큐가 없습니다.');
        return;
    }
    
    const newStatus = currentStatus === 'pending' ? 'completed' : 'pending';
    const statusText = newStatus === 'pending' ? '대기중' : '완룜됨';
    
    if (!confirm(`선택된 ${selectedQueues.length}개 항목을 ${statusText} 상태로 변경하시겠습니까?`)) {
        return;
    }
    
    $('#loadingOverlay').show();
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'change_status',
            queue_ids: selectedQueues,
            new_status: newStatus
        },
        dataType: 'json',
        success: function(response) {
            $('#loadingOverlay').hide();
            
            if (response.success) {
                alert(response.message || `${selectedQueues.length}개 항목의 상태가 변경되었습니다.`);
                selectedQueues = [];
                $('#selectAllCheckbox').prop('checked', false);
                loadQueues();
                loadStats();
            } else {
                alert('상태 변경 실패: ' + (response.message || '알 수 없는 오류'));
            }
        },
        error: function(xhr, status, error) {
            $('#loadingOverlay').hide();
            console.error('일괄 상태 변경 오류:', error);
            alert('상태 변경 중 오류가 발생했습니다.');
        }
    });
}

/**
 * 개별 큐 삭제
 */
function deleteQueue(queueId) {
    if (!confirm('정말로 이 큐를 삭제하시겠습니까?')) {
        return;
    }
    
    $('#loadingOverlay').show();
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'delete_queue',
            queue_ids: [queueId]
        },
        dataType: 'json',
        success: function(response) {
            $('#loadingOverlay').hide();
            
            if (response.success) {
                alert('큐가 삭제되었습니다.');
                loadQueues();
                loadStats();
            } else {
                alert('삭제 실패: ' + (response.message || '알 수 없는 오류'));
            }
        },
        error: function(xhr, status, error) {
            $('#loadingOverlay').hide();
            console.error('큐 삭제 오류:', error);
            alert('삭제 중 오류가 발생했습니다.');
        }
    });
}

/**
 * 개별 상태 변경
 */
function changeQueueStatus(queueId, newStatus) {
    console.log('changeQueueStatus 호출됨');
    console.log('queueId:', queueId);
    console.log('newStatus:', newStatus);
    
    $('#loadingOverlay').show();
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'change_status',
            queue_ids: [queueId],
            new_status: newStatus
        },
        dataType: 'json',
        success: function(response) {
            $('#loadingOverlay').hide();
            
            if (response.success) {
                alert(response.message || '상태가 변경되었습니다.');
                loadQueues();
                loadStats();
            } else {
                alert('상태 변경 실패: ' + (response.message || '알 수 없는 오류'));
            }
        },
        error: function(xhr, status, error) {
            $('#loadingOverlay').hide();
            console.error('상태 변경 오류:', error);
            alert('상태 변경 중 오류가 발생했습니다.');
        }
    });
}

/**
 * 즉시 발행 (계획서 110-114줄, auto_post_products.py 연동)
 */
function immediatePublish(queueId) {
    if (!confirm('이 큐를 즉시 발행하시겠습니까?\n\n발행이 완료되면 completed 상태로 변경됩니다.')) {
        return;
    }
    
    $('#loadingOverlay').show();
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'immediate_publish',
            queue_id: queueId
        },
        dataType: 'json',
        timeout: 120000, // 2분 타임아웃
        success: function(response) {
            $('#loadingOverlay').hide();
            
            if (response.success) {
                let message = '글이 성공적으로 발행되었습니다!';
                if (response.post_url) {
                    message += '\n\n발행된 글: ' + response.post_url;
                    if (confirm(message + '\n\n발행된 글을 확인하시겠습니까?')) {
                        window.open(response.post_url, '_blank');
                    }
                } else {
                    alert(message);
                }
                // 성공시 pending → completed로 상태 변경되므로 목록 새로고침
                loadQueues();
                loadStats();
            } else {
                alert('발행 실패: ' + (response.message || '알 수 없는 오류'));
            }
        },
        error: function(xhr, status, error) {
            $('#loadingOverlay').hide();
            console.error('즉시 발행 오류:', error);
            
            let errorMessage = '발행 중 오류가 발생했습니다.';
            if (status === 'timeout') {
                errorMessage = '발행 처리 시간이 초과되었습니다.\n잠시 후 큐 목록을 확인해주세요.';
            }
            
            alert(errorMessage);
        }
    });
}

/**
 * 헬퍼 함수들 (기존 queue_utils.php 함수와 호환)
 */
function getCategoryName(categoryId) {
    const categories = {
        '354': "Today's Pick",
        '355': '기발한 잡화점',
        '356': '스마트 리빙',
        '12': '우리잇템'
    };
    return categories[categoryId] || '알 수 없는 카테고리';
}

function getPromptTypeName(promptType) {
    const types = {
        'essential_items': '필수템형 🎯',
        'friend_review': '친구 추천형 👫', 
        'professional_analysis': '전문 분석형 📊',
        'amazing_discovery': '놀라움 발견형 ✨'
    };
    return types[promptType] || '기본형';
}

function getStatusText(status) {
    return status === 'pending' ? '🟡 대기중' : '🟢 완료됨';
}

function getKeywordsCount(keywords) {
    return (keywords && Array.isArray(keywords)) ? keywords.length : 0;
}

function getProductsCount(keywords) {
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

function getKeywordsList(keywords) {
    if (!keywords || !Array.isArray(keywords) || keywords.length === 0) {
        return '키워드 없음';
    }
    
    const keywordNames = keywords.map(keyword => keyword.name || keyword).filter(name => name);
    return keywordNames.length > 0 ? keywordNames.join(', ') : '키워드 없음';
}

console.log('큐 관리 시스템 JavaScript 로드 완료 (계획서 v2.0 완전 준수)');
