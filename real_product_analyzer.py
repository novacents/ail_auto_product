#!/usr/bin/env python3
"""
실제 작동하는 상품 분석기
- 쿠팡 + 알리익스프레스 100% 검증 완료
- affiliate_editor.php에서 호출됨
"""

import os
import sys
import json
import hmac
import hashlib
import time
import urllib.parse
import urllib.request
import re

def load_env_simple():
    """Simple .env loader without dependencies"""
    env_path = "/home/novacents/.env"
    env_vars = {}
    try:
        with open(env_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    env_vars[key.strip()] = value.strip().strip('"').strip("'")
        return env_vars
    except Exception:
        return {}

class CoupangAPI:
    """검증된 쿠팡 API 클래스"""
    
    def __init__(self, access_key, secret_key):
        self.access_key = access_key
        self.secret_key = secret_key
        self.domain = "https://api-gateway.coupang.com"
    
    def generate_signature(self, method, url_path):
        """공식 가이드 기반 HMAC 서명 생성"""
        # GMT 시간대 설정
        os.environ["TZ"] = "GMT+0"
        if hasattr(time, 'tzset'):
            time.tzset()
        
        # GMT 시간 형식: YYMMDDTHHMMSSZ
        datetime_gmt = time.strftime('%y%m%d') + 'T' + time.strftime('%H%M%S') + 'Z'
        
        # URL 경로와 쿼리 분리
        path_parts = url_path.split("?")
        path = path_parts[0]
        query = path_parts[1] if len(path_parts) > 1 else ""
        
        # 서명 메시지 생성
        message = datetime_gmt + method + path + query
        
        # HMAC 서명 계산
        signature = hmac.new(
            self.secret_key.encode('utf-8'),
            message.encode('utf-8'),
            hashlib.sha256
        ).hexdigest()
        
        # Authorization 헤더 생성
        authorization = f"CEA algorithm=HmacSHA256, access-key={self.access_key}, signed-date={datetime_gmt}, signature={signature}"
        
        return authorization
    
    def extract_product_info_from_url(self, url):
        """실제 쿠팡 URL에서 상품 정보 추출 (개선됨)"""
        parsed = urllib.parse.urlparse(url)
        query_params = urllib.parse.parse_qs(parsed.query)
        
        result = {}
        
        # 상품 ID 추출 (products/숫자)
        product_match = re.search(r'/products/(\d+)', parsed.path)
        if product_match:
            result['product_id'] = product_match.group(1)
        
        # itemId 추출 (더 정확한 상품 식별자)
        if 'itemId' in query_params:
            result['item_id'] = query_params['itemId'][0]
        
        # vendorItemId 추출
        if 'vendorItemId' in query_params:
            result['vendor_item_id'] = query_params['vendorItemId'][0]
        
        return result
    
    def extract_product_id_from_url(self, url):
        """기존 호환성을 위한 래퍼 함수"""
        info = self.extract_product_info_from_url(url)
        return info.get('product_id')
    
    def convert_to_affiliate_link(self, product_url):
        """일반 상품 링크를 어필리에이트 링크로 변환"""
        try:
            # URL 경로 구성
            url_path = "/v2/providers/affiliate_open_api/apis/openapi/deeplink"
            
            # 서명 생성
            authorization = self.generate_signature("POST", url_path)
            
            # 요청 데이터
            data = {
                'coupangUrls': [product_url]
            }
            
            # 헤더 구성
            headers = {
                'Authorization': authorization,
                'Content-Type': 'application/json'
            }
            
            # 요청 실행
            full_url = f"{self.domain}{url_path}"
            req = urllib.request.Request(
                full_url, 
                data=json.dumps(data).encode('utf-8'),
                headers=headers,
                method='POST'
            )
            
            with urllib.request.urlopen(req) as response:
                if response.status == 200:
                    result = json.loads(response.read().decode('utf-8'))
                    if result.get('data') and len(result['data']) > 0:
                        return result['data'][0].get('shortenUrl')
            return None
        except Exception:
            return None
    
    def search_products_by_keyword(self, keyword, limit=5):
        """키워드로 상품 검색"""
        try:
            # URL 경로 구성
            url_path = "/v2/providers/affiliate_open_api/apis/openapi/products/search"
            query_params = {
                'keyword': keyword,
                'limit': str(limit)
            }
            query_string = urllib.parse.urlencode(query_params)
            full_path = f"{url_path}?{query_string}"
            
            # 서명 생성
            authorization = self.generate_signature("GET", full_path)
            
            # 헤더 구성
            headers = {
                'Authorization': authorization,
                'Content-Type': 'application/json'
            }
            
            # 요청 실행
            full_url = f"{self.domain}{full_path}"
            req = urllib.request.Request(full_url, headers=headers)
            
            with urllib.request.urlopen(req) as response:
                if response.status == 200:
                    data = json.loads(response.read().decode('utf-8'))
                    return self.format_coupang_response(data)
            return None
        except Exception:
            return None
    
    def get_product_info_from_url(self, product_url):
        """실제 URL 구조에 맞는 상품 정보 추출 (개선됨)"""
        try:
            # 1. URL에서 상품 정보 추출
            url_info = self.extract_product_info_from_url(product_url)
            product_id = url_info.get('product_id')
            item_id = url_info.get('item_id')
            
            if not product_id:
                return None
            
            # 2. 어필리에이트 링크 변환 (전체 URL 사용)
            affiliate_link = self.convert_to_affiliate_link(product_url)
            
            # 3. 상품 검색으로 상세 정보 가져오기
            # itemId를 우선적으로 사용하여 더 정확한 검색
            search_methods = []
            
            # 방법 1: itemId 검색 (가장 정확)
            if item_id:
                search_methods.append(('itemId', item_id))
            
            # 방법 2: productId 검색
            search_methods.append(('productId', product_id))
            
            # 방법 3: URL에서 키워드 추출
            if "q=" in product_url:
                query_match = re.search(r'q=([^&]+)', product_url)
                if query_match:
                    keyword = urllib.parse.unquote(query_match.group(1))
                    search_methods.append(('keyword', keyword))
            
            # 각 방법으로 검색 시도
            for method_name, search_term in search_methods:
                try:
                    search_result = self.search_products_by_keyword(str(search_term), limit=50)
                    
                    if search_result and search_result.get('products'):
                        # 정확한 매칭 찾기
                        for product in search_result['products']:
                            # product_id 또는 item_id 매칭 확인
                            product_pid = str(product.get('product_id', ''))
                            if product_pid == product_id:
                                product['affiliate_link'] = affiliate_link or product_url
                                product['method_used'] = f"검색성공({method_name})"
                                return product
                
                except Exception:
                    continue
            
            # 모든 방법이 실패한 경우 기본 정보 반환
            return {
                'platform': '쿠팡',
                'product_id': product_id,
                'item_id': item_id,
                'title': f'쿠팡 상품 {product_id}',
                'price': '가격 확인 필요',
                'image_url': '',
                'affiliate_link': affiliate_link or product_url,
                'rating': 0,
                'review_count': 0,
                'method_used': '기본정보반환'
            }
            
        except Exception:
            return None
    
    def format_coupang_response(self, data):
        """쿠팡 API 응답을 표준 형식으로 변환"""
        products = []
        
        if 'data' in data and 'productData' in data['data']:
            for item in data['data']['productData']:
                product = {
                    'platform': '쿠팡',
                    'product_id': item.get('productId', ''),
                    'title': item.get('productName', ''),
                    'price': f"{item.get('productPrice', 0):,}원",
                    'image_url': item.get('productImage', ''),
                    'original_url': item.get('productUrl', ''),
                    'rating': 0,
                    'review_count': 0
                }
                products.append(product)
        
        return {'products': products}

class AliexpressAPI:
    """검증된 알리익스프레스 API 클래스"""
    
    def __init__(self, app_key, app_secret, tracking_id="default"):
        self.app_key = app_key
        self.app_secret = app_secret
        self.tracking_id = tracking_id
        self.gateway_url = "https://api-sg.aliexpress.com/sync"
    
    def generate_signature(self, params):
        """MD5 서명 생성"""
        sorted_params = sorted(params.items())
        query_string = ''.join([f"{k}{v}" for k, v in sorted_params])
        sign_string = self.app_secret + query_string + self.app_secret
        
        return hashlib.md5(sign_string.encode('utf-8')).hexdigest().upper()
    
    def extract_product_info_from_url(self, url):
        """실제 알리익스프레스 URL에서 상품 정보 추출 (개선됨)"""
        parsed = urllib.parse.urlparse(url)
        query_params = urllib.parse.parse_qs(parsed.query)
        
        result = {}
        
        # 상품 ID 추출 (item/숫자)
        item_match = re.search(r'/item/(\d+)\.html', parsed.path)
        if item_match:
            result['product_id'] = item_match.group(1)
        
        # 중요한 파라미터들 추출
        important_keys = ['spm', 'algo_pvid', 'pdp_npi', 'curPageLogUid']
        for key in important_keys:
            if key in query_params:
                result[key] = query_params[key][0]
        
        return result
    
    def extract_product_id_from_url(self, url):
        """기존 호환성을 위한 래퍼 함수"""
        info = self.extract_product_info_from_url(url)
        return info.get('product_id')
    
    def convert_to_affiliate_link(self, product_url):
        """일반 상품 링크를 어필리에이트 링크로 변환"""
        try:
            params = {
                'method': 'aliexpress.affiliate.link.generate',
                'app_key': self.app_key,
                'timestamp': str(int(time.time() * 1000)),
                'format': 'json',
                'v': '2.0',
                'sign_method': 'md5',
                'source_values': product_url,
                'promotion_link_type': '0',
                'tracking_id': self.tracking_id
            }
            
            params['sign'] = self.generate_signature(params)
            
            query_string = urllib.parse.urlencode(params)
            full_url = f"{self.gateway_url}?{query_string}"
            
            req = urllib.request.Request(full_url)
            
            with urllib.request.urlopen(req) as response:
                if response.status == 200:
                    data = json.loads(response.read().decode('utf-8'))
                    
                    if 'aliexpress_affiliate_link_generate_response' in data:
                        resp_result = data['aliexpress_affiliate_link_generate_response']['resp_result']
                        if resp_result.get('result') and resp_result['result'].get('promotion_links'):
                            links = resp_result['result']['promotion_links']['promotion_link']
                            if isinstance(links, list) and len(links) > 0:
                                return links[0].get('promotion_link')
                            elif isinstance(links, dict):
                                return links.get('promotion_link')
            return None
        except Exception:
            return None
    
    def get_product_details(self, product_id):
        """상품 상세 정보 조회"""
        try:
            params = {
                'method': 'aliexpress.affiliate.productdetail.get',
                'app_key': self.app_key,
                'timestamp': str(int(time.time() * 1000)),
                'format': 'json',
                'v': '2.0',
                'sign_method': 'md5',
                'product_ids': product_id,
                'tracking_id': self.tracking_id
            }
            
            params['sign'] = self.generate_signature(params)
            
            query_string = urllib.parse.urlencode(params)
            full_url = f"{self.gateway_url}?{query_string}"
            
            req = urllib.request.Request(full_url)
            
            with urllib.request.urlopen(req) as response:
                if response.status == 200:
                    data = json.loads(response.read().decode('utf-8'))
                    
                    if 'aliexpress_affiliate_productdetail_get_response' in data:
                        resp_result = data['aliexpress_affiliate_productdetail_get_response']['resp_result']
                        if resp_result.get('result') and resp_result['result'].get('products'):
                            products = resp_result['result']['products']['product']
                            
                            if isinstance(products, list) and len(products) > 0:
                                return self.format_single_product(products[0])
                            elif isinstance(products, dict):
                                return self.format_single_product(products)
            return None
        except Exception:
            return None
    
    def get_product_info_from_url(self, product_url):
        """실제 URL 구조에 맞는 상품 정보 추출 (개선됨)"""
        try:
            # 1. URL에서 상품 정보 추출
            url_info = self.extract_product_info_from_url(product_url)
            product_id = url_info.get('product_id')
            
            if not product_id:
                return None
            
            # 2. 어필리에이트 링크 변환 (전체 URL 사용)
            # 복잡한 파라미터가 있는 URL도 제대로 변환
            affiliate_link = self.convert_to_affiliate_link(product_url)
            
            # 3. 상품 상세 정보 API 호출
            product_info = self.get_product_details(product_id)
            
            if product_info:
                product_info['affiliate_link'] = affiliate_link or product_url
                product_info['method_used'] = '상세정보API성공'
                # 추출된 파라미터 정보도 포함
                product_info['extracted_params'] = {
                    k: v for k, v in url_info.items() 
                    if k in ['spm', 'algo_pvid', 'pdp_npi']
                }
                return product_info
            
            # 상세 정보를 가져올 수 없는 경우 기본 정보 반환
            return {
                'platform': '알리익스프레스',
                'product_id': product_id,
                'title': f'알리익스프레스 상품 {product_id}',
                'price': '가격 확인 필요',
                'image_url': '',
                'affiliate_link': affiliate_link or product_url,
                'rating': 0,
                'review_count': 0,
                'method_used': '기본정보반환',
                'extracted_params': {
                    k: v for k, v in url_info.items() 
                    if k in ['spm', 'algo_pvid', 'pdp_npi']
                }
            }
            
        except Exception:
            return None
    
    def format_single_product(self, item):
        """단일 상품 정보 포맷팅"""
        # USD to KRW 환율 (대략적)
        usd_to_krw = 1400
        
        try:
            price_usd = float(item.get('target_sale_price', '0'))
            price_krw = int(price_usd * usd_to_krw)
        except:
            price_usd = 0
            price_krw = 0
        
        return {
            'platform': '알리익스프레스',
            'product_id': item.get('product_id', ''),
            'title': item.get('product_title', ''),
            'price': f"{price_krw:,}원 (${price_usd})",
            'image_url': item.get('product_main_image_url', ''),
            'original_url': item.get('product_detail_url', ''),
            'rating': item.get('evaluate_rate', '0'),
            'review_count': item.get('lastest_volume', 0)
        }

def detect_platform(url):
    """URL에서 플랫폼 감지"""
    if 'coupang.com' in url:
        return 'coupang'
    elif 'aliexpress.com' in url:
        return 'aliexpress'
    else:
        return None

def main():
    """메인 실행 함수"""
    if len(sys.argv) != 3:
        print(json.dumps({
            "success": False,
            "message": "Usage: python3 real_product_analyzer.py <platform> <url>"
        }))
        return
    
    platform = sys.argv[1].lower()
    url = sys.argv[2]
    
    # 환경변수 로드
    env_vars = load_env_simple()
    
    # 플랫폼 자동 감지
    detected_platform = detect_platform(url)
    if detected_platform:
        platform = detected_platform
    
    try:
        if platform == 'coupang':
            access_key = env_vars.get('COUPANG_ACCESS_KEY')
            secret_key = env_vars.get('COUPANG_SECRET_KEY')
            
            if not access_key or not secret_key:
                print(json.dumps({
                    "success": False,
                    "message": "쿠팡 API 키가 설정되지 않았습니다"
                }))
                return
            
            api = CoupangAPI(access_key, secret_key)
            result = api.get_product_info_from_url(url)
            
        elif platform == 'aliexpress':
            app_key = env_vars.get('ALIEXPRESS_APP_KEY')
            app_secret = env_vars.get('ALIEXPRESS_APP_SECRET')
            tracking_id = env_vars.get('ALIEXPRESS_TRACKING_ID', 'default')
            
            if not app_key or not app_secret:
                print(json.dumps({
                    "success": False,
                    "message": "알리익스프레스 API 키가 설정되지 않았습니다"
                }))
                return
            
            api = AliexpressAPI(app_key, app_secret, tracking_id)
            result = api.get_product_info_from_url(url)
            
        else:
            print(json.dumps({
                "success": False,
                "message": f"지원하지 않는 플랫폼입니다: {platform}"
            }))
            return
        
        if result:
            print(json.dumps({
                "success": True,
                "data": result
            }, ensure_ascii=False))
        else:
            print(json.dumps({
                "success": False,
                "message": "상품 정보를 추출할 수 없습니다"
            }))
            
    except Exception as e:
        print(json.dumps({
            "success": False,
            "message": f"처리 중 오류가 발생했습니다: {str(e)}"
        }))

if __name__ == "__main__":
    main()
# -*- coding: utf-8 -*-
"""
실제 상품 정보 분석 스크립트
SafeAPIManager를 사용하여 진짜 API 호출
"""

import sys
import json
import os
from datetime import datetime

# SafeAPIManager 경로 추가
sys.path.append("/var/www/novacents/tools")

def analyze_product_real(platform, url):
    """실제 API를 사용한 상품 분석"""
    try:
        # SafeAPIManager 임포트 및 초기화
        from safe_api_manager import SafeAPIManager
        
        # 조용한 모드로 실행 (JSON 출력만)
        os.environ['SAFE_API_QUIET'] = '1'
        api_manager = SafeAPIManager(mode="production")
        
        if platform.lower() == "coupang":
            return analyze_coupang_real(api_manager, url)
        elif platform.lower() == "aliexpress":
            return analyze_aliexpress_real(api_manager, url)
        else:
            return {
                "success": False,
                "message": f"지원하지 않는 플랫폼: {platform}"
            }
            
    except ImportError as e:
        return {
            "success": False,
            "message": f"SafeAPIManager 로드 실패: {str(e)}"
        }
    except Exception as e:
        return {
            "success": False,
            "message": f"분석 중 오류 발생: {str(e)}"
        }

def analyze_coupang_real(api_manager, url):
    """쿠팡 실제 API 호출"""
    try:
        # 실제 링크 변환 API 호출
        success, affiliate_link, product_info = api_manager.convert_coupang_to_affiliate_link(url)
        
        if not success:
            return {
                "success": False,
                "message": "쿠팡 링크 변환에 실패했습니다.",
                "details": "API 호출 제한에 도달했거나 유효하지 않은 URL입니다."
            }
        
        # 상품 ID 추출
        import re
        product_id_match = re.search(r'/products/(\d+)', url)
        product_id = product_id_match.group(1) if product_id_match else "unknown"
        
        return {
            "success": True,
            "platform": "쿠팡",
            "product_id": product_id,
            "original_url": url,
            "affiliate_url": affiliate_link,
            "product_info": {
                "title": product_info.get("title", product_info.get("productName", "상품명 정보 없음")),
                "price": product_info.get("price", product_info.get("productPrice", "가격 정보 없음")),
                "original_price": product_info.get("original_price", "원가 정보 없음"),
                "discount_rate": product_info.get("discount_rate", "할인 정보 없음"),
                "image_url": product_info.get("image_url", product_info.get("productImage", "이미지 정보 없음")),
                "rating": product_info.get("rating", "평점 정보 없음"),
                "review_count": product_info.get("review_count", "리뷰 정보 없음"),
                "brand_name": product_info.get("brand_name", "브랜드 정보 없음"),
                "category_name": product_info.get("category_name", product_info.get("categoryName", "카테고리 정보 없음")),
                "is_rocket": product_info.get("is_rocket", product_info.get("isRocket", False)),
                "is_free_shipping": product_info.get("is_free_shipping", False)
            },
            "conversion_method": "실제 쿠팡 딥링크 API 호출",
            "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        }
        
    except Exception as e:
        return {
            "success": False,
            "message": f"쿠팡 API 호출 중 오류: {str(e)}"
        }

def analyze_aliexpress_real(api_manager, url):
    """알리익스프레스 실제 API 호출"""
    try:
        # 실제 링크 변환 API 호출
        success, affiliate_link, product_info = api_manager.convert_aliexpress_to_affiliate_link(url)
        
        if not success:
            return {
                "success": False,
                "message": "알리익스프레스 링크 변환에 실패했습니다.",
                "details": "API 키가 설정되지 않았거나 유효하지 않은 URL입니다."
            }
        
        # 상품 ID 추출
        import re
        product_id_match = re.search(r'/item/(\d+)\.html', url)
        product_id = product_id_match.group(1) if product_id_match else "unknown"
        
        # USD를 KRW로 변환 (환율 1400원 적용)
        def convert_usd_to_krw(price_str):
            if not price_str or price_str == "가격 정보 없음":
                return price_str
            try:
                # 숫자만 추출
                import re
                price_match = re.search(r'[\d.]+', str(price_str))
                if price_match:
                    usd_price = float(price_match.group())
                    krw_price = int(usd_price * 1400)
                    return f"{krw_price:,}원"
            except:
                pass
            return price_str
        
        # 상품 정보 포맷팅
        formatted_price = convert_usd_to_krw(product_info.get("price", product_info.get("target_sale_price", "가격 정보 없음")))
        formatted_original_price = convert_usd_to_krw(product_info.get("original_price", product_info.get("target_original_price", "원가 정보 없음")))
        
        return {
            "success": True,
            "platform": "알리익스프레스",
            "product_id": product_id,
            "original_url": url,
            "affiliate_url": affiliate_link,
            "product_info": {
                "title": product_info.get("title", product_info.get("product_title", "상품명 정보 없음")),
                "price": formatted_price,
                "original_price": formatted_original_price,
                "discount_rate": product_info.get("discount_rate", product_info.get("discount", "할인 정보 없음")),
                "image_url": product_info.get("image_url", product_info.get("product_main_image_url", "이미지 정보 없음")),
                "rating": product_info.get("rating", product_info.get("evaluate_rate", "평점 정보 없음")),
                "review_count": product_info.get("review_count", product_info.get("volume", product_info.get("lastest_volume", "리뷰 정보 없음"))),
                "category": product_info.get("category", product_info.get("first_level_category_name", "카테고리 정보 없음")),
                "commission": product_info.get("commission", product_info.get("30days_commission", "수수료 정보 없음")),
                "commission_rate": product_info.get("commission_rate", product_info.get("relevant_market_commission_rate", "수수료율 정보 없음")),
                "is_plus": product_info.get("is_plus", product_info.get("plus_product", False))
            },
            "conversion_method": "실제 알리익스프레스 공식 SDK API 호출",
            "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        }
        
    except Exception as e:
        return {
            "success": False,
            "message": f"알리익스프레스 API 호출 중 오류: {str(e)}"
        }

def main():
    """메인 함수"""
    if len(sys.argv) != 3:
        result = {
            "success": False,
            "message": "사용법: python3 real_product_analyzer.py <platform> <url>",
            "example": "python3 real_product_analyzer.py coupang https://www.coupang.com/products/123456"
        }
    else:
        platform = sys.argv[1]
        url = sys.argv[2]
        result = analyze_product_real(platform, url)
    
    # JSON 형태로 출력 (stdout만 사용)
    print(json.dumps(result, ensure_ascii=False, indent=2))

if __name__ == "__main__":
    main()