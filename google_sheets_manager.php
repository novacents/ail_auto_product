<?php
/**
 * Google Sheets API 연동 관리자
 * 상품 발굴 데이터를 구글 시트에 저장하고 관리
 * gdrive_config.php와 동일한 방식으로 Python 스크립트 사용
 */

class GoogleSheetsManager {
    private $spreadsheetName = '상품 발굴 데이터';
    private $python_path;
    private $token_file;
    
    // 구글 시트 열 구조 (상세 정보 포함)
    private $headers = [
        'ID', '키워드', '상품명', '가격', '평점', '판매량', 
        '이미지URL', '상품URL', '어필리에이트링크', '생성일시',
        '주요기능', '크기/용량', '색상', '재질/소재', '전원/배터리',
        '해결하는문제', '시간절약', '공간활용', '비용절감',
        '사용장소', '사용빈도', '적합한사용자', '사용법',
        '장점1', '장점2', '장점3', '주의사항'
    ];
    
    public function __construct() {
        $this->python_path = '/usr/bin/python3';
        $this->token_file = '/var/www/novacents/tools/google_token.json';
        
        // OAuth 토큰 파일 존재 확인
        if (!file_exists($this->token_file)) {
            throw new Exception("OAuth 토큰 파일을 찾을 수 없습니다. oauth_setup.php를 먼저 실행하세요: {$this->token_file}");
        }
    }
    
    /**
     * Python 스크립트를 실행하여 Google Sheets API 호출
     */
    private function executePythonScript($script_content) {
        $temp_script = tempnam(sys_get_temp_dir(), 'gsheets_');
        file_put_contents($temp_script, $script_content);
        chmod($temp_script, 0755);
        
        try {
            // stderr를 별도 파일로 리다이렉트하여 JSON과 분리
            $error_log = tempnam(sys_get_temp_dir(), 'gsheets_error_');
            
            $output = [];
            $return_code = 0;
            exec("{$this->python_path} {$temp_script} 2>{$error_log}", $output, $return_code);
            
            // 에러 로그 읽기 (디버깅용)
            $error_output = file_exists($error_log) ? file_get_contents($error_log) : '';
            
            // 임시 파일들 삭제
            unlink($temp_script);
            if (file_exists($error_log)) {
                unlink($error_log);
            }
            
            if ($return_code !== 0) {
                throw new Exception("Python 스크립트 실행 실패 (종료 코드: {$return_code})\nSTDOUT: " . implode("\n", $output) . "\nSTDERR: " . $error_output);
            }
            
            $result = implode("\n", $output);
            
            // 빈 결과 확인
            if (empty(trim($result))) {
                throw new Exception("Python 스크립트가 빈 결과를 반환했습니다.\nSTDERR: " . $error_output);
            }
            
            $json_result = json_decode($result, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON 파싱 실패 (JSON 오류: " . json_last_error_msg() . ")\nPython 출력: " . $result . "\nSTDERR: " . $error_output);
            }
            
            return $json_result;
        } catch (Exception $e) {
            if (file_exists($temp_script)) {
                unlink($temp_script);
            }
            if (isset($error_log) && file_exists($error_log)) {
                unlink($error_log);
            }
            throw $e;
        }
    }
    
    /**
     * 스프레드시트 생성 또는 기존 시트 찾기
     */
    public function getOrCreateSpreadsheet() {
        // PHP 배열을 Python 리스트 문자열로 변환
        $headers_str = "['" . implode("', '", $this->headers) . "']";
        
        $script = <<<PYTHON
import json
import sys
import os
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build

try:
    # OAuth 토큰 로드
    creds = None
    token_file = '{$this->token_file}'
    
    if os.path.exists(token_file):
        creds = Credentials.from_authorized_user_file(token_file, [
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive'
        ])
    
    if not creds:
        raise Exception("OAuth 토큰을 찾을 수 없습니다. oauth_setup.php를 실행하세요.")
    
    # 토큰 갱신 필요 시 자동 갱신
    if creds.expired and creds.refresh_token:
        creds.refresh(Request())
        # 갱신된 토큰 저장
        with open(token_file, 'w') as token:
            token.write(creds.to_json())
    
    # Sheets API 및 Drive API 서비스 생성
    sheets_service = build('sheets', 'v4', credentials=creds)
    drive_service = build('drive', 'v3', credentials=creds)
    
    # 기존 스프레드시트 검색
    query = f"name='{$this->spreadsheetName}' and mimeType='application/vnd.google-apps.spreadsheet'"
    results = drive_service.files().list(q=query, fields="files(id, name)").execute()
    
    files = results.get('files', [])
    
    if files:
        # 기존 스프레드시트 사용
        spreadsheet_id = files[0]['id']
        print(json.dumps({
            'success': True,
            'spreadsheet_id': spreadsheet_id,
            'spreadsheet_url': f'https://docs.google.com/spreadsheets/d/{spreadsheet_id}',
            'action': 'found_existing'
        }))
    else:
        # 새 스프레드시트 생성
        spreadsheet = {
            'properties': {
                'title': '{$this->spreadsheetName}'
            },
            'sheets': [{
                'properties': {
                    'title': 'Sheet1'
                }
            }]
        }
        
        result = sheets_service.spreadsheets().create(body=spreadsheet).execute()
        spreadsheet_id = result['spreadsheetId']
        
        # 헤더 추가
        headers = {$headers_str}
        
        sheets_service.spreadsheets().values().update(
            spreadsheetId=spreadsheet_id,
            range='Sheet1!A1:AA1',
            valueInputOption='RAW',
            body={'values': [headers]}
        ).execute()
        
        # 헤더 스타일링
        requests = [{
            'repeatCell': {
                'range': {
                    'sheetId': 0,
                    'startRowIndex': 0,
                    'endRowIndex': 1,
                    'startColumnIndex': 0,
                    'endColumnIndex': len(headers)
                },
                'cell': {
                    'userEnteredFormat': {
                        'backgroundColor': {
                            'red': 0.9,
                            'green': 0.9,
                            'blue': 0.9
                        },
                        'textFormat': {
                            'bold': True
                        }
                    }
                },
                'fields': 'userEnteredFormat(backgroundColor,textFormat)'
            }
        }]
        
        sheets_service.spreadsheets().batchUpdate(
            spreadsheetId=spreadsheet_id,
            body={'requests': requests}
        ).execute()
        
        print(json.dumps({
            'success': True,
            'spreadsheet_id': spreadsheet_id,
            'spreadsheet_url': f'https://docs.google.com/spreadsheets/d/{spreadsheet_id}',
            'action': 'created_new'
        }))
    
except Exception as e:
    print(json.dumps({
        'success': False,
        'error': str(e)
    }))
PYTHON;

        return $this->executePythonScript($script);
    }
    
