<?php

namespace Tests\Feature;

use App\Infrastructure\Notifications\SlackMessage;
use App\Infrastructure\Notifications\SlackTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlackTransportTest extends TestCase
{
    private function enabled(): SlackTransport
    {
        config(['notifications.enabled' => true, 'notifications.webhook_url' => 'https://hooks.slack.com/services/SYNTHETIC/ONLY/TEST']);

        return new SlackTransport;
    }

    public function test_disabled_or_non_slack_urls_never_make_requests(): void
    {
        Http::fake();
        $transport = $this->enabled();
        config(['notifications.enabled' => false]);
        $this->assertSame('blocked', $transport->send([])['status']);
        config(['notifications.enabled' => true, 'notifications.webhook_url' => 'https://example.com/services/token']);
        $this->assertFalse($transport->configured());
        Http::assertNothingSent();
    }

    public function test_success_requires_slack_acknowledgement(): void
    {
        Http::fakeSequence()->push('ok', 200)->push('invalid_payload', 200);
        $transport = $this->enabled();
        $this->assertSame(['status' => 'sent'], $transport->send(['text' => 'Test']));
        $this->assertSame('blocked', $transport->send(['text' => 'Test'])['status']);
    }

    public function test_rate_limit_waits_and_invalid_configuration_stops_automatic_retry(): void
    {
        Http::fakeSequence()->push('rate_limited', 429, ['Retry-After' => '120'])
            ->push('invalid_token', 403)->push('unavailable', 503)->push('', 302, ['Location' => 'https://example.com']);
        $transport = $this->enabled();
        $this->assertSame(['status' => 'retry', 'reason' => 'rate_limited', 'delay' => 120], $transport->send([]));
        $this->assertSame('blocked', $transport->send([])['status']);
        $this->assertSame('retry', $transport->send([])['status']);
        $this->assertSame('blocked', $transport->send([])['status']);
    }

    public function test_connection_exceptions_do_not_expose_the_secret_url(): void
    {
        Http::fake(fn () => throw new ConnectionException('https://hooks.slack.com/services/PRIVATE/SECRET'));
        $result = $this->enabled()->send([]);
        $this->assertSame(['status' => 'retry', 'reason' => 'network_error', 'delay' => 60], $result);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
    }

    public function test_every_alert_has_a_distinct_title_color_and_plain_text_fields(): void
    {
        $titles = [];
        foreach (SlackMessage::TYPES as $type => $metadata) {
            $message = (new SlackMessage)->make($type, ['Team' => '<!channel>', 'Stake' => '₦100.00'], 'event-id', '2026-10-24T10:00:00Z');
            $titles[] = $message['text'];
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $message['attachments'][0]['color']);
            $this->assertSame('plain_text', $message['attachments'][0]['blocks'][1]['fields'][0]['type']);
        }
        $this->assertCount(count(SlackMessage::TYPES), array_unique($titles));
    }
}
