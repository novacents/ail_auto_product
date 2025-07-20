/* Affiliate Editor JavaScript - 압축 최적화 버전 + 썸네일 URL 기능 */
let keywords = []; let currentKeywordIndex = -1; let currentProductIndex = -1; let currentProductData = null;
document.addEventListener('DOMContentLoaded', function() { updateUI(); handleScrollToTop(); });

// 중앙 정렬 성공 모달 함수들
function showSuccessModal(title, message, icon = '✅') {
    const modal = document.getElementById('successModal');
    const iconEl = document.getElementById('successIcon');
    const titleEl = document.getElementById('successTitle');
    const messageEl = document.getElementById('successMessage');
    
    iconEl.textContent = icon;
    titleEl.textContent = title;
    messageEl.textContent = message;
    
    modal.style.display = 'flex';
    
    // 2초 후 자동 닫기
    setTimeout(() => {
        closeSuccessModal();
    }, 2000);
}

function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    modal.style.display = 'none';
}

// 모달 외부 클릭 시 닫기
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('successModal');
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeSuccessModal();
        }
    });
});

// 상단으로 이동 버튼 관련 함수
function handleScrollToTop() {
    const scrollBtn = document.getElementById('scrollToTop');
    
    window.addEventListener('scroll', function() {
        if (window.pageYOffset > 300) {
            scrollBtn.classList.add('show');
        } else {
            scrollBtn.classList.remove('show');
        }
    });
}

function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

function formatPrice(price) { if (!price) return price; return price.replace(/₩(\d)/, '₩ $1'); }

function showDetailedError(title, message, debugData = null) {
    const existingModal = document.getElementById('errorModal');
    if (existingModal) { existingModal.remove(); }
    let fullMessage = message;
    if (debugData) { fullMessage += '\n\n=== 디버그 정보 ===\n'; fullMessage += JSON.stringify(debugData, null, 2); }
    const modal = document.createElement('div'); modal.id = 'errorModal';
    modal.style.cssText = `position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center;`;
    modal.innerHTML = `<div style="background: white; border-radius: 10px; padding: 30px; max-width: 800px; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3);"><h2 style="color: #dc3545; margin-bottom: 20px; font-size: 24px;">🚨 ${title}</h2><div style="margin-bottom: 20px;"><textarea id="errorContent" readonly style="width: 100%; height: 300px; padding: 15px; border: 1px solid #ddd; border-radius: 6px; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.4; background: #f8f9fa; resize: vertical;">${fullMessage}</textarea></div><div style="display: flex; gap: 10px; justify-content: flex-end;"><button onclick="copyErrorToClipboard()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">📋 복사하기</button><button onclick="closeErrorModal()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">닫기</button></div></div>`;
    document.body.appendChild(modal);
    modal.addEventListener('click', function(e) { if (e.target === modal) { closeErrorModal(); } });
}

function copyErrorToClipboard() {
    const errorContent = document.getElementById('errorContent'); errorContent.select(); document.execCommand('copy');
    const copyBtn = event.target; const originalText = copyBtn.textContent; copyBtn.textContent = '✅ 복사됨!'; copyBtn.style.background = '#28a745';
    setTimeout(() => { copyBtn.textContent = originalText; copyBtn.style.background = '#007bff'; }, 2000);
}

function closeErrorModal() { const modal = document.getElementById('errorModal'); if (modal) { modal.remove(); } }
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeErrorModal(); } });

function toggleTitleGenerator() { const generator = document.getElementById('titleGenerator'); generator.style.display = generator.style.display === 'none' ? 'block' : 'none'; }

async function generateTitles() {
    const keywordsInput = document.getElementById('titleKeyword').value.trim();
    if (!keywordsInput) { showDetailedError('입력 오류', '키워드를 입력해주세요.'); return; }
    const loading = document.getElementById('titleLoading'); const titlesDiv = document.getElementById('generatedTitles');
    loading.style.display = 'block'; titlesDiv.style.display = 'none';
    try {
        const formData = new FormData(); formData.append('action', 'generate_titles'); formData.append('keywords', keywordsInput);
        const response = await fetch('', { method: 'POST', body: formData }); const result = await response.json();
        if (result.success) { displayTitles(result.titles); } else { showDetailedError('제목 생성 실패', result.message); }
    } catch (error) { showDetailedError('제목 생성 오류', '제목 생성 중 오류가 발생했습니다.', { 'error': error.message, 'keywords': keywordsInput }); }
    finally { loading.style.display = 'none'; }
}

