#!/usr/bin/env python3
"""
알리익스프레스 한국어 상품명 문제 해결
API에서 한국어를 지원하지 않는 경우 대안 방법 적용
"""

import os
import sys
import json
import hashlib
import time
import urllib.parse
import urllib.request
import re

def load_env_simple():
    """Simple .env loader"""
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

class AliexpressAPI:
    """개선된 알리익스프레스 API - 한국어 문제 해결"""
    
    def __init__(self, app_key, app_secret, tracking_id="blog"):
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
    
    def extract_product_id_from_url(self, url):
        """URL에서 상품 ID 추출"""
        # ko.aliexpress.com도 지원
        patterns = [
            r'/item/(\d+)\.html',
            r'/item/(\d+)$',
            r'productId=(\d+)'
        ]
        
        for pattern in patterns:
            match = re.search(pattern, url)
            if match:
                return match.group(1)
        return None
    
    def get_product_details_multiple_methods(self, product_id, original_url):
        """
        여러 방법으로 상품 정보 조회 시도
        1. productdetail.get (한국어 파라미터 포함)
        2. product.query (검색 방식)
        3. 링크 생성만 (최후 수단)
        """
        print(f"🎯 알리익스프레스 상품 정보 조회 시작: {product_id}", file=sys.stderr)
        
        # 방법 1: productdetail.get API (한국어 파라미터 강화)
        result = self.try_productdetail_api(product_id, original_url)
        if result:
            print("✅ productdetail.get API로 성공!", file=sys.stderr)
            return result
        
        # 방법 2: product.query API (검색 방식)
        result = self.try_product_query_api(product_id, original_url)
        if result:
            print("✅ product.query API로 성공!", file=sys.stderr)
            return result
        
        # 방법 3: 링크 생성만 (최후 수단)
        print("🔄 상품 정보 조회 실패, 링크 생성만 시도", file=sys.stderr)
        affiliate_link = self.convert_to_affiliate_link(original_url)
        
        return {
            'platform': '알리익스프레스',
            'product_id': product_id,
            'title': f'AliExpress 상품 {product_id}',
            'price': '가격 정보 확인 필요',
            'image_url': '',
            'original_url': original_url,
            'affiliate_link': affiliate_link or original_url,
            'rating': '평점 정보 없음',
            'review_count': '판매량 정보 없음',
            'method_used': '링크생성만_폴백',
            'note': '상품 정보 API 조회 실패, 어필리에이트 링크만 생성'
        }
    
    def try_productdetail_api(self, product_id, original_url):
        """방법 1: productdetail.get API (한국어 파라미터 다양하게 시도)"""
        try:
            print("📋 productdetail.get API 시도 (한국어 설정)", file=sys.stderr)
            
            # 🔥 한국어 파라미터를 다양하게 시도
            language_attempts = [
                {'target_language': 'ko', 'target_currency': 'KRW'},  # ko 시도
                {'target_language': 'KR', 'target_currency': 'KRW'},  # KR 시도  
                {'target_language': 'ko_KR', 'target_currency': 'KRW'},  # ko_KR 시도
                {}  # 파라미터 없이 시도
            ]
            
            for i, lang_params in enumerate(language_attempts):
                print(f"🔄 언어 설정 시도 #{i+1}: {lang_params}", file=sys.stderr)
                
                params = {
                    'method': 'aliexpress.affiliate.productdetail.get',
                    'app_key': self.app_key,
                    'timestamp': str(int(time.time() * 1000)),
                    'format': 'json',
                    'v': '2.0',
                    'sign_method': 'md5',
                    'product_ids': product_id,
                    'fields': 'product_id,product_title,product_main_image_url,target_sale_price,target_original_price,evaluate_rate,lastest_volume,first_level_category_name,promotion_link',
                    'tracking_id': self.tracking_id
                }
                
                # 언어 파라미터 추가
                params.update(lang_params)
                
                # 서명 생성
                params['sign'] = self.generate_signature(params)
                
                query_string = urllib.parse.urlencode(params)
                full_url = f"{self.gateway_url}?{query_string}"
                
                req = urllib.request.Request(full_url)
                
                with urllib.request.urlopen(req) as response:
                    response_text = response.read().decode('utf-8')
                    
                    if response.status == 200:
                        data = json.loads(response_text)
                        
                        if 'aliexpress_affiliate_productdetail_get_response' in data:
                            resp_result = data['aliexpress_affiliate_productdetail_get_response']['resp_result']
                            if resp_result.get('result') and resp_result['result'].get('products'):
                                products_data = resp_result['result']['products']
                                
                                if 'product' in products_data:
                                    products = products_data['product']
                                    if isinstance(products, list) and len(products) > 0:
                                        product_info = self.format_product_info(products[0], original_url, f"productdetail_attempt_{i+1}")
                                        if product_info:
                                            return product_info
                                    elif isinstance(products, dict):
                                        product_info = self.format_product_info(products, original_url, f"productdetail_attempt_{i+1}")
                                        if product_info:
                                            return product_info
            
            return None
            
        except Exception as e:
            print(f"❌ productdetail API 오류: {str(e)}", file=sys.stderr)
            return None
    
    def try_product_query_api(self, product_id, original_url):
        """방법 2: product.query API (검색 방식)"""
        try:
            print("📋 product.query API 시도 (검색 방식)", file=sys.stderr)
            
            params = {
                'method': 'aliexpress.affiliate.product.query',
                'app_key': self.app_key,
                'timestamp': str(int(time.time() * 1000)),
                'format': 'json',
                'v': '2.0',
                'sign_method': 'md5',
                'keywords': product_id,  # 상품 ID를 키워드로 검색
                'page_size': '5',
                'page_no': '1',
                'tracking_id': self.tracking_id
            }
            
            params['sign'] = self.generate_signature(params)
            
            query_string = urllib.parse.urlencode(params)
            full_url = f"{self.gateway_url}?{query_string}"
            
            req = urllib.request.Request(full_url)
            
            with urllib.request.urlopen(req) as response:
                response_text = response.read().decode('utf-8')
                print(f"📄 query API 응답: {response_text}", file=sys.stderr)
                
                if response.status == 200:
                    data = json.loads(response_text)
                    
                    if 'aliexpress_affiliate_product_query_response' in data:
                        resp_result = data['aliexpress_affiliate_product_query_response']['resp_result']
                        if resp_result.get('result') and resp_result['result'].get('products'):
                            products = resp_result['result']['products']
                            if isinstance(products, list) and len(products) > 0:
                                # 상품 ID가 일치하는 것 찾기
                                for product in products:
                                    if str(product.get('product_id')) == str(product_id):
                                        return self.format_product_info(product, original_url, "product_query_exact_match")
                                
                                # 정확한 매칭이 없으면 첫 번째 결과
                                return self.format_product_info(products[0], original_url, "product_query_first_result")
            
            return None
            
        except Exception as e:
            print(f"❌ product.query API 오류: {str(e)}", file=sys.stderr)
            return None
    
    def convert_to_affiliate_link(self, product_url):
        """어필리에이트 링크 생성"""
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
                response_text = response.read().decode('utf-8')
                
                if response.status == 200:
                    data = json.loads(response_text)
                    
                    if 'aliexpress_affiliate_link_generate_response' in data:
                        resp_result = data['aliexpress_affiliate_link_generate_response']['resp_result']
                        if resp_result.get('result') and resp_result['result'].get('promotion_links'):
                            promotion_links = resp_result['result']['promotion_links']
                            
                            if 'promotion_link' in promotion_links:
                                links = promotion_links['promotion_link']
                                if isinstance(links, list) and len(links) > 0:
                                    return links[0].get('promotion_link')
                                elif isinstance(links, dict):
                                    return links.get('promotion_link')
            return None
            
        except Exception as e:
            print(f"❌ 링크 생성 오류: {str(e)}", file=sys.stderr)
            return None
    
    def format_product_info(self, item, original_url, method_used):
        """상품 정보 포맷팅 (한국어 처리 개선)"""
        try:
            # 상품명 처리 (한국어가 안 되면 영어라도 정확히)
            product_title = item.get('product_title', '') or item.get('title', '') or f'AliExpress Product {item.get("product_id", "")}'
            
            # 가격 처리 (USD -> KRW 변환)
            price = item.get('target_sale_price', '0') or item.get('sale_price', '0')
            try:
                usd_price = float(price)
                krw_price = int(usd_price * 1400)  # 환율 1400원 적용
                formatted_price = f"${usd_price:.2f} (약 ₩{krw_price:,})"
            except:
                formatted_price = f"${price}"
            
            # 이미지 URL
            image_url = item.get('product_main_image_url', '') or item.get('main_image_url', '')
            
            # 평점 처리
            rating = item.get('evaluate_rate', '0') or item.get('rating', '0')
            if rating and '%' not in str(rating):
                rating = f"{rating}%"
            
            # 판매량 처리
            volume = item.get('lastest_volume', 0) or item.get('volume', 0)
            try:
                volume_int = int(volume)
                volume_display = f"{volume_int:,}개 판매" if volume_int > 0 else "판매량 정보 없음"
            except:
                volume_display = "판매량 정보 없음"
            
            # 어필리에이트 링크
            affiliate_link = item.get('promotion_link', '') or self.convert_to_affiliate_link(original_url)
            
            result = {
                'platform': '알리익스프레스',
                'product_id': item.get('product_id', ''),
                'title': product_title,  # 🔥 최대한 정확한 상품명
                'price': formatted_price,  # 🔥 USD + KRW 병기
                'image_url': image_url,
                'original_url': original_url,
                'rating': rating,
                'review_count': volume_display,
                'category': item.get('first_level_category_name', '카테고리 정보 없음'),
                'affiliate_link': affiliate_link or original_url,
                'method_used': method_used,
                'note': '한국어 상품명은 API 제한으로 영어로 표시될 수 있습니다. 가격은 USD와 KRW로 표시됩니다.'
            }
            
            print(f"✅ 상품 정보 포맷팅 성공: {result['title'][:50]}...", file=sys.stderr)
            return result
            
        except Exception as e:
            print(f"❌ 상품 정보 포맷팅 오류: {str(e)}", file=sys.stderr)
            return None

