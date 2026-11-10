<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LocalizedErrorResponse
{
    public static function render(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();
        if ($status < 400 || $exception instanceof ValidationException) {
            return $response;
        }

        $content = trans('http.'.$status);
        if (! is_array($content)) {
            $content = trans('http.'.($status >= 500 ? 500 : 400));
        }

        if ($response instanceof JsonResponse) {
            $response->setData(['message' => $content['message']]);
        } else {
            $response->setContent(view('errors.http', [
                'status' => $status, 'heading' => $content['title'], 'message' => $content['message'],
            ])->render());
            $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        }

        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }
}
