<?php
/**
 * 상품 정보 분석 API
 * 쿠팡 파트너스 API와 알리익스프레스 API를 사용하여 상품 정보를 실시간으로 분석
 */

// 워드프레스 환경 로드
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');

// 관리자 권한 확인
if (!current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '접근 권한이 없습니다.']);
    exit;
}

// AJAX 요청만 처리
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

// 요청 데이터 파싱
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '잘못된 요청 데이터입니다.']);
    exit;
}

$action = $input['action'] ?? '';
$url = $input['url'] ?? '';
$platform = $input['platform'] ?? '';

if (!$action || !$url || !$platform) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '필수 파라미터가 누락되었습니다.']);
    exit;
}

// 헤더 설정
header('Content-Type: application/json');

if ($action === 'analyze_product') {
    analyzeProduct($url, $platform);
} else {
    echo json_encode(['success' => false, 'message' => '지원하지 않는 액션입니다.']);
}

/**
 * 상품 정보 분석 함수
 */
function analyzeProduct($url, $platform) {
    try {
        // Python 스크립트 경로 (간단한 버전 사용)
        $script_path = '/var/www/novacents/tools/simple_product_analyzer.py';
        
        // Python 스크립트가 없으면 생성
        if (!file_exists($script_path)) {
            createProductAnalyzerScript($script_path);
        }
        
        // Python 스크립트 실행
        $command = escapeshellcmd("/usr/bin/python3 $script_path") . " " . 
                   escapeshellarg($platform) . " " . 
                   escapeshellarg($url) . " 2>&1";
        
        $output = shell_exec($command);
        
        if (empty($output)) {
            throw new Exception('Python 스크립트 실행 결과가 없습니다.');
        }
        
        // JSON 응답 파싱
        $result = json_decode($output, true);
        
        if ($result === null) {
            throw new Exception('Python 스크립트 응답 파싱 실패: ' . $output);
        }
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '상품 분석 중 오류가 발생했습니다: ' . $e->getMessage(),
            'raw_output' => $output ?? null
        ]);
    }
}

/**
 * Python 상품 분석 스크립트 생성
 */
