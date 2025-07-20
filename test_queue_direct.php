<?php
/**
 * queue_manager.php의 실제 동작을 직접 테스트
 */

// queue_manager.php와 동일한 설정
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

echo "<h1>queue_manager.php 직접 시뮬레이션</h1>";

// 1. 전체 큐 로드 테스트
echo "<h2>1. get_queue_list 액션 시뮬레이션</h2>";
$queue = load_queue();
$queue_list_response = ['success' => true, 'queue' => $queue];
$queue_list_json = json_encode($queue_list_response);

echo "✅ 큐 목록 JSON 생성 성공<br>";
echo "📄 JSON 크기: " . strlen($queue_list_json) . " 문자<br>";
echo "📊 큐 항목 수: " . count($queue) . "<br><br>";

if (count($queue) > 0) {
    $first_item = $queue[0];
    $queue_id = $first_item['queue_id'];
    
    echo "<h2>2. get_queue_item 액션 시뮬레이션</h2>";
    echo "🎯 대상 Queue ID: {$queue_id}<br>";
    
    // queue_manager.php의 get_queue_item 로직 그대로 실행
    $found_item = null;
    foreach ($queue as $item) {
        if ($item['queue_id'] === $queue_id) {
            $found_item = $item;
            break;
        }
    }
    
    if ($found_item) {
        echo "✅ 큐 항목 검색 성공<br>";
        
        // 응답 생성 (queue_manager.php와 동일한 방식)
        $response = [
            'success' => true, 
            'item' => $found_item
        ];
        
        $response_json = json_encode($response, JSON_UNESCAPED_UNICODE);
        
        if ($response_json === false) {
            echo "❌ 응답 JSON 인코딩 실패: " . json_last_error_msg() . "<br>";
        } else {
            echo "✅ 응답 JSON 인코딩 성공<br>";
            echo "📄 응답 크기: " . strlen($response_json) . " 문자<br>";
            
            // JavaScript가 받을 데이터 파싱 테스트
            $parsed_response = json_decode($response_json, true);
            if ($parsed_response === null) {
                echo "❌ 응답 재파싱 실패<br>";
            } else {
                echo "✅ 응답 재파싱 성공<br>";
                
                $item_data = $parsed_response['item'];
                echo "<h3>📋 파싱된 데이터 분석:</h3>";
                echo "📝 제목: " . ($item_data['title'] ?? 'N/A') . "<br>";
                echo "🏷️ 키워드 수: " . (isset($item_data['keywords']) ? count($item_data['keywords']) : 0) . "<br>";
                
                if (isset($item_data['keywords']) && is_array($item_data['keywords'])) {
                    foreach ($item_data['keywords'] as $kIndex => $keyword) {
                        echo "<h4>🔍 키워드 {$kIndex}: {$keyword['name']}</h4>";
                        echo "  🔗 AliExpress URLs: " . (isset($keyword['aliexpress']) ? count($keyword['aliexpress']) : 0) . "개<br>";
                        echo "  📦 Products Data: " . (isset($keyword['products_data']) ? count($keyword['products_data']) : 0) . "개<br>";
                        
                        if (isset($keyword['products_data']) && is_array($keyword['products_data'])) {
                            foreach ($keyword['products_data'] as $pIndex => $product) {
                                echo "    상품 {$pIndex}:<br>";
                                echo "      URL: " . ($product['url'] ?? 'N/A') . "<br>";
                                echo "      분석 데이터: " . (isset($product['analysis_data']) ? '✅' : '❌') . "<br>";
                                echo "      사용자 데이터: " . (isset($product['user_data']) ? '✅' : '❌') . "<br>";
                                
                                if (isset($product['user_data']) && is_array($product['user_data'])) {
                                    echo "      📊 사용자 데이터 상세:<br>";
                                    foreach ($product['user_data'] as $section => $data) {
                                        echo "        {$section}: " . (is_array($data) ? count($data) . "개 항목" : "단일 값") . "<br>";
                                        
                                        if ($section === 'specs' && is_array($data)) {
                                            foreach ($data as $key => $value) {
                                                echo "          {$key}: {$value}<br>";
                                            }
                                        }
                                    }
                                }
                                echo "<br>";
                            }
                        }
                    }
                }
            }
        }
    } else {
        echo "❌ 큐 항목 검색 실패<br>";
    }
}

