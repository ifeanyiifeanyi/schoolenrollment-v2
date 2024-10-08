<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApplicationStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        if ($user && $user->applications) {
            return redirect()->route('student.dashboard')->with('warning', 'You have already submitted an application.');
        }

        return $next($request);
    }
}
