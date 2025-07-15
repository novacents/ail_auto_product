#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
상품 정보 조회 스크립트 (PHP에서 호출용)
파일 위치: /var/www/novacents/tools/get_product_info.py
"""

import sys
import json
import os

# SafeAPIManager 임포트
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.append(current_dir)

try:
    from safe_api_manager import SafeAPIManager
except ImportError as e:
    print(json.dumps({"error": f"SafeAPIManager 임포트 실패: {e}"}))
    sys.exit(1)

def get_product_info(product_url):
    """상품 정보 조회"""
    try:
        api_manager = SafeAPIManager(mode="production")
        
        # 플랫폼 확인
        if 'coupang.com' in product_url.lower():
            platform = "쿠팡"
            success, affiliate_link, product_info = api_manager.convert_coupang_to_affiliate_link(product_url)
        elif 'aliexpress.com' in product_url.lower():
            platform = "알리익스프레스"
            success, affiliate_link, product_info = api_manager.convert_aliexpress_to_affiliate_link(product_url)
        else:
            return {
                "success": False,
                "error": "지원하지 않는 플랫폼",
                "platform": "unknown"
            }
        
        if success and affiliate_link:
            return {
                "success": True,
                "platform": platform,
                "affiliate_link": affiliate_link,
                "product_info": product_info,
                "original_url": product_url
            }
        else:
            return {
                "success": False,
                "error": "상품 정보 조회 실패",
                "platform": platform,
                "original_url": product_url
            }
            
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "original_url": product_url
        }

def main():
    """메인 실행 함수"""
    if len(sys.argv) != 2:
        print(json.dumps({"error": "사용법: python3 get_product_info.py <product_url>"}))
        sys.exit(1)
    
    product_url = sys.argv[1]
    result = get_product_info(product_url)
    print(json.dumps(result, ensure_ascii=False))

if __name__ == "__main__":
    main()