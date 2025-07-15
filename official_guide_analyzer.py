#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
🔧 API 응답 구조 문제 긴급 수정
- 원래 작동하던 로직 기반으로 복원
- 평점 정보 추출만 강화
- API 응답 구조 파싱 안정화
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

def parse_rating_value(evaluate_rate):
    """🌟 평점 값을 0-100 숫자로 파싱"""
    try:
        if not evaluate_rate:
            return 0
        
        # 평점 값 파싱
        rate_str = str(evaluate_rate).strip()
        
        if not rate_str or rate_str == '0' or rate_str.lower() == 'none':
            return 0
        
        # %가 있는 경우 제거
        if rate_str.endswith('%'):
            rate_value = float(rate_str.replace('%', ''))
        else:
            rate_value = float(rate_str)
        
        # 0-100 범위로 제한
        final_value = max(0, min(100, rate_value))
        safe_log(f"✅ 평점 파싱 성공: {rate_str} → {final_value}%")
        return final_value
        
    except Exception as e:
        safe_log(f"❌ 평점 파싱 실패: {e}")
        return 0

def format_rating_with_stars(rating_value):
    """🌟 평점을 별표 형태로 변환"""
    if not rating_value or rating_value == 0:
        return "평점 정보 없음"
    
    if rating_value >= 95:
        return f"⭐⭐⭐⭐⭐ ({rating_value}%)"
    elif rating_value >= 80:
        return f"⭐⭐⭐⭐ ({rating_value}%)"
    elif rating_value >= 60:
        return f"⭐⭐⭐ ({rating_value}%)"
    elif rating_value >= 40:
        return f"⭐⭐ ({rating_value}%)"
    elif rating_value >= 20:
        return f"⭐ ({rating_value}%)"
    else:
        return f"({rating_value}%)"

