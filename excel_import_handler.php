<?php
/**
 * 엑셀 파일 업로드 및 파싱 핸들러
 * affiliate_editor.php와 연동하여 구글 시트 데이터를 자동으로 입력
 * product_save_list.php의 27개 열 CSV 형식과 호환
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
    
    // 데이터 변환 - affiliate_editor.php의 processExcelData에서 기대하는 형식
    $processedData = convertToProcessExcelDataFormat($data);
    
    // 업로드된 파일 삭제
    if (file_exists($uploadedFile['tmp_name'])) {
        unlink($uploadedFile['tmp_name']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $processedData,
        'total_products' => count($processedData)
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
 * CSV 파일 파싱 - product_save_list.php에서 생성된 27개 열 구조 지원
 */
function parseCSVFile($filePath) {
    $data = [];
    $handle = fopen($filePath, 'r');
    
    if (!$handle) {
        throw new Exception('파일을 읽을 수 없습니다.');
    }
    
    // UTF-8 BOM 제거
    $firstLine = fgets($handle);
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
        fseek($handle, 3);
    } else {
        rewind($handle);
    }
    
    // 헤더 읽기
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new Exception('CSV 파일이 비어있습니다.');
    }
    
    // 헤더 인덱스 매핑
    $headerMap = array_flip($header);
    
    // 필수 컬럼 확인 - product_save_list.php 기준으로 수정
    $requiredColumns = ['키워드', '상품URL'];
    foreach ($requiredColumns as $column) {
        if (!isset($headerMap[$column])) {
            fclose($handle);
            throw new Exception("필수 컬럼 '{$column}'이(가) 없습니다. 현재 헤더: " . implode(', ', $header));
        }
    }
    
    // 데이터 읽기
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 2) { // 최소 키워드, 상품URL 필요
            continue; // 불완전한 행 스킵
        }
        
        $product = [];
        
        // 기본 정보 - product_save_list.php의 27개 열 구조 기준
        $product['keyword'] = trim($row[$headerMap['키워드']] ?? '');
        $product['product_url'] = trim($row[$headerMap['상품URL']] ?? '');
        
        // 상품명 - product_save_list.php에서는 '상품명'으로 출력됨
        $product['product_name'] = trim($row[$headerMap['상품명']] ?? '');
        
        // 빈 행 스킵 - 키워드와 상품URL이 모두 있어야 함
        if (empty($product['keyword']) || empty($product['product_url'])) {
            continue;
        }
        
        // 상품명이 없으면 기본값 설정
        if (empty($product['product_name'])) {
            $product['product_name'] = '상품 정보';
        }
        
        // 상세 정보 - 기능/스펙 (product_save_list.php 헤더 기준)
        $product['main_function'] = trim($row[$headerMap['주요기능']] ?? '');
        $product['size_capacity'] = trim($row[$headerMap['크기/용량']] ?? '');
        $product['color'] = trim($row[$headerMap['색상']] ?? '');
        $product['material'] = trim($row[$headerMap['재질/소재']] ?? '');
        $product['power_battery'] = trim($row[$headerMap['전원/배터리']] ?? '');
        
        // 상세 정보 - 효율성
        $product['problem_solving'] = trim($row[$headerMap['해결하는문제']] ?? '');
        $product['time_saving'] = trim($row[$headerMap['시간절약']] ?? '');
        $product['space_efficiency'] = trim($row[$headerMap['공간활용']] ?? '');
        $product['cost_saving'] = trim($row[$headerMap['비용절감']] ?? '');
        
        // 상세 정보 - 사용법
        $product['usage_location'] = trim($row[$headerMap['사용장소']] ?? '');
        $product['usage_frequency'] = trim($row[$headerMap['사용빈도']] ?? '');
        $product['target_users'] = trim($row[$headerMap['적합한사용자']] ?? '');
        $product['usage_method'] = trim($row[$headerMap['사용법']] ?? '');
        
        // 상세 정보 - 장점/주의사항
        $product['advantage1'] = trim($row[$headerMap['장점1']] ?? '');
        $product['advantage2'] = trim($row[$headerMap['장점2']] ?? '');
        $product['advantage3'] = trim($row[$headerMap['장점3']] ?? '');
        $product['precautions'] = trim($row[$headerMap['주의사항']] ?? '');
        
        $data[] = $product;
    }
    
    fclose($handle);
    
    if (empty($data)) {
        throw new Exception('엑셀파일에서 유효한 데이터를 찾을 수 없습니다. 키워드와 상품URL이 모두 입력된 행이 있는지 확인해주세요.');
    }
    
    return $data;
}

/**
 * affiliate_editor.php의 processExcelData에서 기대하는 형식으로 변환
 */
function convertToProcessExcelDataFormat($data) {
    $processedData = [];
    
    foreach ($data as $product) {
        // affiliate_editor.php의 processExcelData에서 찾는 필드들
        $item = [
            'keyword' => $product['keyword'],
            'url' => $product['product_url'], // processExcelData에서 찾는 필드
            'product_detail' => $product['product_name'], // processExcelData에서 찾는 필드
            
            // 상세 정보를 별도 필드로 추가 (affiliate_editor에서 자동으로 사용자 입력 필드에 채워짐)
            'user_details' => [
                'specs' => [
                    'main_function' => $product['main_function'],
                    'size_capacity' => $product['size_capacity'],
                    'color' => $product['color'],
                    'material' => $product['material'],
                    'power_battery' => $product['power_battery']
                ],
                'efficiency' => [
                    'problem_solving' => $product['problem_solving'],
                    'time_saving' => $product['time_saving'],
                    'space_efficiency' => $product['space_efficiency'],
                    'cost_saving' => $product['cost_saving']
                ],
                'usage' => [
                    'usage_location' => $product['usage_location'],
                    'usage_frequency' => $product['usage_frequency'],
                    'target_users' => $product['target_users'],
                    'usage_method' => $product['usage_method']
                ],
                'benefits' => [
                    'advantages' => array_filter([
                        $product['advantage1'],
                        $product['advantage2'],
                        $product['advantage3']
                    ]),
                    'precautions' => $product['precautions']
                ]
            ]
        ];
        
        $processedData[] = $item;
    }
    
    return $processedData;
}
?>