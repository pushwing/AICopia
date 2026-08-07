// Bootstrap form validation
(function () {
    'use strict';
    document.querySelectorAll('.needs-validation').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();

// 카카오(구 다음) 우편번호 서비스 — 버튼 클릭 시점에 CDN 스크립트를 지연 로드한다.
// 스크립트를 <script> 태그로 미리 심어두면 로드가 실패했을 때(광고 차단 확장·네트워크 오류 등)
// 클릭 시 `kakao is not defined` ReferenceError 만 나고 주소 입력이 완전히 막힌다.
//
// 도메인·네임스페이스는 카카오 이관에 맞춰 t1.kakaocdn.net · kakao.Postcode 를 쓴다.
// 번들이 window.kakao 와 window.daum 을 서로 alias 하므로 구 네임스페이스도 동작하지만,
// 레거시 다음 도메인은 순차 종료 중이라 신규 경로로 통일한다.
(function () {
    'use strict';

    var POSTCODE_SRC = 'https://t1.kakaocdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
    var loadingPromise = null;

    // 스크립트를 한 번만 로드하고 Promise 를 캐시한다. 실패 시 캐시를 비워 다음 클릭에서 재시도한다.
    function loadPostcodeScript() {
        if (window.kakao && window.kakao.Postcode) {
            return Promise.resolve();
        }
        if (loadingPromise) {
            return loadingPromise;
        }

        loadingPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src   = POSTCODE_SRC;
            script.async = true;
            script.onload = function () {
                if (window.kakao && window.kakao.Postcode) {
                    resolve();
                    return;
                }
                reject(new Error('스크립트는 로드됐지만 kakao.Postcode 가 없습니다.'));
            };
            script.onerror = function () {
                reject(new Error('우편번호 스크립트 로드 실패: ' + POSTCODE_SRC));
            };
            document.head.appendChild(script);
        })['catch'](function (err) {
            loadingPromise = null;
            throw err;
        });

        return loadingPromise;
    }

    // 검색 서비스를 못 쓰는 상황에서도 주문이 막히지 않도록 주소 입력란의 readonly 를 해제한다.
    function enableManualAddressInput() {
        ['zipcode', 'address1'].forEach(function (id) {
            var field = document.getElementById(id);
            if (field) {
                field.readOnly = false;
            }
        });
    }

    /**
     * 우편번호 검색 팝업을 연다.
     * @param {function(Object): void} oncomplete 주소 선택 시 호출되는 콜백
     */
    window.openPostcode = function (oncomplete) {
        loadPostcodeScript().then(function () {
            new window.kakao.Postcode({ oncomplete: oncomplete }).open();
        })['catch'](function (err) {
            console.error('[postcode]', err);
            enableManualAddressInput();
            alert('주소 검색 서비스를 불러오지 못했습니다.\n네트워크 상태나 광고 차단 확장 프로그램을 확인해 주세요.\n우선 우편번호와 주소를 직접 입력하실 수 있습니다.');
        });
    };
})();
