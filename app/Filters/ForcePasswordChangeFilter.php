<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 기본 비밀번호를 쓰는 계정을 비밀번호 변경 화면으로 묶어두는 필터 (이슈 #119)
 *
 * `users.must_change_password` 가 서 있는 동안에는 다른 화면으로 나가지 못한다.
 * 변경 화면과 로그아웃은 예외로 두어야 무한 리다이렉트나 계정 잠김이 생기지 않는다.
 */
class ForcePasswordChangeFilter implements FilterInterface
{
    /** 플래그가 서 있어도 접근을 허용할 경로 (변경하러 가는 길·탈출구) */
    private const array EXEMPT_PREFIXES = [
        'auth/profile',
        'auth/logout',
        'auth/login',
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        // 비로그인 사용자는 AuthFilter 소관이다.
        if (! $session->get('user_id')) {
            return null;
        }

        if (! $session->get('must_change_password')) {
            return null;
        }

        // getPath() 는 IncomingRequest 에만 있다 (CLI 요청 등은 이 필터 대상이 아님)
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $path = trim($request->getPath(), '/');
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return null;
            }
        }

        return redirect()->to('/auth/profile?tab=password')
            ->with('error', '초기 비밀번호를 사용 중입니다. 계속하려면 비밀번호를 변경해주세요.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
