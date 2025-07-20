let currentQueue = [];
let dragEnabled = false;
let currentEditingQueueId = null;
let currentEditingData = null;

document.addEventListener('DOMContentLoaded', () => loadQueue());

async function loadQueue() {
    try {
        showLoading();
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_queue_list'
        });
        const result = await response.json();
        if (result.success) {
            currentQueue = result.queue;
            console.log('🔍 큐 데이터 로드 완료:', currentQueue.length, '개 항목');
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

function displayQueue() {
    const queueList = document.getElementById('queueList');
    if (currentQueue.length === 0) {
        queueList.innerHTML = `<div class="empty-state"><h3>📦 저장된 정보가 없습니다</h3><p>아직 저장된 큐 항목이 없습니다.</p><a href="affiliate_editor.php" class="btn btn-primary">첫 번째 글 작성하기</a></div>`;
        return;
    }
    
    let html = '';
    currentQueue.forEach(item => {
        const keywordCount = item.keywords ? item.keywords.length : 0;
        const totalLinks = item.keywords ? item.keywords.reduce((sum, k) => sum + (k.coupang?.length || 0) + (k.aliexpress?.length || 0), 0) : 0;
        const statusClass = `status-${item.status}`;
        const statusText = getStatusText(item.status);
        const productsSummary = getProductsSummary(item.keywords);
        
        html += `<div class="queue-item" data-queue-id="${item.queue_id}" draggable="${dragEnabled}">
            <div class="queue-header">
                <div>
                    <h3 class="queue-title">${item.title}</h3>
                    <p class="queue-meta">${item.category_name} | ${item.prompt_type_name || '기본형'} | ${item.created_at} | <span class="status-badge ${statusClass}">${statusText}</span></p>
                </div>
                <div class="queue-actions">
                    <button class="btn btn-primary btn-small" onclick="editQueue('${item.queue_id}')">✏️ 편집</button>
                    <button class="btn btn-orange btn-small" onclick="immediatePublish('${item.queue_id}')">🚀 즉시발행</button>
                    <button class="btn btn-danger btn-small" onclick="deleteQueue('${item.queue_id}')">🗑️ 삭제</button>
                </div>
            </div>
            <div class="queue-content">
                <div class="queue-info">
                    <div class="info-item"><div class="info-value">${keywordCount}</div><div class="info-label">키워드</div></div>
                    <div class="info-item"><div class="info-value">${totalLinks}</div><div class="info-label">총 링크</div></div>
                    <div class="info-item"><div class="info-value">${productsSummary.products_with_data}</div><div class="info-label">분석완료</div></div>
                    <div class="info-item"><div class="info-value">${item.priority || 1}</div><div class="info-label">우선순위</div></div>
                    <div class="info-item"><div class="info-value">${item.has_user_details ? 'O' : 'X'}</div><div class="info-label">상세정보</div></div>
                    <div class="info-item"><div class="info-value">${item.has_product_data ? 'O' : 'X'}</div><div class="info-label">상품데이터</div></div>
                </div>
                ${item.keywords && item.keywords.length > 0 ? `<div class="keywords-preview"><h4>키워드:</h4><div class="keyword-tags">${item.keywords.map(k => `<span class="keyword-tag">${k.name}</span>`).join('')}</div></div>` : ''}
                ${generateProductsPreview(productsSummary)}
            </div>
        </div>`;
    });
    
    queueList.innerHTML = html;
    if (dragEnabled) addDragEvents();
}

function getProductsSummary(keywords) {
    let total_products = 0, products_with_data = 0, product_samples = [];
    if (!Array.isArray(keywords)) return {total_products: 0, products_with_data: 0, product_samples: []};
    
    keywords.forEach(keyword => {
        if (keyword.products_data && Array.isArray(keyword.products_data)) {
            keyword.products_data.forEach(product_data => {
                total_products++;
                if (product_data.analysis_data) {
                    products_with_data++;
                    if (product_samples.length < 3) {
                        const analysis = product_data.analysis_data;
                        product_samples.push({
                            title: analysis.title || '상품명 없음',
                            image_url: analysis.image_url || '',
                            price: analysis.price || '가격 정보 없음',
                            url: product_data.url || ''
                        });
                    }
                }
            });
        }
        if (keyword.aliexpress && Array.isArray(keyword.aliexpress)) total_products += keyword.aliexpress.length;
        if (keyword.coupang && Array.isArray(keyword.coupang)) total_products += keyword.coupang.length;
    });
    
    return {total_products, products_with_data, product_samples};
}

function generateProductsPreview(productsSummary) {
    if (productsSummary.product_samples.length === 0) {
        return `<div class="products-preview"><h4>🛍️ 상품 정보:</h4><div class="no-products-data">상품 분석 데이터가 없습니다.</div></div>`;
    }
    
    const productsHtml = productsSummary.product_samples.map(product => {
        const imageHtml = product.image_url ? 
            `<img src="${product.image_url}" alt="${product.title}" class="product-image" onerror="this.style.display='none'">` :
            `<div class="product-image" style="background:#f0f0f0;display:flex;align-items:center;justify-content:center;color:#999;font-size:10px;">이미지<br>없음</div>`;
        
        return `<div class="product-card">${imageHtml}<div class="product-info"><div class="product-title">${product.title}</div><div class="product-price">${formatPrice(product.price)}</div><div class="product-url">${product.url.substring(0, 50)}...</div></div></div>`;
    }).join('');
    
    return `<div class="products-preview"><h4>🛍️ 상품 정보 (${productsSummary.products_with_data}/${productsSummary.total_products}개 분석완료):</h4><div class="products-grid">${productsHtml}</div></div>`;
}

function formatPrice(price) {
    if (!price || price === '가격 정보 없음') return '가격 정보 없음';
    return price.replace(/₩(\d)/, '₩ $1');
}

function getStatusText(status) {
    const statusMap = {'pending': '대기 중', 'processing': '처리 중', 'completed': '완료', 'failed': '실패', 'immediate': '즉시발행'};
    return statusMap[status] || status;
}

function sortQueue() {
    const sortBy = document.getElementById('sortBy').value;
    const sortOrder = document.getElementById('sortOrder').value;
    currentQueue.sort((a, b) => {
        let aValue = a[sortBy], bValue = b[sortBy];
        if (typeof aValue === 'string' && typeof bValue === 'string') {
            aValue = aValue.toLowerCase();
            bValue = bValue.toLowerCase();
        }
        return sortOrder === 'asc' ? (aValue < bValue ? -1 : aValue > bValue ? 1 : 0) : (aValue > bValue ? -1 : aValue < bValue ? 1 : 0);
    });
    displayQueue();
}

function toggleDragSort() {
    dragEnabled = !dragEnabled;
    document.getElementById('dragToggleText').textContent = dragEnabled ? '드래그 정렬 비활성화' : '드래그 정렬 활성화';
    document.querySelectorAll('.queue-item').forEach(item => item.draggable = dragEnabled);
    if (dragEnabled) addDragEvents();
}

function addDragEvents() {
    document.querySelectorAll('.queue-item').forEach(item => {
        item.addEventListener('dragstart', handleDragStart);
        item.addEventListener('dragover', handleDragOver);
        item.addEventListener('drop', handleDrop);
        item.addEventListener('dragend', handleDragEnd);
    });
}

let draggedItem = null;
function handleDragStart(e) { draggedItem = this; this.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; }
function handleDragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; this.classList.add('drag-over'); }
function handleDrop(e) {
    e.preventDefault(); this.classList.remove('drag-over');
    if (draggedItem !== this) {
        const draggedId = draggedItem.dataset.queueId, targetId = this.dataset.queueId;
        const draggedIndex = currentQueue.findIndex(item => item.queue_id === draggedId);
        const targetIndex = currentQueue.findIndex(item => item.queue_id === targetId);
        currentQueue.splice(targetIndex, 0, currentQueue.splice(draggedIndex, 1)[0]);
        saveQueueOrder(); displayQueue();
    }
}
function handleDragEnd(e) { this.classList.remove('dragging'); document.querySelectorAll('.queue-item').forEach(item => item.classList.remove('drag-over')); }

