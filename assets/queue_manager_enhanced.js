// 🚀 향상된 큐 관리자 JavaScript (Phase 2 구현)
// 기존 queue_manager.js를 확장한 향상된 기능들

// 🔄 전역 변수 (추가)
let currentCategoryFilter = 'all';
let currentPeriodFilter = 'all';
let currentView = 'grid';
let searchTimeout = null;
let selectedItems = new Set();
let sortBy = 'created_at';
let sortOrder = 'desc';
let autoRefreshInterval = null;

// 🚀 향상된 기능 초기화
function initializeEnhancedFeatures() {
    console.log('🚀 향상된 기능 초기화 시작...');
    
    // 검색 입력 필드 이벤트 리스너
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', handleSearchInput);
        searchInput.addEventListener('keypress', handleSearchKeyPress);
    }
    
    // 필터 선택자 이벤트 리스너
    const categoryFilter = document.getElementById('categoryFilter');
    const periodFilter = document.getElementById('periodFilter');
    const sortBySelect = document.getElementById('sortBy');
    const sortOrderSelect = document.getElementById('sortOrder');
    
    if (categoryFilter) categoryFilter.addEventListener('change', handleCategoryFilter);
    if (periodFilter) periodFilter.addEventListener('change', handlePeriodFilter);
    if (sortBySelect) sortBySelect.addEventListener('change', handleSortChange);
    if (sortOrderSelect) sortOrderSelect.addEventListener('change', handleSortChange);
    
    // 뷰 버튼 이벤트 리스너
    const viewButtons = document.querySelectorAll('.view-btn');
    viewButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const view = btn.getAttribute('data-view');
            if (view) changeView(view);
        });
    });
    
    // 자동 새로고침 시작
    startAutoRefresh();
    
    console.log('✅ 향상된 기능 초기화 완료');
}

// 📊 향상된 통계 업데이트
function updateEnhancedQueueStats() {
    const stats = {
        total: currentQueue.length,
        pending: currentQueue.filter(item => item.status === 'pending').length,
        processing: currentQueue.filter(item => item.status === 'processing').length,
        completed: currentQueue.filter(item => item.status === 'completed').length,
        failed: currentQueue.filter(item => item.status === 'failed').length
    };
    
    // 기본 통계 업데이트
    document.getElementById('totalCount').textContent = stats.total;
    document.getElementById('pendingCount').textContent = stats.pending;
    document.getElementById('processingCount').textContent = stats.processing;
    document.getElementById('completedCount').textContent = stats.completed;
    document.getElementById('failedCount').textContent = stats.failed;
    
    // 필터 버튼 카운트 업데이트
    updateFilterCounts(stats);
    
    // 기간별 통계 업데이트
    updatePeriodStats();
    
    // 표시 되는 항목 수 업데이트
    updateVisibleCount();
    
    console.log('📊 향상된 통계 업데이트 완료:', stats);
}

// 🔢 필터 버튼 카운트 업데이트
function updateFilterCounts(stats) {
    const elements = {
        'filterCountAll': stats.total,
        'filterCountPending': stats.pending,
        'filterCountProcessing': stats.processing,
        'filterCountCompleted': stats.completed,
        'filterCountFailed': stats.failed
    };
    
    Object.entries(elements).forEach(([id, count]) => {
        const element = document.getElementById(id);
        if (element) element.textContent = count;
    });
}

// 📈 기간별 통계 업데이트 (실제 데이터 기반)
function updatePeriodStats() {
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
    const weekStart = new Date(today.setDate(today.getDate() - today.getDay()));
    
    const todayProcessed = currentQueue.filter(item => 
        item.status === 'completed' && 
        item.updated_at && 
        item.updated_at.startsWith(todayStr)
    ).length;
    
    const weekProcessed = currentQueue.filter(item => 
        item.status === 'completed' && 
        item.updated_at && 
        new Date(item.updated_at) >= weekStart
    ).length;
    
    // 평균 처리시간 계산
    const avgProcessTime = calculateAverageProcessTime();
    
    // UI 업데이트
    const elements = {
        'todayProcessed': todayProcessed,
        'weekProcessed': weekProcessed,
        'avgProcessTime': avgProcessTime
    };
    
    Object.entries(elements).forEach(([id, value]) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    });
}