function displayTitles(titles) {
    const optionsDiv = document.getElementById('titleOptions'); const titlesDiv = document.getElementById('generatedTitles');
    optionsDiv.innerHTML = ''; titles.forEach((title) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'title-option'; button.textContent = title; button.onclick = () => selectTitle(title); optionsDiv.appendChild(button); });
    titlesDiv.style.display = 'block';
}

function selectTitle(title) { document.getElementById('title').value = title; document.getElementById('titleGenerator').style.display = 'none'; }

function toggleKeywordInput() {
    const inputSection = document.getElementById('keywordInputSection'); const isVisible = inputSection.classList.contains('show');
    if (isVisible) { inputSection.classList.remove('show'); } else { inputSection.classList.add('show'); document.getElementById('newKeywordInput').focus(); }
}

function addKeywordFromInput() {
    const input = document.getElementById('newKeywordInput'); const name = input.value.trim();
    if (name) { const keyword = { name: name, products: [] }; keywords.push(keyword); updateUI(); input.value = ''; document.getElementById('keywordInputSection').classList.remove('show'); addProduct(keywords.length - 1); } else { showDetailedError('입력 오류', '키워드를 입력해주세요.'); }
}

function cancelKeywordInput() { const input = document.getElementById('newKeywordInput'); input.value = ''; document.getElementById('keywordInputSection').classList.remove('show'); }

document.addEventListener('DOMContentLoaded', function() { document.getElementById('newKeywordInput').addEventListener('keypress', function(e) { if (e.key === 'Enter') { e.preventDefault(); addKeywordFromInput(); } }); });

function addKeyword() { toggleKeywordInput(); }

function addProduct(keywordIndex) {
    const product = { 
        id: Date.now() + Math.random(), 
        url: '', 
        name: `상품 ${keywords[keywordIndex].products.length + 1}`, 
        status: 'empty', 
        analysisData: null, 
        userData: {}, 
        isSaved: false, 
        generatedHtml: null,
        thumbnailUrl: '' // 썸네일 URL 필드 추가
    };
    keywords[keywordIndex].products.push(product); updateUI(); selectProduct(keywordIndex, keywords[keywordIndex].products.length - 1);
}

// 키워드 삭제 함수
function deleteKeyword(keywordIndex) {
    if (confirm(`"${keywords[keywordIndex].name}" 키워드를 삭제하시겠습니까?\n이 키워드에 포함된 모든 상품도 함께 삭제됩니다.`)) {
        // 현재 선택된 키워드가 삭제되는 경우 초기화
        if (currentKeywordIndex === keywordIndex) {
            currentKeywordIndex = -1;
            currentProductIndex = -1;
            document.getElementById('topNavigation').style.display = 'none';
            document.getElementById('productDetailContent').style.display = 'none';
            document.getElementById('currentProductTitle').textContent = '상품을 선택해주세요';
            document.getElementById('currentProductSubtitle').textContent = '왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.';
        } else if (currentKeywordIndex > keywordIndex) {
            // 삭제된 키워드보다 뒤에 있는 키워드가 선택된 경우 인덱스 조정
            currentKeywordIndex--;
        }
        
        keywords.splice(keywordIndex, 1);
        updateUI();
    }
}

// 상품 삭제 함수
function deleteProduct(keywordIndex, productIndex) {
    const product = keywords[keywordIndex].products[productIndex];
    if (confirm(`"${product.name}" 상품을 삭제하시겠습니까?`)) {
        // 현재 선택된 상품이 삭제되는 경우 초기화
        if (currentKeywordIndex === keywordIndex && currentProductIndex === productIndex) {
            currentKeywordIndex = -1;
            currentProductIndex = -1;
            document.getElementById('topNavigation').style.display = 'none';
            document.getElementById('productDetailContent').style.display = 'none';
            document.getElementById('currentProductTitle').textContent = '상품을 선택해주세요';
            document.getElementById('currentProductSubtitle').textContent = '왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.';
        } else if (currentKeywordIndex === keywordIndex && currentProductIndex > productIndex) {
            // 같은 키워드의 뒤에 있는 상품이 선택된 경우 인덱스 조정
            currentProductIndex--;
        }
        
        keywords[keywordIndex].products.splice(productIndex, 1);
        updateUI();
    }
}