async function saveQueueOrder() {
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=reorder_queue&order=${encodeURIComponent(JSON.stringify(currentQueue.map(item => item.queue_id)))}`
        });
        const result = await response.json();
        if (!result.success) { alert('순서 저장에 실패했습니다: ' + result.message); loadQueue(); }
    } catch (error) { console.error('순서 저장 오류:', error); alert('순서 저장 중 오류가 발생했습니다.'); loadQueue(); }
}

async function deleteQueue(queueId) {
    if (!confirm('정말로 이 항목을 삭제하시겠습니까?')) return;
    try {
        showLoading();
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=delete_queue_item&queue_id=${encodeURIComponent(queueId)}`
        });
        const result = await response.json();
        if (result.success) { alert('항목이 삭제되었습니다.'); loadQueue(); } else { alert('삭제에 실패했습니다: ' + result.message); }
    } catch (error) { console.error('삭제 오류:', error); alert('삭제 중 오류가 발생했습니다.'); } finally { hideLoading(); }
}

async function immediatePublish(queueId) {
    if (!confirm('선택한 항목을 즉시 발행하시겠습니까?')) return;
    try {
        showLoading();
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=immediate_publish&queue_id=${encodeURIComponent(queueId)}`
        });
        const result = await response.json();
        if (result.success) {
            alert('✅ 글이 성공적으로 발행되었습니다!');
            if (result.post_url) window.open(result.post_url, '_blank');
            loadQueue();
        } else { alert('발행에 실패했습니다: ' + result.message); }
    } catch (error) { console.error('발행 오류:', error); alert('발행 중 오류가 발생했습니다.'); } finally { hideLoading(); }
}

async function editQueue(queueId) {
    try {
        showLoading();
        console.log('🔍 편집 요청 시작:', queueId);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get_queue_item&queue_id=${encodeURIComponent(queueId)}`
        });
        
        if (!response.ok) {
            throw new Error(`HTTP 오류: ${response.status} ${response.statusText}`);
        }
        
        const responseText = await response.text();
        console.log('🔍 서버 응답 텍스트 길이:', responseText.length, '문자');
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('❌ JSON 파싱 오류:', parseError);
            console.log('📝 응답 텍스트 일부:', responseText.substring(0, 500));
            throw new Error('서버 응답을 파싱할 수 없습니다');
        }
        
        if (result.success) {
            currentEditingQueueId = queueId;
            currentEditingData = result.item;
            
            console.log('✅ 편집할 큐 데이터 로드 성공:');
            console.log('📊 제목:', currentEditingData.title);
            console.log('📊 키워드 수:', currentEditingData.keywords ? currentEditingData.keywords.length : 0);
            
            populateEditModal(result.item);
            document.getElementById('editModal').style.display = 'flex';
        } else { 
            console.error('❌ 서버 오류:', result.message);
            alert('항목을 불러오는데 실패했습니다: ' + result.message); 
        }
    } catch (error) { 
        console.error('❌ 편집 데이터 로드 오류:', error); 
        alert('편집 데이터를 불러오는 중 오류가 발생했습니다: ' + error.message); 
    } finally { 
        hideLoading(); 
    }
}

