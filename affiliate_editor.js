let kw=[],cKI=-1,cPI=-1,cPD=null;
let draggedElement=null,draggedType=null,draggedIndex=null,draggedKeywordIndex=null;
document.addEventListener('DOMContentLoaded',function(){updateUI();handleScrollToTop();checkForNewImageUrl();setupFileInput();});
function showSuccessModal(t,m,i='✅'){document.getElementById('successIcon').textContent=i;document.getElementById('successTitle').textContent=t;document.getElementById('successMessage').textContent=m;document.getElementById('successModal').style.display='flex';setTimeout(()=>{closeSuccessModal();},2000);}
function closeSuccessModal(){document.getElementById('successModal').style.display='none';}
document.addEventListener('DOMContentLoaded',function(){document.getElementById('successModal').addEventListener('click',function(e){if(e.target===document.getElementById('successModal'))closeSuccessModal();});});
function handleScrollToTop(){window.addEventListener('scroll',function(){document.getElementById('scrollToTop').classList.toggle('show',window.pageYOffset>300);});}
function scrollToTop(){window.scrollTo({top:0,behavior:'smooth'});}
function openImageSelector(){window.open('image_selector.php','_blank');}
window.addEventListener('focus',function(){checkForNewImageUrl();});
function checkForNewImageUrl(){const newUrl=localStorage.getItem('selected_image_url');if(newUrl){document.getElementById('thumbnail_url').value=newUrl;localStorage.removeItem('selected_image_url');}}
function formatPrice(p){return p?p.replace(/₩(\d)/,'₩ $1'):p;}
function formatRatingDisplay(rating){if(!rating)return {stars:'',percent:'정보 없음'};const cleanRating=rating.trim();if(cleanRating.includes('⭐')){const starsMatch=cleanRating.match(/(⭐+)/);const percentMatch=cleanRating.match(/(\d+(?:\.\d+)?%)/);return {stars:starsMatch?starsMatch[1]:'⭐⭐⭐⭐⭐',percent:percentMatch?percentMatch[1]:'정보 없음'};}return {stars:'⭐⭐⭐⭐⭐',percent:cleanRating};}
function showDetailedError(t,m,d=null){const em=document.getElementById('errorModal');if(em)em.remove();let fm=m;if(d)fm+='\n\n=== 디버그 정보 ===\n'+JSON.stringify(d,null,2);const md=document.createElement('div');md.id='errorModal';md.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center;';md.innerHTML=`<div style="background:white;border-radius:10px;padding:30px;max-width:600px;min-width:500px;max-height:80vh;overflow-y:auto;box-shadow:0 10px 30px rgba(0,0,0,0.3);"><h2 style="color:#dc3545;margin-bottom:20px;font-size:24px;">🚨 ${t}</h2><div style="margin-bottom:20px;"><div id="errorContent" style="width:100%;padding:15px;border:1px solid #ddd;border-radius:6px;font-family:Arial,sans-serif;font-size:14px;line-height:1.6;background:#f8f9fa;white-space:pre-line;">${fm}</div></div><div style="display:flex;gap:10px;justify-content:flex-end;"><button onclick="copyErrorToClipboard()" style="padding:10px 20px;background:#007bff;color:white;border:none;border-radius:6px;cursor:pointer;font-size:14px;">📋 복사하기</button><button onclick="closeErrorModal()" style="padding:10px 20px;background:#6c757d;color:white;border:none;border-radius:6px;cursor:pointer;font-size:14px;">닫기</button></div></div>`;document.body.appendChild(md);md.addEventListener('click',function(e){if(e.target===md)closeErrorModal();});}
function copyErrorToClipboard(){const ec=document.getElementById('errorContent');const range=document.createRange();range.selectNodeContents(ec);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);document.execCommand('copy');selection.removeAllRanges();const cb=event.target;const ot=cb.textContent;cb.textContent='✅ 복사됨!';cb.style.background='#28a745';setTimeout(()=>{cb.textContent=ot;cb.style.background='#007bff';},2000);}
function closeErrorModal(){const m=document.getElementById('errorModal');if(m)m.remove();}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeErrorModal();});
function toggleTitleGenerator(){const g=document.getElementById('titleGenerator');g.style.display=g.style.display==='none'?'block':'none';}
async function generateTitles(){const ki=document.getElementById('titleKeyword').value.trim();if(!ki){showDetailedError('입력 오류','키워드를 입력해주세요.');return;}const l=document.getElementById('titleLoading'),td=document.getElementById('generatedTitles');l.style.display='block';td.style.display='none';try{const fd=new FormData();fd.append('action','generate_titles');fd.append('keywords',ki);const r=await fetch('',{method:'POST',body:fd});const rs=await r.json();if(rs.success)displayTitles(rs.titles);else showDetailedError('제목 생성 실패',rs.message);}catch(e){showDetailedError('제목 생성 오류','제목 생성 중 오류가 발생했습니다.',{'error':e.message,'keywords':ki});}finally{l.style.display='none';}}
function displayTitles(t){const od=document.getElementById('titleOptions'),td=document.getElementById('generatedTitles');od.innerHTML='';t.forEach((ti)=>{const b=document.createElement('button');b.type='button';b.className='title-option';b.textContent=ti;b.onclick=()=>selectTitle(ti);od.appendChild(b);});td.style.display='block';}
function selectTitle(t){document.getElementById('title').value=t;document.getElementById('titleGenerator').style.display='none';}
function toggleKeywordInput(){const is=document.getElementById('keywordInputSection');if(is.classList.contains('show'))is.classList.remove('show');else{is.classList.add('show');document.getElementById('newKeywordInput').focus();}}
function addKeywordFromInput(){const i=document.getElementById('newKeywordInput'),n=i.value.trim();if(n){kw.push({name:n,products:[]});updateUI();i.value='';document.getElementById('keywordInputSection').classList.remove('show');addProduct(kw.length-1);}else showDetailedError('입력 오류','키워드를 입력해주세요.');}
function cancelKeywordInput(){document.getElementById('newKeywordInput').value='';document.getElementById('keywordInputSection').classList.remove('show');}
function toggleExcelUpload(){const es=document.getElementById('excelUploadSection');if(es.classList.contains('show'))es.classList.remove('show');else{es.classList.add('show');}}
function cancelExcelUpload(){document.getElementById('excelUploadSection').classList.remove('show');document.getElementById('excelFile').value='';document.getElementById('fileName').textContent='';}
function setupFileInput(){document.getElementById('excelFile').addEventListener('change',function(e){const file=e.target.files[0];if(file){document.getElementById('fileName').textContent=`선택된 파일: ${file.name}`;}else{document.getElementById('fileName').textContent='';}});}
async function uploadExcelFile(){const fileInput=document.getElementById('excelFile');if(!fileInput.files[0]){showDetailedError('파일 선택 오류','업로드할 엑셀 파일을 선택해주세요.');return;}const formData=new FormData();formData.append('excel_file',fileInput.files[0]);try{const response=await fetch('excel_import_handler.php',{method:'POST',body:formData});const result=await response.json();if(result.success){processExcelData(result.data);showSuccessModal('업로드 완료!','엑셀 파일이 성공적으로 업로드되고 자동 입력되었습니다.','📄');cancelExcelUpload();}else{showDetailedError('업로드 실패',result.message);}}catch(error){showDetailedError('업로드 오류','파일 업로드 중 오류가 발생했습니다.',{'error':error.message});}}
function processExcelData(data){if(!data||data.length===0){showDetailedError('데이터 오류','엑셀 파일에서 유효한 데이터를 찾을 수 없습니다.');return;}data.forEach(item=>{if(item.keyword&&item.url){let keywordIndex=-1;for(let i=0;i<kw.length;i++){if(kw[i].name===item.keyword){keywordIndex=i;break;}}if(keywordIndex===-1){kw.push({name:item.keyword,products:[]});keywordIndex=kw.length-1;}const product={id:Date.now()+Math.random(),url:item.url,name:`상품 ${kw[keywordIndex].products.length+1}`,status:'empty',analysisData:null,userData:item.user_details||{},isSaved:false,generatedHtml:null};kw[keywordIndex].products.push(product);}});updateUI();document.getElementById('batchProcessSection').style.display='block';}
document.addEventListener('DOMContentLoaded',function(){document.getElementById('newKeywordInput').addEventListener('keypress',function(e){if(e.key==='Enter'){e.preventDefault();addKeywordFromInput();}});});
function addProduct(ki){const p={id:Date.now()+Math.random(),url:'',name:`상품 ${kw[ki].products.length+1}`,status:'empty',analysisData:null,userData:{},isSaved:false,generatedHtml:null};kw[ki].products.push(p);updateUI();selectProduct(ki,kw[ki].products.length-1);}
function deleteKeyword(ki){if(confirm(`"${kw[ki].name}" 키워드를 삭제하시겠습니까?\n이 키워드에 포함된 모든 상품도 함께 삭제됩니다.`)){if(cKI===ki){cKI=-1;cPI=-1;document.getElementById('topNavigation').style.display='none';document.getElementById('productDetailContent').style.display='none';document.getElementById('currentProductTitle').textContent='상품을 선택해주세요';document.getElementById('currentProductSubtitle').textContent='왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.';}else if(cKI>ki)cKI--;kw.splice(ki,1);updateUI();}}
function deleteProduct(ki,pi){const p=kw[ki].products[pi];if(confirm(`"${p.name}" 상품을 삭제하시겠습니까?`)){if(cKI===ki&&cPI===pi){cKI=-1;cPI=-1;document.getElementById('topNavigation').style.display='none';document.getElementById('productDetailContent').style.display='none';document.getElementById('currentProductTitle').textContent='상품을 선택해주세요';document.getElementById('currentProductSubtitle').textContent='왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.';}else if(cKI===ki&&cPI>pi)cPI--;kw[ki].products.splice(pi,1);updateUI();}}
// 키워드 순서 변경 함수들 (팝업 제거)
function moveKeywordUp(keywordIndex){if(keywordIndex<=0)return;const keyword=kw.splice(keywordIndex,1)[0];kw.splice(keywordIndex-1,0,keyword);if(cKI===keywordIndex)cKI=keywordIndex-1;else if(cKI===keywordIndex-1)cKI=keywordIndex;updateUI();}
function moveKeywordDown(keywordIndex){if(keywordIndex>=kw.length-1)return;const keyword=kw.splice(keywordIndex,1)[0];kw.splice(keywordIndex+1,0,keyword);if(cKI===keywordIndex)cKI=keywordIndex+1;else if(cKI===keywordIndex+1)cKI=keywordIndex;updateUI();}
// 상품 순서 변경 함수들 (팝업 제거)
function moveProductUp(keywordIndex,productIndex){if(productIndex<=0)return;const product=kw[keywordIndex].products.splice(productIndex,1)[0];kw[keywordIndex].products.splice(productIndex-1,0,product);if(cKI===keywordIndex&&cPI===productIndex)cPI=productIndex-1;else if(cKI===keywordIndex&&cPI===productIndex-1)cPI=productIndex;updateUI();}
function moveProductDown(keywordIndex,productIndex){if(productIndex>=kw[keywordIndex].products.length-1)return;const product=kw[keywordIndex].products.splice(productIndex,1)[0];kw[keywordIndex].products.splice(productIndex+1,0,product);if(cKI===keywordIndex&&cPI===productIndex)cPI=productIndex+1;else if(cKI===keywordIndex&&cPI===productIndex+1)cPI=productIndex;updateUI();}
function clearUserInputFields(){['main_function','size_capacity','color','material','power_battery','problem_solving','time_saving','space_efficiency','cost_saving','usage_location','usage_frequency','target_users','usage_method','advantage1','advantage2','advantage3','precautions'].forEach(id=>document.getElementById(id).value='');}
function loadUserInputFields(u){if(!u)return;if(u.specs){['main_function','size_capacity','color','material','power_battery'].forEach(k=>{if(u.specs[k])document.getElementById(k).value=u.specs[k];});}if(u.efficiency){['problem_solving','time_saving','space_efficiency','cost_saving'].forEach(k=>{if(u.efficiency[k])document.getElementById(k).value=u.efficiency[k];});}if(u.usage){['usage_location','usage_frequency','target_users','usage_method'].forEach(k=>{if(u.usage[k])document.getElementById(k).value=u.usage[k];});}if(u.benefits){if(u.benefits.advantages){u.benefits.advantages.forEach((v,i)=>{if(i<3)document.getElementById(`advantage${i+1}`).value=v;});}if(u.benefits.precautions)document.getElementById('precautions').value=u.benefits.precautions;}}
function selectProduct(ki,pi){cKI=ki;cPI=pi;const p=kw[ki].products[pi];document.querySelectorAll('.product-item').forEach(i=>i.classList.remove('active'));const si=document.querySelector(`[data-keyword="${ki}"][data-product="${pi}"]`);if(si)si.classList.add('active');updateDetailPanel(p);document.getElementById('topNavigation').style.display='block';}
function updateDetailPanel(p){document.getElementById('currentProductTitle').textContent=p.name;document.getElementById('currentProductSubtitle').textContent=`키워드: ${kw[cKI].name}`;document.getElementById('productUrl').value=p.url||'';if(p.userData&&Object.keys(p.userData).length>0)loadUserInputFields(p.userData);else clearUserInputFields();if(p.analysisData){showAnalysisResult(p.analysisData);if(p.generatedHtml){document.getElementById('htmlCode').textContent=p.generatedHtml;document.getElementById('htmlSourceSection').style.display='block';}}else hideAnalysisResult();document.getElementById('productDetailContent').style.display='block';}
async function analyzeProduct(){const u=document.getElementById('productUrl').value.trim();if(!u){showDetailedError('입력 오류','상품 URL을 입력해주세요.');return;}if(cKI===-1||cPI===-1){showDetailedError('선택 오류','상품을 먼저 선택해주세요.');return;}const p=kw[cKI].products[cPI];p.url=u;p.status='analyzing';updateUI();try{const r=await fetch('product_analyzer_v2.php',{method:'POST',headers:{'Content-Type':'application/json',},body:JSON.stringify({action:'analyze_product',url:u,platform:'aliexpress'})});if(!r.ok)throw new Error(`HTTP 오류: ${r.status} ${r.statusText}`);const rt=await r.text();let rs;try{rs=JSON.parse(rt);}catch(e){showDetailedError('JSON 파싱 오류','서버 응답을 파싱할 수 없습니다.',{'parseError':e.message,'responseText':rt,'responseLength':rt.length,'url':u,'timestamp':new Date().toISOString()});p.status='error';updateUI();return;}if(rs.success){p.analysisData=rs.data;p.status='completed';p.name=rs.data.title||`상품 ${cPI+1}`;cPD=rs.data;showAnalysisResult(rs.data);const gh=generateOptimizedMobileHtml(rs.data);p.generatedHtml=gh;}else{p.status='error';showDetailedError('상품 분석 실패',rs.message||'알 수 없는 오류가 발생했습니다.',{'success':rs.success,'message':rs.message,'debug_info':rs.debug_info||null,'raw_output':rs.raw_output||null,'url':u,'platform':'aliexpress','timestamp':new Date().toISOString(),'mobile_optimized':true});}}catch(e){p.status='error';showDetailedError('네트워크 오류','상품 분석 중 네트워크 오류가 발생했습니다.',{'error':e.message,'stack':e.stack,'url':u,'timestamp':new Date().toISOString()});}updateUI();}
function showAnalysisResult(d){const r=document.getElementById('analysisResult'),c=document.getElementById('productCard');const ratingInfo=formatRatingDisplay(d.rating_display);const fp=formatPrice(d.price);c.innerHTML=`<div class="product-content-split"><div class="product-image-large"><img src="${d.image_url}" alt="${d.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><div class="aliexpress-logo-right"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress"/></div><h3 class="product-title-right">${d.title}</h3><div class="product-price-right">${fp}</div><div class="product-rating-right"><span class="rating-stars">${ratingInfo.stars}</span><span>(고객만족도: ${ratingInfo.percent})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}</div><div class="product-extra-info-right"><div class="info-row"><span class="info-label">상품 ID</span><span class="info-value">${d.product_id}</span></div><div class="info-row"><span class="info-label">플랫폼</span><span class="info-value">${d.platform}</span></div></div></div></div><div class="purchase-button-full"><a href="${d.affiliate_link}" target="_blank" rel="nofollow"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></a></div>`;r.classList.add('show');const ph=`<div class="preview-product-card"><div class="preview-card-content"><div class="product-content-split"><div class="product-image-large"><img src="${d.image_url}" alt="${d.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><div class="aliexpress-logo-right"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress"/></div><h3 class="product-title-right">${d.title}</h3><div class="product-price-right">${fp}</div><div class="product-rating-right"><span class="rating-stars">${ratingInfo.stars}</span><span>(고객만족도: ${ratingInfo.percent})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}</div></div></div><div class="purchase-button-full"><a href="${d.affiliate_link}" target="_blank" rel="nofollow"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></a></div></div></div>`;document.getElementById('htmlPreview').innerHTML=ph;}
function generateOptimizedMobileHtml(d,updateDOM=true){
    if(!d) return null;
    const rd=formatRatingDisplay(d.rating_display);
    const fp=formatPrice(d.price);
    
    // 반응형 HTML 및 CSS 생성 (줄바꿈 제거하여 순수 한 줄 HTML 생성)
    const hc=`<style>.product-responsive-container{display:flex;justify-content:center;margin:25px 0;}.product-responsive-card{border:2px solid #eee;padding:30px;border-radius:15px;background:#f9f9f9;box-shadow:0 4px 8px rgba(0,0,0,0.1);max-width:1000px;width:100%;}@media (min-width: 1601px){.product-responsive-content{display:grid;grid-template-columns:400px 1fr;gap:30px;align-items:start;margin-bottom:25px;}.product-responsive-image{text-align:center;}.product-responsive-info{display:flex;flex-direction:column;gap:20px;}.product-responsive-logo{margin-bottom:15px;text-align:center;}.product-responsive-title{text-align:center;}.product-responsive-price{text-align:center;}.product-responsive-rating{justify-content:center;flex-wrap:nowrap;}.product-responsive-sales{text-align:center;}.product-responsive-button{text-align:center;margin-top:30px;}.product-responsive-button img{content:url('https://novacents.com/tools/images/aliexpress-button-pc.png');}}@media (max-width: 1600px){.product-responsive-content{display:flex;flex-direction:column;gap:20px;}.product-responsive-image{text-align:center;order:2;}.product-responsive-info{display:contents;}.product-responsive-logo{text-align:left;order:1;margin-bottom:0;}.product-responsive-title{text-align:left;order:3;}.product-responsive-price{text-align:center;order:4;}.product-responsive-rating{justify-content:flex-start;order:5;}.product-responsive-sales{text-align:left;order:6;}.product-responsive-button{text-align:center;order:7;margin-top:20px;}.product-responsive-button img{content:url('https://novacents.com/tools/images/aliexpress-button-mobile.png');}}.product-responsive-image img{width:100%;max-width:400px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,0.15);}.product-responsive-logo img{width:250px;height:60px;object-fit:contain;}.product-responsive-title{color:#1c1c1c;margin:0 0 20px 0;font-size:21px;font-weight:600;line-height:1.4;word-break:keep-all;overflow-wrap:break-word;}.product-responsive-price{background:linear-gradient(135deg,#e62e04 0%,#ff9900 100%);color:white;padding:14px 30px;border-radius:10px;font-size:40px;font-weight:700;margin-bottom:20px;box-shadow:0 4px 15px rgba(230,46,4,0.3);}.product-responsive-rating{color:#1c1c1c;font-size:20px;display:flex;align-items:center;gap:10px;margin-bottom:15px;}.product-responsive-rating .stars{color:#ff9900;}.product-responsive-sales{color:#1c1c1c;font-size:18px;margin:0 0 15px 0;}.product-responsive-button a{text-decoration:none;}.product-responsive-button img{max-width:100%;height:auto;cursor:pointer;}</style><div class="product-responsive-container"><div class="product-responsive-card"><div class="product-responsive-content"><div class="product-responsive-image"><img src="${d.image_url}" alt="${d.title}"></div><div class="product-responsive-info"><div class="product-responsive-logo"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress"></div><h3 class="product-responsive-title">${d.title}</h3><div class="product-responsive-price"><strong>${fp}</strong></div><div class="product-responsive-rating"><span class="stars">${rd.stars}</span><span>(고객만족도: ${rd.percent})</span></div><p class="product-responsive-sales"><strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}</p></div></div><div class="product-responsive-button"><a href="${d.affiliate_link}" target="_blank" rel="nofollow"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></a></div></div></div>`;

    if(updateDOM){
        const ph=`<div class="preview-product-card"><div class="preview-card-content"><div class="product-content-split"><div class="product-image-large"><img src="${d.image_url}" alt="${d.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><div class="aliexpress-logo-right"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress"/></div><h3 class="product-title-right">${d.title}</h3><div class="product-price-right">${fp}</div><div class="product-rating-right"><span class="rating-stars">${rd.stars}</span><span>(고객만족도: ${rd.percent})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}</div></div></div><div class="purchase-button-full"><a href="${d.affiliate_link}" target="_blank" rel="nofollow"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></a></div></div></div>`;
        document.getElementById('htmlPreview').innerHTML=ph;
        document.getElementById('htmlCode').textContent=hc;
        document.getElementById('htmlSourceSection').style.display='block';
    }
    return hc;
}
async function copyHtmlSource(){const hc=document.getElementById('htmlCode').textContent,cb=document.querySelector('.copy-btn');try{await navigator.clipboard.writeText(hc);const ot=cb.textContent;cb.textContent='✅ 복사됨!';cb.classList.add('copied');setTimeout(()=>{cb.textContent=ot;cb.classList.remove('copied');},2000);}catch(e){const ce=document.getElementById('htmlCode'),r=document.createRange();r.selectNodeContents(ce);const s=window.getSelection();s.removeAllRanges();s.addRange(r);showDetailedError('복사 알림','HTML 소스가 선택되었습니다. Ctrl+C로 복사하세요.');}}
function hideAnalysisResult(){document.getElementById('analysisResult').classList.remove('show');document.getElementById('htmlSourceSection').style.display='none';}
function updateUI(){updateProductsList();updateProgress();}
function updateProductsList(){const l=document.getElementById('productsList');if(kw.length===0){l.innerHTML='<div class="empty-state"><h3>📦 상품이 없습니다</h3><p>위의 "키워드 추가" 버튼을 클릭하여<br>첫 번째 키워드를 추가해보세요!</p></div>';return;}let h='';kw.forEach((k,ki)=>{h+=`<div class="keyword-group draggable" draggable="true" data-keyword-index="${ki}"><div class="keyword-header"><div class="keyword-header-left"><div class="keyword-order-controls"><button type="button" class="keyword-order-btn" onclick="handleKeywordOrderChange(event, ${ki}, 'up')" ${ki===0?'disabled':''}title="키워드 위로">▲</button><button type="button" class="keyword-order-btn" onclick="handleKeywordOrderChange(event, ${ki}, 'down')" ${ki===kw.length-1?'disabled':''}title="키워드 아래로">▼</button></div><div class="keyword-info"><span class="keyword-name">📁 ${k.name}</span><span class="product-count">${k.products.length}개</span></div></div><div class="keyword-actions"><button type="button" class="btn btn-success btn-small" onclick="addProduct(${ki})">+상품</button><button type="button" class="btn btn-danger btn-small" onclick="deleteKeyword(${ki})">🗑️</button></div></div>`;k.products.forEach((p,pi)=>{const si=getStatusIcon(p.status,p.isSaved);h+=`<div class="product-item draggable" draggable="true" data-keyword="${ki}" data-product="${pi}" onclick="selectProduct(${ki},${pi})"><div class="product-order-controls"><button type="button" class="product-order-btn" onclick="handleProductOrderChange(event, ${ki}, ${pi}, 'up')" ${pi===0?'disabled':''}title="상품 위로">▲</button><button type="button" class="product-order-btn" onclick="handleProductOrderChange(event, ${ki}, ${pi}, 'down')" ${pi===k.products.length-1?'disabled':''}title="상품 아래로">▼</button></div><span class="product-status">${si}</span><span class="product-name">${p.name}</span><div class="product-actions"><button type="button" class="btn btn-danger btn-small" onclick="event.stopPropagation();deleteProduct(${ki},${pi})">🗑️</button></div></div>`;});h+='</div>';});l.innerHTML=h;setupDragAndDrop();}
// 이벤트 핸들러 함수들 추가
function handleKeywordOrderChange(event, keywordIndex, direction) {
    event.stopPropagation();
    if (direction === 'up') {
        moveKeywordUp(keywordIndex);
    } else {
        moveKeywordDown(keywordIndex);
    }
}
function handleProductOrderChange(event, keywordIndex, productIndex, direction) {
    event.stopPropagation();
    if (direction === 'up') {
        moveProductUp(keywordIndex, productIndex);
    } else {
        moveProductDown(keywordIndex, productIndex);
    }
}
function getStatusIcon(s,is=false){switch(s){case'completed':return is?'✅':'🔍';case'analyzing':return'🔄';case'error':return'⚠️';default:return'❌';}}
function updateProgress(){const tp=kw.reduce((s,k)=>s+k.products.length,0),cp=kw.reduce((s,k)=>s+k.products.filter(p=>p.isSaved).length,0),pe=tp>0?(cp/tp)*100:0;document.getElementById('progressFill').style.width=pe+'%';document.getElementById('progressText').textContent=`${cp}/${tp} 완성`;}
function collectUserInputDetails(){const d={},sp={},ef={},us={},be={},av=[];addIfNotEmpty(sp,'main_function','main_function');addIfNotEmpty(sp,'size_capacity','size_capacity');addIfNotEmpty(sp,'color','color');addIfNotEmpty(sp,'material','material');addIfNotEmpty(sp,'power_battery','power_battery');if(Object.keys(sp).length>0)d.specs=sp;addIfNotEmpty(ef,'problem_solving','problem_solving');addIfNotEmpty(ef,'time_saving','time_saving');addIfNotEmpty(ef,'space_efficiency','space_efficiency');addIfNotEmpty(ef,'cost_saving','cost_saving');if(Object.keys(ef).length>0)d.efficiency=ef;addIfNotEmpty(us,'usage_location','usage_location');addIfNotEmpty(us,'usage_frequency','usage_frequency');addIfNotEmpty(us,'target_users','target_users');addIfNotEmpty(us,'usage_method','usage_method');if(Object.keys(us).length>0)d.usage=us;['advantage1','advantage2','advantage3'].forEach(id=>{const v=document.getElementById(id)?.value.trim();if(v)av.push(v);});if(av.length>0)be.advantages=av;addIfNotEmpty(be,'precautions','precautions');if(Object.keys(be).length>0)d.benefits=be;return d;}
function addIfNotEmpty(o,k,e){const v=document.getElementById(e)?.value.trim();if(v)o[k]=v;}

