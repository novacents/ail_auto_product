#!/usr/bin/env python3
"""
올바른 쿠팡 API 호출 방식 - 문서에서 확인한 POST + coupangUrls 방식
정확한 상품 정보 추출을 위한 수정된 코드
"""

import hmac
import hashlib
import requests
import json
from time import gmtime, strftime
import sys
import re
import urllib.parse

def load_api_keys():
    """API 키 로드"""
    env_path = "/home/novacents/.env"
    with open(env_path, 'r') as f:
        lines = f.readlines()
    
    ACCESS_KEY = None
    SECRET_KEY = None
    
    for line in lines:
        line = line.strip()
        if line.startswith('COUPANG_ACCESS_KEY='):
            ACCESS_KEY = line.split('=', 1)[1].strip().strip('"').strip("'")
        elif line.startswith('COUPANG_SECRET_KEY='):
            SECRET_KEY = line.split('=', 1)[1].strip().strip('"').strip("'")
    
    return ACCESS_KEY, SECRET_KEY

def generateHmac(method, url, secretKey, accessKey):
    """공식 HMAC 생성 (기존과 동일)"""
    path, *query = url.split("?")
    datetimeGMT = strftime('%y%m%d', gmtime()) + 'T' + strftime('%H%M%S', gmtime()) + 'Z'
    message = datetimeGMT + method + path + (query[0] if query else "")

    signature = hmac.new(bytes(secretKey, "utf-8"),
                         message.encode("utf-8"),
                         hashlib.sha256).hexdigest()

    return "CEA algorithm=HmacSHA256, access-key={}, signed-date={}, signature={}".format(accessKey, datetimeGMT, signature)

def get_product_info_by_url_correct(product_url, ACCESS_KEY, SECRET_KEY):
    """
    올바른 쿠팡 API 호출 방식 - 문서에서 확인한 POST + coupangUrls 방식
    이 방식이 정확한 상품 정보를 가져오는 방법입니다!
    """
    try:
        print(f"🎯 올바른 쿠팡 API 호출 시작: {product_url}", file=sys.stderr)
        
        # 🔥 문서에서 확인한 정확한 엔드포인트 (POST 방식)
        REQUEST_METHOD = "POST"
        URL = "/v2/providers/affiliate_open_api/apis/openapi/v1/products/search"
        
        # 🔥 핵심: coupangUrls 배열로 전송 (이게 정확한 방법!)
        request_data = {
            "coupangUrls": [product_url]  # 정확한 URL 전달
        }
        
        authorization = generateHmac(REQUEST_METHOD, URL, SECRET_KEY, ACCESS_KEY)
        
        DOMAIN = "https://api-gateway.coupang.com"
        full_url = f"{DOMAIN}{URL}"
        
        headers = {
            "Authorization": authorization,
            "Content-Type": "application/json"
        }
        
        print(f"📤 POST 요청: {full_url}", file=sys.stderr)
        print(f"📦 요청 데이터: {request_data}", file=sys.stderr)
        
        response = requests.post(full_url, 
                               json=request_data, 
                               headers=headers, 
                               timeout=30)
        
        print(f"📥 응답 상태: {response.status_code}", file=sys.stderr)
        print(f"📄 응답 내용: {response.text}", file=sys.stderr)
        
        if response.status_code == 200:
            data = response.json()
            if data.get('rCode') == '0':
                # 🔥 올바른 응답 구조 처리
                rdata = data.get('rData', {})
                product_data = rdata.get('productData', [])
                
                if product_data and len(product_data) > 0:
                    # 정확한 상품 정보 추출 성공!
                    product = product_data[0]
                    
                    print(f"✅ 정확한 상품 정보 추출 성공!", file=sys.stderr)
                    print(f"🏷️ 상품명: {product.get('productName')}", file=sys.stderr)
                    
                    return {
                        "success": True,
                        "method": "POST_coupangUrls_정확한방식",
                        "original_url": product_url,
                        "product_info": {
                            "title": product.get('productName', '상품명 없음'),
                            "price": product.get('productPrice', 0),
                            "price_formatted": f"{product.get('productPrice', 0):,}원",
                            "image_url": product.get('productImage', ''),
                            "category": product.get('categoryName', ''),
                            "is_rocket": product.get('isRocket', False),
                            "is_free_shipping": product.get('isFreeShipping', False),
                            "affiliate_url": product.get('productUrl', ''),
                            "product_id": product.get('productId', ''),
                            "brand_name": product.get('brandName', ''),
                            "vendor": product.get('vendorItemName', ''),
                            "rating": product.get('productRating', '평점 정보 없음'),
                            "review_count": product.get('reviewCount', '리뷰 정보 없음')
                        }
                    }
                else:
                    print("❌ productData가 비어있음", file=sys.stderr)
            else:
                print(f"❌ API 오류: {data.get('rMessage')}", file=sys.stderr)
        else:
            print(f"❌ HTTP 오류: {response.status_code}", file=sys.stderr)
            
        return None
        
    except Exception as e:
        print(f"❌ 정확한 API 호출 오류: {type(e).__name__}: {e}", file=sys.stderr)
        return None

def analyze_coupang_product_correct(product_url):
    """수정된 쿠팡 상품 분석 - 올바른 API 방식 사용"""
    try:
        # API 키 로드
        ACCESS_KEY, SECRET_KEY = load_api_keys()
        if not ACCESS_KEY or not SECRET_KEY:
            return {"success": False, "error": "API 키 없음"}
        
        # 🔥 올바른 방식으로 정확한 상품 정보 가져오기
        result = get_product_info_by_url_correct(product_url, ACCESS_KEY, SECRET_KEY)
        
        if result:
            return result
        else:
            return {
                "success": False,
                "error": "올바른 API 호출로도 상품 정보를 가져올 수 없습니다"
            }
            
    except Exception as e:
        print(f"❌ 분석 오류: {type(e).__name__}: {e}", file=sys.stderr)
        return {
            "success": False,
            "error": f"분석 중 오류 발생: {str(e)}"
        }

def main():
    """테스트용 메인 함수"""
    if len(sys.argv) < 2:
        result = {"success": False, "error": "상품 URL이 필요합니다"}
        print(json.dumps(result, ensure_ascii=False))
        return
    
    product_url = sys.argv[1]
    result = analyze_coupang_product_correct(product_url)
    print(json.dumps(result, ensure_ascii=False))

if __name__ == "__main__":
    main()
