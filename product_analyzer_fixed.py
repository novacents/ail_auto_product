#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
상품 정보 분석 스크립트 (수정된 버전)
쿠팡 파트너스 API와 알리익스프레스 API를 사용하여 상품 정보를 추출
JSON 파싱 문제 해결: 로그와 JSON 출력 분리
"""

import sys
import json
import os
import re
from datetime import datetime

# SafeAPIManager 경로 추가
sys.path.append("/var/www/novacents/tools")
from safe_api_manager import SafeAPIManager

# 알리익스프레스 SDK 경로 추가
sys.path.append("/home/novacents/aliexpress-sdk")
import iop

# 로그 출력을 stderr로 리디렉션 (JSON 파싱 문제 해결)
import logging
logging.basicConfig(
    level=logging.INFO,
    format='[%(levelname)s] %(message)s',
    stream=sys.stderr  # 로그는 stderr로, JSON은 stdout으로
)

def log_info(message):
    """정보 로그 출력 (stderr로)"""
    logging.info(message)

def analyze_coupang_product(url):
    """쿠팡 상품 분석 (JSON 출력 개선)"""
    try:
        log_info("쿠팡 상품 분석 시작")
        
        # SafeAPIManager 초기화 (로그 출력 최소화)
        os.environ['SAFE_API_QUIET'] = '1'  # 조용한 모드
        api_manager = SafeAPIManager(mode="production")
        
        # 상품 ID 추출
        product_id_match = re.search(r'/products/(\d+)', url)
        if not product_id_match:
            return {
                "success": False,
                "message": "유효한 쿠팡 상품 URL이 아닙니다.",
                "url": url
            }
        
        product_id = product_id_match.group(1)
        log_info(f"상품 ID 추출: {product_id}")
        
        # 어필리에이트 링크 변환 (우선)
        log_info("어필리에이트 링크 변환 시도")
        convert_success, affiliate_link, convert_product_info = api_manager.convert_coupang_to_affiliate_link(url)
        
        if not convert_success:
            return {
                "success": False,
                "message": "쿠팡 링크 변환에 실패했습니다.",
                "product_id": product_id,
                "url": url
            }
        
        # 상품 정보 검색 (보완용)
        log_info("상품 정보 검색 시도")
        search_success, products = api_manager.search_coupang_safe(product_id, limit=1)
        
        # 상품 정보 정리
        if search_success and products:
            product = products[0]
            log_info("검색된 상품 정보 사용")
        elif convert_product_info:
            product = convert_product_info
            log_info("변환 시 획득한 상품 정보 사용")
        else:
            product = {}
            log_info("상품 정보 없음 - 기본값 사용")
        
        # 최종 결과 구성
        result = {
            "success": True,
            "platform": "쿠팡",
            "product_id": product_id,
            "original_url": url,
            "affiliate_url": affiliate_link,
            "product_info": {
                "title": product.get("title", product.get("productName", "상품명 없음")),
                "price": product.get("price", product.get("productPrice", "가격 정보 없음")),
                "original_price": product.get("original_price", "원가 정보 없음"),
                "discount_rate": product.get("discount_rate", "할인율 정보 없음"),
                "image_url": product.get("image_url", product.get("productImage", "이미지 없음")),
                "rating": product.get("rating", "평점 정보 없음"),
                "review_count": product.get("review_count", "리뷰 정보 없음"),
                "brand_name": product.get("brand_name", "브랜드 정보 없음"),
                "category_name": product.get("category_name", product.get("categoryName", "카테고리 정보 없음")),
                "is_rocket": product.get("is_rocket", product.get("isRocket", False)),
                "is_free_shipping": product.get("is_free_shipping", False)
            },
            "conversion_method": "딥링크 API 변환",
            "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        }
        
        log_info("쿠팡 상품 분석 완료")
        return result
        
    except Exception as e:
        log_info(f"쿠팡 상품 분석 예외: {str(e)}")
        return {
            "success": False,
            "message": f"쿠팡 상품 분석 중 오류: {str(e)}",
            "url": url
        }

def analyze_aliexpress_product(url):
    """알리익스프레스 상품 분석 (JSON 출력 개선)"""
    try:
        log_info("알리익스프레스 상품 분석 시작")
        
        # SafeAPIManager 초기화 (로그 출력 최소화)
        os.environ['SAFE_API_QUIET'] = '1'  # 조용한 모드
        api_manager = SafeAPIManager(mode="production")
        
        # 상품 ID 추출
        product_id_match = re.search(r'/item/(\d+)\.html', url)
        if not product_id_match:
            return {
                "success": False,
                "message": "유효한 알리익스프레스 상품 URL이 아닙니다.",
                "url": url
            }
        
        product_id = product_id_match.group(1)
        log_info(f"상품 ID 추출: {product_id}")
        
        # 어필리에이트 링크 변환 (우선)
        log_info("어필리에이트 링크 변환 시도")
        convert_success, affiliate_link, convert_product_info = api_manager.convert_aliexpress_to_affiliate_link(url)
        
        if not convert_success:
            return {
                "success": False,
                "message": "알리익스프레스 링크 변환에 실패했습니다.",
                "product_id": product_id,
                "url": url
            }
        
        # 상품 정보 보완 시도
        product_info = convert_product_info
        
        if not product_info:
            log_info("상품 검색으로 정보 보완 시도")
            # 상품 ID로 검색 시도
            search_success, search_products = api_manager.search_aliexpress_safe(product_id, limit=1)
            if search_success and search_products:
                product_info = search_products[0]
                log_info("검색된 상품 정보 사용")
            else:
                log_info("상품 상세 정보 API 시도")
                # 상품 상세 정보 API 시도
                product_info = api_manager._get_aliexpress_product_details(product_id)
        
        # 상품 정보 정리
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
            log_info("상품 정보 사용")
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
            log_info("기본 상품 정보 사용")
        
        # 최종 결과 구성
        result = {
            "success": True,
            "platform": "알리익스프레스",
            "product_id": product_id,
            "original_url": url,
            "affiliate_url": affiliate_link,
            "product_info": formatted_product_info,
            "conversion_method": "공식 SDK 링크 변환",
            "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        }
        
        log_info("알리익스프레스 상품 분석 완료")
        return result
        
    except Exception as e:
        log_info(f"알리익스프레스 상품 분석 예외: {str(e)}")
        return {
            "success": False,
            "message": f"알리익스프레스 상품 분석 중 오류: {str(e)}",
            "url": url
        }

def main():
    """메인 함수 - JSON만 stdout으로 출력"""
    if len(sys.argv) != 3:
        result = {"success": False, "message": "Usage: python3 product_analyzer_fixed.py <platform> <url>"}
        print(json.dumps(result, ensure_ascii=False))
        sys.exit(1)
    
    platform = sys.argv[1].lower()
    url = sys.argv[2]
    
    log_info(f"상품 분석 시작: {platform} - {url}")
    
    if platform == "coupang":
        result = analyze_coupang_product(url)
    elif platform == "aliexpress":
        result = analyze_aliexpress_product(url)
    else:
        result = {"success": False, "message": "지원하지 않는 플랫폼입니다."}
    
    # JSON만 stdout으로 출력 (로그는 이미 stderr로 출력됨)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    
    log_info("상품 분석 완료")

if __name__ == "__main__":
    main()