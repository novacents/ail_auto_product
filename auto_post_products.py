#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
AliExpress 어필리에이트 상품 자동 등록 시스템 v3.0
- 분할 큐 시스템 적용
- 백업 파일 기반 완전 복원 버전
"""

import os
import sys
import json
import requests
import time
import random
import re
import gc
from datetime import datetime
import argparse
import subprocess
from urllib.parse import quote, unquote

def load_configuration():
    """환경 설정을 로드합니다 (.env 파일 우선)"""
    config = {}
    
    # .env 파일에서 설정 로드
    env_file_path = '/home/novacents/.env'
    if os.path.exists(env_file_path):
        print(f"✅ .env 파일에서 설정을 로드합니다: {env_file_path}")
        with open(env_file_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    key = key.strip()
                    value = value.strip().strip('"').strip("'")
                    config[key] = value
    else:
        print(f"⚠️ .env 파일을 찾을 수 없습니다: {env_file_path}")
        return None
    
    # 필수 설정값 확인
    required_keys = [
        'NOVACENTS_WP_URL', 'NOVACENTS_WP_USER', 'NOVACENTS_WP_APP_PASS',
        'OPENAI_API_KEY', 'ALIEXPRESS_APP_KEY', 'ALIEXPRESS_APP_SECRET',
        'ALIEXPRESS_TRACKING_ID'
    ]
    
    missing_keys = [key for key in required_keys if not config.get(key)]
    if missing_keys:
        print(f"❌ .env 파일에서 누락된 설정: {missing_keys}")
        return None
    
    print("✅ 모든 필수 설정이 로드되었습니다.")
    return config

def get_queue_files():
    """큐 파일 목록을 가져옵니다"""
    queue_dir = '/var/www/novacents/tools/queues/pending'
    if not os.path.exists(queue_dir):
        return []
    
    return [f for f in os.listdir(queue_dir) if f.endswith('.json')]

class AliExpressPostingSystem:
    def __init__(self):
        """시스템 초기화"""
        self.config = load_configuration()
        if not self.config:
            raise Exception("설정 파일을 로드할 수 없습니다.")
        
        # 기본 설정 (환경변수명 수정)
        self.wordpress_url = self.config['NOVACENTS_WP_URL']
        self.wordpress_username = self.config['NOVACENTS_WP_USER']
        self.wordpress_password = self.config['NOVACENTS_WP_APP_PASS']
        self.openai_api_key = self.config['OPENAI_API_KEY']
        
        # AliExpress API 설정
        self.aliexpress_app_key = self.config['ALIEXPRESS_APP_KEY']
        self.aliexpress_secret = self.config['ALIEXPRESS_APP_SECRET']
        self.aliexpress_session = self.config.get('ALIEXPRESS_SESSION', '')
        self.aliexpress_tracking_id = self.config['ALIEXPRESS_TRACKING_ID']
        
        # 시스템 설정
        self.immediate_mode = False
        self.current_job_id = None
        
        print("🚀 AliExpress 자동 등록 시스템이 초기화되었습니다.")

    def call_php_function(self, function_name, *args):
        """PHP 함수를 호출합니다 (큐 관리 시스템용)"""
        try:
            php_script = f"""
            <?php
            require_once('/var/www/novacents/tools/queue_utils.php');
            
            $result = {function_name}({', '.join([f'"{arg}"' if isinstance(arg, str) else str(arg) for arg in args])});
            echo json_encode($result);
            ?>
            """
            
            # <?php와 ?> 태그를 완전히 제거한 순수 PHP 코드 전달
            php_code_only = php_script.strip()
            if php_code_only.startswith('<?php'):
                php_code_only = php_code_only[5:]
            if php_code_only.endswith('?>'):
                php_code_only = php_code_only[:-2]
            php_code_only = php_code_only.strip()
            
            # 디버깅 로그: PHP 스크립트 실제 내용
            print(f"🔍 [DEBUG] PHP 함수 호출: {function_name} - Args: {args}")
            print(f"🔍 [DEBUG] 실행할 PHP 코드 길이: {len(php_code_only)} chars")
            
            result = subprocess.run(['php', '-r', php_code_only], 
                                  capture_output=True, text=True, check=True)
            
            # 디버깅 로그: subprocess 실행 후 상태
            print(f"🔍 [DEBUG] PHP 실행 결과 - ReturnCode: {result.returncode}")
            
            if result.stdout:
                return json.loads(result.stdout)
            return None
            
        except Exception as e:
            print(f"❌ PHP 함수 호출 실패: {e}")
            return None
    
    def load_queue_split(self, queue_id):
        """분할 큐에서 특정 큐 항목을 로드합니다"""
        return self.call_php_function('load_queue_split', queue_id)
    
    def update_queue_status_split(self, queue_id, status, message=''):
        """분할 큐의 상태를 업데이트합니다"""
        return self.call_php_function('update_queue_status', queue_id, status, message)
    
    def remove_job_from_queue(self, job_id):
        """즉시 발행 모드에서 큐에서 작업을 제거합니다"""
        if self.immediate_mode:
            return self.call_php_function('remove_queue_split', job_id)
        return True

    def get_openai_headers(self):
        """OpenAI API 헤더를 반환합니다"""
        return {
            'Authorization': f'Bearer {self.openai_api_key}',
            'Content-Type': 'application/json'
        }

    def generate_affiliate_link(self, original_url):
        """AliExpress 어필리에이트 링크를 생성합니다"""
        try:
            # URL에서 상품 ID 추출
            product_id_match = re.search(r'/item/(\d+)\.html', original_url)
            if not product_id_match:
                product_id_match = re.search(r'item/([^/]+)', original_url)
            
            if product_id_match:
                product_id = product_id_match.group(1)
                # 어필리에이트 링크 생성
                affiliate_url = f"https://s.click.aliexpress.com/e/_DmvKRbb?bz=120x90&pid={self.aliexpress_tracking_id}&productId={product_id}"
                return affiliate_url
            
            return original_url
            
        except Exception as e:
            print(f"❌ 어필리에이트 링크 생성 실패: {e}")
            return original_url

    def analyze_product_with_openai(self, product_data):
        """OpenAI를 사용하여 상품을 분석합니다"""
        try:
            headers = self.get_openai_headers()
            
            prompt = f"""
            다음 AliExpress 상품 정보를 분석해주세요:
            
            제목: {product_data.get('title', '제목 없음')}
            가격: {product_data.get('price', '가격 정보 없음')}
            평점: {product_data.get('rating', '평점 정보 없음')}
            
            다음 형식으로 분석 결과를 JSON으로 제공해주세요:
            {{
                "summary": "상품 요약 (50자 이내)",
                "features": ["주요 특징1", "주요 특징2", "주요 특징3"],
                "pros": ["장점1", "장점2", "장점3"],
                "cons": ["단점1", "단점2"],
                "recommendation": "추천 대상 (30자 이내)"
            }}
            """
            
            data = {
                "model": "gpt-3.5-turbo",
                "messages": [
                    {"role": "system", "content": "당신은 상품 분석 전문가입니다. 정확하고 유용한 정보를 제공해주세요."},
                    {"role": "user", "content": prompt}
                ],
                "max_tokens": 1000,
                "temperature": 0.7
            }
            
            response = requests.post('https://api.openai.com/v1/chat/completions', 
                                   headers=headers, json=data, timeout=30)
            
            if response.status_code == 200:
                result = response.json()
                analysis_text = result['choices'][0]['message']['content']
                
                # JSON 파싱 시도
                try:
                    analysis_json = json.loads(analysis_text)
                    return analysis_json
                except json.JSONDecodeError:
                    # JSON 파싱 실패 시 기본 구조 반환
                    return {
                        "summary": "OpenAI 분석 결과",
                        "features": ["분석된 특징"],
                        "pros": ["분석된 장점"],
                        "cons": ["분석된 단점"],
                        "recommendation": "일반 사용자"
                    }
            else:
                print(f"❌ OpenAI API 호출 실패: {response.status_code}")
                return None
                
        except Exception as e:
            print(f"❌ OpenAI 상품 분석 실패: {e}")
            return None

    def generate_wordpress_content(self, job_data):
        """워드프레스 콘텐츠를 생성합니다"""
        try:
            # 작업 데이터에서 정보 추출
            title = job_data.get('title', '제목 없음')
            keywords = job_data.get('keywords', [])
            prompt_type = job_data.get('prompt_type', 'essential_items')
            user_details = job_data.get('user_details', {})
            
            # 프롬프트 타입별 처리
            prompt_templates = {
                'essential_items': self.generate_essential_items_content,
                'friend_review': self.generate_friend_review_content,
                'professional_analysis': self.generate_professional_analysis_content,
                'amazing_discovery': self.generate_amazing_discovery_content
            }
            
            generator_func = prompt_templates.get(prompt_type, self.generate_essential_items_content)
            content = generator_func(title, keywords, user_details)
            
            return content
            
        except Exception as e:
            print(f"❌ 워드프레스 콘텐츠 생성 실패: {e}")
            return None

    def generate_essential_items_content(self, title, keywords, user_details):
        """필수템형 콘텐츠를 생성합니다"""
        content = f"<h2>{title}</h2>\n\n"
        content += "<p>일상생활을 더욱 편리하게 만들어줄 필수 아이템들을 소개해드립니다.</p>\n\n"
        
        for i, keyword in enumerate(keywords, 1):
            keyword_name = keyword.get('name', f'키워드 {i}')
            content += f"<h3>{i}. {keyword_name}</h3>\n\n"
            
            # 상품 정보 추가
            if 'products_data' in keyword:
                for product in keyword['products_data']:
                    if product.get('generated_html'):
                        content += product['generated_html'] + "\n\n"
            
            content += "<p>이 제품은 일상생활의 편의성을 크게 향상시켜줍니다.</p>\n\n"
        
        return content

    def generate_friend_review_content(self, title, keywords, user_details):
        """친구 추천형 콘텐츠를 생성합니다"""
        content = f"<h2>{title}</h2>\n\n"
        content += "<p>친구가 직접 사용해보고 강력 추천하는 상품들을 소개합니다!</p>\n\n"
        
        for i, keyword in enumerate(keywords, 1):
            keyword_name = keyword.get('name', f'추천 아이템 {i}')
            content += f"<h3>🌟 {keyword_name} - 친구 강력 추천!</h3>\n\n"
            
            # 상품 정보 추가
            if 'products_data' in keyword:
                for product in keyword['products_data']:
                    if product.get('generated_html'):
                        content += product['generated_html'] + "\n\n"
            
            content += "<p>실제로 사용해본 후기를 바탕으로 정말 만족스러운 제품이라고 자신 있게 추천드립니다.</p>\n\n"
        
        return content

    def generate_professional_analysis_content(self, title, keywords, user_details):
        """전문 분석형 콘텐츠를 생성합니다"""
        content = f"<h2>{title}</h2>\n\n"
        content += "<p>전문적인 관점에서 꼼꼼히 분석한 상품들을 소개해드립니다.</p>\n\n"
        
        for i, keyword in enumerate(keywords, 1):
            keyword_name = keyword.get('name', f'분석 대상 {i}')
            content += f"<h3>📊 {keyword_name} 전문 분석</h3>\n\n"
            
            # 상품 정보 추가
            if 'products_data' in keyword:
                for product in keyword['products_data']:
                    if product.get('generated_html'):
                        content += product['generated_html'] + "\n\n"
                    
                    # 전문 분석 정보 추가
                    if product.get('user_data'):
                        user_data = product['user_data']
                        content += "<div style='background:#f8f9fa; padding:15px; border-radius:8px; margin:15px 0;'>\n"
                        content += "<h4>🔍 전문 분석 결과</h4>\n"
                        
                        if user_data.get('specs'):
                            specs = user_data['specs']
                            content += "<p><strong>주요 사양:</strong><br>\n"
                            for key, value in specs.items():
                                if value:
                                    content += f"• {key}: {value}<br>\n"
                            content += "</p>\n"
                        
                        if user_data.get('efficiency'):
                            efficiency = user_data['efficiency']
                            content += "<p><strong>효율성 분석:</strong><br>\n"
                            for key, value in efficiency.items():
                                if value:
                                    content += f"• {key}: {value}<br>\n"
                            content += "</p>\n"
                        
                        content += "</div>\n\n"
            
            content += "<p>전문적인 분석을 통해 검증된 우수한 제품입니다.</p>\n\n"
        
        return content

    def generate_amazing_discovery_content(self, title, keywords, user_details):
        """놀라움 발견형 콘텐츠를 생성합니다"""
        content = f"<h2>{title}</h2>\n\n"
        content += "<p>정말 놀라운 발견! 이런 제품이 있다니 믿을 수 없을 정도로 신기한 아이템들을 소개합니다.</p>\n\n"
        
        for i, keyword in enumerate(keywords, 1):
            keyword_name = keyword.get('name', f'놀라운 발견 {i}')
            content += f"<h3>✨ {keyword_name} - 정말 신기한 발견!</h3>\n\n"
            
            # 상품 정보 추가
            if 'products_data' in keyword:
                for product in keyword['products_data']:
                    if product.get('generated_html'):
                        content += product['generated_html'] + "\n\n"
            
            content += "<p>이런 제품이 존재한다는 것 자체가 놀라울 정도로 혁신적인 아이템입니다!</p>\n\n"
        
        return content

    def publish_to_wordpress(self, title, content, category_id, thumbnail_url=None):
        """워드프레스에 글을 발행합니다"""
        try:
            # 워드프레스 REST API 엔드포인트
            wp_api_url = f"{self.wordpress_url}/wp-json/wp/v2/posts"
            
            # 인증 정보
            auth = (self.wordpress_username, self.wordpress_password)
            
            # 발행 데이터 준비
            post_data = {
                'title': title,
                'content': content,
                'status': 'publish',
                'categories': [int(category_id)] if category_id else [],
                'format': 'standard'
            }
            
            # 썸네일 URL이 있으면 추가
            if thumbnail_url:
                post_data['meta'] = {
                    'thumbnail_url': thumbnail_url
                }
            
            # 워드프레스에 POST 요청
            headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
            
            response = requests.post(wp_api_url, json=post_data, auth=auth, headers=headers, timeout=30)
            
            if response.status_code in [200, 201]:
                post_info = response.json()
                post_url = post_info.get('link', '')
                post_id = post_info.get('id', '')
                
                print(f"✅ 워드프레스 발행 성공: {post_url}")
                return {
                    'success': True,
                    'post_id': post_id,
                    'post_url': post_url,
                    'message': '글이 성공적으로 발행되었습니다.'
                }
            else:
                error_msg = f"워드프레스 발행 실패: HTTP {response.status_code}"
                print(f"❌ {error_msg}")
                print(f"응답 내용: {response.text}")
                return {
                    'success': False,
                    'message': error_msg,
                    'response': response.text
                }
                
        except requests.exceptions.Timeout:
            error_msg = "워드프레스 API 요청 시간 초과"
            print(f"❌ {error_msg}")
            return {'success': False, 'message': error_msg}
        except requests.exceptions.RequestException as e:
            error_msg = f"워드프레스 API 요청 실패: {str(e)}"
            print(f"❌ {error_msg}")
            return {'success': False, 'message': error_msg}
        except Exception as e:
            error_msg = f"워드프레스 발행 중 오류: {str(e)}"
            print(f"❌ {error_msg}")
            return {'success': False, 'message': error_msg}

    def process_job(self, job_data):
        """작업을 처리합니다"""
        try:
            job_id = job_data.get('queue_id', 'unknown')
            title = job_data.get('title', '제목 없음')
            category_id = job_data.get('category_id', '356')
            thumbnail_url = job_data.get('thumbnail_url', '')
            
            print(f"🔄 작업 처리 시작: {title} (ID: {job_id})")
            
            # 상태를 처리 중으로 업데이트
            if not self.immediate_mode:
                self.update_queue_status_split(job_id, 'processing', '작업 처리 중...')
            
            # 콘텐츠 생성
            content = self.generate_wordpress_content(job_data)
            if not content:
                error_msg = "콘텐츠 생성에 실패했습니다."
                print(f"❌ {error_msg}")
                if not self.immediate_mode:
                    self.update_queue_status_split(job_id, 'failed', error_msg)
                return {'success': False, 'message': error_msg}
            
            # 워드프레스에 발행
            result = self.publish_to_wordpress(title, content, category_id, thumbnail_url)
            
            if result['success']:
                print(f"✅ 작업 완료: {title}")
                
                # 즉시 발행과 일반 처리 모두 completed 상태로 업데이트
                self.update_queue_status_split(job_id, 'completed', f"발행 완료: {result['post_url']}")
                
                return result
            else:
                print(f"❌ 발행 실패: {title}")
                if not self.immediate_mode:
                    self.update_queue_status_split(job_id, 'failed', result['message'])
                return result
                
        except Exception as e:
            error_msg = f"작업 처리 중 오류: {str(e)}"
            print(f"❌ {error_msg}")
            if not self.immediate_mode:
                self.update_queue_status_split(job_data.get('queue_id', 'unknown'), 'failed', error_msg)
            return {'success': False, 'message': error_msg}
        finally:
            # 메모리 정리
            gc.collect()

    def update_job_status(self, job_id, status, message=''):
        """작업 상태를 업데이트합니다 (레거시 호환)"""
        return self.update_queue_status_split(job_id, status, message)

    def run_queue_mode(self):
        """큐 모드로 실행합니다"""
        print("🚀 큐 모드로 실행을 시작합니다...")
        
        try:
            # 대기 중인 큐 파일들 가져오기
            queue_files = get_queue_files()
            
            if not queue_files:
                print("📭 처리할 큐 항목이 없습니다.")
                return
            
            print(f"📋 총 {len(queue_files)}개의 큐 항목을 발견했습니다.")
            
            # 각 큐 항목 처리
            for queue_file in queue_files:
                try:
                    queue_id = queue_file.replace('.json', '')
                    print(f"\n🔄 큐 항목 처리 중: {queue_id}")
                    
                    # 큐 데이터 로드
                    job_data = self.load_queue_split(queue_id)
                    if not job_data:
                        print(f"❌ 큐 데이터를 로드할 수 없습니다: {queue_id}")
                        continue
                    
                    # 작업 처리
                    result = self.process_job(job_data)
                    
                    if result['success']:
                        print(f"✅ 큐 항목 처리 완료: {queue_id}")
                    else:
                        print(f"❌ 큐 항목 처리 실패: {queue_id} - {result['message']}")
                    
                    # 작업 간 간격
                    time.sleep(2)
                    
                except Exception as e:
                    print(f"❌ 큐 항목 처리 중 오류: {queue_file} - {str(e)}")
                    continue
            
            print("\n🎉 모든 큐 처리가 완료되었습니다.")
            
        except Exception as e:
            print(f"❌ 큐 모드 실행 중 오류: {str(e)}")

    def run_immediate_mode(self, job_data):
        """즉시 모드로 특정 작업을 실행합니다"""
        print("⚡ 즉시 모드로 실행을 시작합니다...")
        
        self.immediate_mode = True
        
        try:
            result = self.process_job(job_data)
            
            if result['success']:
                print("✅ 즉시 발행이 완료되었습니다.")
                print(f"워드프레스 발행 성공: {result['post_url']}")
            else:
                print(f"❌ 즉시 발행 실패: {result['message']}")
            
            return result
            
        except Exception as e:
            error_msg = f"즉시 모드 실행 중 오류: {str(e)}"
            print(f"❌ {error_msg}")
            return {'success': False, 'message': error_msg}
        finally:
            self.immediate_mode = False

def main():
    """메인 함수"""
    try:
        # 명령행 인수 파싱
        parser = argparse.ArgumentParser(description='AliExpress 어필리에이트 자동 등록 시스템')
        parser.add_argument('--mode', choices=['queue', 'immediate'], default='queue', 
                           help='실행 모드 (queue: 큐 처리, immediate: 즉시 처리)')
        parser.add_argument('--queue-id', help='즉시 모드에서 처리할 큐 ID')
        parser.add_argument('--immediate-file', help='keyword_processor.php에서 전달된 임시 파일 경로')
        
        args = parser.parse_args()
        
        # 시스템 초기화
        system = AliExpressPostingSystem()
        
        if args.mode == 'immediate':
            if args.immediate_file and os.path.exists(args.immediate_file):
                # keyword_processor.php에서 전달된 파일 처리
                print(f"📄 임시 파일에서 데이터 로드: {args.immediate_file}")
                with open(args.immediate_file, 'r', encoding='utf-8') as f:
                    job_data = json.load(f)
                
                # 임시 파일 삭제
                os.remove(args.immediate_file)
                print(f"🗑️ 임시 파일 삭제: {args.immediate_file}")
                
            elif args.queue_id:
                # 큐 ID로 데이터 로드
                print(f"🔍 큐 ID로 데이터 로드: {args.queue_id}")
                job_data = system.load_queue_split(args.queue_id)
                if not job_data:
                    print(f"❌ 큐 데이터를 찾을 수 없습니다: {args.queue_id}")
                    return
            else:
                print("❌ 즉시 모드에서는 --queue-id 또는 --immediate-file 인수가 필요합니다.")
                return
            
            # 즉시 모드 실행
            system.run_immediate_mode(job_data)
        else:
            # 큐 모드 실행
            system.run_queue_mode()
            
    except KeyboardInterrupt:
        print("\n⏹️ 사용자에 의해 중단되었습니다.")
    except Exception as e:
        print(f"❌ 시스템 오류: {str(e)}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    main()