// 사용자 입력 필드 초기화 함수
function clearUserInputFields() {
    // 기능 및 스펙 초기화
    document.getElementById('main_function').value = '';
    document.getElementById('size_capacity').value = '';
    document.getElementById('color').value = '';
    document.getElementById('material').value = '';
    document.getElementById('power_battery').value = '';
    
    // 효율성 분석 초기화
    document.getElementById('problem_solving').value = '';
    document.getElementById('time_saving').value = '';
    document.getElementById('space_efficiency').value = '';
    document.getElementById('cost_saving').value = '';
    
    // 사용 시나리오 초기화
    document.getElementById('usage_location').value = '';
    document.getElementById('usage_frequency').value = '';
    document.getElementById('target_users').value = '';
    document.getElementById('usage_method').value = '';
    
    // 장점 및 주의사항 초기화
    document.getElementById('advantage1').value = '';
    document.getElementById('advantage2').value = '';
    document.getElementById('advantage3').value = '';
    document.getElementById('precautions').value = '';
    
    // 썸네일 URL 초기화
    const thumbnailInput = document.getElementById('thumbnailUrl');
    const thumbnailPreview = document.getElementById('thumbnailPreview');
    if (thumbnailInput) thumbnailInput.value = '';
    if (thumbnailPreview) {
        thumbnailPreview.src = '';
        thumbnailPreview.classList.remove('show');
    }
}

// 저장된 사용자 입력 필드 로드 함수
function loadUserInputFields(userData) {
    if (!userData) return;
    
    // 기능 및 스펙 로드
    if (userData.specs) {
        document.getElementById('main_function').value = userData.specs.main_function || '';
        document.getElementById('size_capacity').value = userData.specs.size_capacity || '';
        document.getElementById('color').value = userData.specs.color || '';
        document.getElementById('material').value = userData.specs.material || '';
        document.getElementById('power_battery').value = userData.specs.power_battery || '';
    }
    
    // 효율성 분석 로드
    if (userData.efficiency) {
        document.getElementById('problem_solving').value = userData.efficiency.problem_solving || '';
        document.getElementById('time_saving').value = userData.efficiency.time_saving || '';
        document.getElementById('space_efficiency').value = userData.efficiency.space_efficiency || '';
        document.getElementById('cost_saving').value = userData.efficiency.cost_saving || '';
    }
    
    // 사용 시나리오 로드
    if (userData.usage) {
        document.getElementById('usage_location').value = userData.usage.usage_location || '';
        document.getElementById('usage_frequency').value = userData.usage.usage_frequency || '';
        document.getElementById('target_users').value = userData.usage.target_users || '';
        document.getElementById('usage_method').value = userData.usage.usage_method || '';
    }
    
    // 장점 및 주의사항 로드
    if (userData.benefits) {
        if (userData.benefits.advantages) {
            document.getElementById('advantage1').value = userData.benefits.advantages[0] || '';
            document.getElementById('advantage2').value = userData.benefits.advantages[1] || '';
            document.getElementById('advantage3').value = userData.benefits.advantages[2] || '';
        }
        document.getElementById('precautions').value = userData.benefits.precautions || '';
    }
    
    // 썸네일 URL 로드
    if (userData.thumbnailUrl) {
        const thumbnailInput = document.getElementById('thumbnailUrl');
        const thumbnailPreview = document.getElementById('thumbnailPreview');
        if (thumbnailInput) {
            thumbnailInput.value = userData.thumbnailUrl;
            if (thumbnailPreview) {
                thumbnailPreview.src = userData.thumbnailUrl;
                thumbnailPreview.classList.add('show');
            }
        }
    }
}

// 썸네일 URL 변경 시 미리보기 업데이트
function updateThumbnailPreview() {
    const thumbnailInput = document.getElementById('thumbnailUrl');
    const thumbnailPreview = document.getElementById('thumbnailPreview');
    
    if (thumbnailInput && thumbnailPreview) {
        const url = thumbnailInput.value.trim();
        if (url && (url.startsWith('http://') || url.startsWith('https://'))) {
            thumbnailPreview.src = url;
            thumbnailPreview.classList.add('show');
            
            // 현재 선택된 상품에 썸네일 URL 저장
            if (currentKeywordIndex !== -1 && currentProductIndex !== -1) {
                keywords[currentKeywordIndex].products[currentProductIndex].thumbnailUrl = url;
            }
        } else {
            thumbnailPreview.src = '';
            thumbnailPreview.classList.remove('show');
        }
    }
}

function selectProduct(keywordIndex, productIndex) {
    currentKeywordIndex = keywordIndex; currentProductIndex = productIndex; const product = keywords[keywordIndex].products[productIndex];
    document.querySelectorAll('.product-item').forEach(item => { item.classList.remove('active'); });
    const selectedItem = document.querySelector(`[data-keyword="${keywordIndex}"][data-product="${productIndex}"]`);
    if (selectedItem) { selectedItem.classList.add('active'); } 
    updateDetailPanel(product);
    
    // 상품 선택 시 상단 네비게이션 표시
    document.getElementById('topNavigation').style.display = 'block';
}

