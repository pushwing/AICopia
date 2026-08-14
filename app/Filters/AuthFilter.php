<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\FrameBridge;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 로그인 여부 및 권한 확인 필터
 * 사용법: $routes->group('', ['filter' => 'auth:member'], ...)
 *        $routes->group('', ['filter' => 'auth:admin'], ...)
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session      = session();
        $isLoggedIn   = (bool) $session->get('user_id');
        $requiredRole = $arguments[0] ?? 'member';

        if (! $isLoggedIn) {
            // 이니시스·나이스페이 결제 레이어(iframe) 안에서 로드되면 SameSite=Lax
            // 세션 쿠키가 안 실려 로그인 상태가 비어 보인다 — 로그인 화면으로
            // 튕기지 말고 최상위 창을 같은 URL로 이동시키는 탈출 페이지를 준다.
            if (FrameBridge::isFramed($request)) {
                return service('response')->setBody(FrameBridge::render((string) current_url(true)));
            }

            session()->setFlashdata('redirect_url', current_url());
            return redirect()->to('/auth/login')->with('error', '로그인이 필요합니다.');
        }

        // 관리자가 강제 탈퇴시킨 회원의 세션이 만료 전까지 살아있지 않도록,
        // PK 단건 조회로 매 요청 탈퇴 여부를 확인한다(인덱스를 타는 조회라 부하가 작다).
        $userId = (int) $session->get('user_id');
        $user   = db_connect()->table('users')->select('withdrawn_at')->where('id', $userId)->get()->getRowArray();

        if (is_array($user) && ! empty($user['withdrawn_at'])) {
            $session->destroy();
            return redirect()->to('/auth/login')->with('error', '탈퇴한 계정입니다.');
        }

        if ($requiredRole === 'admin' && $session->get('user_role') !== 'admin') {
            return redirect()->back()->with('error', '접근 권한이 없습니다.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