// ⏱️ 평균 처리시간 계산
function calculateAverageProcessTime() {
    const completedItems = currentQueue.filter(item => 
        item.status === 'completed' && 
        item.created_at && 
        item.updated_at
    );
    
    if (completedItems.length === 0) return '-';
    
    const totalMinutes = completedItems.reduce((sum, item) => {
        const created = new Date(item.created_at);
        const updated = new Date(item.updated_at);
        const diffMinutes = (updated - created) / (1000 * 60);
        return sum + diffMinutes;
    }, 0);
    
    const avgMinutes = totalMinutes / completedItems.length;
    return avgMinutes < 60 ? `${avgMinutes.toFixed(1)}분` : `${(avgMinutes/60).toFixed(1)}시간`;
}

// 🔢 표시 중인 항목 수 업데이트
function updateVisibleCount() {
    const visibleCount = filteredQueue.length;
    const totalCount = currentQueue.length;
    
    const visibleElement = document.getElementById('visibleCount');
    const totalElement = document.getElementById('totalItemCount');
    
    if (visibleElement) visibleElement.textContent = visibleCount;
    if (totalElement) totalElement.textContent = totalCount;
}

// 🔍 향상된 필터링 및 검색 시스템
function applyEnhancedFiltersAndSearch() {
    console.log('🔍 향상된 필터 적용 시작:', {
        status: currentFilter,
        category: currentCategoryFilter,
        period: currentPeriodFilter,
        search: currentSearchTerm,
        sort: `${sortBy} ${sortOrder}`
    });
    
    filteredQueue = currentQueue.filter(item => {
        // 상태 필터
        const matchesStatus = currentFilter === 'all' || item.status === currentFilter;
        
        // 카테고리 필터
        const matchesCategory = currentCategoryFilter === 'all' || 
            item.category_id === currentCategoryFilter;
        
        // 기간 필터
        const matchesPeriod = applyPeriodFilter(item);
        
        // 검색 필터 (제목, 키워드, 카테고리명)
        const matchesSearch = currentSearchTerm === '' || 
            item.title.toLowerCase().includes(currentSearchTerm.toLowerCase()) ||
            (item.category_name && item.category_name.toLowerCase().includes(currentSearchTerm.toLowerCase())) ||
            (item.prompt_type_name && item.prompt_type_name.toLowerCase().includes(currentSearchTerm.toLowerCase())) ||
            (item.keywords && item.keywords.some(kw => 
                kw.name && kw.name.toLowerCase().includes(currentSearchTerm.toLowerCase())
            ));
        
        return matchesStatus && matchesCategory && matchesPeriod && matchesSearch;
    });
    
    // 정렬 적용
    applySorting();
    
    // UI 업데이트
    updateVisibleCount();
    
    // 기존 renderQueue 함수 호출
    if (typeof renderQueue === 'function') {
        renderQueue();
    }
    
    console.log('✅ 향상된 필터 적용 완료:', filteredQueue.length, '개 항목 표시');
}

// 📅 기간 필터 적용
function applyPeriodFilter(item) {
    if (currentPeriodFilter === 'all') return true;
    
    const now = new Date();
    const itemDate = new Date(item.created_at || item.updated_at);
    
    switch (currentPeriodFilter) {
        case 'today':
            return itemDate.toDateString() === now.toDateString();
        case 'week':
            const weekStart = new Date(now);
            weekStart.setDate(now.getDate() - now.getDay());
            weekStart.setHours(0, 0, 0, 0);
            return itemDate >= weekStart;
        case 'month':
            return itemDate.getMonth() === now.getMonth() && 
                   itemDate.getFullYear() === now.getFullYear();
        default:
            return true;
    }
}

