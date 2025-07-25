<?php
/**
 * 엑셀 파일 업로드 및 파싱 핸들러
 * affiliate_editor.php와 연동하여 구글 시트 데이터를 자동으로 입력
 */
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
if(!current_user_can('manage_options')) wp_die('접근 권한이 없습니다.');

// 에러 리포팅 설정
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('잘못된 요청입니다.');
    }
    
    if (!isset($_FILES['excel_file'])) {
        throw new Exception('파일이 업로드되지 않았습니다.');
    }
    
    $uploadedFile = $_FILES['excel_file'];
    
    // 파일 업로드 에러 체크
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('파일 업로드 중 오류가 발생했습니다: ' . $uploadedFile['error']);
    }
    
    // 파일 확장자 확인
    $allowedExtensions = ['csv', 'xls', 'xlsx'];
    $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedExtensions)) {
        throw new Exception('지원하지 않는 파일 형식입니다. CSV 또는 Excel 파일만 업로드 가능합니다.');
    }
    
    // CSV 파일 처리
    if ($fileExtension === 'csv') {
        $data = parseCSVFile($uploadedFile['tmp_name']);
    } else {
        // Excel 파일은 CSV로 변환 후 처리하도록 안내
        throw new Exception('현재는 CSV 파일만 지원합니다. 구글 시트에서 CSV로 내보내기 해주세요.');
    }
    
    // 데이터 변환
    $keywords = convertToKeywordStructure($data);
    
    // 업로드된 파일 삭제
    if (file_exists($uploadedFile['tmp_name'])) {
        unlink($uploadedFile['tmp_name']);
    }
    
    echo json_encode([
        'success' => true,
        'keywords' => $keywords,
        'total_products' => count($data)
    ]);
    
} catch (Exception $e) {
    // 오류 발생 시에도 파일 삭제
    if (isset($uploadedFile) && file_exists($uploadedFile['tmp_name'])) {
        unlink($uploadedFile['tmp_name']);
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * CSV 파일 파싱
 */
function parseCSVFile($filePath) {
    $data = [];
    $handle = fopen($filePath, 'r');
    
    if (!$handle) {
        throw new Exception('파일을 읽을 수 없습니다.');
    }
    
    // 헤더 읽기
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new Exception('CSV 파일이 비어있습니다.');
    }
    
    // 헤더 인덱스 매핑
    $headerMap = array_flip($header);
    
    // 필수 컬럼 확인
    $requiredColumns = ['키워드', '상품URL'];
    foreach ($requiredColumns as $column) {
        if (!isset($headerMap[$column])) {
            fclose($handle);
            throw new Exception("필수 컬럼 '{$column}'이(가) 없습니다.");
        }
    }
    
    // 데이터 읽기
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < count($header)) {
            continue; // 불완전한 행 스킵
        }
        
        $product = [];
        
        // 기본 정보
        $product['keyword'] = $row[$headerMap['키워드']] ?? '';
        $product['product_url'] = $row[$headerMap['상품URL']] ?? '';
        
        // 빈 행 스킵
        if (empty($product['keyword']) || empty($product['product_url'])) {
            continue;
        }
        
        // 상세 정보 - 기능/스펙
        if (isset($headerMap['주요기능'])) {
            $product['main_function'] = $row[$headerMap['주요기능']] ?? '';
        }
        if (isset($headerMap['크기/용량'])) {
            $product['size_capacity'] = $row[$headerMap['크기/용량']] ?? '';
        }
        if (isset($headerMap['색상'])) {
            $product['color'] = $row[$headerMap['색상']] ?? '';
        }
        if (isset($headerMap['재질/소재'])) {
            $product['material'] = $row[$headerMap['재질/소재']] ?? '';
        }
        if (isset($headerMap['전원/배터리'])) {
            $product['power_battery'] = $row[$headerMap['전원/배터리']] ?? '';
        }
        
        // 상세 정보 - 효율성
        if (isset($headerMap['해결하는문제'])) {
            $product['problem_solving'] = $row[$headerMap['해결하는문제']] ?? '';
        }
        if (isset($headerMap['시간절약'])) {
            $product['time_saving'] = $row[$headerMap['시간절약']] ?? '';
        }
        if (isset($headerMap['공간활용'])) {
            $product['space_efficiency'] = $row[$headerMap['공간활용']] ?? '';
        }
        if (isset($headerMap['비용절감'])) {
            $product['cost_saving'] = $row[$headerMap['비용절감']] ?? '';
        }
        
        // 상세 정보 - 사용법
        if (isset($headerMap['사용장소'])) {
            $product['usage_location'] = $row[$headerMap['사용장소']] ?? '';
        }
        if (isset($headerMap['사용빈도'])) {
            $product['usage_frequency'] = $row[$headerMap['사용빈도']] ?? '';
        }
        if (isset($headerMap['적합한사용자'])) {
            $product['target_users'] = $row[$headerMap['적합한사용자']] ?? '';
        }
        if (isset($headerMap['사용법'])) {
            $product['usage_method'] = $row[$headerMap['사용법']] ?? '';
        }
        
        // 상세 정보 - 장점/주의사항
        if (isset($headerMap['장점1'])) {
            $product['advantage1'] = $row[$headerMap['장점1']] ?? '';
        }
        if (isset($headerMap['장점2'])) {
            $product['advantage2'] = $row[$headerMap['장점2']] ?? '';
        }
        if (isset($headerMap['장점3'])) {
            $product['advantage3'] = $row[$headerMap['장점3']] ?? '';
        }
        if (isset($headerMap['주의사항'])) {
            $product['precautions'] = $row[$headerMap['주의사항']] ?? '';
        }
        
        $data[] = $product;
    }
    
    fclose($handle);
    
    if (empty($data)) {
        throw new Exception('유효한 데이터가 없습니다.');
    }
    
    return $data;
}

