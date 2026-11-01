<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateInternalService
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        if (config('services.udev_internal.enabled') !== true) {
            return response()->json(['message' => 'Layanan internal dinonaktifkan.'], Response::HTTP_NOT_FOUND);
        }

        $expectedToken = config('services.udev_internal.token');
        $token = $request->bearerToken();

        if (! is_string($expectedToken) || $expectedToken === '') {
            return response()->json(['message' => 'Konfigurasi autentikasi layanan internal belum tersedia.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! is_string($token) || $token === '' || ! hash_equals($expectedToken, $token)) {
            return response()->json(['message' => 'Autentikasi layanan internal diperlukan.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($ability !== null && $ability !== '') {
            $abilities = config('services.udev_internal.abilities', []);
            if (! is_array($abilities) || ! in_array($ability, $abilities, true)) {
                return response()->json(['message' => 'Layanan internal tidak memiliki otorisasi yang diperlukan.'], Response::HTTP_FORBIDDEN);
            }
        }

        $request->attributes->set('internal_service_id', (string) config('services.udev_internal.service_id', 'udev-billing'));

        return $next($request);
    }
}
