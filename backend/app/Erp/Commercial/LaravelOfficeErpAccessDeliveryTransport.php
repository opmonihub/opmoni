<?php

namespace App\Erp\Commercial;

use App\Mail\OfficeErpAccessDeliveryMail;
use Illuminate\Support\Facades\Mail;

final class LaravelOfficeErpAccessDeliveryTransport implements OfficeErpAccessDeliveryTransport
{
    private const ALLOWED_TRANSPORTS = ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'sendmail'];

    public function send(string $recipient, string $subject, string $url): void
    {
        $mailer = (string) config('mail.default');
        $this->assertSecureMailer($mailer);

        Mail::mailer($mailer)->to($recipient)->send(new OfficeErpAccessDeliveryMail($subject, $url));
    }

    public function assertAvailable(): void
    {
        $this->assertSecureMailer((string) config('mail.default'));
    }

    private function assertSecureMailer(string $mailer, array $visited = []): void
    {
        if ($mailer === '' || in_array($mailer, $visited, true)) {
            throw new OfficeErpAccessDeliveryTransportRejected('The access delivery transport is unavailable.');
        }
        $visited[] = $mailer;
        $config = config('mail.mailers.'.$mailer);
        if (! is_array($config)) {
            throw new OfficeErpAccessDeliveryTransportRejected('The access delivery transport is unavailable.');
        }

        $transport = $config['transport'] ?? null;
        if ($transport === 'failover' || $transport === 'roundrobin') {
            $mailers = $config['mailers'] ?? [];
            if (! is_array($mailers) || $mailers === []) {
                throw new OfficeErpAccessDeliveryTransportRejected('The access delivery transport is unavailable.');
            }
            foreach ($mailers as $nestedMailer) {
                if (! is_string($nestedMailer)) {
                    throw new OfficeErpAccessDeliveryTransportRejected('The access delivery transport is unavailable.');
                }
                $this->assertSecureMailer($nestedMailer, $visited);
            }

            return;
        }

        if (! is_string($transport) || ! in_array($transport, self::ALLOWED_TRANSPORTS, true)) {
            throw new OfficeErpAccessDeliveryTransportRejected('The access delivery transport is unavailable.');
        }
    }
}
