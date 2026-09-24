<?php

namespace App\Exceptions;

use App\Support\LocaleResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ApiExceptionRenderer
{
    public function shouldRender(Request $request): bool
    {
        return $request->is('api/*')
            || $request->is('api/admin/*')
            || $request->expectsJson();
    }

    public function render(Throwable $e, Request $request): JsonResponse
    {
        LocaleResolver::apply($request);

        $status = $this->resolveStatusCode($e);
        $message = $this->resolveMessage($e);

        $payload = [
            'success' => false,
            'status' => 'error',
            'code' => $status,
            'message' => $message,
            'data' => [],
            'pagination' => null,
        ];

        if ($e instanceof ValidationException) {
            $payload['errors'] = $e->errors();
        }

        if ($e instanceof ApiRenderable) {
            $payload['error_code'] = $e->apiErrorCode();
            $payload['data'] = $e->apiData();
        }

        if ($request->boolean('debug') && config('app.debug')) {
            $payload['debug'] = [
                'type' => class_basename($e),
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect($e->getTrace())->take(10)->all(),
            ];
        }

        return response()->json($payload, $status);
    }

    protected function resolveStatusCode(Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => $e->status,
            $e instanceof ApiRenderable => $e->apiStatus(),
            $e instanceof ConflictException => 409,
            $e instanceof NotFoundHttpException => 404,
            $e instanceof MethodNotAllowedHttpException => 405,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };
    }

    protected function resolveMessage(Throwable $e): string|array
    {
        return match (true) {
            $e instanceof ValidationException => __('api.validation_failed'),
            $e instanceof ApiRenderable => $e->apiMessage(),
            $e instanceof NotFoundHttpException => __('api.not_found'),
            $e instanceof MethodNotAllowedHttpException => __('api.method_not_allowed'),
            $e instanceof AuthenticationException => __('api.unauthenticated'),
            $e instanceof AuthorizationException => __('api.unauthorized'),
            $e instanceof ConflictException => $e->getMessage() ?: __('api.conflict'),
            default => $this->resolveServerMessage($e),
        };
    }

    protected function resolveServerMessage(Throwable $e): string
    {
        if (config('app.debug') && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        return __('api.server_error');
    }
}