function populateEditModal(item) {
    console.log('🔧 편집 모달 채우기 시작:', item.title);
    
    document.getElementById('editTitle').value = item.title || '';
    document.getElementById('editCategory').value = item.category_id || '356';
    document.getElementById('editPromptType').value = item.prompt_type || 'essential_items';
    
    console.log('🔧 기본 정보 설정 완료. 키워드 표시 시작...');
    displayKeywords(item.keywords || []);
}

function displayKeywords(keywords) {
    console.log('🔍 키워드 표시 시작:', keywords.length, '개');
    const keywordList = document.getElementById('keywordList');
    let html = '';
    
    keywords.forEach((keyword, index) => {
        console.log(`🔍 키워드 ${index} "${keyword.name}" 처리 중...`);
        
        const aliexpressLinks = keyword.aliexpress || [];
        console.log(`  - aliexpress URLs: ${aliexpressLinks.length}개`);
        console.log(`  - products_data: ${keyword.products_data ? keyword.products_data.length : 0}개`);
        
        html += `<div class="keyword-item" data-keyword-index="${index}">
            <div class="keyword-item-header">
                <input type="text" class="keyword-item-title" value="${keyword.name}" placeholder="키워드 이름">
                <div class="keyword-item-actions">
                    <button type="button" class="btn btn-danger btn-small" onclick="removeKeyword(${index})">삭제</button>
                </div>
            </div>
            <div class="product-list">
                <h5>알리익스프레스 상품 (${aliexpressLinks.length}개)</h5>
                <div class="aliexpress-products" id="aliexpress-products-${index}">`;
        
        // 🔧 각 상품별 HTML 생성 및 즉시 폼 필드 값 설정 준비
        aliexpressLinks.forEach((url, urlIndex) => {
            console.log(`    상품 ${urlIndex} URL: ${url}`);
            
            let analysisHtml = '';
            let productUserData = null;
            
            // products_data에서 해당 URL의 상품 데이터 찾기
            if (keyword.products_data && Array.isArray(keyword.products_data)) {
                const productData = keyword.products_data.find(pd => pd.url === url);
                
                if (productData) {
                    console.log(`      ✅ 상품 데이터 찾음:`, {
                        hasAnalysis: !!productData.analysis_data,
                        hasUserData: !!productData.user_data,
                        userDataStructure: productData.user_data ? Object.keys(productData.user_data) : []
                    });
                    
                    // 분석 데이터 HTML 생성
                    if (productData.analysis_data) {
                        const analysis = productData.analysis_data;
                        analysisHtml = `<div class="analysis-result">
                            <div class="product-preview">
                                <img src="${analysis.image_url || ''}" alt="${analysis.title || '상품명 없음'}" onerror="this.style.display='none'">
                                <div class="product-info-detail">
                                    <h4>${analysis.title || '상품명 없음'}</h4>
                                    <p><strong>가격:</strong> ${formatPrice(analysis.price)}</p>
                                    <p><strong>평점:</strong> ${analysis.rating_display || '평점 정보 없음'}</p>
                                    <p><strong>판매량:</strong> ${analysis.lastest_volume || '판매량 정보 없음'}</p>
                                </div>
                            </div>
                        </div>`;
                    }
                    
                    // 사용자 데이터 가져오기
                    productUserData = productData.user_data || null;
                    if (productUserData) {
                        console.log(`      📝 사용자 데이터 구조:`, productUserData);
                    }
                }
            }
            
            html += `<div class="product-item-edit" data-product-index="${urlIndex}">
                <div class="product-item-edit-header">
                    <input type="url" class="product-url-input" value="${url}" placeholder="상품 URL" onchange="updateProductUrl(${index}, 'aliexpress', ${urlIndex}, this.value)">
                    <button type="button" class="btn btn-secondary btn-small" onclick="analyzeProduct(${index}, 'aliexpress', ${urlIndex})">분석</button>
                    <button type="button" class="btn btn-danger btn-small" onclick="removeProduct(${index}, 'aliexpress', ${urlIndex})">삭제</button>
                </div>
                ${analysisHtml}
                <div class="analysis-result" id="analysis-${index}-aliexpress-${urlIndex}" style="display:none;"></div>
                <div class="product-details-toggle" onclick="toggleProductDetails(${index}, 'aliexpress', ${urlIndex})">📝 상품별 상세 정보 ${productUserData ? '(입력됨)' : '(미입력)'}</div>
                <div class="product-user-details" id="product-details-${index}-aliexpress-${urlIndex}">
                    <h5>이 상품의 상세 정보</h5>
                    ${generateProductDetailsForm(index, 'aliexpress', urlIndex, productUserData)}
                </div>
            </div>`;
        });
        
        html += `</div>
                <div class="add-product-section">
                    <div style="display: flex; gap: 10px;">
                        <input type="url" class="new-product-url" id="new-product-url-${index}" placeholder="새 알리익스프레스 상품 URL">
                        <button type="button" class="btn btn-success btn-small" onclick="addProduct(${index}, 'aliexpress')">추가</button>
                    </div>
                </div>
            </div>
        </div>`;
    });
    
    keywordList.innerHTML = html;
    console.log('✅ 키워드 HTML 생성 완료');
    
    // 🔧 DOM 업데이트 후 폼 필드 값 재설정 (중요!)
    setTimeout(() => {
        console.log('🔧 폼 필드 값 재설정 시작...');
        keywords.forEach((keyword, kIndex) => {
            if (keyword.products_data && Array.isArray(keyword.products_data)) {
                keyword.products_data.forEach(product => {
                    if (product.user_data) {
                        const urlIndex = keyword.aliexpress.indexOf(product.url);
                        if (urlIndex >= 0) {
                            console.log(`🔧 상품 ${kIndex}-${urlIndex} 폼 필드 값 설정 중...`);
                            setProductFormValues(kIndex, 'aliexpress', urlIndex, product.user_data);
                        }
                    }
                });
            }
        });
        console.log('✅ 모든 폼 필드 값 설정 완료');
    }, 100);
}