function updateDetailPanel(product) {
    const titleEl = document.getElementById('currentProductTitle'); const subtitleEl = document.getElementById('currentProductSubtitle');
    const contentEl = document.getElementById('productDetailContent'); const urlInput = document.getElementById('productUrl');
    titleEl.textContent = product.name; subtitleEl.textContent = `키워드: ${keywords[currentKeywordIndex].name}`; urlInput.value = product.url || '';
    
    // 사용자 입력 필드 초기화 또는 기존 데이터 로드
    if (product.userData && Object.keys(product.userData).length > 0) {
        // 기존에 저장된 데이터가 있으면 로드
        loadUserInputFields(product.userData);
    } else {
        // 새 상품이면 모든 필드 초기화
        clearUserInputFields();
    }
    
    // 썸네일 URL 로드
    const thumbnailInput = document.getElementById('thumbnailUrl');
    const thumbnailPreview = document.getElementById('thumbnailPreview');
    if (thumbnailInput && product.thumbnailUrl) {
        thumbnailInput.value = product.thumbnailUrl;
        if (thumbnailPreview) {
            thumbnailPreview.src = product.thumbnailUrl;
            thumbnailPreview.classList.add('show');
        }
    }
    
    if (product.analysisData) { showAnalysisResult(product.analysisData); } else { hideAnalysisResult(); } contentEl.style.display = 'block';
}

async function analyzeProduct() {
    const url = document.getElementById('productUrl').value.trim();
    if (!url) { showDetailedError('입력 오류', '상품 URL을 입력해주세요.'); return; }
    if (currentKeywordIndex === -1 || currentProductIndex === -1) { showDetailedError('선택 오류', '상품을 먼저 선택해주세요.'); return; }
    const product = keywords[currentKeywordIndex].products[currentProductIndex];
    product.url = url; product.status = 'analyzing'; updateUI();
    try {
        const response = await fetch('product_analyzer_v2.php', { method: 'POST', headers: { 'Content-Type': 'application/json', }, body: JSON.stringify({ action: 'analyze_product', url: url, platform: 'aliexpress' }) });
        if (!response.ok) { throw new Error(`HTTP 오류: ${response.status} ${response.statusText}`); }
        const responseText = await response.text(); let result;
        try { result = JSON.parse(responseText); } catch (parseError) { showDetailedError('JSON 파싱 오류', '서버 응답을 파싱할 수 없습니다.', { 'parseError': parseError.message, 'responseText': responseText, 'responseLength': responseText.length, 'url': url, 'timestamp': new Date().toISOString() }); product.status = 'error'; updateUI(); return; }
        if (result.success) { 
            product.analysisData = result.data; 
            product.status = 'completed'; 
            product.name = result.data.title || `상품 ${currentProductIndex + 1}`; 
            currentProductData = result.data; 
            showAnalysisResult(result.data); 
            
            // HTML 생성 및 저장
            const generatedHtml = generateOptimizedMobileHtml(result.data);
            product.generatedHtml = generatedHtml;
            console.log('Generated HTML saved to product:', generatedHtml);
        } else { 
            product.status = 'error'; 
            showDetailedError('상품 분석 실패', result.message || '알 수 없는 오류가 발생했습니다.', { 'success': result.success, 'message': result.message, 'debug_info': result.debug_info || null, 'raw_output': result.raw_output || null, 'url': url, 'platform': 'aliexpress', 'timestamp': new Date().toISOString(), 'mobile_optimized': true }); 
        }
    } catch (error) { product.status = 'error'; showDetailedError('네트워크 오류', '상품 분석 중 네트워크 오류가 발생했습니다.', { 'error': error.message, 'stack': error.stack, 'url': url, 'timestamp': new Date().toISOString() }); }
    updateUI();
}

function showAnalysisResult(data) {
    const resultEl = document.getElementById('analysisResult'); const cardEl = document.getElementById('productCard');
    const ratingDisplay = data.rating_display ? data.rating_display.replace(/⭐/g, '').replace(/[()]/g, '').trim() : '정보 없음';
    const formattedPrice = formatPrice(data.price);
    cardEl.innerHTML = `<div class="product-content-split"><div class="product-image-large"><img src="${data.image_url}" alt="${data.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><div class="aliexpress-logo-right"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" /></div><h3 class="product-title-right">${data.title}</h3><div class="product-price-right">${formattedPrice}</div><div class="product-rating-right"><span class="rating-stars">⭐⭐⭐⭐⭐</span><span>(고객만족도: ${ratingDisplay})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}</div><div class="product-extra-info-right"><div class="info-row"><span class="info-label">상품 ID</span><span class="info-value">${data.product_id}</span></div><div class="info-row"><span class="info-label">플랫폼</span><span class="info-value">${data.platform}</span></div></div></div></div><div class="purchase-button-full"><a href="${data.affiliate_link}" target="_blank" rel="nofollow"><picture><source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></picture></a></div>`;
    resultEl.classList.add('show');
}

