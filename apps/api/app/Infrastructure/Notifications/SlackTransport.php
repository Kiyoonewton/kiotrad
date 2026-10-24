<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Support\Facades\Http;
use Throwable;

class SlackTransport
{
    public function configured(): bool
    {
        return config('notifications.enabled') && preg_match(
            '~^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$~D',
            (string) config('notifications.webhook_url'),
        ) === 1;
    }

    public function send(array $payload): array
    {
        if (! $this->configured()) {
            return ['status' => 'blocked', 'reason' => 'configuration_required', 'delay' => 300];
        }
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->post(config('notifications.webhook_url'), $payload);
            if ($response->status() === 200 && trim($response->body()) === 'ok') {
                return ['status' => 'sent'];
            }
            if ($response->status() === 429) {
                return ['status' => 'retry', 'reason' => 'rate_limited',
                    'delay' => max(1, min(86400, (int) $response->header('Retry-After')))];
            }
            $retry = $response->serverError() || in_array($response->status(), [408, 425], true);

            return ['status' => $retry ? 'retry' : 'blocked', 'reason' => 'http_'.$response->status(), 'delay' => 60];
        } catch (Throwable) {
            // Never log a request exception: its URL contains the webhook secret.
            return ['status' => 'retry', 'reason' => 'network_error', 'delay' => 60];
        }
    }
}