// 🔧 새로운 함수: 폼 필드에 실제 값 설정
function setProductFormValues(keywordIndex, platform, productIndex, userData) {
    if (!userData || typeof userData !== 'object') return;
    
    console.log(`🔧 폼 필드 값 설정: ${keywordIndex}-${platform}-${productIndex}`, userData);
    
    const specs = userData.specs || {};
    const efficiency = userData.efficiency || {};
    const usage = userData.usage || {};
    const benefits = userData.benefits || {};
    const advantages = benefits.advantages || [];
    
    const setFieldValue = (fieldId, value) => {
        const element = document.getElementById(fieldId);
        if (element && value) {
            element.value = value;
            console.log(`  ✅ ${fieldId}: ${value}`);
        } else if (!element) {
            console.log(`  ❌ 필드 없음: ${fieldId}`);
        }
    };
    
    // Specs 섹션
    setFieldValue(`pd-main-function-${keywordIndex}-${platform}-${productIndex}`, specs.main_function);
    setFieldValue(`pd-size-capacity-${keywordIndex}-${platform}-${productIndex}`, specs.size_capacity);
    setFieldValue(`pd-color-${keywordIndex}-${platform}-${productIndex}`, specs.color);
    setFieldValue(`pd-material-${keywordIndex}-${platform}-${productIndex}`, specs.material);
    setFieldValue(`pd-power-battery-${keywordIndex}-${platform}-${productIndex}`, specs.power_battery);
    
    // Efficiency 섹션
    setFieldValue(`pd-problem-solving-${keywordIndex}-${platform}-${productIndex}`, efficiency.problem_solving);
    setFieldValue(`pd-time-saving-${keywordIndex}-${platform}-${productIndex}`, efficiency.time_saving);
    setFieldValue(`pd-space-efficiency-${keywordIndex}-${platform}-${productIndex}`, efficiency.space_efficiency);
    setFieldValue(`pd-cost-saving-${keywordIndex}-${platform}-${productIndex}`, efficiency.cost_saving);
    
    // Usage 섹션
    setFieldValue(`pd-usage-location-${keywordIndex}-${platform}-${productIndex}`, usage.usage_location);
    setFieldValue(`pd-usage-frequency-${keywordIndex}-${platform}-${productIndex}`, usage.usage_frequency);
    setFieldValue(`pd-target-users-${keywordIndex}-${platform}-${productIndex}`, usage.target_users);
    
    // Benefits 섹션
    setFieldValue(`pd-advantage1-${keywordIndex}-${platform}-${productIndex}`, advantages[0]);
    setFieldValue(`pd-advantage2-${keywordIndex}-${platform}-${productIndex}`, advantages[1]);
    setFieldValue(`pd-advantage3-${keywordIndex}-${platform}-${productIndex}`, advantages[2]);
    setFieldValue(`pd-precautions-${keywordIndex}-${platform}-${productIndex}`, benefits.precautions);
}