// HTML 생성 함수 - HTML 반환하도록 변경
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
    
    const previewHtml = `<div class="preview-product-card"><div class="preview-card-content"><div class="product-content-split"><div class="product-image-large"><img src="${data.image_url}" alt="${data.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><div class="aliexpress-logo-right"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" /></div><h3 class="product-title-right">${data.title}</h3><div class="product-price-right">${formattedPrice}</div><div class="product-rating-right"><span class="rating-stars">⭐⭐⭐⭐⭐</span><span>(고객만족도: ${ratingDisplay})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}</div></div></div><div class="purchase-button-full"><a href="${data.affiliate_link}" target="_blank" rel="nofollow"><picture><source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></picture></a></div></div></div>`;
    document.getElementById('htmlPreview').innerHTML = previewHtml; 
    document.getElementById('htmlCode').textContent = htmlCode; 
    document.getElementById('htmlSourceSection').style.display = 'block';
    
    // 생성된 HTML 반환
    return htmlCode;
}

async function copyHtmlSource() {
    const htmlCode = document.getElementById('htmlCode').textContent; const copyBtn = document.querySelector('.copy-btn');
    try { await navigator.clipboard.writeText(htmlCode); const originalText = copyBtn.textContent; copyBtn.textContent = '✅ 복사됨!'; copyBtn.classList.add('copied'); setTimeout(() => { copyBtn.textContent = originalText; copyBtn.classList.remove('copied'); }, 2000); } catch (error) { const codeEl = document.getElementById('htmlCode'); const range = document.createRange(); range.selectNodeContents(codeEl); const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range); showDetailedError('복사 알림', 'HTML 소스가 선택되었습니다. Ctrl+C로 복사하세요.'); }
}

function hideAnalysisResult() { const resultEl = document.getElementById('analysisResult'); resultEl.classList.remove('show'); document.getElementById('htmlSourceSection').style.display = 'none'; }

function updateUI() { updateProductsList(); updateProgress(); }

function updateProductsList() {
    const listEl = document.getElementById('productsList');
    if (keywords.length === 0) { listEl.innerHTML = `<div class="empty-state"><h3>📦 상품이 없습니다</h3><p>위의 "키워드 추가" 버튼을 클릭하여<br>첫 번째 키워드를 추가해보세요!</p></div>`; return; }
    let html = ''; 
    keywords.forEach((keyword, keywordIndex) => { 
        html += `<div class="keyword-group">
            <div class="keyword-header">
                <div class="keyword-info">
                    <span class="keyword-name">📁 ${keyword.name}</span>
                    <span class="product-count">${keyword.products.length}개</span>
                </div>
                <div class="keyword-actions">
                    <button type="button" class="btn btn-success btn-small" onclick="addProduct(${keywordIndex})">+상품</button>
                    <button type="button" class="btn btn-danger btn-small" onclick="deleteKeyword(${keywordIndex})">🗑️</button>
                </div>
            </div>`; 
        keyword.products.forEach((product, productIndex) => { 
            const statusIcon = getStatusIcon(product.status, product.isSaved); 
            html += `<div class="product-item" data-keyword="${keywordIndex}" data-product="${productIndex}" onclick="selectProduct(${keywordIndex}, ${productIndex})">
                <span class="product-status">${statusIcon}</span>
                <span class="product-name">${product.name}</span>
                <div class="product-actions">
                    <button type="button" class="btn btn-danger btn-small" onclick="event.stopPropagation(); deleteProduct(${keywordIndex}, ${productIndex})">🗑️</button>
                </div>
            </div>`; 
        }); 
        html += '</div>'; 
    }); 
    listEl.innerHTML = html;
}

// 상태 아이콘 함수 - 저장 여부까지 고려
function getStatusIcon(status, isSaved = false) { 
    switch (status) { 
        case 'completed': 
            return isSaved ? '✅' : '🔍'; // 분석 완료 + 저장됨 = ✅, 분석만 완료 = 🔍
        case 'analyzing': 
            return '🔄'; 
        case 'error': 
            return '⚠️'; 
        default: 
            return '❌'; 
    } 
}

// 진행률 계산 함수 - 저장된 상품만 완료로 인정
function updateProgress() {
    const totalProducts = keywords.reduce((sum, keyword) => sum + keyword.products.length, 0);
    const completedProducts = keywords.reduce((sum, keyword) => sum + keyword.products.filter(p => p.isSaved).length, 0); // isSaved가 true인 상품만 완료로 인정
    const percentage = totalProducts > 0 ? (completedProducts / totalProducts) * 100 : 0;
    document.getElementById('progressFill').style.width = percentage + '%'; document.getElementById('progressText').textContent = `${completedProducts}/${totalProducts} 완성`;
}

