<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

trait ApiResponseTrait
{
    /**
     * Return a standardized success response envelope.
     */
    protected function success(mixed $data = null, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        $payload['timestamp'] = now()->toIso8601String();

        return response()->json($payload, $status);
    }

    /**
     * Return a standardized error response envelope.
     */
    protected function error(string $message, string $code = 'ERROR', mixed $details = null, int $status = 400): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }
}