function generateProductDetailsForm(keywordIndex, platform, productIndex, existingDetails) {
    console.log(`🔧 상품 상세 정보 폼 생성:`, {
        keywordIndex, platform, productIndex, 
        hasExistingDetails: !!existingDetails
    });
    
    // 🔧 빈 폼 생성 (값은 별도로 설정)
    return `
        <div class="product-detail-field"><label>주요 기능</label><input type="text" id="pd-main-function-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 자동 압축, 물 절약"></div>
        <div class="product-detail-field"><label>크기/용량</label><input type="text" id="pd-size-capacity-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 30cm × 20cm"></div>
        <div class="product-detail-field"><label>색상</label><input type="text" id="pd-color-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 화이트, 블랙"></div>
        <div class="product-detail-field"><label>재질/소재</label><input type="text" id="pd-material-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 스테인리스 스틸"></div>
        <div class="product-detail-field"><label>전원/배터리</label><input type="text" id="pd-power-battery-${keywordIndex}-${platform}-${productIndex}" placeholder="예: USB 충전"></div>
        <div class="product-detail-field"><label>해결하는 문제</label><input type="text" id="pd-problem-solving-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 설거지 시간 오래 걸림"></div>
        <div class="product-detail-field"><label>시간 절약 효과</label><input type="text" id="pd-time-saving-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 10분 → 3분"></div>
        <div class="product-detail-field"><label>공간 활용</label><input type="text" id="pd-space-efficiency-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 50% 공간 절약"></div>
        <div class="product-detail-field"><label>비용 절감</label><input type="text" id="pd-cost-saving-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 월 전기료 30% 절약"></div>
        <div class="product-detail-field"><label>주요 사용 장소</label><input type="text" id="pd-usage-location-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 주방, 욕실"></div>
        <div class="product-detail-field"><label>사용 빈도</label><input type="text" id="pd-usage-frequency-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 매일"></div>
        <div class="product-detail-field"><label>적합한 사용자</label><input type="text" id="pd-target-users-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 1인 가구"></div>
        <div class="product-detail-field"><label>핵심 장점 1</label><input type="text" id="pd-advantage1-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 설치 간편함"></div>
        <div class="product-detail-field"><label>핵심 장점 2</label><input type="text" id="pd-advantage2-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 유지비 저렴함"></div>
        <div class="product-detail-field"><label>핵심 장점 3</label><input type="text" id="pd-advantage3-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 내구성 뛰어남"></div>
        <div class="product-detail-field"><label>주의사항</label><input type="text" id="pd-precautions-${keywordIndex}-${platform}-${productIndex}" placeholder="예: 물기 주의"></div>`;
}

function toggleProductDetails(keywordIndex, platform, productIndex) {
    const detailsDiv = document.getElementById(`product-details-${keywordIndex}-${platform}-${productIndex}`);
    if (detailsDiv) detailsDiv.classList.toggle('active');
}

function updateProductUrl(keywordIndex, platform, productIndex, newUrl) {
    console.log(`🔄 URL 업데이트: 키워드 ${keywordIndex}, ${platform} ${productIndex} -> ${newUrl}`);
    
    if (!currentEditingData.keywords[keywordIndex][platform]) currentEditingData.keywords[keywordIndex][platform] = [];
    if (!currentEditingData.keywords[keywordIndex].products_data) currentEditingData.keywords[keywordIndex].products_data = [];
    
    // 기존 URL 저장
    const oldUrl = currentEditingData.keywords[keywordIndex][platform][productIndex];
    
    // URL 배열 업데이트
    currentEditingData.keywords[keywordIndex][platform][productIndex] = newUrl;
    
    // products_data 배열도 동기화
    if (oldUrl) {
        const existingProductIndex = currentEditingData.keywords[keywordIndex].products_data.findIndex(pd => pd.url === oldUrl);
        if (existingProductIndex >= 0) {
            currentEditingData.keywords[keywordIndex].products_data[existingProductIndex].url = newUrl;
            console.log(`✅ 기존 상품 데이터 URL 업데이트: ${oldUrl} -> ${newUrl}`);
        }
    } else {
        // 새로운 상품 데이터 추가
        currentEditingData.keywords[keywordIndex].products_data.push({
            url: newUrl, platform: platform, analysis_data: null, user_data: null, generated_html: null
        });
        console.log(`➕ 새 상품 데이터 추가: ${newUrl}`);
    }
}

function addKeyword() {
    const nameInput = document.getElementById('newKeywordName');
    const name = nameInput.value.trim();
    if (!name) { alert('키워드 이름을 입력해주세요.'); return; }
    if (!currentEditingData.keywords) currentEditingData.keywords = [];
    currentEditingData.keywords.push({name: name, aliexpress: [], coupang: [], products_data: []});
    displayKeywords(currentEditingData.keywords);
    nameInput.value = '';
}

function removeKeyword(index) {
    if (confirm('이 키워드를 삭제하시겠습니까?')) {
        currentEditingData.keywords.splice(index, 1);
        displayKeywords(currentEditingData.keywords);
    }
}

function addProduct(keywordIndex, platform) {
    const urlInput = document.getElementById(`new-product-url-${keywordIndex}`);
    const url = urlInput.value.trim();
    if (!url) { alert('상품 URL을 입력해주세요.'); return; }
    
    if (!currentEditingData.keywords[keywordIndex][platform]) currentEditingData.keywords[keywordIndex][platform] = [];
    if (!currentEditingData.keywords[keywordIndex].products_data) currentEditingData.keywords[keywordIndex].products_data = [];
    
    // 중복 URL 체크
    if (currentEditingData.keywords[keywordIndex][platform].includes(url)) {
        alert('이미 추가된 상품 URL입니다.');
        return;
    }
    
    currentEditingData.keywords[keywordIndex][platform].push(url);
    currentEditingData.keywords[keywordIndex].products_data.push({
        url: url, 
        platform: platform, 
        analysis_data: null, 
        user_data: null, 
        generated_html: null
    });
    
    displayKeywords(currentEditingData.keywords);
    urlInput.value = '';
    
    console.log('🔧 새 상품 추가됨:', {
        keywordIndex: keywordIndex,
        platform: platform,
        url: url
    });
}