// 사용자 상세 정보 수집 함수들
function collectUserInputDetails() {
    const details = {};
    
    // 기능 및 스펙
    const specs = {};
    addIfNotEmpty(specs, 'main_function', 'main_function');
    addIfNotEmpty(specs, 'size_capacity', 'size_capacity');
    addIfNotEmpty(specs, 'color', 'color');
    addIfNotEmpty(specs, 'material', 'material');
    addIfNotEmpty(specs, 'power_battery', 'power_battery');
    if (Object.keys(specs).length > 0) details.specs = specs;
    
    // 효율성 분석
    const efficiency = {};
    addIfNotEmpty(efficiency, 'problem_solving', 'problem_solving');
    addIfNotEmpty(efficiency, 'time_saving', 'time_saving');
    addIfNotEmpty(efficiency, 'space_efficiency', 'space_efficiency');
    addIfNotEmpty(efficiency, 'cost_saving', 'cost_saving');
    if (Object.keys(efficiency).length > 0) details.efficiency = efficiency;
    
    // 사용 시나리오
    const usage = {};
    addIfNotEmpty(usage, 'usage_location', 'usage_location');
    addIfNotEmpty(usage, 'usage_frequency', 'usage_frequency');
    addIfNotEmpty(usage, 'target_users', 'target_users');
    addIfNotEmpty(usage, 'usage_method', 'usage_method');
    if (Object.keys(usage).length > 0) details.usage = usage;
    
    // 장점 및 주의사항
    const benefits = {};
    const advantages = [];
    ['advantage1', 'advantage2', 'advantage3'].forEach(id => {
        const value = document.getElementById(id)?.value.trim();
        if (value) advantages.push(value);
    });
    if (advantages.length > 0) benefits.advantages = advantages;
    addIfNotEmpty(benefits, 'precautions', 'precautions');
    if (Object.keys(benefits).length > 0) details.benefits = benefits;
    
    // 썸네일 URL 추가
    const thumbnailUrl = document.getElementById('thumbnailUrl')?.value.trim();
    if (thumbnailUrl) details.thumbnailUrl = thumbnailUrl;
    
    return details;
}

function addIfNotEmpty(obj, key, elementId) {
    const value = document.getElementById(elementId)?.value.trim();
    if (value) obj[key] = value;
}

// 키워드 데이터 수집 함수 - 분석 데이터와 HTML도 포함
function collectKeywordsData() {
    console.log('collectKeywordsData: Starting comprehensive keyword data collection...');
    const keywordsData = [];
    
    keywords.forEach((keyword, keywordIndex) => {
        console.log(`Processing keyword ${keywordIndex}: ${keyword.name}`);
        
        // keyword_processor.php가 기대하는 형식으로 변경
        const keywordData = {
            name: keyword.name,
            coupang: [], // 쿠팡 링크 배열 (현재는 사용하지 않음)
            aliexpress: [], // 알리익스프레스 링크 배열
            products_data: [], // 상품 분석 데이터와 HTML 정보
            thumbnailUrl: '' // 썸네일 URL 추가
        };
        
        // 각 키워드의 상품 URL들과 분석 데이터 수집
        keyword.products.forEach((product, productIndex) => {
            console.log(`  Checking product ${productIndex}: "${product.url}"`);
            
            // 더 엄격한 URL 유효성 검사
            if (product.url && 
                typeof product.url === 'string' && 
                product.url.trim() !== '' && 
                product.url.trim() !== 'undefined' && 
                product.url.trim() !== 'null' &&
                product.url.includes('aliexpress.com')) {
                
                const trimmedUrl = product.url.trim();
                console.log(`    Valid URL found: ${trimmedUrl}`);
                keywordData.aliexpress.push(trimmedUrl);
                
                // 상품 분석 데이터와 HTML 소스도 함께 수집
                const productData = {
                    url: trimmedUrl,
                    analysis_data: product.analysisData || null,
                    generated_html: product.generatedHtml || null,
                    user_data: product.userData || {},
                    thumbnail_url: product.thumbnailUrl || ''
                };
                
                keywordData.products_data.push(productData);
                console.log(`    Product data collected:`, {
                    hasAnalysisData: !!product.analysisData,
                    hasGeneratedHtml: !!product.generatedHtml,
                    hasUserData: !!(product.userData && Object.keys(product.userData).length > 0),
                    hasThumbnailUrl: !!product.thumbnailUrl
                });
            } else {
                console.log(`    Invalid or empty URL skipped: "${product.url}"`);
            }
        });
        
        // 유효한 링크가 있는 키워드만 추가
        if (keywordData.aliexpress.length > 0) {
            console.log(`  Keyword "${keyword.name}" added with ${keywordData.aliexpress.length} valid links and ${keywordData.products_data.length} product data entries`);
            keywordsData.push(keywordData);
        } else {
            console.log(`  Keyword "${keyword.name}" skipped - no valid links`);
        }
    });
    
    console.log('Final comprehensive keywords data:', keywordsData);
    console.log('Total keywords with valid data:', keywordsData.length);
    
    return keywordsData;
}

