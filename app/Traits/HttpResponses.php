<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait HttpResponses
{
    /**
     * Return a success JSON response.
     */
    protected function success(mixed $data, ?string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Return a paginated success JSON response.
     */
    protected function paginatedSuccess(mixed $paginator, ?string $classResource = null, ?string $message = null): JsonResponse
    {
        $data = $classResource
            ? $classResource::collection($paginator->items())
            : $paginator->items();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 200);
    }

    /**
     * Return an error JSON response.
     */
    protected function error(mixed $data, ?string $message, int $code): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $data,
        ], $code);
    }
}