/**
 * 데이터를 affiliate_editor.php에서 사용하는 키워드 구조로 변환
 */
function convertToKeywordStructure($data) {
    $keywords = [];
    $keywordMap = [];
    
    foreach ($data as $product) {
        $keywordName = $product['keyword'];
        
        // 키워드가 없으면 키워드 맵에 추가
        if (!isset($keywordMap[$keywordName])) {
            $keywordMap[$keywordName] = count($keywords);
            $keywords[] = [
                'name' => $keywordName,
                'products' => []
            ];
        }
        
        $keywordIndex = $keywordMap[$keywordName];
        
        // 상품 정보 구성
        $productData = [
            'id' => time() . '_' . rand(1000, 9999),
            'url' => $product['product_url'],
            'name' => '상품 ' . (count($keywords[$keywordIndex]['products']) + 1),
            'status' => 'empty',
            'analysisData' => null,
            'userData' => [
                'specs' => [
                    'main_function' => $product['main_function'] ?? '',
                    'size_capacity' => $product['size_capacity'] ?? '',
                    'color' => $product['color'] ?? '',
                    'material' => $product['material'] ?? '',
                    'power_battery' => $product['power_battery'] ?? ''
                ],
                'efficiency' => [
                    'problem_solving' => $product['problem_solving'] ?? '',
                    'time_saving' => $product['time_saving'] ?? '',
                    'space_efficiency' => $product['space_efficiency'] ?? '',
                    'cost_saving' => $product['cost_saving'] ?? ''
                ],
                'usage' => [
                    'usage_location' => $product['usage_location'] ?? '',
                    'usage_frequency' => $product['usage_frequency'] ?? '',
                    'target_users' => $product['target_users'] ?? '',
                    'usage_method' => $product['usage_method'] ?? ''
                ],
                'benefits' => [
                    'advantages' => array_filter([
                        $product['advantage1'] ?? '',
                        $product['advantage2'] ?? '',
                        $product['advantage3'] ?? ''
                    ]),
                    'precautions' => $product['precautions'] ?? ''
                ]
            ],
            'isSaved' => false,
            'generatedHtml' => null
        ];
        
        $keywords[$keywordIndex]['products'][] = $productData;
    }
    
    return $keywords;
}
?>