function removeProduct(keywordIndex, platform, urlIndex) {
    if (confirm('이 상품을 삭제하시겠습니까?')) {
        const removedUrl = currentEditingData.keywords[keywordIndex][platform][urlIndex];
        currentEditingData.keywords[keywordIndex][platform].splice(urlIndex, 1);
        
        // products_data에서도 해당 URL의 상품 제거
        if (currentEditingData.keywords[keywordIndex].products_data) {
            const productIndex = currentEditingData.keywords[keywordIndex].products_data.findIndex(pd => pd.url === removedUrl);
            if (productIndex >= 0) {
                currentEditingData.keywords[keywordIndex].products_data.splice(productIndex, 1);
                console.log(`🗑️ 상품 데이터 삭제됨: ${removedUrl}`);
            }
        }
        displayKeywords(currentEditingData.keywords);
    }
}

async function analyzeProduct(keywordIndex, platform, urlIndex) {
    const url = currentEditingData.keywords[keywordIndex][platform][urlIndex];
    if (!url) { alert('분석할 상품 URL이 없습니다.'); return; }
    
    console.log(`🔍 상품 분석 시작: ${url}`);
    
    const resultDiv = document.getElementById(`analysis-${keywordIndex}-${platform}-${urlIndex}`);
    if (resultDiv) { resultDiv.innerHTML = '<div style="text-align:center;padding:20px;">분석 중...</div>'; resultDiv.style.display = 'block'; }
    
    try {
        const response = await fetch('product_analyzer_v2.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'analyze_product', 
                url: url, 
                platform: 'aliexpress' 
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP 오류: ${response.status} ${response.statusText}`);
        }
        
        const responseText = await response.text();
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            throw new Error(`JSON 파싱 오류: ${parseError.message}`);
        }
        
        if (result.success && result.data) {
            if (!currentEditingData.keywords[keywordIndex].products_data) currentEditingData.keywords[keywordIndex].products_data = [];
            
            // URL로 매칭하여 해당 상품 데이터 찾거나 생성
            let productIndex = currentEditingData.keywords[keywordIndex].products_data.findIndex(pd => pd.url === url);
            if (productIndex < 0) {
                currentEditingData.keywords[keywordIndex].products_data.push({
                    url: url, platform: platform, analysis_data: null, user_data: null, generated_html: null
                });
                productIndex = currentEditingData.keywords[keywordIndex].products_data.length - 1;
            }
            
            const generatedHtml = generateOptimizedMobileHtml(result.data);
            const existingUserData = currentEditingData.keywords[keywordIndex].products_data[productIndex].user_data || null;
            
            currentEditingData.keywords[keywordIndex].products_data[productIndex] = {
                url: url, 
                platform: platform, 
                analysis_data: result.data,
                generated_html: generatedHtml,
                user_data: existingUserData
            };
            
            displayAnalysisResult(keywordIndex, platform, urlIndex, result.data);
            
            console.log('🔍 상품 분석 완료:', {
                url: url,
                analysisData: result.data.title,
                generatedHtml: generatedHtml ? '생성됨' : '생성 실패'
            });
        } else {
            if (resultDiv) resultDiv.innerHTML = `<div style="color:red;padding:10px;">분석 실패: ${result.message || '알 수 없는 오류'}</div>`;
        }
    } catch (error) {
        console.error('상품 분석 오류:', error);
        if (resultDiv) resultDiv.innerHTML = `<div style="color:red;padding:10px;">상품 분석 중 오류가 발생했습니다: ${error.message}</div>`;
    }
}

function generateOptimizedMobileHtml(data) {
    if (!data) return null;
    const ratingDisplay = data.rating_display ? data.rating_display.replace(/⭐/g, '').replace(/[()]/g, '').trim() : '정보 없음';
    const formattedPrice = formatPrice(data.price);
    const htmlCode = `<div style="display: flex; justify-content: center; margin: 25px 0;">
    <div style="border: 2px solid #eee; padding: 30px; border-radius: 15px; background: #f9f9f9; box-shadow: 0 4px 8px rgba(0,0,0,0.1); max-width: 1000px; width: 100%;">
        
        <div style="display: grid; grid-template-columns: 400px 1fr; gap: 30px; align-items: start; margin-bottom: 25px;">
            <div style="text-align: center;">
                <img src="${data.image_url}" alt="${data.title}" style="width: 100%; max-width: 400px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.15);">
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div style="margin-bottom: 15px; text-align: center;">
                    <img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" style="width: 250px; height: 60px; object-fit: contain;" />
                </div>
                
                <h3 style="color: #1c1c1c; margin: 0 0 20px 0; font-size: 21px; font-weight: 600; line-height: 1.4; word-break: keep-all; overflow-wrap: break-word; text-align: center;">${data.title}</h3>
                
                <div style="background: linear-gradient(135deg, #e62e04 0%, #ff9900 100%); color: white; padding: 14px 30px; border-radius: 10px; font-size: 40px; font-weight: 700; text-align: center; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(230, 46, 4, 0.3);">
                    <strong>${formattedPrice}</strong>
                </div>
                
                <div style="color: #1c1c1c; font-size: 20px; display: flex; align-items: center; gap: 10px; margin-bottom: 15px; justify-content: center; flex-wrap: nowrap;">
                    <span style="color: #ff9900;">⭐⭐⭐⭐⭐</span>
                    <span>(고객만족도: ${ratingDisplay})</span>
                </div>
                
                <p style="color: #1c1c1c; font-size: 18px; margin: 0 0 15px 0; text-align: center;"><strong>📦 판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}</p>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 30px; width: 100%;">
            <a href="${data.affiliate_link}" target="_blank" rel="nofollow" style="text-decoration: none;">
                <picture>
                    <source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png">
                    <img src="https://novacents.com/tools/images/aliexpress-button-pc.png" 
                         alt="알리익스프레스에서 구매하기" 
                         style="max-width: 100%; height: auto; cursor: pointer;">
                </picture>
            </a>
        </div>
    </div>
</div>

<style>
@media (max-width: 1600px) {
    div[style*="grid-template-columns: 400px 1fr"] {
        display: block !important;
        grid-template-columns: none !important;
        gap: 15px !important;
    }
    
    img[style*="max-width: 400px"] {
        width: 95% !important;
        max-width: none !important;
        margin-bottom: 30px !important;
    }
    
    div[style*="gap: 20px"] {
        gap: 10px !important;
    }
    
    div[style*="text-align: center"] img[alt="AliExpress"] {
        display: block;
        margin: 0 !important;
    }
    div[style*="text-align: center"]:has(img[alt="AliExpress"]) {
        text-align: left !important;
        margin-bottom: 10px !important;
    }
    
    h3[style*="text-align: center"] {
        text-align: left !important;
        font-size: 18px !important;
        margin-bottom: 10px !important;
    }
    
    div[style*="font-size: 40px"] {
        font-size: 28px !important;
        padding: 12px 20px !important;
        margin-bottom: 10px !important;
    }
    
    div[style*="justify-content: center"][style*="flex-wrap: nowrap"] {
        justify-content: flex-start !important;
        font-size: 16px !important;
        margin-bottom: 10px !important;
        gap: 8px !important;
    }
    
    p[style*="text-align: center"] {
        text-align: left !important;
        font-size: 16px !important;
        margin-bottom: 10px !important;
    }
    
    div[style*="margin-top: 30px"] {
        margin-top: 15px !important;
    }
}

@media (max-width: 480px) {
    img[style*="width: 95%"] {
        width: 100% !important;
    }
    
    h3[style*="font-size: 18px"] {
        font-size: 16px !important;
    }
    
    div[style*="font-size: 28px"] {
        font-size: 24px !important;
    }
}
</style>`;
    
    return htmlCode;
}

function displayAnalysisResult(keywordIndex, platform, urlIndex, data) {
    const resultDiv = document.getElementById(`analysis-${keywordIndex}-${platform}-${urlIndex}`);
    if (!resultDiv) return;
    
    resultDiv.innerHTML = `<div class="product-preview"><img src="${data.image_url}" alt="${data.title}" onerror="this.style.display='none'"><div class="product-info-detail"><h4>${data.title}</h4><p><strong>가격:</strong> ${formatPrice(data.price)}</p><p><strong>평점:</strong> ${data.rating_display || '평점 정보 없음'}</p><p><strong>판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}</p></div></div>`;
    resultDiv.style.display = 'block';
    
    const productItemEdit = document.querySelector(`.keyword-item[data-keyword-index="${keywordIndex}"] .product-item-edit[data-product-index="${urlIndex}"]`);
    if (productItemEdit) {
        const toggleBtn = productItemEdit.querySelector('.product-details-toggle');
        if (toggleBtn) {
            const url = currentEditingData.keywords[keywordIndex].aliexpress[urlIndex];
            const productData = currentEditingData.keywords[keywordIndex].products_data.find(pd => pd.url === url);
            const hasDetails = productData && productData.user_data;
            toggleBtn.innerHTML = `📝 상품별 상세 정보 ${hasDetails ? '(입력됨)' : '(미입력)'}`;
        }
    }
}

async function saveEditedQueue() {
    try {
        console.log('💾 저장 시작 - 현재 편집 데이터:', currentEditingData);
        
        collectAllUserDetailsToCurrentData();
        
        const collectedKeywords = collectEditedKeywords();
        const updatedData = {
            title: document.getElementById('editTitle').value.trim(),
            category_id: parseInt(document.getElementById('editCategory').value),
            prompt_type: document.getElementById('editPromptType').value,
            keywords: collectedKeywords,
            user_details: {}
        };
        
        console.log('💾 저장할 데이터:', updatedData);
        
        if (!updatedData.title || updatedData.title.length < 5) { alert('제목은 5자 이상이어야 합니다.'); return; }
        if (!updatedData.keywords || updatedData.keywords.length === 0) { alert('최소 하나의 키워드가 필요합니다.'); return; }
        
        showLoading();
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=update_queue_item&queue_id=${encodeURIComponent(currentEditingQueueId)}&data=${encodeURIComponent(JSON.stringify(updatedData))}`
        });
        const result = await response.json();
        
        if (result.success) { 
            alert('항목이 성공적으로 업데이트되었습니다.'); 
            closeEditModal(); 
            loadQueue(); 
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

function collectAllUserDetailsToCurrentData() {
    console.log('📝 사용자 상세 정보 수집 시작...');
    
    currentEditingData.keywords.forEach((keyword, keywordIndex) => {
        if (keyword.aliexpress && Array.isArray(keyword.aliexpress)) {
            keyword.aliexpress.forEach((url, urlIndex) => {
                const productDetails = collectProductDetails(keywordIndex, 'aliexpress', urlIndex);
                
                if (keyword.products_data && Array.isArray(keyword.products_data)) {
                    const productData = keyword.products_data.find(pd => pd.url === url);
                    if (productData) {
                        if (Object.keys(productDetails).length > 0) {
                            productData.user_data = productDetails;
                            console.log(`📝 상품 ${keywordIndex}-${urlIndex} 상세 정보 업데이트:`, productDetails);
                        } else if (!productData.user_data) {
                            productData.user_data = null;
                        }
                    }
                }
            });
        }
    });
    
    console.log('📝 사용자 상세 정보 수집 완료');
}

function collectEditedKeywords() {
    const keywords = [];
    document.querySelectorAll('.keyword-item').forEach((item, keywordIndex) => {
        const name = item.querySelector('.keyword-item-title').value.trim();
        if (name) {
            const keywordData = currentEditingData.keywords[keywordIndex];
            const aliexpressUrls = [], products_data = [];
            
            item.querySelectorAll('.aliexpress-products .product-url-input').forEach((input, productIndex) => {
                const url = input.value.trim();
                if (url) {
                    aliexpressUrls.push(url);
                    
                    let existingData = null;
                    if (keywordData && keywordData.products_data && Array.isArray(keywordData.products_data)) {
                        existingData = keywordData.products_data.find(pd => pd.url === url);
                    }
                    
                    const productData = {
                        url: url, 
                        platform: 'aliexpress',
                        analysis_data: existingData ? existingData.analysis_data : null,
                        generated_html: existingData ? existingData.generated_html : null,
                        user_data: existingData ? existingData.user_data : null
                    };
                    
                    products_data.push(productData);
                }
            });
            
            keywords.push({name: name, aliexpress: aliexpressUrls, coupang: [], products_data: products_data});
        }
    });
    return keywords;
}

function collectProductDetails(keywordIndex, platform, productIndex) {
    const details = {}, specs = {}, efficiency = {}, usage = {}, benefits = {};
    
    addIfNotEmptyProduct(specs, 'main_function', `pd-main-function-${keywordIndex}-${platform}-${productIndex}`);
    addIfNotEmptyProduct(specs, 'size_capacity', `pd-size-capacity-${keywordIndex}-${platform}-${productIndex}`);
    addIfNotEmptyProduct(specs, 'color', `pd-color-${keywordIndex}-${platform}-${productIndex}`);
    addIfNotEmptyProduct(specs, 'material', `pd-material-${keywordIndex}-${platform}-${productIndex}`);
    addIfNotEmptyProduct(specs, 'power_battery', `pd-power-battery-${keywordIndex}-${platform}-${productIndex}`);
    if (Object.keys(specs).length > 0) details.specs = specs;
    
    addIfNotEmptyProduct(efficiency, 'problem_solving', `pd-problem-solving-${keywordIndex}-${platform}-${productIndex}`);
    addIfNotEmptyProduct(efficiency, 'time_saving', `pd-time-saving-${keywordIndex}-${platform}-${productIndex}`);
    addIfNotEmptyProduct(efficiency, 'space_efficiency', `pd-space-efficiency-${keywordIndex}-${platform}-${productIndex}`);
    addIfNotEmptyProduct(efficiency, 'cost_saving', `pd-cost-saving-${keywordIndex}-${platform}-${productIndex}`);
    if (Object.keys(efficiency).length > 0) details.efficiency = efficiency;
    
    addIfNotEmptyProduct(usage, 'usage_location', `pd-usage-location-${keywordIndex}-${platform}-${productIndex}`);
    addIfNotEmptyProduct(usage, 'usage_frequency', `pd-usage-frequency-${keywordIndex}-${platform}-${productIndex}`);
    addIfNotEmptyProduct(usage, 'target_users', `pd-target-users-${keywordIndex}-${platform}-${productIndex}`);
    if (Object.keys(usage).length > 0) details.usage = usage;
    
    const advantages = [];
    [`pd-advantage1-${keywordIndex}-${platform}-${productIndex}`, `pd-advantage2-${keywordIndex}-${platform}-${productIndex}`, `pd-advantage3-${keywordIndex}-${platform}-${productIndex}`].forEach(id => {
        const value = document.getElementById(id)?.value.trim();
        if (value) advantages.push(value);
    });
    if (advantages.length > 0) benefits.advantages = advantages;
    addIfNotEmptyProduct(benefits, 'precautions', `pd-precautions-${keywordIndex}-${platform}-${productIndex}`);
    if (Object.keys(benefits).length > 0) details.benefits = benefits;
    
    return details;
}

function addIfNotEmptyProduct(obj, key, elementId) {
    const element = document.getElementById(elementId);
    if (element) { const value = element.value.trim(); if (value) obj[key] = value; }
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    currentEditingQueueId = null;
    currentEditingData = null;
}

function refreshQueue() { loadQueue(); }
function showLoading() { document.getElementById('loadingOverlay').style.display = 'flex'; }
function hideLoading() { document.getElementById('loadingOverlay').style.display = 'none'; }

document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && document.getElementById('editModal').style.display === 'flex') closeEditModal(); });