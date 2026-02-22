<?php

declare(strict_types=1);

class Auth
{
    public static function validateApiKey(Request $request): void
    {
        $expected = Config::get('API_KEY');
        if ($expected === '' || $expected === 'change-me-to-a-secure-key') {
            Response::serverError('API_KEY not configured. Please set a secure API key in .env');
        }

        $provided = $request->header('x-api-key');
        if ($provided === null || $provided === '') {
            Response::unauthorized('Missing X-API-Key header');
        }

        if (!hash_equals($expected, $provided)) {
            Response::unauthorized('Invalid API key');
        }
    }
}