function validateAndSubmitData(formData, isPublishNow = false) {
    console.log('validateAndSubmitData: Starting validation...');
    console.log('Form data:', formData);
    
    // 기본 검증
    if (!formData.title || formData.title.length < 5) {
        showDetailedError('입력 오류', '제목은 5자 이상이어야 합니다.');
        return false;
    }
    
    if (!formData.keywords || formData.keywords.length === 0) {
        showDetailedError('입력 오류', '최소 하나의 키워드와 상품 링크가 필요합니다.');
        return false;
    }
    
    // 각 키워드에 유효한 링크가 있는지 확인
    let hasValidLinks = false;
    let totalValidLinks = 0;
    let totalProductsWithData = 0;
    
    formData.keywords.forEach(keyword => {
        if (keyword.aliexpress && keyword.aliexpress.length > 0) {
            // 빈 문자열이 아닌 실제 URL만 카운트
            const validUrls = keyword.aliexpress.filter(url => 
                url && 
                typeof url === 'string' && 
                url.trim() !== '' && 
                url.includes('aliexpress.com')
            );
            
            if (validUrls.length > 0) {
                hasValidLinks = true;
                totalValidLinks += validUrls.length;
                totalProductsWithData += keyword.products_data ? keyword.products_data.length : 0;
                console.log(`Keyword "${keyword.name}" has ${validUrls.length} valid URLs and ${keyword.products_data ? keyword.products_data.length : 0} product data entries`);
            }
        }
    });
    
    if (!hasValidLinks || totalValidLinks === 0) {
        showDetailedError('입력 오류', '각 키워드에 최소 하나의 유효한 알리익스프레스 상품 링크가 있어야 합니다.\n\n현재 상태:\n- URL을 입력했는지 확인하세요\n- 분석 버튼을 클릭했는지 확인하세요\n- 알리익스프레스 URL인지 확인하세요');
        return false;
    }
    
    console.log(`Validation passed! Total valid links: ${totalValidLinks}, Products with data: ${totalProductsWithData}`);
    
    if (isPublishNow) {
        // 즉시 발행용 AJAX 전송
        return true;
    } else {
        // 폼 데이터를 hidden input으로 설정하여 전송
        const form = document.getElementById('affiliateForm');
        
        // 기존 hidden input 제거
        const existingInputs = form.querySelectorAll('input[type="hidden"]');
        existingInputs.forEach(input => input.remove());
        
        // 새로운 hidden input들 추가
        const hiddenInputs = [
            { name: 'title', value: formData.title },
            { name: 'category', value: formData.category },
            { name: 'prompt_type', value: formData.prompt_type },
            { name: 'keywords', value: JSON.stringify(formData.keywords) },
            { name: 'user_details', value: JSON.stringify(formData.user_details) }
        ];
        
        hiddenInputs.forEach(({ name, value }) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });
        
        console.log('Hidden inputs added, submitting form...');
        
        // 폼 전송
        form.submit();
        return true;
    }
}

// 즉시 발행 기능
async function publishNow() {
    console.log('🚀 즉시 발행을 시작합니다...');
    
    // 1. 기존 키워드 데이터 수집 (분석 데이터와 HTML 포함)
    const keywordsData = collectKeywordsData();
    
    // 2. 사용자 입력 상세 정보 수집 (빈 값 제외)
    const userDetails = collectUserInputDetails();
    
    // 3. 기본 정보 수집 (프롬프트 타입 추가)
    const formData = {
        title: document.getElementById('title').value.trim(),
        category: document.getElementById('category').value,
        prompt_type: document.getElementById('prompt_type').value,
        keywords: keywordsData,
        user_details: userDetails
    };
    
    console.log('즉시 발행용 종합 데이터:', formData);
    
    // 4. 검증
    if (!validateAndSubmitData(formData, true)) {
        return;
    }
    
    // 5. 로딩 표시
    const loadingOverlay = document.getElementById('loadingOverlay');
    const publishBtn = document.getElementById('publishNowBtn');
    
    loadingOverlay.style.display = 'flex';
    publishBtn.disabled = true;
    publishBtn.textContent = '발행 중...';
    
    try {
        // 6. 즉시 발행 요청 - keyword_processor.php로 직접 전송
        const response = await fetch('keyword_processor.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                title: formData.title,
                category: formData.category,
                prompt_type: formData.prompt_type,
                keywords: JSON.stringify(formData.keywords),
                user_details: JSON.stringify(formData.user_details),
                publish_mode: 'immediate'
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccessModal('발행 완료!', '글이 성공적으로 발행되었습니다!', '🚀');
            if (result.post_url) {
                window.open(result.post_url, '_blank');
            }
        } else {
            showDetailedError('발행 실패', result.message || '알 수 없는 오류가 발생했습니다.');
        }
        
    } catch (error) {
        showDetailedError('발행 오류', '즉시 발행 중 오류가 발생했습니다.', {
            'error': error.message,
            'timestamp': new Date().toISOString()
        });
    } finally {
        // 7. 로딩 해제
        loadingOverlay.style.display = 'none';
        publishBtn.disabled = false;
        publishBtn.textContent = '🚀 즉시 발행';
    }
}

