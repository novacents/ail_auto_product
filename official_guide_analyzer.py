#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
🌟 평점 정보 추출 강화 + 원래 별 개수 방식 복원
- 평점 정보 추출 로직 대폭 강화
- 원래 방식 (평점 %에 따라 별 개수 조절) 복원
- 더 많은 평점 필드 확인
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

def parse_rating_value_enhanced(product_data):
    """🌟 평점 값 추출 대폭 강화 - 모든 가능한 필드 확인"""
    try:
        safe_log("🔍 평점 정보 대폭 강화된 추출 시작")
        
        # 🔥 모든 가능한 평점 필드들 체크
        rating_fields = [
            'evaluate_rate',      # 기본 필드
            'evaluation_rate',    # 대안 1
            'rating',            # 대안 2
            'product_rating',    # 대안 3
            'score',             # 대안 4
            'rate',              # 대안 5
            'product_score',     # 대안 6
            'seller_score',      # 대안 7
            'quality_score',     # 대안 8
            'average_rating',    # 대안 9
            'star_rating'        # 대안 10
        ]
        
        found_rating = None
        found_field = None
        
        for field in rating_fields:
            if field in product_data:
                value = product_data[field]
                if value and str(value) != '0' and str(value).lower() != 'none' and str(value) != 'null':
                    found_rating = value
                    found_field = field
                    safe_log(f"🎯 평점 필드 '{field}'에서 발견: {value}")
                    break
        
        if not found_rating:
            safe_log("⚠️ 모든 평점 필드에서 데이터를 찾을 수 없음")
            return 0
        
        # 평점 값 파싱
        rate_str = str(found_rating).strip()
        
        # %가 있는 경우 제거
        if rate_str.endswith('%'):
            rate_value = float(rate_str.replace('%', ''))
        else:
            rate_value = float(rate_str)
        
        # 0-100 범위로 제한
        final_value = max(0, min(100, rate_value))
        safe_log(f"✅ 평점 파싱 성공: {found_field}={rate_str} → {final_value}%")
        return final_value
        
    except Exception as e:
        safe_log(f"❌ 평점 파싱 실패: {e}")
        return 0

def format_rating_original_style(rating_value):
    """🌟 원래 방식 - 평점 %에 따라 별 개수 조절"""
    if not rating_value or rating_value == 0:
        return "평점 정보 없음"
    
    # 🔥 원래 방식: 평점에 따라 별 개수 결정
    if rating_value >= 90:
        return f"⭐⭐⭐⭐⭐ ({rating_value}%)"
    elif rating_value >= 70:
        return f"⭐⭐⭐⭐ ({rating_value}%)"
    elif rating_value >= 50:
        return f"⭐⭐⭐ ({rating_value}%)"
    elif rating_value >= 30:
        return f"⭐⭐ ({rating_value}%)"
    elif rating_value >= 10:
        return f"⭐ ({rating_value}%)"
    else:
        return f"⭐ ({rating_value}%)"  # 최소 1개 별

