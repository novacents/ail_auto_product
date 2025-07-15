#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
WordPress 글 상품 업데이트 스크립트 (PHP에서 호출용)
파일 위치: /var/www/novacents/tools/update_post_products.py
"""

import sys
import json
import os
import re
import time
import requests
import base64
from datetime import datetime
from dotenv import load_dotenv

# SafeAPIManager 임포트
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.append(current_dir)

try:
    from safe_api_manager import SafeAPIManager
except ImportError as e:
    print(json.dumps({"success": False, "error": f"SafeAPIManager 임포트 실패: {e}"}))
    sys.exit(1)

class PostProductUpdater:
    """WordPress 글 상품 업데이트"""
    
    def __init__(self):
        """초기화"""
        # 환경변수 로드
        env_path = '/home/novacents/.env'
        load_dotenv(env_path)
        
        self.config = {
            "telegram_bot_token": os.getenv("TELEGRAM_BOT_TOKEN"),
            "telegram_chat_id": os.getenv("TELEGRAM_CHAT_ID"),
            "wp_url": os.getenv("NOVACENTS_WP_URL", "https://novacents.com"),
            "wp_user": os.getenv("NOVACENTS_WP_USER", "admin"),
            "wp_app_pass": os.getenv("NOVACENTS_WP_APP_PASS"),
        }
        
        self.api_manager = SafeAPIManager(mode="production")
    
    def get_post_by_id(self, post_id):
        """글 정보 조회"""
        try:
            api_url = f"{self.config['wp_url']}/wp-json/wp/v2/posts/{post_id}"
            
            credentials = f"{self.config['wp_user']}:{self.config['wp_app_pass']}"
            encoded_credentials = base64.b64encode(credentials.encode()).decode()
            headers = {
                'Authorization': f'Basic {encoded_credentials}',
                'Content-Type': 'application/json'
            }
            
            response = requests.get(api_url, headers=headers, timeout=30)
            
            if response.status_code == 200:
                return response.json()
            else:
                return None
                
        except Exception as e:
            return None
    
    def get_product_info_and_affiliate_link(self, product_url):
        """상품 정보 조회 및 어필리에이트 링크 생성"""
        try:
            # 플랫폼 확인
            if 'coupang.com' in product_url.lower():
                platform = "쿠팡"
                success, affiliate_link, product_info = self.api_manager.convert_coupang_to_affiliate_link(product_url)
            elif 'aliexpress.com' in product_url.lower():
                platform = "알리익스프레스"
                success, affiliate_link, product_info = self.api_manager.convert_aliexpress_to_affiliate_link(product_url)
            else:
                return None, None, None, "지원하지 않는 플랫폼"
            
            if success and affiliate_link:
                return platform, affiliate_link, product_info, None
            else:
                return platform, None, None, "링크 변환 실패"
                
        except Exception as e:
            return None, None, None, str(e)
    
    def generate_product_html_block(self, platform, product_info, affiliate_link):
        """상품 정보 HTML 블록 생성"""
        if not product_info:
            return f"""
<div class="product-block" style="border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 10px; text-align: center;">
    <h3>{platform} 상품</h3>
    <p><a href="{affiliate_link}" target="_blank" rel="nofollow">
        <button style="background-color: #ff6b35; color: white; padding: 15px 30px; border: none; border-radius: 8px; font-size: 18px; cursor: pointer; width: 100%;">
            🛒 {platform}에서 구매하기
        </button>
    </a></p>
</div>
"""
        
        # 상품 정보가 있는 경우 상세 블록 생성
        title = product_info.get('title', '상품명 없음')
        price = product_info.get('price', '가격 정보 없음')
        image_url = product_info.get('image_url', '')
        rating = product_info.get('rating', '')
        review_count = product_info.get('review_count', '')
        
        # 이미지 태그 생성
        image_html = ""
        if image_url and image_url != "이미지 정보 없음" and image_url.startswith('http'):
            image_html = f'<img src="{image_url}" alt="{title}" style="max-width: 100%; height: auto; margin: 15px 0; border-radius: 8px;">'
        
        # 평점 및 리뷰 정보
        rating_html = ""
        if rating and rating != "평점 정보 없음":
            rating_html += f"<p style='margin: 10px 0;'>⭐ 평점: {rating}"
            if review_count and review_count != "리뷰 정보 없음":
                rating_html += f" (리뷰 {review_count}개)"
            rating_html += "</p>"
        
        # 버튼 색상 설정
        button_color = "#ff6b35" if platform == "쿠팡" else "#ff4757"
        
        html_block = f"""
<div class="product-block" style="border: 2px solid #eee; padding: 25px; margin: 25px 0; border-radius: 15px; background: #f9f9f9; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
    <h3 style="color: #333; margin-bottom: 15px; font-size: 1.3em;">{title}</h3>
    {image_html}
    <p style="color: #e74c3c; font-size: 1.2em; font-weight: bold; margin: 15px 0;"><strong>💰 가격: {price}</strong></p>
    {rating_html}
    <p style="margin-top: 20px;"><a href="{affiliate_link}" target="_blank" rel="nofollow" style="text-decoration: none;">
        <button style="background: linear-gradient(135deg, {button_color} 0%, {'#ff8e53' if platform == '쿠팡' else '#ff6b8a'} 100%); color: white; padding: 18px 35px; border: none; border-radius: 12px; font-size: 18px; font-weight: bold; cursor: pointer; width: 100%; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
            🛒 {platform}에서 구매하기
        </button>
    </a></p>