// 저장 기능 (현재 상품만 저장) - isSaved 플래그 추가 + 중앙 정렬 모달 적용
function saveCurrentProduct() {
    if (currentKeywordIndex === -1 || currentProductIndex === -1) {
        showDetailedError('선택 오류', '저장할 상품을 먼저 선택해주세요.');
        return;
    }
    
    const product = keywords[currentKeywordIndex].products[currentProductIndex];
    
    // 현재 상품의 URL 업데이트
    const url = document.getElementById('productUrl').value.trim();
    if (url) {
        product.url = url;
    }
    
    // 썸네일 URL 업데이트
    const thumbnailUrl = document.getElementById('thumbnailUrl')?.value.trim();
    if (thumbnailUrl) {
        product.thumbnailUrl = thumbnailUrl;
    }
    
    // 사용자 입력 상세 정보 수집하여 개별 상품에 저장
    const userDetails = collectUserInputDetails();
    product.userData = userDetails;
    
    // 저장 완료 플래그 설정
    product.isSaved = true;
    
    // UI 업데이트
    updateUI();
    
    // 중앙 정렬 성공 모달로 변경
    showSuccessModal('저장 완료!', '현재 상품 정보가 성공적으로 저장되었습니다.', '💾');
    console.log('저장된 상품 정보:', product);
}

// 완료 기능 (전체 데이터를 대기열에 저장) - 분석 데이터와 HTML 포함
function completeProduct() {
    console.log('✅ 전체 데이터를 대기열에 저장합니다...');
    
    // 1. 기존 키워드 데이터 수집 (분석 데이터와 HTML 포함)
    const keywordsData = collectKeywordsData();
    
    // 2. 사용자 입력 상세 정보 수집 (빈 값 제외)
    const userDetails = collectUserInputDetails();
    
    console.log('수집된 사용자 상세 정보:', userDetails);
    
    // 3. 기본 정보 수집 (프롬프트 타입 추가)
    const formData = {
        title: document.getElementById('title').value.trim(),
        category: document.getElementById('category').value,
        prompt_type: document.getElementById('prompt_type').value,
        keywords: keywordsData,
        user_details: userDetails // 새로 추가되는 사용자 상세 정보
    };
    
    console.log('전체 수집된 종합 데이터:', formData);
    
    // 4. 검증 및 전송
    if (validateAndSubmitData(formData)) {
        // validateAndSubmitData 내부에서 폼 전송이 이루어짐
        console.log('대기열 저장 요청이 전송되었습니다.');
    }
}

function previousProduct() {
    if (currentKeywordIndex === -1 || currentProductIndex === -1) return;
    
    const currentKeyword = keywords[currentKeywordIndex];
    if (currentProductIndex > 0) {
        selectProduct(currentKeywordIndex, currentProductIndex - 1);
    } else if (currentKeywordIndex > 0) {
        const prevKeyword = keywords[currentKeywordIndex - 1];
        selectProduct(currentKeywordIndex - 1, prevKeyword.products.length - 1);
    }
}

function nextProduct() {
    if (currentKeywordIndex === -1 || currentProductIndex === -1) return;
    
    const currentKeyword = keywords[currentKeywordIndex];
    if (currentProductIndex < currentKeyword.products.length - 1) {
        selectProduct(currentKeywordIndex, currentProductIndex + 1);
    } else if (currentKeywordIndex < keywords.length - 1) {
        selectProduct(currentKeywordIndex + 1, 0);
    }
}

document.getElementById('titleKeyword').addEventListener('keypress', function(e) { if (e.key === 'Enter') { e.preventDefault(); generateTitles(); } });