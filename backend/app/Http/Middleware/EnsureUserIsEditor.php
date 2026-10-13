<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsEditor
{
    /**
     * Allow only authenticated Editors to perform the requested mutation.
     *
     * Authentication is enforced by the route's auth middleware before this check.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== UserRole::EDITOR) {
            return response()->json([
                'message' => 'This action is unauthorized.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
