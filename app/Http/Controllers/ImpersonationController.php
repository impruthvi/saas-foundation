<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\EndImpersonation;
use App\Contracts\Operators;
use App\Enums\ImpersonationEnd;
use App\Models\Impersonation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class ImpersonationController extends Controller
{
    public function destroy(Request $request, EndImpersonation $end, Operators $operators): Response
    {
        abort_unless(Impersonation::liveIn($request->session()) instanceof Impersonation, Response::HTTP_FORBIDDEN);

        $end->handle($request->session(), ImpersonationEnd::Operator);

        return Inertia::location($operators->returnUrl());
    }
}
