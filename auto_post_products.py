#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
모든 자동화 로직과 안정성 강화 기능이 포함된 자동 포스팅 스크립트 최종 완성본.
이 스크립트는 설정된 모든 카테고리를 '라운드-로빈' 방식으로 순회하며,
발행 기록을 체크하여 중복 없이 새로운 글을 공평하게 발행합니다.
(v34.3: Google Maps 정확도를 위해 Place ID 적용)
"""

import os
import requests
import json
import time
import re
import google.generativeai as genai
import urllib.parse
import random
import html
import traceback
import itertools
from dotenv import load_dotenv
from PIL import Image
from io import BytesIO

# ##############################################################################
# 사용자 설정 (자동화 제어)
# ##############################################################################
MAX_POSTS_PER_RUN = 10
PUBLISHED_LOG_FILE = "published_log.txt"
POST_DELAY_SECONDS = 15
# ##############################################################################

try:
    from places_config_overseas import TARGET_PLACES_OVERSEAS
except ImportError:
    print("[❌] 오류: 'places_config_overseas.py' 파일을 찾을 수 없거나, TARGET_PLACES_OVERSEAS 변수가 없습니다.")
    exit()

CATEGORY_ID_TO_NAME = {
    23: "일본", 25: "중국/홍콩", 30: "태국", 31: "베트남", 32: "필리핀", 33: "말레이시아", 34: "싱가포르", 35: "인도네시아",
    36: "서유럽", 37: "동유럽", 52: "남유럽", 38: "북유럽", 39: "영국/아일랜드", 40: "미국", 41: "캐나다", 42: "멕시코",
    43: "호주", 44: "뉴질랜드", 45: "피지 및 남태평양 휴양지", 46: "두바이/아랍에미리트", 47: "터키", 48: "이집트", 49: "모로코", 50: "기타"
}

def load_configuration():
    print("[⚙️] 1. 설정을 로드합니다...")
    load_dotenv()
    config = {
        "gemini_api_key": os.getenv("GEMINI_API_KEY"),
        "google_places_api_key": os.getenv("GOOGLE_PLACES_API_KEY"),
        "pexels_key": os.getenv("PEXELS_API_KEY"),
        "wp_url": os.getenv("WP_URL"),
        "wp_api_base": os.getenv("WP_API_BASE"),
        "wp_user": os.getenv("WP_USER"),
        "wp_app_pass": os.getenv("WP_APP_PASS"),
    }
    if not all(config.values()):
         print("[❌] 오류: .env 파일에 필요한 모든 환경 변수가 설정되지 않았습니다. 스크립트를 종료합니다.")
         return None
    try:
        genai.configure(api_key=config["gemini_api_key"])
        print("[✅] Gemini API가 성공적으로 구성되었습니다.")
    except Exception as e:
        print(f"[❌] Gemini API 구성 중 오류 발생: {e}")
        return None
    return config

def get_final_redirected_url(initial_url):
    try:
        response = requests.get(initial_url, allow_redirects=True, timeout=15, stream=True)
        response.raise_for_status()
        return response.url
    except requests.RequestException:
        return None

def validate_image_url(url):
    try:
        response = requests.get(url, timeout=15, allow_redirects=True)
        response.raise_for_status()
        content_type = response.headers.get('Content-Type', '')
        if 'image/' not in content_type: return False
        content_length = int(response.headers.get('Content-Length', 0))
        if content_length < 10240: return False
        try:
            img = Image.open(BytesIO(response.content))
            img.verify()
            if img.size[0] < 100 or img.size[1] < 100: return False
            return True
        except Exception: return False
    except requests.RequestException: return False

def get_google_place_details(cfg, place_name, category_name):
    search_query = f"{place_name} in {category_name} tourist attraction landmark"
    print(f"[🔍] '{search_query}': Google Places API에서 정보 및 사진 출처를 검색합니다...")
    api_key = cfg["google_places_api_key"]
    search_url = "https://places.googleapis.com/v1/places:searchText"
    search_headers = {
        "Content-Type": "application/json",
        "X-Goog-Api-Key": api_key,
        "X-Goog-FieldMask": "places.id,places.displayName,places.formattedAddress,places.websiteUri,places.editorialSummary,places.photos.name,places.photos.authorAttributions,places.regularOpeningHours"
    }
    search_data = {"textQuery": search_query, "languageCode": "ko"}
    details = {}
    try:
        response = requests.post(search_url, headers=search_headers, json=search_data, timeout=20)
        response.raise_for_status()
        data = response.json()
        places = data.get('places', [])
        if not places: return None
        place_info = places[0]
        details['place_id'] = place_info.get('id')
        details['overview'] = place_info.get('editorialSummary', {}).get('text', 'No information available.')
        details['address'] = place_info.get('formattedAddress', 'No information available.')
        details['homepage'] = place_info.get('websiteUri', 'No information available.')
        opening_hours_texts = place_info.get('regularOpeningHours', {}).get('weekdayDescriptions', [])
        details['opening_hours'] = " \n ".join(opening_hours_texts) if opening_hours_texts else 'No information available.'
        image_data = []
        photos = place_info.get('photos', [])
        for photo in photos:
            photo_name = photo.get('name')
            photo_url = f"https://places.googleapis.com/v1/{photo_name}/media?maxHeightPx=1200&key={api_key}"
            attribution_html = "Photo from Google"
            author_attributions = photo.get('authorAttributions', [])
            if author_attributions:
                attribution_html = author_attributions[0].get('displayName', 'Google')
            image_data.append({
                "url": photo_url, "source": "Google", "attribution": attribution_html
            })
        details['images'] = image_data
        print(f"[✅] Google Places: 상세 정보 및 이미지/출처 {len(details['images'])}개 수집 완료.")
        return details
    except requests.exceptions.RequestException as e:
        print(f"[❌] '{search_query}': Google Places API 요청 오류: {e}")
        return None
    except Exception as e:
        print(f"[❌] '{search_query}': Google Places 데이터 처리 중 오류: {e}")
        return None

def search_pexels_images(cfg, place_name, category_name):
    search_query = f"{place_name} {category_name} travel landscape landmark -food -restaurant -dish"
    print(f"[🔍] '{search_query}': Pexels API에서 고품질 이미지 및 출처를 검색합니다...")
    try:
        headers = {"Authorization": cfg['pexels_key']}
        params = {"query": search_query, "per_page": 15, "locale": "ko-KR"}
        response = requests.get("https://api.pexels.com/v1/search", headers=headers, params=params, timeout=20)
        response.raise_for_status()
        results = response.json().get('photos', [])
        image_data = []
        for item in results:
            image_data.append({
                'url': item['src']['large2x'],
                'source': 'Pexels',
                'attribution': f"Photo by {item.get('photographer', 'Unknown')} on Pexels",
                'photographer': item.get('photographer', 'Unknown'),
                'photographer_url': item.get('photographer_url')
            })
        print(f"[✅] Pexels: 이미지/출처 {len(image_data)}개 수집 완료.")
        return image_data
    except requests.exceptions.RequestException as e:
        print(f"[❌] '{search_query}': Pexels API 요청 오류: {e}")
        return []
    except Exception as e:
        print(f"[❌] '{search_query}': Pexels 데이터 처리 중 오류: {e}")
        return []

def generate_gemini_content(place_name, place_details, context_images):
    print(f"[🤖] '{place_name}': Gemini AI로 강화된 본문, 테이블, FAQ 데이터 생성을 시작합니다...")
    model = genai.GenerativeModel('gemini-1.5-pro-latest')
    prompt_parts = [
        f"당신은 세계 최고의 월드 트래블 전문 블로거이자 SEO 전문가입니다. 아래에 제공되는 해외 여행지 '{place_name}'에 대한 공식 정보와 실제 사진들을 바탕으로, 한국인 여행자들을 위한 매우 상세하고 유용한 블로그 글과 부가 정보를 생성해주세요.\n\n"
        f"### 공식 정보 요약 (Official Information from Google Places):\n"
        f"- 주소 (Address): {place_details.get('address')}\n- 운영시간 (Hours): {place_details.get('opening_hours')}\n- 홈페이지 (Homepage): {place_details.get('homepage')}\n- 개요 (Overview):\n{place_details.get('overview')}\n\n"
        f"### 참고 사진 (Reference Photos):\n(아래 첨부된 사진들을 참고하여, 사진의 분위기와 특징을 글에 자연스럽게 녹여내세요.)\n",
    ]
    image_count = 0
    for img_data in context_images:
        if image_count >= 3: break
        try:
            response_img = requests.get(img_data['url'], timeout=15)
            response_img.raise_for_status()
            img_bytes = response_img.content
            mime_type = response_img.headers.get('Content-Type', 'image/jpeg')
            prompt_parts.append({"mime_type": mime_type, "data": img_bytes})
            image_count += 1
        except Exception as e:
            print(f"[⚠️] '{place_name}': Gemini용 이미지 다운로드({img_data['url']}) 오류: {e}")

    prompt_parts.append(f"\n### 지시사항 ###\n1. 위 정보와 사진들을 바탕으로, 아래 '블로그 글 형식'에 맞춰 매우 상세하고 유용한 글을 작성해주세요.\n2. 그 다음, 글 내용과 공식 정보를 바탕으로, 아래 '요약 테이블 데이터'와 'FAQ 데이터'를 형식에 맞춰 각각 생성해주세요.\n\n"
                        f"--- 블로그 글 형식 시작 ---\n# 제목: [여기에 '{place_name}'을(를) 핵심 키워드로 자연스럽게 포함시키면서, 최신 SEO 트렌드를 반영하여 사용자의 호기심을 자극하고 반드시 클릭하고 싶게 만드는 15자에서 40자 사이의 매우 매력적인 제목을 작성해주세요.]\n\n"
                        f"[여기에 '{place_name}'에 대한 흥미를 유발하는 서론 작성. 방문해야 하는 이유를 강력하게 제시하세요.]\n\n"
                        f"#### [이곳의 역사와 숨겨진 이야기]\n[단순 정보 나열이 아닌, 흥미로운 일화나 배경을 중심으로 상세히 서술]\n\n"
                        f"#### [놓치면 후회하는 핵심 볼거리 TOP 3]\n[가장 중요한 볼거리나 체험활동 3가지를 순위나 리스트 형식으로 상세히 설명]\n\n"
                        f"#### [찾아가는 방법: 교통편 완벽 정리]\n[지하철, 버스, 택시 등 대중교통 이용법과 자가용 이용 시 주차 정보를 매우 상세하고 구체적으로 작성]\n\n"
                        f"#### [여행 꿀팁: 최적 방문 시기, 입장료, 주변 정보]\n[가장 여행하기 좋은 계절이나 시간대, 공식적인 입장료 정보, 예상치 못한 비용, 주변의 다른 볼거리 등을 구체적인 팁과 함께 작성]\n\n"
                        f"#### [현지인 추천: 주변 맛집 & 특색있는 카페]\n[관광지 근처에서 식사하거나 차를 마시기 좋은, 현지인에게 인기 있는 식당이나 카페 1~2곳을 추천 이유와 함께 소개]\n"
                        f"--- 블로그 글 형식 끝 ---\n\n"
                        f"--- 요약 테이블 데이터 형식 시작 ---\n<TABLE_DATA>\n"
                        f"위치: [{place_details.get('address')}]\n"
                        f"운영시간: [핵심 운영 시간과 휴무일을 요약하여 기입]\n"
                        f"입장료: [알려진 입장료 정보 기입, 무료인 경우 '무료'라고 명시, 변동 가능성 언급]\n"
                        f"공식 웹사이트: [{place_details.get('homepage')}]\n"
                        f"추천 방문 시기: [가장 방문하기 좋은 계절이나 요일 명시]\n"
                        f"방문꿀팁: [가장 중요한 팁 하나를 30자 내외로 요약하여 기입]\n"
                        f"</TABLE_DATA>\n--- 요약 테이블 데이터 형식 끝 ---\n\n"
                        f"--- FAQ 데이터 형식 시작 (정확히 5개 항목) ---\n<FAQ_DATA>\n"
                        f"Q1: [여행자들이 가장 궁금해할 만한 실용적인 첫 번째 질문 작성 (예: 티켓 예매 방법)]\nA1: [첫 번째 질문에 대한 상세하고 친절한 답변 작성]\n"
                        f"Q2: [두 번째 예상 질문 작성 (예: 사진 촬영 팁)]\nA2: [두 번째 답변 작성]\n"
                        f"Q3: [세 번째 예상 질문 작성 (예: 소요 시간)]\nA3: [세 번째 답변 작성]\n"
                        f"Q4: [네 번째 예상 질문 작성 (예: 근처 다른 관광지)]\nA4: [네 번째 답변 작성]\n"
                        f"Q5: [다섯 번째 예상 질문 작성 (예: 어린이나 노약자 동반 시 팁)]\nA5: [다섯 번째 답변 작성]\n"
                        f"</FAQ_DATA>\n--- FAQ 데이터 형식 끝 ---")
    try:
        response_gen_content = model.generate_content(prompt_parts, generation_config={"response_mime_type": "text/plain"})
        print(f"[✅] '{place_name}': 콘텐츠 생성을 완료했습니다.")
        return response_gen_content.text
    except Exception as e:
        print(f"[❌] '{place_name}': Gemini 콘텐츠 생성 중 오류 발생: {e}")
        return None

def generate_dedicated_title(place_name, post_content_summary):
    print(f"[🤖] '{place_name}': 전용 제목 생성을 시도합니다...")
    fallback_title = place_name
    model = genai.GenerativeModel('gemini-1.5-pro-latest')
    prompt = f"당신은 사용자의 클릭을 극대화하는 매우 창의적인 여행 블로그 전문 카피라이터입니다.\n다음 여행지와 게시물 핵심 내용 요약을 바탕으로, 사용자의 시선을 즉시 사로잡고 반드시 클릭하고 싶게 만드는 매우 매력적인 블로그 게시물 제목을 **오직 하나만, 다른 설명 없이 제목 텍스트 자체만** 생성해주세요.\n[요구사항]\n1. '{place_name}' 키워드를 자연스럽게 포함하되, 단순 나열이 아닌 흥미로운 방식으로 활용하세요.\n2. 제목의 길이는 한글 기준 15자 이상 40자 이하로 작성해주세요.\n3. 예시: \"{place_name} 여행의 모든 것: 놓치지 말아야 할 숨은 명소 TOP 5 전격 공개!\"\n4. 결과는 오직 제목 텍스트만이어야 하며, '# 제목:'과 같은 접두어나 따옴표, 줄바꿈 등을 절대 포함하지 마세요.\n[여행지명]\n{place_name}\n[게시물 핵심 내용 요약]\n{post_content_summary}\n생성할 제목:"

    for i in range(3):
        try:
            print(f"[🤖] -> 제목 생성 시도 ({i+1}/3)...")
            response = model.generate_content(prompt)
            new_title = response.text.strip()
            if new_title and '\n' not in new_title and (10 <= len(new_title) <= 50):
                print(f"[✅] '{place_name}': 전용 제목 생성 성공: '{new_title}'")
                return new_title
            else:
                print(f"[⚠️] -> 생성된 제목이 유효성 검증에 실패했습니다: '{new_title}'")
        except Exception as e:
            print(f"[❌] -> 제목 생성 중 API 오류 발생: {e}")
        if i < 2: time.sleep(2)

    print(f"[⚠️] '{place_name}': 전용 제목 생성에 최종 실패하여 폴백 제목을 사용합니다.")
    return fallback_title

def generate_focus_keyphrase(place_name, post_content_summary):
    print(f"[🤖] '{place_name}': 초점 키프레이즈를 생성합니다...")
    fallback_keyphrase = f"{place_name} 가볼만한 곳"
    try:
        model = genai.GenerativeModel('gemini-1.5-pro-latest')
        prompt = f"당신은 여행 블로그 전문 SEO 콘텐츠 전략가입니다. 주어진 정보를 바탕으로, 워드프레스 블로그 글에 사용할 최적의 '초점 키프레이즈'를 딱 하나만 생성해 주세요.\n[정보]\n* 글의 핵심 주제: {place_name} 상세 소개 및 여행 정보\n[규칙]\n1. '{place_name}' 또는 관련 지리적 명칭을 자연스럽게 포함해야 합니다.\n2. 3~5 단어의 '롱테일 키프레이즈' 형태여야 합니다.\n3. 사용자의 검색 의도가 명확히 드러나는 단어(예: 가볼만한 곳, 필수 코스, 후기, 팁)를 포함해야 합니다.\n4. 생성된 키프레이즈 외에 다른 설명, 줄 바꿈, 따옴표 등은 절대 포함하지 마세요."
        response = model.generate_content(prompt)
        keyphrase = response.text.strip()
        if not keyphrase or '\n' in keyphrase or len(keyphrase.split()) < 2 or len(keyphrase.split()) > 7: return fallback_keyphrase
        print(f"[✅] '{place_name}': 초점 키프레이즈 생성 완료: {keyphrase}")
        return keyphrase
    except Exception as e:
        print(f"[❌] '{place_name}': 초점 키프레이즈 생성 실패: {e}. 폴백을 사용합니다.")
        return fallback_keyphrase

def generate_meta_description(focus_keyphrase, place_name, post_content_summary):
    print(f"[🤖] '{place_name}': 메타 설명을 생성합니다...")
    fallback_description = f"'{focus_keyphrase}'에 대한 모든 것! 입장료, 교통 정보부터 숨겨진 관람 팁까지 완벽 가이드를 확인해보세요."
    try:
        model = genai.GenerativeModel('gemini-1.5-pro-latest')
        prompt = f"당신은 SEO 전문 카피라이터입니다. 주어진 정보와 '초점 키프레이즈'를 바탕으로, 구글 검색에 노출될 매력적인 '메타 설명'을 한 문단만 생성해 주세요.\n[정보]\n* 글의 핵심 주제: {place_name} 여행 정보\n* 초점 키프레이즈: {focus_keyphrase}\n[규칙]\n1. '{focus_keyphrase}'를 문장 안에 자연스럽게 포함하고, 가급적 문장 앞부분에 배치하세요.\n2. 글의 핵심 내용을 요약하며 궁금증을 자아내는 문구를 사용하세요.\n3. 클릭을 유도하는 문구(Call to Action)를 적절히 사용해 주세요.\n4. 전체 길이는 한글 기준 120자 이상, 150자 이하로 작성해야 합니다.\n5. 생성된 메타 설명 외에 다른 설명, 줄 바꿈, 따옴표 등은 절대 포함하지 마세요."
        response = model.generate_content(prompt)
        description = response.text.strip()
        if not (110 <= len(description) <= 160): return fallback_description
        print(f"[✅] '{place_name}': 메타 설명 생성 완료.")
        return description
    except Exception as e:
        print(f"[❌] '{place_name}': 메타 설명 생성 실패: {e}. 폴백을 사용합니다.")
        return fallback_description

def generate_tags(place_name, post_content_summary):
    print(f"[🤖] '{place_name}': 게시물 태그를 생성합니다...")
    fallback_tags = [place_name, f"{place_name.split()[-1]} 여행", f"{place_name} 가볼만한곳", f"{place_name} 추천"]
    try:
        model = genai.GenerativeModel('gemini-1.5-pro-latest')
        prompt = f"당신은 SEO 전문가입니다. 다음 여행지에 대한 블로그 글의 핵심 내용을 바탕으로, 검색 최적화에 가장 효과적인 키워드 5~10개를 쉼표(,)로 구분된 하나의 문자열로 만들어주세요.\n[요구사항]\n- '{place_name}'을(를) 포함한 핵심/관련 키워드를 조화롭게 포함하세요.\n- 각 키워드는 실제 검색어처럼 자연스러워야 합니다.\n- 결과는 오직 '키워드1,키워드2,키워드3' 형식으로만 출력하고 다른 설명은 절대 추가하지 마세요.\n[여행지 및 글 내용 요약]\n여행지: {place_name}\n내용 요약: {post_content_summary[:500]}"
        response = model.generate_content(prompt)
        tags_string = response.text.strip()
        tags = [tag.strip() for tag in tags_string.split(',') if tag.strip()][:10]
        if not tags: return fallback_tags
        print(f"[✅] '{place_name}': 태그 {len(tags)}개 생성 완료.")
        return tags
    except Exception as e:
        print(f"[❌] '{place_name}': 태그 생성 실패: {e}. 폴백을 사용합니다.")
        return fallback_tags

def extract_table_data_and_format_html(raw_content, place_name):
    try:
        print(f"[⚙️] '{place_name}': 요약 테이블 데이터 파싱 및 HTML 생성을 시작합니다...")
        table_data_match = re.search(r'<TABLE_DATA>(.*?)</TABLE_DATA>', raw_content, re.DOTALL)
        if not table_data_match: return "", raw_content
        table_data_str = table_data_match.group(1).strip()
        content_without_table = raw_content.replace(table_data_match.group(0), '').strip()
        table_data = {}
        for line in table_data_str.split('\n'):
            if ':' in line:
                key, value = line.split(':', 1); value = value.strip().strip('[]')
                table_data[key.strip()] = value
        if not table_data: return "", content_without_table
        html = f'<div style="margin-bottom: 2.5em;">\n<h3 style="margin-bottom: 0.5em;"><strong>{place_name} 한눈에 보기</strong></h3>\n'
        html += '<table style="width: 100%; border-collapse: collapse; font-size: 16px;">\n<tbody>\n'
        for key, value in table_data.items():
            html += '<tr style="border-bottom: 1px solid #e0e0e0;">\n'
            html += f'<th scope="row" style="width: 130px; min-width: 130px; white-space: nowrap; background-color: #f7f7f7; padding: 14px; text-align: left; font-weight: bold; vertical-align: top;">{key}</th>\n'
            if key == "공식 웹사이트" and value.startswith('http'):
                html += f'<td style="padding: 14px; vertical-align: top;"><a href="{value}" target="_blank" rel="noopener noreferrer">{value}</a></td>\n'
            else:
                html += f'<td style="padding: 14px; vertical-align: top;">{value}</td>\n'
            html += '</tr>\n'
        html += '</tbody>\n</table>\n</div>\n'
        print(f"[✅] '{place_name}': 요약 테이블 HTML 생성을 완료했습니다.")
        return html, content_without_table
    except Exception as e:
        print(f"[❌] 테이블 데이터 처리 중 오류 발생: {e}")
        return "", raw_content

def extract_faq_data_and_format_html(raw_content):
    try:
        print(f"[⚙️] FAQ 데이터 파싱 및 HTML 생성을 시작합니다...")
        faq_data_match = re.search(r'<FAQ_DATA>(.*?)</FAQ_DATA>', raw_content, re.DOTALL)
        if not faq_data_match: return "", raw_content, []
        faq_data_str = faq_data_match.group(1).strip()
        content_without_faq = raw_content.replace(faq_data_match.group(0), '').strip()
        qas = re.findall(r'Q\d+:\s*(.*?)\s*\n\s*A\d+:\s*(.*)', faq_data_str)
        if not qas: return "", content_without_faq, []
        html_content = '<div class="faq-section" style="margin-top: 2.5em; border-top: 2px solid #f0f0f0; padding-top: 2em;">\n'
        html_content += '<h3 style="margin-bottom: 1em;"><strong>자주 묻는 질문 (FAQ)</strong></h3>\n'
        for i, (question, answer) in enumerate(qas):
            html_content += '<details style="border: 1px solid #e0e0e0; border-radius: 5px; padding: 15px; margin-bottom: 10px;"'
            if i == 0: html_content += ' open'
            html_content += '>\n'
            html_content += f'<summary style="cursor: pointer; font-weight: bold; font-size: 1.1em; list-style-position: inside; display: list-item;">Q. {question}</summary>\n'
            html_content += f'<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;"><p>{answer}</p></div>\n'
            html_content += '</details>\n'
        html_content += '</div>\n'
        print(f"[✅] FAQ 아코디언 HTML 생성을 완료했습니다.")
        return html_content, content_without_faq, qas
    except Exception as e:
        print(f"[❌] FAQ 데이터 처리 중 오류 발생: {e}")
        return "", raw_content, []

def create_gallery_html(image_urls, place_name):
    if not image_urls: return ""
    print(f"[⚙️] '{place_name}': {len(image_urls)}개 이미지로 Masonry 갤러리 HTML 생성을 시작합니다...")
    gallery_html = '<div class="masonry-gallery">\n'
    for url in image_urls:
        alt_text = f"{place_name} 갤러리 이미지"
        gallery_html += f'<div class="masonry-item"><img src="{url}" alt="{alt_text}" loading="lazy"/></div>\n'
    gallery_html += '</div>\n'
    print(f"[✅] '{place_name}': Masonry 갤러리 HTML 생성을 완료했습니다.")
    return gallery_html

def create_Maps_html(place_name, place_id, api_key):
    print(f"[⚙️] '{place_name}': Google Maps embed HTML 생성을 시작합니다...")
    try:
        query_param = ""
        if place_id:
            query_param = f"q=place_id:{place_id}"
        else:
            query_param = f"q={urllib.parse.quote(place_name)}"

        maps_html = f'''
<div style="margin: 2em 0;">
<h4><strong><span style="color: #1a73e8;">📍</span> {place_name} 위치 확인하기</strong></h4>
<iframe
    width="100%"
    height="450"
    style="border:0; border-radius: 8px; margin-top: 1em;"
    loading="lazy"
    allowfullscreen
    referrerpolicy="no-referrer-when-downgrade"
    src="https://www.google.com/maps/embed/v1/place?key={api_key}&{query_param}">
</iframe>
</div>'''
        print(f"[✅] '{place_name}': Google Maps HTML 생성을 완료했습니다.")
        return maps_html
    except Exception as e:
        print(f"[❌] '{place_name}': Google Maps HTML 생성 중 오류: {e}")
        return ""

def search_youtube_video(place_name, category_name, api_key):
    search_query = f'"{place_name}" 여행 가이드 브이로그 -사건 -사고 -뉴스 -논란 -이슈'
    print(f"[🔍] '{search_query}': YouTube에서 관련 동영상을 검색합니다...")
    try:
        search_url = "https://www.googleapis.com/youtube/v3/search"
        params = {
            'part': 'snippet', 'q': search_query, 'key': api_key,
            'maxResults': 1, 'type': 'video', 'videoEmbeddable': 'true',
            'relevanceLanguage': 'ko'
        }
        response = requests.get(search_url, params=params, timeout=20)
        response.raise_for_status()
        results = response.json().get('items', [])
        if results:
            video_id = results[0]['id']['videoId']
            channel_title = results[0]['snippet']['channelTitle']
            print(f"[✅] YouTube: 영상 검색 성공 (Video ID: {video_id})")
            return video_id, channel_title
        else:
            print(f"[⚠️] YouTube: '{search_query}'에 대한 영상을 찾지 못했습니다.")
            return None, None
    except Exception as e:
        print(f"[❌] YouTube API 처리 중 오류: {e}")
        return None, None

def create_youtube_embed_html(video_id, place_name):
    if not video_id:
        return ""
    print(f"[⚙️] '{place_name}': YouTube 영상 임베드 HTML을 생성합니다...")
    embed_html = f'''
<div style="margin: 2em 0;">
<h4><strong><span style="color: #ff0000;">🎬</span> {place_name} 생생하게 미리보기</strong></h4>
<div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 100%; height: auto; margin-top: 1em;">
    <iframe
        src="https://www.youtube.com/embed/{video_id}"
        frameborder="0"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowfullscreen
        style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border-radius: 8px;">
    </iframe>
</div>
</div>'''
    print(f"[✅] YouTube: 임베드 HTML 생성을 완료했습니다.")
    return embed_html

def parse_and_format_html(raw_text, featured_image_data, place_name, table_html="", gallery_html="", maps_html="", youtube_html=""):
    print(f"[⚙️] '{place_name}': 본문을 HTML 형식으로 가공하고 모든 요소를 삽입합니다...")
    content_for_html = re.sub(r'# 제목: .*\n*', '', raw_text)
    content_for_html = re.sub(r'^---.*?---\n?', '', content_for_html, flags=re.MULTILINE).strip()
    lines = content_for_html.strip().split('\n')
    content_html_parts = []
    if featured_image_data:
        alt_text = f"{place_name} 대표 이미지"
        caption_text = f"{place_name}의 아름다운 전경"
        if featured_image_data.get('attribution'):
            caption_text += f"<br><small><i>{html.escape(featured_image_data['attribution'])}</i></small>"
        content_html_parts.append(f'<figure class="wp-block-image size-large"><img src="{featured_image_data["url"]}" alt="{alt_text}" style="width:100%; max-width:720px; height:auto; display:block; margin:1rem auto;" loading="lazy"><figcaption style="text-align:center; font-size:0.9em; color:#555;">{caption_text}</figcaption></figure>')
    if table_html: content_html_parts.append(table_html)
    temp_body_parts = []
    for line_content in lines:
        current_line_stripped = line_content.strip()
        if not current_line_stripped: continue
        if current_line_stripped.startswith("#### "):
            if temp_body_parts:
                content_html_parts.append("\n".join(temp_body_parts)); temp_body_parts = []
            heading_text = current_line_stripped.replace("#### ", "").strip()
            content_html_parts.append(f'<h4 style="margin-top: 2em; margin-bottom: 1em; font-size: 1.5em; font-weight: bold;">{heading_text}</h4>')
            if "역사" in heading_text and youtube_html:
                content_html_parts.append(youtube_html)
            if "찾아가는 방법" in heading_text and maps_html:
                content_html_parts.append(maps_html)
            if "볼거리" in heading_text and gallery_html:
                 content_html_parts.append(gallery_html)
        else:
            processed_line = re.sub(r'\*\*(.*?)\*\*', r'<strong>\1</strong>', current_line_stripped)
            temp_body_parts.append(f'<p style="font-size:18px; line-height:1.8; margin-bottom:1em;">{processed_line}</p>')
    if temp_body_parts: content_html_parts.append("\n".join(temp_body_parts))
    print(f"[✅] '{place_name}': HTML 가공 및 모든 요소 삽입 완료.")
    return "\n".join(content_html_parts)

def create_sources_html(pexels_data, google_data, youtube_data):
    print("[⚙️] 콘텐츠 출처 정보 HTML 블록을 생성합니다...")
    html_parts = ['<div class="content-sources" style="margin-top: 3em; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px; background-color: #f9f9f9; font-size: 14px;">']
    html_parts.append('<h4 style="margin-top: 0;"><strong>콘텐츠 출처</strong></h4>')
    html_parts.append('<ul style="list-style-type: disc; margin-left: 20px;">')
    pexels_photographers = {}
    has_unattributed_pexels = False
    for item in pexels_data:
        photographer_url = item.get('photographer_url')
        if photographer_url:
            if photographer_url not in pexels_photographers:
                 pexels_photographers[photographer_url] = item.get('photographer', 'Unknown')
        else:
            has_unattributed_pexels = True
    photo_sources_list = []
    for url, name in pexels_photographers.items():
        photo_sources_list.append(f'Photo by <a href="{url}" target="_blank" rel="noopener noreferrer">{name}</a> on Pexels')
    if has_unattributed_pexels:
        photo_sources_list.append('<a href="https://www.pexels.com" target="_blank" rel="noopener noreferrer">Additional photos from Pexels</a>')
    if any(item['source'] == 'Google' for item in google_data):
        photo_sources_list.append("Photos from Google")
    if photo_sources_list:
        html_parts.append('<li><strong>사진:</strong> ' + ', '.join(photo_sources_list) + '</li>')
    if youtube_data and youtube_data.get('video_id'):
        video_url = f"https://www.youtube.com/watch?v={youtube_data['video_id']}"
        channel_name = youtube_data.get('channel_title', 'YouTube')
        html_parts.append(f'<li><strong>동영상:</strong> Video by {channel_name} on <a href="{video_url}" target="_blank" rel="noopener noreferrer">YouTube</a></li>')
    html_parts.append('</ul></div>')
    print("[✅] 콘텐츠 출처 HTML 블록 생성을 완료했습니다.")
    return "\n".join(html_parts)

def create_faq_schema_html(qas_data, place_name):
    if not qas_data:
        return ""
    print(f"[⚙️] '{place_name}': FAQ 스키마(JSON-LD)를 생성합니다...")
    try:
        schema = {
            "@context": "https://schema.org",
            "@type": "FAQPage",
            "mainEntity": []
        }
        for question, answer in qas_data:
            schema["mainEntity"].append({
                "@type": "Question",
                "name": question.strip(),
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": answer.strip()
                }
            })
        schema_html = f'<script type="application/ld+json">\n{json.dumps(schema, indent=2, ensure_ascii=False)}\n</script>'
        print(f"[✅] '{place_name}': FAQ 스키마 생성을 완료했습니다.")
        return schema_html
    except Exception as e:
        print(f"[❌] '{place_name}': FAQ 스키마 생성 중 오류: {e}")
        return ""

def ensure_tags_on_wordpress(cfg, tag_list, place_name):
    print(f"[☁️] '{place_name}': 워드프레스에 태그를 확인하고 등록합니다...")
    auth = (cfg["wp_user"], cfg["wp_app_pass"])
    headers = {'Content-Type': 'application/json'}
    tag_ids = []
    for tag_name in tag_list:
        if not tag_name: continue
        try:
            res = requests.get(f"{cfg['wp_api_base']}/tags", auth=auth, params={"search": tag_name}, headers=headers, timeout=10)
            res.raise_for_status()
            existing_tags = res.json()
            found = False
            if isinstance(existing_tags, list):
                for tag_data in existing_tags:
                    if isinstance(tag_data, dict) and tag_data.get('name', '').lower() == tag_name.lower():
                        tag_ids.append(tag_data['id']); found = True; break
            if not found:
                print(f"[⚙️] '{place_name}': 태그 '{tag_name}'을(를) 새로 생성합니다...")
                create_res = requests.post(f"{cfg['wp_api_base']}/tags", auth=auth, json={"name": tag_name}, headers=headers, timeout=10)
                create_res.raise_for_status()
                if create_res.status_code == 201: tag_ids.append(create_res.json()['id'])
        except requests.exceptions.RequestException as e: print(f"[❌] '{place_name}': 태그 API 요청 중 오류 ('{tag_name}'): {e}")
    print(f"[✅] '{place_name}': {len(tag_ids)}개의 태그 ID를 확보했습니다.")
    return tag_ids

def post_to_wordpress(cfg, post_data):
    place_name_for_log = post_data.get('korean_slug', '알 수 없는 여행지')
    print(f"[🚀] '{place_name_for_log}': 워드프레스에 최종 게시물 발행을 시작합니다...")
    auth = (cfg["wp_user"], cfg["wp_app_pass"])
    headers = {'Content-Type': 'application/json'}
    payload = {
        "title": post_data['title'], "content": post_data['content'], "status": "publish",
        "categories": [post_data['wp_category_id']], "tags": post_data['tag_ids'], "slug": post_data['korean_slug']
    }
    try:
        print(f"[⚙️] 1단계 - 게시물을 생성합니다...")
        created_post_res = requests.post(f"{cfg['wp_api_base']}/posts", auth=auth, json=payload, headers=headers, timeout=30)
        created_post_res.raise_for_status()
        post_json = created_post_res.json()
        post_id, post_link = post_json.get("id"), post_json.get("link")
        if not post_id:
            print(f"[❌] '{place_name_for_log}': 게시물 생성 후 ID를 받아오지 못했습니다.")
            return
        print(f"[✅] '{place_name_for_log}': 게시물 생성 성공! (ID: {post_id})")

        print(f"[⚙️] 2단계 - SEO 및 썸네일 메타데이터를 설정합니다...")
        fifu_payload = {"meta": {"_fifu_image_url": post_data['featured_image_url']}}
        requests.post(f"{cfg['wp_api_base']}/posts/{post_id}", auth=auth, json=fifu_payload, headers=headers, timeout=20)
        print("[✅] FIFU 썸네일 설정 완료.")

        yoast_payload = { "post_id": post_id, "focus_keyphrase": post_data['focus_keyphrase'], "meta_description": post_data['meta_description'] }
        yoast_url = f"{cfg['wp_url'].rstrip('/')}/wp-json/my-api/v1/update-seo"
        requests.post(yoast_url, auth=auth, json=yoast_payload, headers=headers, timeout=20)
        print("[✅] Yoast SEO 메타데이터 설정 완료.")

        print(f"[🎉] '{place_name_for_log}': 모든 작업 완료! 발행된 글 주소: {post_link}")
    except requests.exceptions.RequestException as e:
        print(f"[❌] '{place_name_for_log}': 워드프레스 게시 중 오류 발생: {e}")
        if 'created_post_res' in locals() and created_post_res is not None: print(f"응답: {created_post_res.text[:500]}...")
    except Exception as e:
        print(f"[❌] '{place_name_for_log}': 워드프레스 메타데이터 업데이트 중 예기치 않은 오류 발생: {e}")

def process_single_place(cfg, place_name, current_category_id):
    category_name = CATEGORY_ID_TO_NAME.get(current_category_id, "")
    print(f"\n{'='*15} '{place_name}' ({category_name}) 처리 시작 {'='*15}")
    try:
        place_details = get_google_place_details(cfg, place_name, category_name)
        if not place_details:
            print(f"[⚠️] '{place_name} ({category_name})': Google Places 정보를 가져오지 못해 건너뜁니다.")
            return False

        pexels_photos_data = search_pexels_images(cfg, place_name, category_name)
        google_photos_data = place_details.get('images', [])
        all_photos_data = pexels_photos_data + google_photos_data
        if not all_photos_data:
            print(f"[⚠️] '{place_name} ({category_name})': 수집된 이미지가 없어 건너뜁니다.")
            return False

        print(f"[⚙️] '{place_name}': 수집된 {len(all_photos_data)}개 이미지의 URL 추적 및 최종 검증...")
        validated_images_data = []
        for image_data in all_photos_data:
            final_url = get_final_redirected_url(image_data['url'])
            if final_url and validate_image_url(final_url):
                image_data['url'] = final_url
                validated_images_data.append(image_data)
            time.sleep(0.1)

        if not validated_images_data:
            print(f"[❌] '{place_name}': 유효한 이미지가 없어 최종 중단합니다.")
            return False
        print(f"[✅] 최종 유효 이미지 {len(validated_images_data)}개 확보.")

        featured_image_data = validated_images_data[0]
        gallery_images_urls = [item['url'] for item in validated_images_data[1:]]

        raw_content_full, table_html, raw_content_body, faq_html, qas_data = None, "", "", "", []
        for i in range(3):
            print(f"[🤖] 콘텐츠 생성 시도 ({i+1}/3)...")
            raw_content_full = generate_gemini_content(place_name, place_details, validated_images_data)
            if raw_content_full:
                table_html, content_after_table = extract_table_data_and_format_html(raw_content_full, place_name)
                faq_html, raw_content_body, qas_data = extract_faq_data_and_format_html(content_after_table)
                if table_html and faq_html and raw_content_body:
                    print(f"[✅] 시도 {i+1}/3: 테이블 및 FAQ 데이터 생성 성공."); break
            print(f"[⚠️] 시도 {i+1}/3: 테이블 또는 FAQ 데이터가 누락되어 재시도합니다...")
            if i < 2: time.sleep(3)

        if not raw_content_full or not raw_content_body:
            print(f"[❌] '{place_name}': 콘텐츠 생성에 최종 실패하여 중단합니다.")
            return False

        video_id, channel_title = search_youtube_video(place_name, category_name, cfg["google_places_api_key"])
        youtube_html = create_youtube_embed_html(video_id, place_name)
        maps_html = create_Maps_html(place_name, place_details.get('place_id'), cfg["google_places_api_key"])
        gallery_html = create_gallery_html(gallery_images_urls, place_name)

        # (⭐️ UnboundLocalError 수정을 위해 코드 순서 변경 ⭐️)
        # 1. 본문 HTML을 먼저 생성합니다.
        body_html_content = parse_and_format_html(raw_content_body, featured_image_data, place_name, table_html, gallery_html, maps_html, youtube_html)

        # 2. 생성된 본문을 기반으로 SEO용 요약본과 최종 제목을 생성합니다.
        summary_for_seo = "".join(re.findall(r'<p>(.*?)</p>', body_html_content, re.DOTALL))[:500]
        title = generate_dedicated_title(place_name, summary_for_seo)

        # 3. 나머지 부가 정보들을 생성하고 최종 콘텐츠를 조립합니다.
        youtube_data_for_source = {"video_id": video_id, "channel_title": channel_title}
        sources_html = create_sources_html(pexels_photos_data, google_photos_data, youtube_data_for_source)
        faq_schema_html = create_faq_schema_html(qas_data, place_name)
        final_html_content = body_html_content + faq_html + sources_html + faq_schema_html

        focus_keyphrase = generate_focus_keyphrase(place_name, summary_for_seo)
        meta_description = generate_meta_description(focus_keyphrase, place_name, summary_for_seo)
        tags = generate_tags(place_name, summary_for_seo)
        tag_ids = ensure_tags_on_wordpress(cfg, tags, place_name)

        final_post_data = {
            'title': title, 'content': final_html_content, 'tag_ids': tag_ids,
            'featured_image_url': featured_image_data['url'], 'focus_keyphrase': focus_keyphrase,
            'meta_description': meta_description, 'korean_slug': place_name.replace(" ", "-"),
            'wp_category_id': current_category_id
        }

        if not title or not final_post_data.get('content'):
            print(f"[❌] '{place_name}': 최종 콘텐츠(제목 또는 본문)가 비어있어 발행을 중단합니다.")
            return False

        post_to_wordpress(cfg, final_post_data)
        return True

    except Exception as e:
        print(f"[❌] '{place_name} ({category_name})' 처리 중 예기치 않은 최상위 오류 발생: {e}")
        traceback.print_exc()
        return False

def load_published_log(log_file):
    print(f"[⚙️] 발행 기록부 '{log_file}'를 로드합니다...")
    if not os.path.exists(log_file):
        try:
            with open(log_file, 'w', encoding='utf-8') as f: pass
            print("[💡] 발행 기록 파일이 없어 새로 생성합니다.")
            return set()
        except Exception as e:
            print(f"[❌] 발행 기록 파일을 새로 생성하는 중 오류 발생: {e}")
            return set()
    try:
        with open(log_file, 'r', encoding='utf-8') as f:
            return set(line.strip() for line in f if line.strip())
    except Exception as e:
        print(f"[❌] 발행 기록 파일 '{log_file}'을 읽는 중 오류 발생: {e}")
        return set()

def append_to_published_log(log_file, place_name):
    try:
        with open(log_file, 'a', encoding='utf-8') as f:
            f.write(place_name + '\n')
        print(f"[💾] '{place_name}' 발행 기록 완료.")
    except Exception as e:
        print(f"[❌] 발행 기록 파일 '{log_file}'에 쓰는 중 오류 발생: {e}")

def run_automation_cycle(cfg):
    print("\n" + "="*20 + " 자동 포스팅 사이클 시작 " + "="*20)

    published_places = load_published_log(PUBLISHED_LOG_FILE)
    print(f"[ℹ️] 현재까지 발행된 글: {len(published_places)}개")

    unpublished_by_category = {}
    total_unpublished_count = 0
    ordered_category_ids = list(CATEGORY_ID_TO_NAME.keys())

    for category_id in ordered_category_ids:
        if category_id in TARGET_PLACES_OVERSEAS:
            places_list = TARGET_PLACES_OVERSEAS.get(category_id, [])
            unpublished_places = [p for p in places_list if p and p.strip() and p.strip() not in published_places]
            if unpublished_places:
                unpublished_by_category[category_id] = unpublished_places
                total_unpublished_count += len(unpublished_places)

    if total_unpublished_count == 0:
        print("\n[🎉] 모든 여행지에 대한 글 발행이 완료되었습니다! 새로 발행할 글이 없습니다.")
        print("="*61)
        return

    print(f"[ℹ️] 발행 대상 여행지: 총 {total_unpublished_count}개 발견")

    active_categories = [cat_id for cat_id in ordered_category_ids if cat_id in unpublished_by_category and unpublished_by_category[cat_id]]

    succeeded_count_this_run = 0
    if not active_categories:
        print("[💡] 발행할 글이 있는 카테고리가 없습니다.")
    else:
        category_cycler = itertools.cycle(active_categories)
        max_attempts = total_unpublished_count + len(active_categories)
        attempts = 0

        while succeeded_count_this_run < MAX_POSTS_PER_RUN and attempts < max_attempts:
            current_category_id = next(category_cycler)

            if unpublished_by_category.get(current_category_id):
                place_name = unpublished_by_category[current_category_id].pop(0)

                print(f"\n--- 다음 대상 처리 ({succeeded_count_this_run + 1}/{MAX_POSTS_PER_RUN}) ---")

                if process_single_place(cfg, place_name, current_category_id):
                    append_to_published_log(PUBLISHED_LOG_FILE, place_name)
                    succeeded_count_this_run += 1

                    if sum(len(v) for v in unpublished_by_category.values()) == 0:
                        print("[💡] 발행할 모든 글을 처리했습니다.")
                        break

                    if succeeded_count_this_run < MAX_POSTS_PER_RUN:
                         print(f"\n--- 다음 처리까지 {POST_DELAY_SECONDS}초 대기합니다... ---")
                         time.sleep(POST_DELAY_SECONDS)

            if not unpublished_by_category.get(current_category_id) and current_category_id in active_categories:
                active_categories.remove(current_category_id)
                if not active_categories: break
                category_cycler = itertools.cycle(active_categories)

            attempts += 1

    print("\n" + "="*21 + " 자동 포스팅 사이클 종료 " + "="*21)
    print(f"이번 실행에서 총 {succeeded_count_this_run}개의 글을 성공적으로 발행했습니다.")

if __name__ == "__main__":
    script_start_time = time.time()
    config = load_configuration()
    if config:
        run_automation_cycle(config)
    script_end_time = time.time()
    print(f"\n총 스크립트 실행 시간: {script_end_time - script_start_time:.2f}초")
