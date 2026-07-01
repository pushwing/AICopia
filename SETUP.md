# AICopia 설치 가이드

CodeIgniter 4 기반 쇼핑몰 솔루션 AICopia의 로컬 개발 환경 설치 방법입니다.

## 요구 사항

- PHP 8.2+
- MySQL 8.0 / MariaDB 10.6+
- Composer
- FrankenPHP (권장) 또는 기본 PHP 내장 서버

---

## 1. 저장소 클론

```bash
git clone https://github.com/pushwing/AICopia.git
cd AICopia
git checkout dev
```

---

## 2. DB 및 계정 생성

MySQL 8.0은 기본 비밀번호 정책이 강합니다. 대문자·소문자·숫자·특수문자를 포함한 비밀번호를 사용하세요.

```bash
sudo mysql -e "
CREATE DATABASE aicopia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aicopia'@'localhost' IDENTIFIED BY 'Aicopia1234!@';
GRANT ALL PRIVILEGES ON aicopia.* TO 'aicopia'@'localhost';
FLUSH PRIVILEGES;
"
```

---

## 3. 의존성 설치

```bash
composer install
```

---

## 4. CI4 부트스트랩 파일 설정

이 프로젝트는 `codeigniter4/framework`를 Composer 패키지로 사용하므로, 루트에 `spark`와 `public/index.php`가 없습니다. vendor에서 복사합니다.

```bash
FRAMEWORK="vendor/codeigniter4/framework"

# spark CLI 도구
cp "$FRAMEWORK/spark" spark

# 웹 진입점
cp "$FRAMEWORK/public/index.php" public/index.php
cp "$FRAMEWORK/public/robots.txt" public/robots.txt

# writable 디렉토리
mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar

# 누락된 CI4 기본 Config 파일 (프로젝트 커스텀 파일은 덮어쓰지 않음)
for f in "$FRAMEWORK/app/Config/"*.php; do
    name=$(basename "$f")
    [ ! -f "app/Config/$name" ] && cp "$f" "app/Config/$name"
done
[ ! -d "app/Config/Boot" ] && cp -r "$FRAMEWORK/app/Config/Boot" app/Config/Boot
```

---

## 5. Paths.php 수정

`app/Config/Paths.php`의 `$systemDirectory`를 vendor 경로로 변경합니다.

```php
// 변경 전
public string $systemDirectory = __DIR__ . '/../../system';

// 변경 후
public string $systemDirectory = __DIR__ . '/../../vendor/codeigniter4/framework/system';
```

---

## 6. .env 설정

```bash
# .env 파일 직접 생성 (env 템플릿 파일 없음)
cat > .env << 'EOF'
CI_ENVIRONMENT = development

app.baseURL = 'http://localhost:8080'
app.appTimezone = 'Asia/Seoul'

database.default.hostname = 127.0.0.1
database.default.database = aicopia
database.default.username = aicopia
database.default.password = Aicopia1234!@
database.default.DBDriver = MySQLi
database.default.DBPrefix =
database.default.port = 3306
EOF
```

> **주의**: `hostname`은 반드시 `127.0.0.1`을 사용하세요. `localhost`로 설정하면 MySQLi가 Unix 소켓으로 연결을 시도해 WSL/Linux 환경에서 오류가 발생합니다.
>
> `app.baseURL`의 포트는 사용하는 개발 서버에 맞게 변경하세요 (PHP 내장 서버 기본값: `8080`, FrankenPHP 권장: `8200`).

---

## 7. 마이그레이션 실행

```bash
php spark migrate
```

테이블 생성 + 기본 데이터(관리자 계정·사이트 설정·게시판 등) 시딩이 함께 처리됩니다.

---

## 8. 샘플 데이터 시더 (선택)

```bash
php spark db:seed ProductSeeder   # 카테고리 + 상품 8개
php spark db:seed PostSeeder      # 게시글 샘플
php spark db:seed InquirySeeder   # 문의 샘플
```

---

## 9. 업로드 폴더 권한

```bash
chmod -R 755 public/uploads writable
```

---

## 10. TinyMCE 에디터 설치

게시판·상품·페이지 편집기에 사용되는 TinyMCE를 로컬에 복사합니다 (API 키 불필요).

```bash
npm install
cp -r node_modules/tinymce public/tinymce
```

> Node.js가 없으면 [nodejs.org](https://nodejs.org)에서 설치하세요.
> `public/tinymce/`는 `.gitignore`에 포함되어 있으므로 환경마다 위 명령을 실행해야 합니다.

---

## 11. 개발 서버 실행

### PHP 내장 서버 (기본)

별도 설치 없이 바로 사용 가능합니다.

```bash
php spark serve
```

접속: **http://localhost:8080**

포트를 변경하려면:

```bash
php spark serve --port 8200
```

### FrankenPHP (선택)

FrankenPHP가 설치된 경우 더 빠른 성능으로 실행할 수 있습니다.

```bash
# 설치 확인
which frankenphp

# 실행 (프로젝트 루트에 serve.sh 제공)
./serve.sh
```

접속: **http://localhost:8200** (serve.sh 기준)

> `serve.sh`는 FrankenPHP 전용입니다. FrankenPHP가 없으면 `php spark serve`를 사용하세요.

---

## 기본 관리자 계정

| 항목 | 값 |
|------|----|
| 이메일 | `admin@example.com` |
| 비밀번호 | `admin1234!` |
| 관리자 패널 | http://localhost:8200/admin |

> 최초 로그인 후 반드시 비밀번호를 변경하세요.

---

## 주요 URL

| URL | 설명 |
|-----|------|
| `/` | 홈 |
| `/shop` | 상품 목록 |
| `/cart` | 장바구니 |
| `/auth/login` | 로그인 |
| `/auth/register` | 회원가입 |
| `/admin` | 관리자 대시보드 |
| `/admin/products` | 상품 관리 |
| `/admin/orders` | 주문 관리 |
| `/admin/settings/general` | 사이트 설정 |

---

## 크론 등록 (운영 서버)

```
* * * * * cd /path/to/AICopia && php spark tasks:run >> /dev/null 2>&1
```

`Config/Tasks.php`가 `settings` 테이블에서 활성화된 잡을 읽어 스케줄러에 등록합니다. `/admin/schedule`에서 관리합니다.
