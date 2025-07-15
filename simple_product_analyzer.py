#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
간단한 상품 정보 분석기 (GitHub 성공 사례 기반)
JSON 파싱 문제 완전 해결 및 안정성 향상
"""

import sys
import json
import os
import re
import logging
from datetime import datetime

# 로그 설정 (stderr로만 출력)
logging.basicConfig(
    level=logging.ERROR,  # 에러만 출력
    format='%(message)s',
    stream=sys.stderr
)

def extract_product_id(url, platform):
    """URL에서 상품 ID 추출"""
    if platform == "coupang":
        match = re.search(r'/products/(\d+)', url)
        return match.group(1) if match else None
    elif platform == "aliexpress":
        match = re.search(r'/item/(\d+)\.html', url)
        return match.group(1) if match else None
    return None

def analyze_coupang_simple(url):
    """쿠팡 상품 분석 (간단한 방법)"""
    try:
        product_id = extract_product_id(url, "coupang")
        if not product_id:
            return {
                "success": False,
                "message": "유효한 쿠팡 상품 URL이 아닙니다."
            }
        
        # 환경 변수 로드
        from dotenv import load_dotenv
        load_dotenv('/home/novacents/.env')
        
        access_key = os.getenv("COUPANG_ACCESS_KEY")
        secret_key = os.getenv("COUPANG_SECRET_KEY")
        
        if not access_key or not secret_key:
            return {
                "success": False,
                "message": "쿠팡 API 키가 설정되지 않았습니다."
            }
        
        # 간단한 딥링크 변환 테스트
        import requests
        import hmac
        import hashlib
        import time
        
        # GMT 시간 설정
        os.environ["TZ"] = "GMT+0"
        if hasattr(time, 'tzset'):
            time.tzset()
        
        datetime_gmt = time.strftime('%y%m%d') + 'T' + time.strftime('%H%M%S') + 'Z'
        
        # 딥링크 API 호출
        url_path = "/v2/providers/affiliate_open_api/apis/openapi/deeplink"
        message = datetime_gmt + "POST" + url_path
        
        signature = hmac.new(
            secret_key.encode('utf-8'),
            message.encode('utf-8'),
            hashlib.sha256
        ).hexdigest()
        
        authorization = f"CEA algorithm=HmacSHA256, access-key={access_key}, signed-date={datetime_gmt}, signature={signature}"
        
        headers = {
            "Authorization": authorization,
            "Content-Type": "application/json"
        }
        
        data = {
            "coupangUrls": [url]
        }
        
        api_url = "https://api-gateway.coupang.com" + url_path
        response = requests.post(api_url, json=data, headers=headers, timeout=30)
        
        if response.status_code == 200:
            result = response.json()
            if result.get('rCode') == '0' and result.get('data'):
                affiliate_data = result['data'][0]
                
                return {
                    "success": True,
                    "platform": "쿠팡",
                    "product_id": product_id,
                    "original_url": url,
                    "affiliate_url": affiliate_data.get('shortenUrl', ''),
                    "landing_url": affiliate_data.get('landingUrl', ''),
                    "product_info": {
                        "title": f"쿠팡 상품 (ID: {product_id})",
                        "price": "가격 정보는 링크에서 확인하세요",
                        "image_url": "이미지는 링크에서 확인하세요",
                        "message": "링크 변환 성공 - 상세 정보는 어필리에이트 링크에서 확인 가능"
                    },
                    "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                }
        
        return {
            "success": False,
            "message": f"쿠팡 API 호출 실패 (HTTP {response.status_code})"
        }
        
    except Exception as e:
        return {
            "success": False,
            "message": f"쿠팡 분석 오류: {str(e)}"
        }

def analyze_aliexpress_simple(url):
    """알리익스프레스 상품 분석 (간단한 방법)"""
    try:
        product_id = extract_product_id(url, "aliexpress")
        if not product_id:
            return {
                "success": False,
                "message": "유효한 알리익스프레스 상품 URL이 아닙니다."
            }
        
        # 환경 변수 로드
        from dotenv import load_dotenv
        load_dotenv('/home/novacents/.env')
        
        app_key = os.getenv("ALIEXPRESS_APP_KEY")
        app_secret = os.getenv("ALIEXPRESS_APP_SECRET")
        
        if not app_key or not app_secret:
            return {
                "success": False,
                "message": "알리익스프레스 API 키가 설정되지 않았습니다."
            }
        
        # 간단한 IOP SDK 호출
        sys.path.append("/home/novacents/aliexpress-sdk")
        import iop
        
        client = iop.IopClient('https://api-sg.aliexpress.com/sync', app_key, app_secret)
        
        # 링크 변환 요청
        request = iop.IopRequest('aliexpress.affiliate.link.generate', 'POST')
        request.set_simplify()
        request.add_api_param('source_values', url.split('?')[0])
        request.add_api_param('promotion_link_type', '0')
        request.add_api_param('tracking_id', 'default')
        
        response = client.execute(request)
        
        if response.body and 'resp_result' in response.body:
            result = response.body['resp_result'].get('result', {})
            promotion_links = result.get('promotion_links', [])
            
            if promotion_links:
                affiliate_link = promotion_links[0]['promotion_link']
                
                return {
                    "success": True,
                    "platform": "알리익스프레스",
                    "product_id": product_id,
                    "original_url": url,
                    "affiliate_url": affiliate_link,
                    "product_info": {
                        "title": f"AliExpress 상품 (ID: {product_id})",
                        "price": "가격 정보는 링크에서 확인하세요",
                        "image_url": "이미지는 링크에서 확인하세요",
                        "message": "링크 변환 성공 - 상세 정보는 어필리에이트 링크에서 확인 가능"
                    },
                    "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                }
        
        return {
            "success": False,
            "message": "알리익스프레스 링크 변환 실패"
        }
        
    except Exception as e:
        return {
            "success": False,
            "message": f"알리익스프레스 분석 오류: {str(e)}"
        }

def main():
    """메인 함수 - 순수 JSON만 출력"""
    if len(sys.argv) != 3:
        result = {
            "success": False, 
            "message": "Usage: python3 simple_product_analyzer.py <platform> <url>"
        }
        print(json.dumps(result, ensure_ascii=False))
        sys.exit(1)
    
    platform = sys.argv[1].lower()
    url = sys.argv[2]
    
    # 로그는 stderr로, 결과는 stdout으로 완전 분리
    try:
        if platform == "coupang":
            result = analyze_coupang_simple(url)
        elif platform == "aliexpress":
            result = analyze_aliexpress_simple(url)
        else:
            result = {
                "success": False, 
                "message": "지원하지 않는 플랫폼입니다. (coupang 또는 aliexpress)"
            }
        
        # 순수 JSON만 stdout으로 출력
        print(json.dumps(result, ensure_ascii=False, indent=2))
        
    except Exception as e:
        # 예외 발생 시도 JSON 형태로 출력
        error_result = {
            "success": False,
            "message": f"실행 중 오류 발생: {str(e)}"
        }
        print(json.dumps(error_result, ensure_ascii=False))

if __name__ == "__main__":
    main()