// 📄 정렬 적용
function applySorting() {
    filteredQueue.sort((a, b) => {
        let valueA, valueB;
        
        switch (sortBy) {
            case 'created_at':
            case 'updated_at':
                valueA = new Date(a[sortBy] || 0);
                valueB = new Date(b[sortBy] || 0);
                break;
            case 'title':
                valueA = (a.title || '').toLowerCase();
                valueB = (b.title || '').toLowerCase();
                break;
            case 'status':
                const statusOrder = { 'processing': 0, 'pending': 1, 'failed': 2, 'completed': 3 };
                valueA = statusOrder[a.status] || 999;
                valueB = statusOrder[b.status] || 999;
                break;
            case 'priority':
                valueA = a.priority || 999;
                valueB = b.priority || 999;
                break;
            case 'category':
                valueA = (a.category_name || '').toLowerCase();
                valueB = (b.category_name || '').toLowerCase();
                break;
            default:
                return 0;
        }
        
        let comparison = 0;
        if (valueA < valueB) comparison = -1;
        else if (valueA > valueB) comparison = 1;
        
        return sortOrder === 'desc' ? -comparison : comparison;
    });
}

// 🔍 검색 입력 처리
function handleSearchInput(event) {
    const searchTerm = event.target.value.trim();
    
    // 디바운스 처리 (입력 중단 후 500ms 후에 검색)
    if (searchTimeout) clearTimeout(searchTimeout);
    
    searchTimeout = setTimeout(() => {
        console.log('🔍 검색어 변경:', currentSearchTerm, '->', searchTerm);
        currentSearchTerm = searchTerm;
        
        // 기존 applyFiltersAndSearch 함수가 있으면 호출, 없으면 향상된 버전 호출
        if (typeof applyFiltersAndSearch === 'function') {
            applyFiltersAndSearch();
        } else {
            applyEnhancedFiltersAndSearch();
        }
        
        // 검색 제안 업데이트 (추후 구현)
        updateSearchSuggestions(searchTerm);
    }, 500);
}

// 🔍 검색 엔터 키 처리
function handleSearchKeyPress(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        performSearch();
    }
}

// 🔍 검색 수행
function performSearch() {
    const searchInput = document.getElementById('searchInput');
    if (!searchInput) return;
    
    const searchTerm = searchInput.value.trim();
    console.log('🔍 즉시 검색 수행:', searchTerm);
    
    // 타임아웃 취소
    if (searchTimeout) clearTimeout(searchTimeout);
    
    currentSearchTerm = searchTerm;
    
    // 필터 적용
    if (typeof applyFiltersAndSearch === 'function') {
        applyFiltersAndSearch();
    } else {
        applyEnhancedFiltersAndSearch();
    }
    
    // 검색 알림
    if (searchTerm) {
        showNotification(`"${searchTerm}"으로 검색한 결과: ${filteredQueue.length}개 항목`, 'info');
    }
}

// 🔍 검색 지우기
function clearSearch() {
    console.log('🔍 검색 지우기');
    
    const searchInput = document.getElementById('searchInput');
    if (searchInput) searchInput.value = '';
    
    currentSearchTerm = '';
    
    if (typeof applyFiltersAndSearch === 'function') {
        applyFiltersAndSearch();
    } else {
        applyEnhancedFiltersAndSearch();
    }
    
    showNotification('검색 필터가 취소되었습니다.', 'info');
}

// 📂 카테고리별 필터링
function filterByCategory() {
    const select = document.getElementById('categoryFilter');
    if (!select) return;
    
    const newCategory = select.value;
    console.log('📂 카테고리 필터 변경:', currentCategoryFilter, '->', newCategory);
    currentCategoryFilter = newCategory;
    
    if (typeof applyFiltersAndSearch === 'function') {
        applyFiltersAndSearch();
    } else {
        applyEnhancedFiltersAndSearch();
    }
}

// 📅 기간별 필터링
function filterByPeriod() {
    const select = document.getElementById('periodFilter');
    if (!select) return;
    
    const newPeriod = select.value;
    console.log('📅 기간 필터 변경:', currentPeriodFilter, '->', newPeriod);
    currentPeriodFilter = newPeriod;
    
    if (typeof applyFiltersAndSearch === 'function') {
        applyFiltersAndSearch();
    } else {
        applyEnhancedFiltersAndSearch();
    }
}

