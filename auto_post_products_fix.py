# 평점 표시 로직 수정 패치
# 904-918번 줄 수정

# 🔧 평점 표시 개선 - 큐 파일에 저장된 rating_display를 우선 사용
rating_display = product.get('rating_display', '⭐⭐⭐⭐ (75%)')

# rating_display가 이미 정확히 저장되어 있다면 그대로 사용
# 만약 rating_display가 기본값이거나 없다면 계산 로직 사용
if rating_display == '⭐⭐⭐⭐ (75%)' or not rating_display:
    if product.get('rating_raw') and str(product.get('rating_raw')) != '0':
        try:
            rating_float = float(product.get('rating_float', 75.0))
            if rating_float >= 90:
                rating_display = f"⭐⭐⭐⭐⭐ ({rating_float}%)"
            elif rating_float >= 70:
                rating_display = f"⭐⭐⭐⭐ ({rating_float}%)"
            elif rating_float >= 50:
                rating_display = f"⭐⭐⭐ ({rating_float}%)"
            else:
                rating_display = f"⭐⭐⭐⭐ (75%)"
        except:
            rating_display = "⭐⭐⭐⭐ (75%)"