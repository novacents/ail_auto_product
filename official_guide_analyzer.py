#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
🛡️ 간단하게 수정된 알리익스프레스 분석기 
- API에서 받은 정보를 그대로 보여주기
- 복잡한 변환 없이 직관적으로 구현
"""

import os
import sys
import json
import time
import hashlib
import urllib.parse
import urllib.request
import re
from datetime import datetime

# 🔥 조용한 모드 감지 (PHP에서 호출할 때는 로그 출력 안함)
QUIET_MODE = os.environ.get('QUIET_MODE', '0') == '1'

def safe_log(message):
    """🛡️ 안전한 로그 함수 - 조용한 모드에서는 출력 안함"""
    if QUIET_MODE:
        return  # 조용한 모드에서는 로그 출력하지 않음
    
    try:
        # 기존 캐시 디렉토리 활용 (권한 문제 없음)
        log_file = "/var/www/novacents/tools/cache/api_safe.log"
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        with open(log_file, 'a', encoding='utf-8') as f:
            f.write(f"[{timestamp}] {message}\n")
    except:
        # 로그 실패해도 메인 기능에 영향 없음
        pass
    
    # stderr로도 출력 (PHP에서 볼 수 있도록) - 단, 조용한 모드가 아닐 때만
    if not QUIET_MODE:
        print(f"LOG: {message}", file=sys.stderr)

def load_env_safe():
    """🛡️ 안전한 환경변수 로더"""
    env_path = "/home/novacents/.env"
    env_vars = {}
    try:
        with open(env_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    env_vars[key.strip()] = value.strip().strip('"').strip("'")
        safe_log("✅ 환경변수 로드 성공")
        return env_vars
    except Exception as e:
        safe_log(f"❌ 환경변수 로드 실패: {e}")
        return {}

class SimpleAliexpressAnalyzer:
    """
    🛡️ 간단한 알리익스프레스 분석기
    - API 결과를 그대로 표시
    - 복잡한 변환 없이 직관적 구현
    """
    
    def __init__(self, app_key, app_secret, tracking_id="blog"):
        self.app_key = app_key
        self.app_secret = app_secret
        self.tracking_id = tracking_id
        self.gateway_url = "https://api-sg.aliexpress.com/sync"
        
        # 🔥 성공 검증된 완벽한 파라미터 조합
        self.perfect_params = {
            'target_language': 'ko',      # 🔥 핵심: KR이 아닌 ko!
            'target_currency': 'KRW',
            'country': 'KR'
        }
        
        safe_log("🛡️ 간단한 분석기 초기화 완료")

    def generate_signature(self, params):
        """MD5 서명 생성"""
        sorted_params = sorted(params.items())
        query_string = ''.join([f"{k}{v}" for k, v in sorted_params])
        sign_string = self.app_secret + query_string + self.app_secret
        
        return hashlib.md5(sign_string.encode('utf-8')).hexdigest().upper()

    def extract_product_id(self, url):
        """URL에서 상품 ID 추출"""
        patterns = [
            r'/item/(\d+)\.html',
            r'/item/(\d+)$',
            r'/(\d+)\.html'
        ]
        
        for pattern in patterns:
            match = re.search(pattern, url)
            if match:
                product_id = match.group(1)
                safe_log(f"🆔 상품 ID 추출 성공: {product_id}")
                return product_id
        
        safe_log(f"❌ 상품 ID 추출 실패: {url}")
        return None

    def get_product_info(self, url: str) -> dict:
        """
        🎯 간단한 상품 정보 추출 - API 결과 그대로 활용
        """
        safe_log(f"🚀 간단한 분석 시작: {url}")
        
        # 상품 ID 추출
        product_id = self.extract_product_id(url)
        if not product_id:
            raise ValueError("URL에서 상품 ID를 찾을 수 없습니다.")
        
        try:
            # 🔥 성공 검증된 API 파라미터 조합
            base_params = {
                'method': 'aliexpress.affiliate.productdetail.get',
                'app_key': self.app_key,
                'timestamp': str(int(time.time() * 1000)),
                'format': 'json',
                'v': '2.0',
                'sign_method': 'md5',
                'product_ids': product_id,
                'fields': 'product_id,product_title,product_main_image_url,target_sale_price,target_original_price,evaluate_rate,lastest_volume,first_level_category_name,promotion_link,shop_id,shop_url,shop_name',
                'tracking_id': self.tracking_id
            }
            
            # 완벽가이드 핵심 파라미터 적용
            params = {**base_params, **self.perfect_params}
            
            safe_log(f"📋 API 파라미터 설정 완료")
            
            # 서명 생성
            params['sign'] = self.generate_signature(params)
            
            # URL 생성 및 호출
            query_string = urllib.parse.urlencode(params)
            full_url = f"{self.gateway_url}?{query_string}"
            
            safe_log(f"📡 API 호출")
            
            req = urllib.request.Request(full_url)
            with urllib.request.urlopen(req, timeout=15) as response:
                response_text = response.read().decode('utf-8')
                
                if response.status == 200:
                    data = json.loads(response_text)
                    return self.format_response_simple(data, url)
                else:
                    raise ValueError(f"HTTP 오류: {response.status}")
                    
        except Exception as e:
            safe_log(f"❌ API 호출 실패: {e}")
            raise ValueError(f"상품 분석 중 오류가 발생했습니다: {str(e)}")

    def format_response_simple(self, data, original_url):
        """
        🎯 간단한 응답 포맷팅 - API 결과 그대로 활용
        """
        try:
            # 성공 검증된 응답 구조 파싱
            if 'aliexpress_affiliate_productdetail_get_response' in data:
                resp_result = data['aliexpress_affiliate_productdetail_get_response']['resp_result']
                
                if resp_result.get('result') and resp_result['result'].get('products'):
                    products_data = resp_result['result']['products']
                    
                    if 'product' in products_data:
                        products = products_data['product']
                        
                        if isinstance(products, list) and len(products) > 0:
                            product = products[0]
                        elif isinstance(products, dict):
                            product = products
                        else:
                            raise ValueError("상품 데이터 구조 오류")
                        
                        safe_log("🎨 간단한 응답 포맷팅 시작")
                        
                        # 🔥 한국어 상품명 (완벽가이드 성공 결과)
                        title = product.get('product_title', '')
                        has_korean = bool(re.search(r'[가-힣]', title))
                        safe_log(f"📝 상품명: {title[:50]}... (한국어: {'✅' if has_korean else '❌'})")
                        
                        # 🔥 KRW 가격 처리
                        price_str = product.get('target_sale_price', '0')
                        try:
                            if isinstance(price_str, str) and ',' in price_str:
                                price_clean = price_str.replace(',', '')
                            else:
                                price_clean = str(price_str)
                            price_krw = int(float(price_clean))
                            price_display = f"{price_krw:,}원"
                        except:
                            price_display = "가격 정보 없음"
                        
                        safe_log(f"💰 가격: {price_display}")
                        
                        # 🔥 평점 간단 처리
                        evaluate_rate = product.get('evaluate_rate', '')
                        if evaluate_rate and str(evaluate_rate) != '0':
                            rating_display = f"{evaluate_rate}%"
                        else:
                            rating_display = "평점 정보 없음"
                        
                        safe_log(f"🌟 평점: {rating_display}")
                        
                        # 🔥 판매량 간단 처리 - API 필드명 그대로 사용!
                        lastest_volume = product.get('lastest_volume', '')
                        if lastest_volume and str(lastest_volume) != '0':
                            volume_display = f"{lastest_volume}개 판매"
                        else:
                            volume_display = "판매량 정보 없음"
                        
                        safe_log(f"📦 판매량: {volume_display}")
                        
                        # 어필리에이트 링크
                        affiliate_link = product.get('promotion_link', original_url)
                        
                        # 🔥 간단한 최종 결과 - API 필드명 그대로 사용!
                        result = {
                            'platform': 'AliExpress',
                            'product_id': product.get('product_id', ''),
                            'title': title,                          # 🔥 한국어 상품명
                            'price': price_display,                  # 🔥 KRW 가격
                            'image_url': product.get('product_main_image_url', ''),
                            'original_url': original_url,
                            'affiliate_link': affiliate_link,        # 🔥 어필리에이트 링크
                            'rating': rating_display,               # 🔥 평점
                            'lastest_volume': volume_display,       # 🔥 API 필드명 그대로!
                            'shop_name': product.get('shop_name', '상점명 정보 없음'),
                            'method_used': '간단한_직관적_구현',
                            'korean_status': '✅ 한국어 성공' if has_korean else '❌ 영어 표시',
                            'perfect_guide_applied': True
                        }
                        
                        safe_log(f"✅ 간단한 포맷팅 완료")
                        safe_log(f"  한국어 상품명: {'✅' if has_korean else '❌'}")
                        safe_log(f"  가격: {price_display}")
                        safe_log(f"  평점: {rating_display}")
                        safe_log(f"  판매량: {volume_display}")
                        safe_log(f"  어필리에이트 링크: {'✅' if 's.click.aliexpress.com' in affiliate_link else '❌'}")
                        
                        return result
                    else:
                        raise ValueError("products 응답에서 'product' 키를 찾을 수 없음")
                else:
                    raise ValueError("result 또는 products가 응답에 없음")
            else:
                raise ValueError("예상된 응답 구조를 찾을 수 없음")
                
        except Exception as e:
            safe_log(f"❌ 응답 파싱 실패: {e}")
            raise ValueError(f"응답 파싱 중 오류: {str(e)}")

def main():
    """🛡️ 간단한 메인 함수"""
    if len(sys.argv) != 3:
        print(json.dumps({"success": False, "message": "인수 개수가 올바르지 않습니다."}, ensure_ascii=False))
        return

    platform = sys.argv[1].lower()
    url = sys.argv[2]
    
    if platform != 'aliexpress':
        print(json.dumps({"success": False, "message": f"지원하지 않는 플랫폼입니다: {platform}"}, ensure_ascii=False))
        return
    
    try:
        # 🛡️ 안전한 환경변수 로드
        env_vars = load_env_safe()
        
        app_key = env_vars.get('ALIEXPRESS_APP_KEY')
        app_secret = env_vars.get('ALIEXPRESS_APP_SECRET')
        tracking_id = env_vars.get('ALIEXPRESS_TRACKING_ID', 'blog')
        
        if not app_key or not app_secret:
            raise ValueError("알리익스프레스 API 키가 설정되지 않았습니다.")
            
        safe_log(f"🛡️ 간단한 분석 시작 (조용한 모드: {QUIET_MODE})")
        
        # 🔥 간단한 분석기 사용
        analyzer = SimpleAliexpressAnalyzer(app_key, app_secret, tracking_id)
        product_info = analyzer.get_product_info(url)
        
        # 🎯 성공 시, 순수 JSON만 stdout으로 출력
        result = {"success": True, "data": product_info}
        print(json.dumps(result, ensure_ascii=False))
        safe_log(f"✅ 간단한 분석 완료: 평점({product_info.get('rating', 'N/A')}), 판매량({product_info.get('lastest_volume', 'N/A')})")

    except Exception as e:
        safe_log(f"❌ 오류 발생: {e}")
        print(json.dumps({"success": False, "message": f"상품 분석 중 오류가 발생했습니다: {str(e)}"}, ensure_ascii=False))

if __name__ == "__main__":
    main()