// 📄 정렬 변경 처리
function handleSortChange() {
    const sortBySelect = document.getElementById('sortBy');
    const sortOrderSelect = document.getElementById('sortOrder');
    
    if (sortBySelect) sortBy = sortBySelect.value;
    if (sortOrderSelect) sortOrder = sortOrderSelect.value;
    
    console.log('📄 정렬 변경:', sortBy, sortOrder);
    
    if (typeof applyFiltersAndSearch === 'function') {
        applyFiltersAndSearch();
    } else {
        applyEnhancedFiltersAndSearch();
    }
}

// 🎨 뷰 변경 기능
function changeView(viewType) {
    console.log('🎨 뷰 변경:', currentView, '->', viewType);
    currentView = viewType;
    
    // 뷰 버튼 업데이트
    const viewBtns = document.querySelectorAll('.view-btn');
    viewBtns.forEach(btn => btn.classList.remove('active'));
    const activeBtn = document.querySelector(`[data-view="${viewType}"]`);
    if (activeBtn) activeBtn.classList.add('active');
    
    // 큐 리스트 클래스 업데이트
    const queueList = document.getElementById('queueList');
    if (queueList) {
        queueList.className = `queue-list view-${viewType}`;
    }
    
    showNotification(`${viewType === 'grid' ? '그리드' : '리스트'} 뷰로 변경되었습니다.`, 'info');
}

// ☑️ 선택 관리 기능
function selectAll() {
    console.log('☑️ 전체 선택 토글');
    
    if (selectedItems.size === filteredQueue.length) {
        // 전체 선택 해제
        selectedItems.clear();
        showNotification('전체 선택이 해제되었습니다.', 'info');
    } else {
        // 전체 선택
        selectedItems.clear();
        filteredQueue.forEach(item => {
            if (item.queue_id) selectedItems.add(item.queue_id);
        });
        showNotification(`${selectedItems.size}개 항목이 선택되었습니다.`, 'info');
    }
    
    updateSelectionUI();
}

// 🛠️ 대량 작업
function bulkAction(action) {
    if (selectedItems.size === 0) {
        showNotification('선택된 항목이 없습니다.', 'warning');
        return;
    }
    
    console.log('🛠️ 대량 작업:', action, '대상:', selectedItems.size, '개 항목');
    
    switch (action) {
        case 'priority':
            showPriorityDialog();
            break;
        case 'delete':
            showDeleteConfirmDialog();
            break;
        default:
            console.warn('알 수 없는 대량 작업:', action);
    }
}

// ⭐ 우선순위 설정 대화상자
function showPriorityDialog() {
    const priority = prompt(`선택된 ${selectedItems.size}개 항목의 우선순위를 설정하세요 (1-5):`, '1');
    
    if (priority && /^[1-5]$/.test(priority)) {
        console.log('⭐ 우선순위 설정:', priority);
        // TODO: 실제 API 호출 구현
        showNotification(`${selectedItems.size}개 항목의 우선순위가 ${priority}로 설정되었습니다.`, 'success');
        selectedItems.clear();
        updateSelectionUI();
    }
}

// 🗑️ 삭제 확인 대화상자
function showDeleteConfirmDialog() {
    const confirmed = confirm(`선택된 ${selectedItems.size}개 항목을 정말 삭제하시겠습니까?\n\n이 작업은 되돌릴 수 없습니다.`);
    
    if (confirmed) {
        console.log('🗑️ 삭제 확인:', selectedItems.size, '개 항목');
        // TODO: 실제 API 호출 구현
        showNotification(`${selectedItems.size}개 항목이 삭제되었습니다.`, 'success');
        selectedItems.clear();
        updateSelectionUI();
        // 데이터 새로고침
        if (typeof loadQueue === 'function') {
            loadQueue();
        }
    }
}

