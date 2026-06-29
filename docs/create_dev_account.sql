-- ============================================================
-- AICreo Shop — 개발용 DB 계정 생성 및 권한 설정
-- 실행: mysql -u root -p < create_dev_account.sql
-- ============================================================

-- 아래 3개 변수를 실제 값으로 교체하세요
SET @db_name  = 'aicroeshop';   -- 생성한 DB명으로 교체
SET @db_user  = 'aicroedev';    -- 개발용 계정명 (원하는 이름)
SET @db_pass  = 'Dev@1234!';    -- 안전한 비밀번호로 교체

-- ============================================================
-- 1. 계정 생성
-- ============================================================
CREATE USER IF NOT EXISTS 'aicroedev'@'localhost' IDENTIFIED BY 'Dev@1234!';

-- ============================================================
-- 2. 해당 DB에만 필요한 권한 부여 (최소 권한 원칙)
--    SELECT / INSERT / UPDATE / DELETE : 기본 CRUD
--    CREATE / DROP / ALTER / INDEX     : 마이그레이션 실행에 필요
--    REFERENCES                        : 외래키 제약 생성
-- ============================================================
GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, DROP, ALTER, INDEX,
      REFERENCES, CREATE TEMPORARY TABLES
ON `aicroeshop`.* TO 'aicroedev'@'localhost';

-- ============================================================
-- 3. 권한 즉시 적용
-- ============================================================
FLUSH PRIVILEGES;

-- ============================================================
-- 4. 확인
-- ============================================================
SHOW GRANTS FOR 'aicroedev'@'localhost';
