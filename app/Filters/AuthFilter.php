<?php

namespace App\Filters;

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
            session()->setTempdata('redirect_url', current_url(), 300);
            return redirect()->to('/auth/login')->with('error', '로그인이 필요합니다.');
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