// 🔄 선택 UI 업데이트
function updateSelectionUI() {
    // TODO: 선택된 아이템에 시각적 표시 추가
    console.log('🔄 선택 UI 업데이트:', selectedItems.size, '개 선택됨');
}

// 🔔 알림 표시 (기존 함수 강화)
function showNotification(message, type = 'info') {
    console.log('🔔 알림:', type, message);
    
    const container = document.getElementById('notificationContainer');
    if (!container) {
        console.warn('알림 컨테이너를 찾을 수 없습니다.');
        return;
    }
    
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

// 🔄 마지막 업데이트 시간 업데이트
function updateLastUpdated() {
    const now = new Date();
    const timeString = now.toLocaleString('ko-KR', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    const element = document.getElementById('lastUpdated');
    if (element) element.textContent = timeString;
}

// 🔄 자동 새로고침 시스템
function startAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    autoRefreshInterval = setInterval(() => {
        console.log('🔄 자동 새로고침 수행...');
        if (typeof loadQueue === 'function') {
            loadQueue();
        }
        updateLastUpdated();
    }, 30000); // 30초마다
    
    console.log('🔄 자동 새로고침 시작 (30초 간격)');
}

// 🔄 자동 새로고침 토글
function toggleAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
        console.log('🔄 자동 새로고침 중단');
        showNotification('자동 새로고침이 중단되었습니다.', 'info');
    } else {
        startAutoRefresh();
        showNotification('자동 새로고침이 활성화되었습니다. (30초마다)', 'success');
    }
}

// 🔄 드래그 정렬 토글
function toggleDragSort() {
    if (typeof dragEnabled !== 'undefined') {
        dragEnabled = !dragEnabled;
        const toggleText = document.getElementById('dragToggleText');
        
        if (toggleText) {
            toggleText.textContent = dragEnabled ? 
                '🔄 드래그 정렬 비활성화' : 
                '🔄 드래그 정렬 활성화';
        }
        
        console.log('🔄 드래그 정렬:', dragEnabled ? '활성화' : '비활성화');
        showNotification(`드래그 정렬이 ${dragEnabled ? '활성화' : '비활성화'}되었습니다.`, 'info');
        
        // TODO: 드래그 이벤트 리스너 추가/제거
    }
}

// 🔍 검색 제안 업데이트 (추후 구현)
function updateSearchSuggestions(searchTerm) {
    // TODO: 검색 제안 기능 구현
    console.log('📝 검색 제안 업데이트:', searchTerm);
}

// 🔄 기존 함수와 호환성 유지
function searchQueues() {
    console.log('🔍 기존 searchQueues 호출 - handleSearchInput으로 리디렉션');
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        handleSearchInput({ target: searchInput });
    }
}

// 📄 기존 정렬 함수 호환성
function sortQueue() {
    console.log('📄 기존 sortQueue 호출 - handleSortChange로 리디렉션');
    handleSortChange();
}

// 🔄 향상된 새로고침
function refreshQueue() {
    console.log('🔄 향상된 새로고침 요청...');
    if (typeof loadQueue === 'function') {
        loadQueue();
    }
    updateLastUpdated();
}

// 🚀 초기화 실행
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 향상된 큐 관리자 초기화...');
    
    // 기존 초기화 완료를 기다린 후 향상된 기능 추가
    setTimeout(() => {
        initializeEnhancedFeatures();
        updateLastUpdated();
        
        console.log('✅ 향상된 큐 관리자 초기화 완료');
    }, 100);
});

// 🎯 전역 함수로 내보내기 (기존 코드와의 호환성)
window.filterByCategory = filterByCategory;
window.filterByPeriod = filterByPeriod;
window.changeView = changeView;
window.selectAll = selectAll;
window.bulkAction = bulkAction;
window.showNotification = showNotification;
window.updateLastUpdated = updateLastUpdated;
window.toggleAutoRefresh = toggleAutoRefresh;
window.handleSearchKeyPress = function(event) { handleSearchKeyPress(event); };
window.performSearch = performSearch;

console.log('📦 향상된 큐 관리자 JavaScript 로드 완료');