class SafeAliexpressAnalyzer:
    """
    🔧 안전한 알리익스프레스 분석기 (긴급 수정)
    - 원래 작동하던 로직 기반
    - API 응답 구조 안정화
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
        
        safe_log("🔧 안전한 분석기 초기화 완료")

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
        🔧 안전한 상품 정보 추출 (긴급 수정)
        """
        safe_log(f"🚀 안전한 분석 시작: {url}")
        
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
                'fields': 'product_id,product_title,product_main_image_url,target_sale_price,target_original_price,evaluate_rate,lastest_volume,first_level_category_name,promotion_link',
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
                    safe_log(f"📨 API 응답 수신 성공")
                    return self.format_safe_response(data, url)
                else:
                    raise ValueError(f"HTTP 오류: {response.status}")
                    
        except Exception as e:
            safe_log(f"❌ API 호출 실패: {e}")
            raise ValueError(f"상품 분석 중 오류가 발생했습니다: {str(e)}")

    def format_safe_response(self, data, original_url):
        """
        🔧 안전한 응답 포맷팅 (긴급 수정)
        - 모든 가능한 응답 구조 처리
        - 안전한 필드 접근
        """
        try:
            safe_log("🔍 API 응답 구조 안전 확인...")
            safe_log(f"응답 최상위 키들: {list(data.keys())}")
            
            # 🔧 안전한 응답 구조 파싱 (모든 경우 처리)
            product = None
            
            # 가능한 응답 구조들을 순차적으로 확인
            if 'aliexpress_affiliate_productdetail_get_response' in data:
                response_obj = data['aliexpress_affiliate_productdetail_get_response']
                safe_log(f"표준 응답 구조 발견")
                
                if 'resp_result' in response_obj:
                    resp_result = response_obj['resp_result']
                    
                    if resp_result and 'result' in resp_result:
                        result = resp_result['result']
                        
                        if result and 'products' in result:
                            products_data = result['products']
                            
                            if 'product' in products_data:
                                products = products_data['product']
                                
                                if isinstance(products, list) and len(products) > 0:
                                    product = products[0]
                                elif isinstance(products, dict):
                                    product = products
            
            # 🔧 대안 응답 구조도 확인
            if not product:
                # 다른 가능한 구조들 확인
                for key in data.keys():
                    if 'response' in key.lower():
                        safe_log(f"대안 응답 구조 확인: {key}")
                        # 추가 파싱 로직...
            
            if not product:
                safe_log(f"❌ 모든 응답 구조에서 상품 데이터를 찾을 수 없음")
                safe_log(f"전체 응답 구조: {json.dumps(data, ensure_ascii=False, indent=2)}")
                raise ValueError("API 응답에서 상품 데이터를 찾을 수 없습니다.")
            
            safe_log("🔧 안전한 포맷팅 시작")
            safe_log(f"🔍 상품 데이터 확인: {json.dumps(product, ensure_ascii=False, indent=2)[:500]}...")
            
            # 🔥 안전한 필드 추출
            title = product.get('product_title', '상품명 정보 없음')
            has_korean = bool(re.search(r'[가-힣]', title))
            safe_log(f"📝 상품명: {title[:50]}... (한국어: {'✅' if has_korean else '❌'})")
            
            # 🔥 안전한 가격 처리
            price_str = product.get('target_sale_price', '0')
            try:
                if isinstance(price_str, str) and ',' in price_str:
                    price_clean = price_str.replace(',', '')
                else:
                    price_clean = str(price_str)
                price_krw = int(float(price_clean))
                price_display = f"₩{price_krw:,}"
            except:
                price_display = "가격 정보 없음"
            
            safe_log(f"💰 가격: {price_display}")
            
            # 🌟 안전한 평점 정보 추출
            evaluate_rate = product.get('evaluate_rate')
            safe_log(f"🔍 평점 원본: {evaluate_rate}")
            
            # 평점이 없거나 0이면 다른 필드도 확인
            if not evaluate_rate or str(evaluate_rate) == '0' or str(evaluate_rate).lower() == 'none':
                # 다른 가능한 평점 필드들 확인
                alternative_fields = ['rating', 'product_rating', 'evaluation_rate', 'score', 'rate']
                for field in alternative_fields:
                    if field in product and product[field] and str(product[field]) != '0':
                        evaluate_rate = product[field]
                        safe_log(f"🔍 대체 평점 필드 '{field}'에서 발견: {evaluate_rate}")
                        break
            
            rating_value = parse_rating_value(evaluate_rate)
            rating_display = format_rating_with_stars(rating_value)
            safe_log(f"⭐ 최종 평점: {rating_display}")
            
            # 🔥 안전한 판매량 처리
            lastest_volume = product.get('lastest_volume', '')
            if lastest_volume and str(lastest_volume) != '0':
                volume_display = f"{lastest_volume}개 판매"
            else:
                volume_display = "판매량 정보 없음"
            
            safe_log(f"📦 판매량: {volume_display}")
            
            # 안전한 어필리에이트 링크
            affiliate_link = product.get('promotion_link', original_url)
            
            # 🔧 안전한 최종 결과
            result = {
                'platform': 'AliExpress',
                'product_id': product.get('product_id', ''),
                'title': title,
                'price': price_display,
                'image_url': product.get('product_main_image_url', ''),
                'original_url': original_url,
                'affiliate_link': affiliate_link,
                'rating_value': rating_value,
                'rating_display': rating_display,
                'lastest_volume': volume_display,
                'method_used': '안전한_분석_긴급수정'
            }
            
            safe_log(f"✅ 안전한 분석 완료")
            safe_log(f"  한국어 상품명: {'✅' if has_korean else '❌'}")
            safe_log(f"  가격: {price_display}")
            safe_log(f"  평점: {rating_display}")
            safe_log(f"  판매량: {volume_display}")
            
            return result
                
        except Exception as e:
            safe_log(f"❌ 응답 파싱 실패: {e}")
            safe_log(f"전체 응답: {json.dumps(data, ensure_ascii=False, indent=2)[:1000]}...")
            raise ValueError(f"응답 파싱 중 오류: {str(e)}")

def main():
    """🔧 안전한 메인 함수"""
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
            
        safe_log(f"🔧 안전한 분석 시작 (조용한 모드: {QUIET_MODE})")
        
        # 🔧 안전한 분석기 사용
        analyzer = SafeAliexpressAnalyzer(app_key, app_secret, tracking_id)
        product_info = analyzer.get_product_info(url)
        
        # 🎯 성공 시, 순수 JSON만 stdout으로 출력
        result = {"success": True, "data": product_info}
        print(json.dumps(result, ensure_ascii=False))
        safe_log(f"✅ 안전한 분석 완료: 평점({product_info.get('rating_display', 'N/A')}), 판매량({product_info.get('lastest_volume', 'N/A')})")

    except Exception as e:
        safe_log(f"❌ 오류 발생: {e}")
        print(json.dumps({"success": False, "message": f"상품 분석 중 오류가 발생했습니다: {str(e)}"}, ensure_ascii=False))

if __name__ == "__main__":
    main()