class EnhancedAliexpressAnalyzer:
    """
    🌟 평점 정보 추출 대폭 강화 + 원래 별 방식
    - 모든 가능한 평점 필드 확인
    - 원래 별 개수 조절 방식 복원
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
        
        safe_log("🌟 평점 정보 강화 분석기 초기화 완료")

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
        🌟 평점 정보 강화 추출
        """
        safe_log(f"🚀 평점 정보 강화 분석 시작: {url}")
        
        # 상품 ID 추출
        product_id = self.extract_product_id(url)
        if not product_id:
            raise ValueError("URL에서 상품 ID를 찾을 수 없습니다.")
        
        try:
            # 🔥 평점 정보를 위한 확장된 필드 요청
            base_params = {
                'method': 'aliexpress.affiliate.productdetail.get',
                'app_key': self.app_key,
                'timestamp': str(int(time.time() * 1000)),
                'format': 'json',
                'v': '2.0',
                'sign_method': 'md5',
                'product_ids': product_id,
                'fields': 'product_id,product_title,product_main_image_url,target_sale_price,target_original_price,evaluate_rate,evaluation_rate,rating,product_rating,score,rate,lastest_volume,first_level_category_name,promotion_link,seller_score,quality_score,average_rating,star_rating',  # 🔥 평점 필드 대폭 확장
                'tracking_id': self.tracking_id
            }
            
            # 완벽가이드 핵심 파라미터 적용
            params = {**base_params, **self.perfect_params}
            
            # 서명 생성
            params['sign'] = self.generate_signature(params)
            
            # URL 인코딩
            query_string = urllib.parse.urlencode(params)
            request_url = f"{self.gateway_url}?{query_string}"
            
            safe_log(f"🌐 API 요청 URL: {request_url[:100]}...")
            
            # API 호출
            with urllib.request.urlopen(request_url, timeout=10) as response:
                response_data = response.read().decode('utf-8')
                safe_log(f"📥 API 응답 크기: {len(response_data)} bytes")
                
            # JSON 파싱
            data = json.loads(response_data)
            
            if 'error_response' in data:
                error_info = data['error_response']
                error_msg = f"API 오류: {error_info.get('msg', 'Unknown error')} (Code: {error_info.get('code', 'N/A')})"
                safe_log(f"❌ {error_msg}")
                raise Exception(error_msg)
            
            # 상품 데이터 추출
            if 'aliexpress_affiliate_productdetail_get_response' not in data:
                safe_log("❌ 응답에 상품 상세 정보가 없습니다")
                safe_log(f"🔍 실제 응답 구조: {list(data.keys())}")
                raise Exception("응답에 상품 상세 정보가 없습니다")
            
            product_detail = data['aliexpress_affiliate_productdetail_get_response']
            if 'resp_result' not in product_detail:
                safe_log("❌ resp_result가 응답에 없습니다")
                raise Exception("resp_result가 응답에 없습니다")
            
            resp_result = product_detail['resp_result']
            if 'result' not in resp_result:
                safe_log("❌ result가 resp_result에 없습니다")
                raise Exception("result가 resp_result에 없습니다")
            
            result_data = resp_result['result']
            if 'products' not in result_data or not result_data['products']:
                safe_log("❌ 상품 목록이 비어있습니다")
                raise Exception("상품을 찾을 수 없습니다")
                
            product = result_data['products'][0]
            safe_log(f"🔍 전체 상품 데이터: {json.dumps(product, ensure_ascii=False, indent=2)}")
            
            # 🌟 강화된 평점 정보 추출
            rating_value = parse_rating_value_enhanced(product)
            rating_display = format_rating_original_style(rating_value)
            
            # 기본 상품 정보
            title = product.get('product_title', '')
            safe_log(f"📝 상품명: {title}")
            
            # 가격 정보 (KRW 변환된 값 우선)
            target_sale_price = product.get('target_sale_price', '')
            target_original_price = product.get('target_original_price', '')
            
            if target_sale_price:
                # 숫자만 추출하여 천 단위 콤마 추가
                try:
                    price_num = float(target_sale_price)
                    price_display = f"₩ {price_num:,.0f}"
                except:
                    price_display = f"₩ {target_sale_price}"
            else:
                price_display = "가격 정보 없음"
                
            safe_log(f"💰 가격: {price_display}")
            
            # 판매량 정보
            volume = product.get('lastest_volume', '')
            if volume:
                try:
                    volume_num = int(volume)
                    volume_display = f"{volume_num}개 판매"
                except:
                    volume_display = f"{volume}개 판매"
            else:
                volume_display = "판매량 정보 없음"
                
            safe_log(f"📦 판매량: {volume_display}")
            
            # 어필리에이트 링크
            affiliate_link = product.get('promotion_link', '')
            original_url = url
            
            # 응답 데이터 구성
            result = {
                'platform': 'AliExpress',
                'product_id': product.get('product_id', ''),
                'title': title,
                'price': price_display,
                'image_url': product.get('product_main_image_url', ''),
                'original_url': original_url,
                'affiliate_link': affiliate_link,
                'rating_value': rating_value,           # 🌟 숫자 평점 (0-100)
                'rating_display': rating_display,       # 🌟 원래 별 방식 (⭐⭐⭐⭐⭐)
                'lastest_volume': volume_display,
                'first_level_category_name': product.get('first_level_category_name', ''),
                'analyzed_at': datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            }
            
            safe_log(f"✅ 상품 분석 완료: {title[:50]}...")
            return result
            
        except Exception as e:
            safe_log(f"❌ API 호출 실패: {e}")
            raise

def main():
    """🌟 평점 정보 강화 메인 함수"""
    if len(sys.argv) != 3:
        print(json.dumps({"success": False, "message": "인수 개수가 올바르지 않습니다."}, ensure_ascii=False).replace('\\/', '/'))
        return

    platform = sys.argv[1].lower()
    url = sys.argv[2]
    
    if platform != 'aliexpress':
        print(json.dumps({"success": False, "message": f"지원하지 않는 플랫폼입니다: {platform}"}, ensure_ascii=False).replace('\\/', '/'))
        return
    
    try:
        # 🛡️ 안전한 환경변수 로드
        env_vars = load_env_safe()
        
        app_key = env_vars.get('ALIEXPRESS_APP_KEY')
        app_secret = env_vars.get('ALIEXPRESS_APP_SECRET')
        tracking_id = env_vars.get('ALIEXPRESS_TRACKING_ID', 'blog')
        
        if not app_key or not app_secret:
            raise Exception("AliExpress API 키가 설정되지 않았습니다")
        
        safe_log(f"🔑 API 키 확인: App Key={app_key[:10]}..., Tracking ID={tracking_id}")
        
        # 🌟 평점 정보 강화 분석기 생성
        analyzer = EnhancedAliexpressAnalyzer(app_key, app_secret, tracking_id)
        
        # 상품 정보 분석
        product_info = analyzer.get_product_info(url)
        
        # 🎯 성공 시, 순수 JSON만 stdout으로 출력
        result = {"success": True, "data": product_info}
        print(json.dumps(result, ensure_ascii=False).replace('\\/', '/'))
        safe_log(f"✅ 평점 정보 강화 완료: 평점({product_info.get('rating_display', 'N/A')}), 판매량({product_info.get('lastest_volume', 'N/A')})")

    except Exception as e:
        safe_log(f"❌ 오류 발생: {e}")
        print(json.dumps({"success": False, "message": f"상품 분석 중 오류가 발생했습니다: {str(e)}"}, ensure_ascii=False).replace('\\/', '/'))

if __name__ == "__main__":
    main()