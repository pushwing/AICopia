# CI4 게시판 모듈 설치 가이드

## 1. 설치
```bash
composer create-project codeigniter4/appstarter my-project
cd my-project
```

## 2. 이 모듈 파일 복사
`app/` 폴더 내 파일을 모두 복사

## 3. .env 설정
```
CI_ENVIRONMENT = development
database.default.hostname = localhost
database.default.database = your_db
database.default.username = root
database.default.password = 
database.default.DBDriver = MySQLi
```

## 4. 타임존 설정
`app/Config/App.php`에서 한국 시간대로 변경:
```php
public string $appTimezone = 'Asia/Seoul';
```

## 5. 마이그레이션 실행
```bash
php spark migrate
```

## 6. 기본 계정
- 이메일: admin@example.com
- 비밀번호: admin1234!

## 7. 업로드 폴더 권한 (Linux)
```bash
chmod -R 755 public/uploads
```

## URL 구조
| URL | 설명 |
|-----|------|
| /board/notice | 공지사항 목록 |
| /board/free | 자유게시판 목록 |
| /board/qna | 문의게시판 목록 |
| /board/{slug}/write | 글쓰기 |
| /board/{slug}/{id} | 글 보기 |
| /board/file/{id}/download | 파일 다운로드 |
| /admin/boards | 게시판 관리 (관리자) |
| /auth/login | 로그인 |
| /auth/register | 회원가입 |