    /**
     * 단일 상품 데이터 추가
     */
    public function addProduct($productData) {
        // 데이터 변환
        $row = $this->convertProductToRow($productData);
        $row_json = json_encode($row);
        
        $script = <<<PYTHON
import json
import sys
import os
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build

try:
    # OAuth 토큰 로드
    creds = None
    token_file = '{$this->token_file}'
    
    if os.path.exists(token_file):
        creds = Credentials.from_authorized_user_file(token_file, [
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive'
        ])
    
    if not creds:
        raise Exception("OAuth 토큰을 찾을 수 없습니다. oauth_setup.php를 실행하세요.")
    
    # 토큰 갱신 필요 시 자동 갱신
    if creds.expired and creds.refresh_token:
        creds.refresh(Request())
        with open(token_file, 'w') as token:
            token.write(creds.to_json())
    
    # Sheets API 및 Drive API 서비스 생성
    sheets_service = build('sheets', 'v4', credentials=creds)
    drive_service = build('drive', 'v3', credentials=creds)
    
    # 스프레드시트 찾기
    query = f"name='{$this->spreadsheetName}' and mimeType='application/vnd.google-apps.spreadsheet'"
    results = drive_service.files().list(q=query, fields="files(id, name)").execute()
    files = results.get('files', [])
    
    if not files:
        raise Exception("스프레드시트를 찾을 수 없습니다. 먼저 getOrCreateSpreadsheet()를 호출하세요.")
    
    spreadsheet_id = files[0]['id']
    
    # 다음 빈 행 찾기
    range_result = sheets_service.spreadsheets().values().get(
        spreadsheetId=spreadsheet_id,
        range='Sheet1!A:A'
    ).execute()
    
    values = range_result.get('values', [])
    next_row = len(values) + 1
    
    # 데이터 추가
    row_data = {$row_json}
    
    sheets_service.spreadsheets().values().update(
        spreadsheetId=spreadsheet_id,
        range=f'Sheet1!A{next_row}:AA{next_row}',
        valueInputOption='RAW',
        body={'values': [row_data]}
    ).execute()
    
    print(json.dumps({
        'success': True,
        'row': next_row,
        'spreadsheet_id': spreadsheet_id,
        'spreadsheet_url': f'https://docs.google.com/spreadsheets/d/{spreadsheet_id}'
    }))
    
except Exception as e:
    print(json.dumps({
        'success': False,
        'error': str(e)
    }))
PYTHON;

        return $this->executePythonScript($script);
    }
    