echo "<h2>3. 실제 AJAX 요청 테스트</h2>";
echo '<button onclick="testRealAjax()" style="padding: 10px; font-size: 16px; background: #007cba; color: white; border: none; border-radius: 5px; cursor: pointer;">실제 AJAX 테스트</button>';
echo '<div id="ajax-test-result" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 5px;"></div>';

?>

<script>
function testRealAjax() {
    const resultDiv = document.getElementById('ajax-test-result');
    resultDiv.innerHTML = '🔄 테스트 시작...';
    
    console.log('🔍 실제 AJAX 테스트 시작');
    
    // 실제 queue_manager.php에 요청
    fetch('queue_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_queue_item&queue_id=<?php echo $queue_id ?? ""; ?>'
    })
    .then(response => {
        console.log('📡 응답 상태:', response.status);
        return response.text();
    })
    .then(responseText => {
        console.log('📄 응답 텍스트 길이:', responseText.length);
        console.log('📄 응답 텍스트 일부:', responseText.substring(0, 200));
        
        try {
            const result = JSON.parse(responseText);
            console.log('✅ JSON 파싱 성공:', result);
            
            if (result.success) {
                resultDiv.innerHTML = '✅ 성공!<br>';
                resultDiv.innerHTML += `📝 제목: ${result.item.title}<br>`;
                resultDiv.innerHTML += `🏷️ 키워드 수: ${result.item.keywords ? result.item.keywords.length : 0}<br>`;
                
                if (result.item.keywords && result.item.keywords.length > 0) {
                    const firstKeyword = result.item.keywords[0];
                    resultDiv.innerHTML += `🔍 첫 번째 키워드: ${firstKeyword.name}<br>`;
                    resultDiv.innerHTML += `📦 상품 데이터: ${firstKeyword.products_data ? firstKeyword.products_data.length : 0}개<br>`;
                    
                    if (firstKeyword.products_data && firstKeyword.products_data.length > 0) {
                        const firstProduct = firstKeyword.products_data[0];
                        resultDiv.innerHTML += `🔗 첫 번째 상품 URL: ${firstProduct.url}<br>`;
                        resultDiv.innerHTML += `🔍 분석 데이터: ${firstProduct.analysis_data ? '✅' : '❌'}<br>`;
                        resultDiv.innerHTML += `👤 사용자 데이터: ${firstProduct.user_data ? '✅' : '❌'}<br>`;
                        
                        if (firstProduct.user_data) {
                            resultDiv.innerHTML += `📊 사용자 데이터 키: ${Object.keys(firstProduct.user_data).join(', ')}<br>`;
                            
                            // specs 섹션 상세 확인
                            if (firstProduct.user_data.specs) {
                                resultDiv.innerHTML += `🔧 Specs: ${Object.keys(firstProduct.user_data.specs).join(', ')}<br>`;
                                Object.entries(firstProduct.user_data.specs).forEach(([key, value]) => {
                                    resultDiv.innerHTML += `  ${key}: ${value}<br>`;
                                });
                            }
                        }
                    }
                }
            } else {
                resultDiv.innerHTML = `❌ 실패: ${result.message}`;
            }
        } catch (parseError) {
            console.error('❌ JSON 파싱 오류:', parseError);
            resultDiv.innerHTML = `❌ JSON 파싱 오류: ${parseError.message}<br>응답 일부: ${responseText.substring(0, 300)}`;
        }
    })
    .catch(error => {
        console.error('❌ AJAX 오류:', error);
        resultDiv.innerHTML = `❌ AJAX 오류: ${error.message}`;
    });
}

// 페이지 로드 시 자동 실행
document.addEventListener('DOMContentLoaded', function() {
    console.log('📄 페이지 로드 완료');
});
</script>