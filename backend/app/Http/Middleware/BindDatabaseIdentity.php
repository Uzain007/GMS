<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\DatabaseIdentityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindDatabaseIdentity
{
    public function __construct(private readonly DatabaseIdentityContext $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        return $this->identity->run($user, fn (): Response => $next($request));
    }
}