function createProductAnalyzerScript($script_path) {
    $script_content = '#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
상품 정보 분석 스크립트
쿠팡 파트너스 API와 알리익스프레스 API를 사용하여 상품 정보를 추출
"""

import sys
import json
import os
from datetime import datetime

# SafeAPIManager 경로 추가
sys.path.append("/var/www/novacents/tools")
from safe_api_manager import SafeAPIManager

# 알리익스프레스 SDK 경로 추가
sys.path.append("/home/novacents/aliexpress-sdk")
import iop

def analyze_coupang_product(url):
    """쿠팡 상품 분석"""
    try:
        # SafeAPIManager 초기화
        api_manager = SafeAPIManager(mode="production")
        
        # 상품 ID 추출
        import re
        product_id_match = re.search(r\'/products/(\d+)\', url)
        if not product_id_match:
            return {
                "success": False,
                "message": "유효한 쿠팡 상품 URL이 아닙니다.",
                "url": url
            }
        
        product_id = product_id_match.group(1)
        
        # 상품 정보 검색
        success, products = api_manager.search_coupang_safe(product_id, limit=1)
        
        if not success or not products:
            return {
                "success": False,
                "message": "상품 정보를 찾을 수 없습니다.",
                "product_id": product_id,
                "url": url
            }
        
        product = products[0]
        
        # 어필리에이트 링크 변환
        convert_success, affiliate_link, convert_product_info = api_manager.convert_coupang_to_affiliate_link(url)
        
        return {
            "success": True,
            "platform": "쿠팡",
            "product_id": product_id,
            "original_url": url,
            "affiliate_url": affiliate_link if convert_success else "변환 실패",
            "product_info": {
                "title": product.get("title", "상품명 없음"),
                "price": product.get("price", "가격 정보 없음"),
                "original_price": product.get("original_price", "원가 정보 없음"),
                "discount_rate": product.get("discount_rate", "할인율 정보 없음"),
                "image_url": product.get("image_url", "이미지 없음"),
                "rating": product.get("rating", "평점 정보 없음"),
                "review_count": product.get("review_count", "리뷰 정보 없음"),
                "brand_name": product.get("brand_name", "브랜드 정보 없음"),
                "category_name": product.get("category_name", "카테고리 정보 없음"),
                "is_rocket": product.get("is_rocket", False),
                "is_free_shipping": product.get("is_free_shipping", False)
            },
            "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        }
        
    except Exception as e:
        return {
            "success": False,
            "message": f"쿠팡 상품 분석 중 오류: {str(e)}",
            "url": url
        }

def analyze_aliexpress_product(url):
    """알리익스프레스 상품 분석 (개선된 API 사용)"""
    try:
        # SafeAPIManager 초기화
        api_manager = SafeAPIManager(mode="production")
        
        # 상품 ID 추출
        import re
        product_id_match = re.search(r\'/item/(\d+)\.html\', url)
        if not product_id_match:
            return {
                "success": False,
                "message": "유효한 알리익스프레스 상품 URL이 아닙니다.",
                "url": url
            }
        
        product_id = product_id_match.group(1)
        
        # 어필리에이트 링크 변환
        convert_success, affiliate_link, product_info = api_manager.convert_aliexpress_to_affiliate_link(url)
        
        # 상품 상세 정보 가져오기 (개선된 search_aliexpress_safe 사용)
        if not product_info:
            # 상품 ID로 검색 시도
            search_success, search_products = api_manager.search_aliexpress_safe(product_id, limit=1)
            if search_success and search_products:
                product_info = search_products[0]
            else:
                # 폴백: 기존 방식 시도
                product_info = api_manager._get_aliexpress_product_details(product_id)
        
        if not convert_success:
            return {
                "success": False,
                "message": "알리익스프레스 링크 변환에 실패했습니다.",
                "product_id": product_id,
                "url": url
            }
        
        # 상품 정보 정리 (개선된 응답 구조 반영)
        if product_info:
            formatted_product_info = {
                "title": product_info.get("title", product_info.get("product_title", "상품명 없음")),
                "price": product_info.get("price", product_info.get("target_sale_price", "가격 정보 없음")),
                "original_price": product_info.get("original_price", product_info.get("target_original_price", "원가 정보 없음")),
                "discount_rate": product_info.get("discount_rate", product_info.get("discount", "할인 정보 없음")),
                "image_url": product_info.get("image_url", product_info.get("product_main_image_url", "이미지 없음")),
                "rating": product_info.get("rating", product_info.get("evaluate_rate", "평점 정보 없음")),
                "review_count": product_info.get("review_count", product_info.get("volume", "리뷰 정보 없음")),
                "category": product_info.get("category", product_info.get("category_name", "카테고리 정보 없음")),
                "commission": product_info.get("commission", "수수료 정보 없음"),
                "commission_rate": product_info.get("commission_rate", "수수료율 정보 없음"),
                "is_plus": product_info.get("is_plus", False)
            }
        else:
            formatted_product_info = {
                "title": "상품명 정보 없음",
                "price": "가격 정보 없음",
                "original_price": "원가 정보 없음",
                "discount_rate": "할인 정보 없음",
                "image_url": "이미지 없음",
                "rating": "평점 정보 없음",
                "review_count": "리뷰 정보 없음",
                "category": "카테고리 정보 없음",
                "commission": "수수료 정보 없음",
                "commission_rate": "수수료율 정보 없음",
                "is_plus": False
            }
        
        return {
            "success": True,
            "platform": "알리익스프레스",
            "product_id": product_id,
            "original_url": url,
            "affiliate_url": affiliate_link,
            "product_info": formatted_product_info,
            "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        }
        
    except Exception as e:
        return {
            "success": False,
            "message": f"알리익스프레스 상품 분석 중 오류: {str(e)}",
            "url": url
        }

def main():
    if len(sys.argv) != 3:
        print(json.dumps({"success": False, "message": "Usage: python3 product_analyzer.py <platform> <url>"}))
        sys.exit(1)
    
    platform = sys.argv[1].lower()
    url = sys.argv[2]
    
    if platform == "coupang":
        result = analyze_coupang_product(url)
    elif platform == "aliexpress":
        result = analyze_aliexpress_product(url)
    else:
        result = {"success": False, "message": "지원하지 않는 플랫폼입니다."}
    
    print(json.dumps(result, ensure_ascii=False, indent=2))

if __name__ == "__main__":
    main()
';
    
    if (file_put_contents($script_path, $script_content) === false) {
        throw new Exception('Python 스크립트 생성에 실패했습니다.');
    }
    
    // 실행 권한 부여
    chmod($script_path, 0755);
}
?>