    /**
     * 여러 상품 데이터 일괄 추가
     */
    public function addProducts($productsData) {
        // 데이터 변환
        $rows = [];
        foreach ($productsData as $productData) {
            $rows[] = $this->convertProductToRow($productData);
        }
        $rows_json = json_encode($rows);
        
        $script = <<<PYTHON
import json
import sys
import os
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build

try:
    # OAuth 토큰 로드
    creds = None
    token_file = '{$this->token_file}'
    
    if os.path.exists(token_file):
        creds = Credentials.from_authorized_user_file(token_file, [
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive'
        ])
    
    if not creds:
        raise Exception("OAuth 토큰을 찾을 수 없습니다. oauth_setup.php를 실행하세요.")
    
    # 토큰 갱신 필요 시 자동 갱신
    if creds.expired and creds.refresh_token:
        creds.refresh(Request())
        with open(token_file, 'w') as token:
            token.write(creds.to_json())
    
    # Sheets API 및 Drive API 서비스 생성
    sheets_service = build('sheets', 'v4', credentials=creds)
    drive_service = build('drive', 'v3', credentials=creds)
    
    # 스프레드시트 찾기
    query = f"name='{$this->spreadsheetName}' and mimeType='application/vnd.google-apps.spreadsheet'"
    results = drive_service.files().list(q=query, fields="files(id, name)").execute()
    files = results.get('files', [])
    
    if not files:
        raise Exception("스프레드시트를 찾을 수 없습니다. 먼저 getOrCreateSpreadsheet()를 호출하세요.")
    
    spreadsheet_id = files[0]['id']
    
    # 다음 빈 행 찾기
    range_result = sheets_service.spreadsheets().values().get(
        spreadsheetId=spreadsheet_id,
        range='Sheet1!A:A'
    ).execute()
    
    values = range_result.get('values', [])
    next_row = len(values) + 1
    
    # 데이터 일괄 추가
    rows_data = {$rows_json}
    end_row = next_row + len(rows_data) - 1
    
    sheets_service.spreadsheets().values().update(
        spreadsheetId=spreadsheet_id,
        range=f'Sheet1!A{next_row}:AA{end_row}',
        valueInputOption='RAW',
        body={'values': rows_data}
    ).execute()
    
    print(json.dumps({
        'success': True,
        'rows_added': len(rows_data),
        'start_row': next_row,
        'end_row': end_row,
        'spreadsheet_id': spreadsheet_id,
        'spreadsheet_url': f'https://docs.google.com/spreadsheets/d/{spreadsheet_id}'
    }))
    
except Exception as e:
    print(json.dumps({
        'success': False,
        'error': str(e)
    }))
PYTHON;

        return $this->executePythonScript($script);
    }
    
    /**
     * 상품 데이터를 시트 행으로 변환 (상세 정보 포함)
     */
    private function convertProductToRow($productData) {
        // 기본 상품 정보
        $row = [
            $productData['id'] ?? '',
            $productData['keyword'] ?? '',
            $productData['product_data']['title'] ?? '',
            $productData['product_data']['price'] ?? '',
            $productData['product_data']['rating_display'] ?? '',
            $productData['product_data']['lastest_volume'] ?? '',
            $productData['product_data']['image_url'] ?? '',
            $productData['product_url'] ?? '',
            $productData['product_data']['affiliate_link'] ?? '',
            $productData['created_at'] ?? date('Y-m-d H:i:s')
        ];
        
        // 상세 정보 추가
        $userDetails = $productData['user_details'] ?? [];
        
        // 기능/스펙 정보
        $specs = $userDetails['specs'] ?? [];
        $row[] = $specs['main_function'] ?? '';
        $row[] = $specs['size_capacity'] ?? '';
        $row[] = $specs['color'] ?? '';
        $row[] = $specs['material'] ?? '';
        $row[] = $specs['power_battery'] ?? '';
        
        // 효율성 정보
        $efficiency = $userDetails['efficiency'] ?? [];
        $row[] = $efficiency['problem_solving'] ?? '';
        $row[] = $efficiency['time_saving'] ?? '';
        $row[] = $efficiency['space_efficiency'] ?? '';
        $row[] = $efficiency['cost_saving'] ?? '';
        
        // 사용 시나리오 정보
        $usage = $userDetails['usage'] ?? [];
        $row[] = $usage['usage_location'] ?? '';
        $row[] = $usage['usage_frequency'] ?? '';
        $row[] = $usage['target_users'] ?? '';
        $row[] = $usage['usage_method'] ?? '';
        
        // 장점/주의사항 정보
        $benefits = $userDetails['benefits'] ?? [];
        $advantages = $benefits['advantages'] ?? [];
        $row[] = $advantages[0] ?? '';
        $row[] = $advantages[1] ?? '';
        $row[] = $advantages[2] ?? '';
        $row[] = $benefits['precautions'] ?? '';
        
        return $row;
    }
    
    /**
     * 스프레드시트 URL 가져오기
     */
    public function getSpreadsheetUrl() {
        $result = $this->getOrCreateSpreadsheet();
        if ($result['success']) {
            return $result['spreadsheet_url'];
        }
        throw new Exception('스프레드시트 생성/조회 실패: ' . $result['error']);
    }
    
    /**
     * 스프레드시트 ID 가져오기
     */
    public function getSpreadsheetId() {
        $result = $this->getOrCreateSpreadsheet();
        if ($result['success']) {
            return $result['spreadsheet_id'];
        }
        throw new Exception('스프레드시트 생성/조회 실패: ' . $result['error']);
    }
    
    /**
     * 인증 상태 확인
     */
    public function isAuthenticated() {
        return file_exists($this->token_file);
    }
}

// 헬퍼 함수들

/**
 * JSON 응답 전송
 */
function send_json_response($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * 에러 응답 전송
 */
function send_error_response($message, $code = 500) {
    http_response_code($code);
    send_json_response([
        'success' => false,
        'error' => $message
    ]);
}

/**
 * 성공 응답 전송
 */
function send_success_response($data) {
    send_json_response(array_merge(
        ['success' => true],
        $data
    ));
}
?>