</div>
"""
        
        return html_block
    
    def update_post_content(self, post_id, product_urls):
        """글 내용 업데이트"""
        try:
            # 기존 글 정보 조회
            post = self.get_post_by_id(post_id)
            if not post:
                return {"success": False, "error": "글을 찾을 수 없습니다"}
            
            # 새로운 상품 블록들 생성
            product_blocks = []
            processed_products = []
            
            for product_url in product_urls:
                if not product_url.strip():
                    continue
                
                platform, affiliate_link, product_info, error = self.get_product_info_and_affiliate_link(product_url.strip())
                
                if error:
                    processed_products.append({
                        "original_url": product_url,
                        "platform": platform or "알 수 없음",
                        "status": "failed",
                        "error": error
                    })
                    continue
                
                if affiliate_link:
                    html_block = self.generate_product_html_block(platform, product_info, affiliate_link)
                    product_blocks.append(html_block)
                    
                    processed_products.append({
                        "original_url": product_url,
                        "platform": platform,
                        "affiliate_link": affiliate_link,
                        "product_info": product_info,
                        "status": "success"
                    })
                
                # API 제한 고려 대기
                time.sleep(1)
            
            # 기존 글 내용에서 상품 블록 제거 및 새로운 블록 추가
            current_content = post['content']['raw'] if 'raw' in post['content'] else post['content']['rendered']
            
            # 기존 상품 블록 제거 (product-block 클래스를 가진 div 제거)
            import re
            # 상품 블록 패턴 매칭 및 제거
            cleaned_content = re.sub(r'<div class="product-block"[^>]*>.*?</div>', '', current_content, flags=re.DOTALL)
            
            # 기존 상품 링크들도 제거
            link_patterns = [
                r'https://(?:www\.)?coupang\.com/[^\s"\'<>]+',
                r'https://link\.coupang\.com/[^\s"\'<>]+',
                r'https://(?:ko\.)?aliexpress\.com/[^\s"\'<>]+',
                r'https://s\.click\.aliexpress\.com/[^\s"\'<>]+'
            ]
            
            for pattern in link_patterns:
                cleaned_content = re.sub(pattern, '', cleaned_content, flags=re.IGNORECASE)
            
            # 새로운 상품 블록들을 글 끝에 추가
            if product_blocks:
                final_content = cleaned_content.rstrip() + '\n\n<hr>\n<h2>🛒 추천 상품</h2>\n\n' + '\n\n'.join(product_blocks)
            else:
                final_content = cleaned_content
            
            # WordPress API로 글 업데이트
            api_url = f"{self.config['wp_url']}/wp-json/wp/v2/posts/{post_id}"
            
            credentials = f"{self.config['wp_user']}:{self.config['wp_app_pass']}"
            encoded_credentials = base64.b64encode(credentials.encode()).decode()
            headers = {
                'Authorization': f'Basic {encoded_credentials}',
                'Content-Type': 'application/json'
            }
            
            update_data = {
                'content': final_content
            }
            
            response = requests.post(api_url, headers=headers, json=update_data, timeout=30)
            
            if response.status_code == 200:
                # 텔레그램 알림 발송
                self.send_update_notification(post, processed_products)
                
                return {
                    "success": True,
                    "message": "글 업데이트 완료",
                    "processed_products": processed_products,
                    "updated_post_url": post['link']
                }
            else:
                return {
                    "success": False,
                    "error": f"WordPress API 오류: {response.status_code}",
                    "response": response.text
                }
            
        except Exception as e:
            return {
                "success": False,
                "error": str(e)
            }
    
    def send_update_notification(self, post, processed_products):
        """업데이트 완료 알림 발송"""
        try:
            success_count = len([p for p in processed_products if p['status'] == 'success'])
            failed_count = len([p for p in processed_products if p['status'] == 'failed'])
            
            message = f"""✅ <b>글 상품 업데이트 완료</b>

📄 <b>글 제목:</b> {post['title']['rendered']}
🔗 <b>글 링크:</b> {post['link']}

📊 <b>처리 결과:</b>
• 성공: {success_count}개
• 실패: {failed_count}개

🛒 <b>업데이트된 상품들:</b>"""

            for product in processed_products:
                if product['status'] == 'success':
                    product_name = product['product_info'].get('title', 'N/A') if product['product_info'] else 'N/A'
                    message += f"\n• {product['platform']}: {product_name[:50]}..."
            
            if failed_count > 0:
                message += f"\n\n❌ <b>실패한 상품들:</b>"
                for product in processed_products:
                    if product['status'] == 'failed':
                        message += f"\n• {product['original_url'][:50]}... ({product['error']})"
            
            message += f"\n\n⏰ <b>업데이트 시간:</b> {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}"
            
            # 텔레그램 발송
            url = f"https://api.telegram.org/bot{self.config['telegram_bot_token']}/sendMessage"
            
            payload = {
                'chat_id': self.config['telegram_chat_id'],
                'text': message,
                'parse_mode': 'HTML',
                'disable_web_page_preview': True
            }
            
            requests.post(url, json=payload, timeout=30)
            
        except Exception as e:
            pass  # 알림 실패해도 메인 기능에는 영향 없음

def main():
    """메인 실행 함수"""
    if len(sys.argv) != 3:
        print(json.dumps({
            "success": False, 
            "error": "사용법: python3 update_post_products.py <post_id> <product_urls_comma_separated>"
        }))
        sys.exit(1)
    
    try:
        post_id = int(sys.argv[1])
        product_urls = [url.strip() for url in sys.argv[2].split(',') if url.strip()]
        
        updater = PostProductUpdater()
        result = updater.update_post_content(post_id, product_urls)
        
        print(json.dumps(result, ensure_ascii=False))
        
    except ValueError:
        print(json.dumps({
            "success": False,
            "error": "post_id는 숫자여야 합니다"
        }))
    except Exception as e:
        print(json.dumps({
            "success": False,
            "error": str(e)
        }))

if __name__ == "__main__":
    main()