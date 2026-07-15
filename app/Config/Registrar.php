<?php

declare(strict_types=1);

namespace Config;

/**
 * app/Config/App.php는 표준 CI4 스켈레톤이라 gitignore 대상이며 커밋되지 않는다
 * (CI/배포 시 vendor에서 매번 새로 복원됨). 그래서 App 설정을 직접 수정하는 대신
 * CI4가 제공하는 Registrar 메커니즘(BaseConfig::registerProperties())으로
 * 값을 오버라이드한다. 이 파일은 추적 대상이라 배포 환경에도 항상 반영된다.
 */
class Registrar
{
    /**
     * @return array<string, string>
     */
    public static function App(): array
    {
        return [
            // 한글 상품 슬러그(가-힣) URL 접근 시 라우터가 400(disallowed characters)을
            // 던지던 문제 수정 (이슈 #72). 기본값 'a-z 0-9~%.:_\-'에 완성형 한글 범위만 추가.
            'permittedURIChars' => 'a-z 0-9~%.:_\-\x{AC00}-\x{D7A3}',
        ];
    }
}
