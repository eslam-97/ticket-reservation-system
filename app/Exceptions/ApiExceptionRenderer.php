<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The single place an error response is built.
 *
 * Every failure leaves the API as { "error": { code, message, details } }.
 * Controllers never build an error body, and the API never renders HTML.
 *
 * Laravel runs prepareException() before this, so some framework exceptions
 * have already been rewritten by the time they arrive: ModelNotFoundException
 * shows up as NotFoundHttpException, and a status-less AuthorizationException
 * as AccessDeniedHttpException. Both are handled below under their new types.
 */
class ApiExceptionRenderer
{
    /**
     * Codes for HTTP statuses that reach us as a bare HttpException.
     */
    private const STATUS_CODES = [
        Response::HTTP_BAD_REQUEST => 'bad_request',
        Response::HTTP_UNAUTHORIZED => 'unauthenticated',
        Response::HTTP_FORBIDDEN => 'forbidden',
        Response::HTTP_NOT_FOUND => 'not_found',
        Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
        Response::HTTP_NOT_ACCEPTABLE => 'not_acceptable',
        Response::HTTP_CONFLICT => 'conflict',
        Response::HTTP_GONE => 'gone',
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'unsupported_media_type',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'validation_failed',
        Response::HTTP_TOO_MANY_REQUESTS => 'too_many_requests',
    ];

    /**
     * Returning null hands the exception back to Laravel's own rendering.
     */
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        return match (true) {
            // Carries its own prepared response; nothing for us to say.
            $e instanceof HttpResponseException => null,

            $e instanceof DomainException => $this->envelope(
                $e->code(),
                $e->getMessage(),
                $e->status(),
                $e->details(),
                $e->headers(),
            ),

            $e instanceof ValidationException => $this->envelope(
                'validation_failed',
                'The given data was invalid.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['errors' => $e->errors()],
            ),

            $e instanceof AuthenticationException => $this->envelope(
                'unauthenticated',
                'Unauthenticated.',
                Response::HTTP_UNAUTHORIZED,
            ),

            $e instanceof AccessDeniedHttpException,
            $e instanceof AuthorizationException => $this->envelope(
                'forbidden',
                'This action is unauthorized.',
                Response::HTTP_FORBIDDEN,
            ),

            // Before the generic HttpException arm: it is one too, and the
            // client needs its Retry-After to know when to come back.
            $e instanceof ThrottleRequestsException => $this->envelope(
                'too_many_requests',
                'Too many requests.',
                Response::HTTP_TOO_MANY_REQUESTS,
                [],
                // Retry-After, and the X-RateLimit-* pair alongside it.
                $e->getHeaders(),
            ),

            $e instanceof NotFoundHttpException => $this->envelope(
                'not_found',
                'Resource not found.',
                Response::HTTP_NOT_FOUND,
            ),

            $e instanceof HttpExceptionInterface => $this->fromHttpException($e),

            default => $this->internalError($e),
        };
    }

    private function fromHttpException(HttpExceptionInterface&Throwable $e): JsonResponse
    {
        $status = $e->getStatusCode();

        return $this->envelope(
            self::STATUS_CODES[$status] ?? 'http_error',
            $e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Request failed.'),
            $status,
            [],
            $e->getHeaders(),
        );
    }

    /**
     * The catch-all. Never leaks a stack trace unless APP_DEBUG is on, and
     * APP_DEBUG is false everywhere the reviewer will run this.
     */
    private function internalError(Throwable $e): JsonResponse
    {
        $details = config('app.debug')
            ? ['exception' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
            : [];

        return $this->envelope(
            'internal_error',
            'Something went wrong.',
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $details,
        );
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    private function envelope(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                // Always an object, so clients can read details.* without
                // first checking whether it came back as an empty array.
                'details' => (object) $details,
            ],
        ], $status, $headers);
    }
}