// 문자열 정제 함수 - JSON 직렬화 안전하게 처리
function sanitizeForJson(str, isHtmlContent = false) {
    if (typeof str !== 'string') return str;
    
    // HTML 콘텐츠인 경우 이스케이프 처리하지 않고 그대로 반환
    if (isHtmlContent) {
        return str;
    }
    
    // 일반 문자열은 기존 방식 유지
    return str
        .replace(/\\/g, '\\\\')     // 백슬래시 이스케이프
        .replace(/"/g, '\\"')       // 따옴표 이스케이프
        .replace(/\n/g, '\\n')      // 개행문자 이스케이프
        .replace(/\r/g, '\\r')      // 캐리지 리턴 이스케이프
        .replace(/\t/g, '\\t')      // 탭 이스케이프
        .replace(/[\x00-\x1F\x7F]/g, ''); // 제어문자 제거
}

// 객체 데이터 정제 함수
function sanitizeObjectForJson(obj, htmlFields = ['generated_html']) {
    if (obj === null || obj === undefined) return obj;
    if (typeof obj === 'string') return sanitizeForJson(obj);
    if (typeof obj !== 'object') return obj;
    
    if (Array.isArray(obj)) {
        return obj.map(item => sanitizeObjectForJson(item, htmlFields));
    }
    
    const sanitized = {};
    for (const [key, value] of Object.entries(obj)) {
        if (htmlFields.includes(key) && typeof value === 'string') {
            // HTML 콘텐츠 필드는 이스케이프 처리 안 함
            sanitized[key] = value;
        } else {
            sanitized[key] = sanitizeObjectForJson(value, htmlFields);
        }
    }
    return sanitized;
}

// 큐 데이터 구조 검증 함수
function validateQueueDataStructure(fd) {
    // 필수 필드 존재 확인
    const requiredFields = ['title', 'category', 'prompt_type', 'keywords', 'user_details'];
    for (const field of requiredFields) {
        if (!fd[field]) {
            console.error('Missing required field:', field);
            return false;
        }
    }

    // keywords 배열 구조 검증
    if (!Array.isArray(fd.keywords) || fd.keywords.length === 0) {
        console.error('Keywords must be non-empty array');
        return false;
    }

    // 각 키워드 구조 검증
    for (const keyword of fd.keywords) {
        if (!keyword.name || !Array.isArray(keyword.aliexpress) || !Array.isArray(keyword.products_data)) {
            console.error('Invalid keyword structure:', keyword);
            return false;
        }
    }

    // user_details 객체 구조 검증
    if (typeof fd.user_details !== 'object' || fd.user_details === null) {
        console.error('user_details must be an object');
        return false;
    }

    return true;
}
function collectKeywordsData(){
    const kd=[];
    kw.forEach((k,ki)=>{
        const kdt={
            name:k.name,
            coupang:[],
            aliexpress:[],
            products_data:[]
        };
        
        k.products.forEach((p,pi)=>{
            if(p.url&&typeof p.url==='string'&&p.url.trim()!==''&&p.url.trim()!=='undefined'&&p.url.trim()!=='null'){
                const tu=p.url.trim();
                kdt.aliexpress.push(tu);
                
                // 데이터 정제 적용
                const pd={
                    url:tu,
                    analysis_data:sanitizeObjectForJson(p.analysisData)||null,
                    generated_html:sanitizeForJson(p.generatedHtml, true)||null,
                    user_data:sanitizeObjectForJson(p.userData)||{}
                };
                kdt.products_data.push(pd);
            }
        });
        
        if(kdt.aliexpress.length>0)kd.push(kdt);
    });
    return kd;
}
function validateAndSubmitData(fd,ip=false){if(!fd.title||fd.title.length<5){showDetailedError('입력 오류','제목은 5자 이상이어야 합니다.');return false;}if(!fd.keywords||fd.keywords.length===0){showDetailedError('입력 오류','최소 하나의 키워드와 상품 링크가 필요합니다.');return false;}let hv=false,tv=0,tpd=0;fd.keywords.forEach(k=>{if(k.aliexpress&&k.aliexpress.length>0){const vu=k.aliexpress.filter(u=>u&&typeof u==='string'&&u.trim()!=='');if(vu.length>0){hv=true;tv+=vu.length;tpd+=k.products_data?k.products_data.length:0;}}});if(!hv||tv===0){showDetailedError('입력 오류','각 키워드에 최소 하나의 유효한 알리익스프레스 상품 링크가 있어야 합니다.\\n\\n현재 상태:\\n- URL을 입력했는지 확인하세요\\n- 분석 버튼을 클릭했는지 확인하세요\\n- 알리익스프레스 URL인지 확인하세요');return false;}if(ip)return true;else{const f=document.getElementById('affiliateForm'),ei=f.querySelectorAll('input[type="hidden"]');ei.forEach(i=>i.remove());const hi=[{name:'title',value:fd.title},{name:'category',value:fd.category},{name:'prompt_type',value:fd.prompt_type},{name:'keywords',value:JSON.stringify(fd.keywords)},{name:'user_details',value:JSON.stringify(fd.user_details)},{name:'thumbnail_url',value:document.getElementById('thumbnail_url').value.trim()}];hi.forEach(({name,value})=>{const i=document.createElement('input');i.type='hidden';i.name=name;i.value=value;f.appendChild(i);});f.submit();return true;}}
async function publishNow(){const lo=document.getElementById('loadingOverlay'),pb=document.getElementById('publishNowBtn');lo.style.display='flex';pb.disabled=true;pb.textContent='발행 중...';try{// 1. 큐 파일 존재 확인
const r=await fetch('keyword_processor.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_latest_queue_file'})});const rs=await r.json();if(rs.success && rs.queue_file){// 2. 큐 파일 기반 즉시 발행
const publishResult=await fetch('keyword_processor.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'publish_from_queue',queue_file:rs.queue_file})});const publishResponse=await publishResult.json();if(publishResponse.success){showSuccessModal('발행 완료!','글이 성공적으로 발행되었습니다!','🚀');if(publishResponse.post_url)window.open(publishResponse.post_url,'_blank');}else showDetailedError('발행 실패',publishResponse.message);}else{showDetailedError('발행 실패','먼저 완료 버튼을 눌러 큐 파일을 생성해주세요.');}}catch(e){showDetailedError('발행 오류','즉시 발행 중 오류가 발생했습니다.',{'error':e.message});}finally{lo.style.display='none';pb.disabled=false;pb.textContent='🚀 즉시 발행';}}
function saveCurrentProduct(){if(cKI===-1||cPI===-1){showDetailedError('선택 오류','저장할 상품을 먼저 선택해주세요.');return;}const p=kw[cKI].products[cPI],u=document.getElementById('productUrl').value.trim();if(u)p.url=u;const ud=collectUserInputDetails();p.userData=ud;p.isSaved=true;updateUI();showSuccessModal('저장 완료!','현재 상품 정보가 성공적으로 저장되었습니다.','💾');}
async function completeProduct(){
    const kd=collectKeywordsData(),
          ud=collectUserInputDetails(),
          fd={
              title:sanitizeForJson(document.getElementById('title').value.trim()),
              category:document.getElementById('category').value,
              prompt_type:document.getElementById('prompt_type').value,
              keywords:kd,
              user_details:sanitizeObjectForJson(ud),
              thumbnail_url:sanitizeForJson(document.getElementById('thumbnail_url').value.trim())
          };

    // 기본 검증
    if(!validateAndSubmitData(fd,true))return;

    // 추가 데이터 구조 검증
    if(!validateQueueDataStructure(fd)){
        showDetailedError('데이터 구조 오류','큐 데이터 구조가 올바르지 않습니다. 모든 필드를 확인해주세요.');
        return;
    }

    // 전체 데이터 정제 (HTML 필드는 이스케이프 제외)
    const sanitizedFd = sanitizeObjectForJson(fd, ['generated_html']);

    // JSON 직렬화 테스트
    let jsonData;
    try{
        jsonData=JSON.stringify(sanitizedFd);
        console.log('Queue data JSON length:',jsonData.length);
        console.log('Queue data structure:',sanitizedFd);
    }catch(e){
        showDetailedError('데이터 직렬화 오류','JSON 변환 중 오류가 발생했습니다: '+e.message);
        return;
    }

    try{
        const r=await fetch('keyword_processor.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                action: 'save_to_queue',
                queue_data: sanitizedFd
            })
        });

        if(!r.ok){
            throw new Error(`HTTP error! status: ${r.status}`);
        }

        const rs=await r.json();
        if(rs.success){
            showSuccessModal('완료!','큐에 성공적으로 저장되었습니다.','✅');
        }else{
            showDetailedError('완료 실패',rs.message||'알 수 없는 오류가 발생했습니다.');
        }
    }catch(e){
        console.error('Complete product error:',e);
        showDetailedError('완료 오류','완료 처리 중 오류가 발생했습니다: '+e.message);
    }
}
function previousProduct(){if(cKI===-1||cPI===-1)return;const ck=kw[cKI];if(cPI>0)selectProduct(cKI,cPI-1);else if(cKI>0){const pk=kw[cKI-1];selectProduct(cKI-1,pk.products.length-1);}}
function nextProduct(){if(cKI===-1||cPI===-1)return;const ck=kw[cKI];if(cPI<ck.products.length-1)selectProduct(cKI,cPI+1);else if(cKI<kw.length-1)selectProduct(cKI+1,0);}
function setupDragAndDrop(){const keywordGroups=document.querySelectorAll('.keyword-group');const productItems=document.querySelectorAll('.product-item');keywordGroups.forEach((group,index)=>{group.addEventListener('dragstart',handleKeywordDragStart);group.addEventListener('dragend',handleDragEnd);group.addEventListener('dragover',handleDragOver);group.addEventListener('drop',handleKeywordDrop);});productItems.forEach((item,index)=>{item.addEventListener('dragstart',handleProductDragStart);item.addEventListener('dragend',handleDragEnd);item.addEventListener('dragover',handleDragOver);item.addEventListener('drop',handleProductDrop);});}
function handleKeywordDragStart(e){if(!e.target.classList.contains('keyword-group'))return;e.stopPropagation();draggedElement=e.target;draggedType='keyword';draggedIndex=parseInt(e.target.dataset.keywordIndex);e.target.classList.add('dragging');e.dataTransfer.effectAllowed='move';}
function handleProductDragStart(e){if(!e.target.classList.contains('product-item'))return;e.stopPropagation();draggedElement=e.target;draggedType='product';draggedKeywordIndex=parseInt(e.target.dataset.keyword);draggedIndex=parseInt(e.target.dataset.product);e.target.classList.add('dragging');e.dataTransfer.effectAllowed='move';}
function handleDragEnd(e){e.target.classList.remove('dragging');document.querySelectorAll('.drag-over').forEach(el=>el.classList.remove('drag-over'));draggedElement=null;draggedType=null;draggedIndex=null;draggedKeywordIndex=null;}
function handleDragOver(e){e.preventDefault();e.dataTransfer.dropEffect='move';}
function handleKeywordDrop(e){e.preventDefault();e.stopPropagation();if(draggedType!=='keyword'||!e.target.closest('.keyword-group'))return;const targetGroup=e.target.closest('.keyword-group');if(!targetGroup||targetGroup===draggedElement)return;const targetIndex=parseInt(targetGroup.dataset.keywordIndex);if(draggedIndex!==targetIndex){const draggedKeyword=kw.splice(draggedIndex,1)[0];kw.splice(targetIndex,0,draggedKeyword);if(cKI===draggedIndex)cKI=targetIndex;else if(cKI===targetIndex)cKI=draggedIndex<targetIndex?cKI+1:cKI-1;else if(cKI>Math.min(draggedIndex,targetIndex)&&cKI<=Math.max(draggedIndex,targetIndex))cKI+=draggedIndex<targetIndex?-1:1;updateUI();showSuccessModal('키워드 순서 변경','키워드 순서가 성공적으로 변경되었습니다.','🔄');}}
function handleProductDrop(e){e.preventDefault();e.stopPropagation();if(draggedType!=='product'||!e.target.closest('.product-item'))return;const targetItem=e.target.closest('.product-item');if(!targetItem||targetItem===draggedElement)return;const targetKeywordIndex=parseInt(targetItem.dataset.keyword);const targetProductIndex=parseInt(targetItem.dataset.product);if(draggedKeywordIndex===targetKeywordIndex&&draggedIndex===targetProductIndex)return;const draggedProduct=kw[draggedKeywordIndex].products.splice(draggedIndex,1)[0];kw[targetKeywordIndex].products.splice(targetProductIndex,0,draggedProduct);if(cKI===draggedKeywordIndex&&cPI===draggedIndex){cKI=targetKeywordIndex;cPI=targetProductIndex;}else if(cKI===targetKeywordIndex){if(cPI>=targetProductIndex)cPI++;}updateUI();showSuccessModal('상품 순서 변경','상품 순서가 성공적으로 변경되었습니다.','🔄');}
// 일괄 처리 함수들
async function batchAnalyzeAll(){
    // 실패 상품 정보 수집을 위한 안전한 배열 초기화
    let failedProducts = [];
    
    const totalProducts=getAllProducts();
    if(totalProducts.length===0){showDetailedError('분석 오류','분석할 상품이 없습니다.');return;}
    
    const batchAnalyzeBtn=document.getElementById('batchAnalyzeBtn'),batchProgress=document.getElementById('batchProgress'),batchProgressText=document.getElementById('batchProgressText'),batchProgressBar=document.getElementById('batchProgressBar');
    batchAnalyzeBtn.disabled=true;batchAnalyzeBtn.textContent='분석 중...';batchProgress.style.display='block';
    
    let completed=0;
    for(let i=0;i<totalProducts.length;i++){
        const {keywordIndex,productIndex,product}=totalProducts[i];
        batchProgressText.textContent=`분석 중... (${completed+1}/${totalProducts.length}) - ${product.name || '상품'}`;
        batchProgressBar.style.width=`${(completed/totalProducts.length)*100}%`;
        
        if(product.url&&product.url.trim()!==''&&product.status==='empty'){
            product.status='analyzing';updateUI();
            let retryCount=0;const maxRetries=2;let analysisSuccess=false;
            
            while(retryCount<=maxRetries&&!analysisSuccess){
                try{
                    const response=await fetch('product_analyzer_v2.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'analyze_product',url:product.url,platform:'aliexpress'})});
                    if(!response.ok)throw new Error(`HTTP 오류: ${response.status}`);
                    const result=await response.json();
                    if(result.success){
                        product.analysisData=result.data;product.status='completed';
                        product.name=result.data.title||`상품 ${productIndex+1}`;
                        const generatedHtml=generateOptimizedMobileHtml(result.data,false);
                        product.generatedHtml=generatedHtml;analysisSuccess=true;
                    }else{
                        throw new Error(result.message||'분석 실패');
                    }
                }catch(error){
                    retryCount++;
                    if(retryCount>maxRetries){
                        product.status='error';
                        // 안전한 실패 정보 수집
                        try {
                            const keywordName = kw[keywordIndex] && kw[keywordIndex].name ? kw[keywordIndex].name : '키워드 없음';
                            const productName = product && product.name ? product.name : `상품 ${productIndex+1}`;
                            const errorMessage = error && error.message ? error.message : '알 수 없는 오류';
                            const productUrl = product && product.url ? product.url : 'URL 없음';
                            
                            failedProducts.push({
                                keyword: keywordName,
                                productName: productName,
                                reason: errorMessage,
                                url: productUrl
                            });
                        } catch(pushError) {
                            console.error('실패 정보 수집 오류:', pushError);
                        }
                        console.error(`최종 실패 [${product.name || '상품'}]: ${error.message || '알 수 없는 오류'}`);
                    }else{
                        console.log(`재시도 ${retryCount}/${maxRetries} [${product.name || '상품'}]: ${error.message || '알 수 없는 오류'}`);
                        await new Promise(resolve=>setTimeout(resolve,3000));
                    }
                }
            }
        }
        completed++;updateUI();await new Promise(resolve=>setTimeout(resolve,2500));
    }
    
    batchProgressText.textContent=`분석 완료! (${completed}/${totalProducts.length})`;
    batchProgressBar.style.width='100%';
    
    // 모달 충돌 방지를 위한 안전한 팝업 표시
    setTimeout(() => {
        // 기존 모달이 표시되지 않은 상태에서만 새 팝업 표시
        const existingModal = document.getElementById('successModal');
        if (!existingModal || existingModal.style.display === 'none') {
            if(failedProducts.length > 0) {
                const successCount = completed - failedProducts.length;
                const failureDetails = failedProducts.map(f => 
                    `• ${f.keyword}: ${f.productName}`
                ).join('\n');
                
                showDetailedError(
                    '일부 상품 분석 실패', 
                    `📊 분석 결과:\n✅ 성공: ${successCount}개\n❌ 실패: ${failedProducts.length}개\n\n🚨 실패한 상품:\n\n${failureDetails}`, 
                    null
                );
            } else {
                showSuccessModal('일괄 분석 완료!',`총 ${completed}개 상품의 분석이 완료되었습니다.`,'🔍');
            }
        }
    }, 500); // 0.5초 대기로 기존 프로세스 완료 보장
    
    setTimeout(()=>{
        batchProgress.style.display='none';
        batchAnalyzeBtn.disabled=false;
        batchAnalyzeBtn.textContent='🔍 전체 분석';
    },3000);
}
async function batchSaveAll(){const totalProducts=getAllProducts();if(totalProducts.length===0){showDetailedError('저장 오류','저장할 상품이 없습니다.');return;}const batchSaveBtn=document.getElementById('batchSaveBtn'),batchProgress=document.getElementById('batchProgress'),batchProgressText=document.getElementById('batchProgressText'),batchProgressBar=document.getElementById('batchProgressBar');batchSaveBtn.disabled=true;batchSaveBtn.textContent='저장 중...';batchProgress.style.display='block';try{let completed=0;for(let i=0;i<totalProducts.length;i++){const {keywordIndex,productIndex,product}=totalProducts[i];if(product.url&&product.url.trim()!==''&&product.status==='completed'&&!product.isSaved){product.isSaved=true;completed++;}}updateUI();batchProgressText.textContent='저장 완료!';batchProgressBar.style.width='100%';showSuccessModal('일괄 저장 완료!',`총 ${completed}개 상품이 저장되었습니다.`,'💾');}catch(error){console.error('일괄 저장 중 오류:',error);}finally{setTimeout(()=>{batchProgress.style.display='none';batchSaveBtn.disabled=false;batchSaveBtn.textContent='💾 전체 저장';},3000);}}
function getAllProducts(){const products=[];kw.forEach((keyword,keywordIndex)=>{keyword.products.forEach((product,productIndex)=>{products.push({keywordIndex,productIndex,product});});});return products;}
document.getElementById('titleKeyword').addEventListener('keypress',function(e){if(e.key==='Enter'){e.preventDefault();generateTitles();}});
