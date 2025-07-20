<?php
/**
 * 큐 데이터 디버깅 스크립트
 * queue_manager.php의 데이터 로딩 문제를 진단하기 위한 임시 스크립트
 */

define('QUEUE_FILE', '/var/www/novacents/tools/product_queue.json');

// JSON 파일 직접 읽기 테스트
echo "<h2>1. JSON 파일 직접 읽기 테스트</h2>";
if (file_exists(QUEUE_FILE)) {
    echo "✅ 파일 존재: " . QUEUE_FILE . "<br>";
    echo "📁 파일 크기: " . filesize(QUEUE_FILE) . " bytes<br>";
    
    $content = file_get_contents(QUEUE_FILE);
    if ($content === false) {
        echo "❌ 파일 읽기 실패<br>";
    } else {
        echo "✅ 파일 읽기 성공<br>";
        echo "📄 내용 길이: " . strlen($content) . " 문자<br>";
        
        // JSON 파싱 테스트
        $queue = json_decode($content, true);
        if ($queue === null) {
            echo "❌ JSON 파싱 실패: " . json_last_error_msg() . "<br>";
        } else {
            echo "✅ JSON 파싱 성공<br>";
            echo "📊 큐 항목 수: " . count($queue) . "<br>";
            
            // 첫 번째 항목 분석
            if (count($queue) > 0) {
                $first_item = $queue[0];
                echo "<h3>첫 번째 큐 항목 분석:</h3>";
                echo "🆔 Queue ID: " . ($first_item['queue_id'] ?? 'N/A') . "<br>";
                echo "📝 제목: " . ($first_item['title'] ?? 'N/A') . "<br>";
                echo "🏷️ 키워드 수: " . (isset($first_item['keywords']) ? count($first_item['keywords']) : 0) . "<br>";
                
                if (isset($first_item['keywords']) && is_array($first_item['keywords'])) {
                    foreach ($first_item['keywords'] as $kIndex => $keyword) {
                        echo "<h4>키워드 {$kIndex}: {$keyword['name']}</h4>";
                        echo "🔗 AliExpress URLs: " . (isset($keyword['aliexpress']) ? count($keyword['aliexpress']) : 0) . "개<br>";
                        echo "📦 Products Data: " . (isset($keyword['products_data']) ? count($keyword['products_data']) : 0) . "개<br>";
                        
                        if (isset($keyword['products_data']) && is_array($keyword['products_data'])) {
                            foreach ($keyword['products_data'] as $pIndex => $product) {
                                echo "  상품 {$pIndex}:<br>";
                                echo "    URL: " . ($product['url'] ?? 'N/A') . "<br>";
                                echo "    분석 데이터: " . (isset($product['analysis_data']) ? '✅' : '❌') . "<br>";
                                echo "    사용자 데이터: " . (isset($product['user_data']) ? '✅' : '❌') . "<br>";
                                echo "    HTML: " . (isset($product['generated_html']) ? '✅' : '❌') . "<br>";
                                
                                if (isset($product['user_data']) && is_array($product['user_data'])) {
                                    echo "    사용자 데이터 구조:<br>";
                                    echo "      specs: " . (isset($product['user_data']['specs']) ? '✅' : '❌') . "<br>";
                                    echo "      efficiency: " . (isset($product['user_data']['efficiency']) ? '✅' : '❌') . "<br>";
                                    echo "      usage: " . (isset($product['user_data']['usage']) ? '✅' : '❌') . "<br>";
                                    echo "      benefits: " . (isset($product['user_data']['benefits']) ? '✅' : '❌') . "<br>";
                                }
                                echo "<br>";
                            }
                        }
                    }
                }
                
                // JSON 출력 테스트
                echo "<h3>JSON 인코딩 테스트:</h3>";
                $json_output = json_encode($first_item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if ($json_output === false) {
                    echo "❌ JSON 인코딩 실패: " . json_last_error_msg() . "<br>";
                } else {
                    echo "✅ JSON 인코딩 성공<br>";
                    echo "📄 출력 길이: " . strlen($json_output) . " 문자<br>";
                }
            }
        }
    }
} else {
    echo "❌ 파일이 존재하지 않음: " . QUEUE_FILE . "<br>";
}

// AJAX 시뮬레이션 테스트
echo "<h2>2. AJAX 시뮬레이션 테스트</h2>";

function load_queue_test() {
    if (!file_exists(QUEUE_FILE)) return [];
    $content = file_get_contents(QUEUE_FILE);
    if ($content === false) return [];
    $queue = json_decode($content, true);
    return is_array($queue) ? $queue : [];
}

$queue = load_queue_test();
if (count($queue) > 0) {
    $first_queue_id = $queue[0]['queue_id'];
    echo "🎯 테스트 대상 Queue ID: {$first_queue_id}<br>";
    
    // get_queue_item 시뮬레이션
    $found_item = null;
    foreach ($queue as $item) {
        if ($item['queue_id'] === $first_queue_id) {
            $found_item = $item;
            break;
        }
    }
    
    if ($found_item) {
        echo "✅ 큐 항목 검색 성공<br>";
        
        $response = [
            'success' => true,
            'item' => $found_item
        ];
        
        $json_response = json_encode($response, JSON_UNESCAPED_UNICODE);
        if ($json_response === false) {
            echo "❌ 응답 JSON 인코딩 실패: " . json_last_error_msg() . "<br>";
        } else {
            echo "✅ 응답 JSON 인코딩 성공<br>";
            echo "📄 응답 크기: " . strlen($json_response) . " 문자<br>";
            
            // 응답 데이터 재파싱 테스트
            $parsed_response = json_decode($json_response, true);
            if ($parsed_response === null) {
                echo "❌ 응답 재파싱 실패<br>";
            } else {
                echo "✅ 응답 재파싱 성공<br>";
                echo "📊 응답 데이터 키워드 수: " . (isset($parsed_response['item']['keywords']) ? count($parsed_response['item']['keywords']) : 0) . "<br>";
            }
        }
    } else {
        echo "❌ 큐 항목 검색 실패<br>";
    }
} else {
    echo "❌ 큐가 비어있음<br>";
}

echo "<h2>3. 실제 PHP 메모리 및 설정 확인</h2>";
echo "📊 PHP 메모리 제한: " . ini_get('memory_limit') . "<br>";
echo "📊 현재 메모리 사용량: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB<br>";
echo "📊 최대 메모리 사용량: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB<br>";
echo "📊 JSON 확장: " . (extension_loaded('json') ? '✅' : '❌') . "<br>";

?>

<h2>4. 브라우저 테스트</h2>
<button onclick="testAjaxCall()">AJAX 호출 테스트</button>
<div id="ajax-result"></div>

<script>
async function testAjaxCall() {
    const resultDiv = document.getElementById('ajax-result');
    resultDiv.innerHTML = '테스트 중...';
    
    try {
        // 첫 번째 큐 ID 가져오기
        const listResponse = await fetch('queue_manager.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_queue_list'
        });
        
        const listResult = await listResponse.json();
        console.log('큐 목록 응답:', listResult);
        
        if (listResult.success && listResult.queue.length > 0) {
            const firstQueueId = listResult.queue[0].queue_id;
            resultDiv.innerHTML += `<br>✅ 큐 목록 로드 성공: ${listResult.queue.length}개 항목<br>`;
            resultDiv.innerHTML += `🎯 첫 번째 Queue ID: ${firstQueueId}<br>`;
            
            // 개별 큐 항목 가져오기
            const itemResponse = await fetch('queue_manager.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=get_queue_item&queue_id=${encodeURIComponent(firstQueueId)}`
            });
            
            const itemText = await itemResponse.text();
            console.log('개별 항목 응답 텍스트:', itemText);
            
            try {
                const itemResult = JSON.parse(itemText);
                console.log('개별 항목 파싱 결과:', itemResult);
                
                if (itemResult.success) {
                    resultDiv.innerHTML += `✅ 개별 항목 로드 성공<br>`;
                    resultDiv.innerHTML += `📝 제목: ${itemResult.item.title}<br>`;
                    resultDiv.innerHTML += `🏷️ 키워드 수: ${itemResult.item.keywords ? itemResult.item.keywords.length : 0}<br>`;
                    
                    if (itemResult.item.keywords && itemResult.item.keywords.length > 0) {
                        const firstKeyword = itemResult.item.keywords[0];
                        resultDiv.innerHTML += `🔍 첫 번째 키워드: ${firstKeyword.name}<br>`;
                        resultDiv.innerHTML += `🔗 상품 URL 수: ${firstKeyword.aliexpress ? firstKeyword.aliexpress.length : 0}<br>`;
                        resultDiv.innerHTML += `📦 상품 데이터 수: ${firstKeyword.products_data ? firstKeyword.products_data.length : 0}<br>`;
                        
                        if (firstKeyword.products_data && firstKeyword.products_data.length > 0) {
                            const firstProduct = firstKeyword.products_data[0];
                            resultDiv.innerHTML += `📄 첫 번째 상품 URL: ${firstProduct.url}<br>`;
                            resultDiv.innerHTML += `🔍 분석 데이터: ${firstProduct.analysis_data ? '✅' : '❌'}<br>`;
                            resultDiv.innerHTML += `👤 사용자 데이터: ${firstProduct.user_data ? '✅' : '❌'}<br>`;
                            
                            if (firstProduct.user_data) {
                                resultDiv.innerHTML += `📊 사용자 데이터 구조: ${JSON.stringify(Object.keys(firstProduct.user_data))}<br>`;
                            }
                        }
                    }
                } else {
                    resultDiv.innerHTML += `❌ 개별 항목 로드 실패: ${itemResult.message}<br>`;
                }
            } catch (parseError) {
                resultDiv.innerHTML += `❌ JSON 파싱 오류: ${parseError.message}<br>`;
                resultDiv.innerHTML += `📄 응답 텍스트 일부: ${itemText.substring(0, 200)}...<br>`;
            }
        } else {
            resultDiv.innerHTML += `❌ 큐 목록 로드 실패<br>`;
        }
    } catch (error) {
        console.error('AJAX 테스트 오류:', error);
        resultDiv.innerHTML += `❌ AJAX 오류: ${error.message}<br>`;
    }
}
</script>