#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
상품 정보 분석 스크립트 v2
올바른 워크플로: 링크 변환 먼저 → 상품 정보 추출
공식 가이드 기반 정확한 구현
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

def analyze_coupang_product_v2(url):
    """쿠팡 상품 분석 (올바른 워크플로)"""
    try:
        # SafeAPIManager 초기화
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
        
        # 1단계: 딥링크 변환 (가장 중요)
        convert_success, affiliate_link, product_info = api_manager.convert_coupang_to_affiliate_link(url)
        
        if not convert_success:
            return {
                "success": False,
                "message": "쿠팡 링크 변환에 실패했습니다.",
                "product_id": product_id,
                "url": url
            }
        
        # 2단계: 상품 정보 확인 및 보완
        if not product_info:
            # 링크 변환에서 상품 정보를 못 가져온 경우
            # 웹 스크래핑이나 다른 방법으로 보완 가능
            product_info = {
                "title": f"쿠팡 상품 (ID: {product_id})",
                "price": "가격 정보를 가져올 수 없습니다",
                "image_url": "이미지 정보를 가져올 수 없습니다",
                "category": "카테고리 정보를 가져올 수 없습니다"
            }
        
        return {
            "success": True,
            "platform": "쿠팡",
            "product_id": product_id,
            "original_url": url,
            "affiliate_url": affiliate_link,
            "product_info": {
                "title": product_info.get("title", product_info.get("productName", "상품명 없음")),
                "price": product_info.get("price", product_info.get("productPrice", "가격 정보 없음")),
                "original_price": product_info.get("original_price", "원가 정보 없음"),
                "discount_rate": product_info.get("discount_rate", "할인 정보 없음"),
                "image_url": product_info.get("image_url", product_info.get("productImage", "이미지 없음")),
                "rating": product_info.get("rating", "평점 정보 없음"),
                "review_count": product_info.get("review_count", "리뷰 정보 없음"),
                "brand_name": product_info.get("brand_name", "브랜드 정보 없음"),
                "category_name": product_info.get("category_name", product_info.get("categoryName", "카테고리 정보 없음")),
                "is_rocket": product_info.get("is_rocket", product_info.get("isRocket", False)),
                "is_free_shipping": product_info.get("is_free_shipping", False)
            },
            "conversion_method": "딥링크 API 변환 성공",
            "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        }
        
    except Exception as e:
        return {
            "success": False,
            "message": f"쿠팡 상품 분석 중 오류: {str(e)}",
            "url": url
        }

def analyze_aliexpress_product_v2(url):
    """알리익스프레스 상품 분석 (올바른 워크플로)"""
    try:
        # SafeAPIManager 초기화
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
        
        # 1단계: 링크 변환 (가장 중요)
        convert_success, affiliate_link, product_info = api_manager.convert_aliexpress_to_affiliate_link(url)
        
        if not convert_success:
            return {
                "success": False,
                "message": "알리익스프레스 링크 변환에 실패했습니다.",
                "product_id": product_id,
                "url": url
            }
        
        # 2단계: 상품 정보 확인 및 보완
        if not product_info:
            # 링크 변환에서 상품 정보를 못 가져온 경우
            # 웹 스크래핑이나 다른 방법으로 보완 가능
            product_info = {
                "title": f"AliExpress Product (ID: {product_id})",
                "price": "Price information not available",
                "image_url": "Image information not available",
                "category": "Category information not available"
            }
        
        return {
            "success": True,
            "platform": "알리익스프레스",
            "product_id": product_id,
            "original_url": url,
            "affiliate_url": affiliate_link,
            "product_info": {
                "title": product_info.get("title", product_info.get("product_title", "상품명 없음")),
                "price": product_info.get("price", product_info.get("target_sale_price", "가격 정보 없음")),
                "original_price": product_info.get("original_price", product_info.get("target_original_price", "원가 정보 없음")),
                "discount_rate": product_info.get("discount_rate", "할인 정보 없음"),
                "image_url": product_info.get("image_url", product_info.get("product_main_image_url", "이미지 없음")),
                "rating": product_info.get("rating", product_info.get("evaluate_rate", "평점 정보 없음")),
                "review_count": product_info.get("review_count", product_info.get("volume", "리뷰 정보 없음")),
                "category": product_info.get("category", product_info.get("first_level_category_name", "카테고리 정보 없음")),
                "commission": product_info.get("commission", product_info.get("30days_commission", "수수료 정보 없음")),
                "commission_rate": product_info.get("commission_rate", product_info.get("relevant_market_commission_rate", "수수료율 정보 없음")),
                "is_plus": product_info.get("is_plus", product_info.get("plus_product", False))
            },
            "conversion_method": "공식 SDK 링크 변환 성공",
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
        print(json.dumps({"success": False, "message": "Usage: python3 product_analyzer_v2.py <platform> <url>"}).replace('\\/', '/'))
        sys.exit(1)
    
    platform = sys.argv[1].lower()
    url = sys.argv[2]
    
    if platform == "coupang":
        result = analyze_coupang_product_v2(url)
    elif platform == "aliexpress":
        result = analyze_aliexpress_product_v2(url)
    else:
        result = {"success": False, "message": "지원하지 않는 플랫폼입니다."}
    
    print(json.dumps(result, ensure_ascii=False, indent=2).replace('\\/', '/'))

if __name__ == "__main__":
    main()