def analyze_aliexpress_product_correct(product_url):
    """개선된 알리익스프레스 상품 분석"""
    try:
        print(f"🎯 알리익스프레스 상품 분석 시작: {product_url}", file=sys.stderr)
        
        # 환경변수 로드
        env_vars = load_env_simple()
        app_key = env_vars.get('ALIEXPRESS_APP_KEY')
        app_secret = env_vars.get('ALIEXPRESS_APP_SECRET')
        tracking_id = env_vars.get('ALIEXPRESS_TRACKING_ID', 'blog')
        
        if not app_key or not app_secret:
            return {
                "success": False,
                "error": "알리익스프레스 API 키가 설정되지 않았습니다"
            }
        
        # API 초기화
        api = AliexpressAPI(app_key, app_secret, tracking_id)
        
        # 상품 ID 추출
        product_id = api.extract_product_id_from_url(product_url)
        if not product_id:
            return {
                "success": False,
                "error": "URL에서 상품 ID를 추출할 수 없습니다"
            }
        
        print(f"🔍 추출된 상품 ID: {product_id}", file=sys.stderr)
        
        # 여러 방법으로 상품 정보 조회
        result = api.get_product_details_multiple_methods(product_id, product_url)
        
        if result:
            return {
                "success": True,
                "original_url": product_url,
                "product_info": result
            }
        else:
            return {
                "success": False,
                "error": "모든 방법으로 상품 정보 조회 실패"
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
    result = analyze_aliexpress_product_correct(product_url)
    print(json.dumps(result, ensure_ascii=False))

if __name__ == "__main__":
    main()
