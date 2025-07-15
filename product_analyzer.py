#!/usr/bin/env python3
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
        product_id_match = re.search(r'/products/(\d+)', url)
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
    """알리익스프레스 상품 분석"""
    try:
        # SafeAPIManager 초기화
        api_manager = SafeAPIManager(mode="production")
        
        # 상품 ID 추출
        import re
        product_id_match = re.search(r'/item/(\d+)\.html', url)
        if not product_id_match:
            return {
                "success": False,
                "message": "유효한 알리익스프레스 상품 URL이 아닙니다.",
                "url": url
            }
        
        product_id = product_id_match.group(1)
        
        # 어필리에이트 링크 변환
        convert_success, affiliate_link, product_info = api_manager.convert_aliexpress_to_affiliate_link(url)
        
        # 상품 상세 정보 가져오기
        if not product_info:
            product_info = api_manager._get_aliexpress_product_details(product_id)
        
        if not convert_success:
            return {
                "success": False,
                "message": "알리익스프레스 링크 변환에 실패했습니다.",
                "product_id": product_id,
                "url": url
            }
        
        # 상품 정보 정리
        if product_info:
            formatted_product_info = {
                "title": product_info.get("product_title", "상품명 없음"),
                "price": product_info.get("target_sale_price", "가격 정보 없음"),
                "original_price": product_info.get("target_original_price", "원가 정보 없음"),
                "discount": product_info.get("discount", "할인 정보 없음"),
                "image_url": product_info.get("product_main_image_url", "이미지 없음"),
                "rating": product_info.get("evaluate_rate", "평점 정보 없음"),
                "volume": product_info.get("volume", "주문량 정보 없음"),
                "category_name": product_info.get("category_name", "카테고리 정보 없음")
            }
        else:
            formatted_product_info = {
                "title": "상품명 정보 없음",
                "price": "가격 정보 없음",
                "original_price": "원가 정보 없음",
                "discount": "할인 정보 없음",
                "image_url": "이미지 없음",
                "rating": "평점 정보 없음",
                "volume": "주문량 정보 없음",
                "category_name": "카테고리 정보 없음"
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
