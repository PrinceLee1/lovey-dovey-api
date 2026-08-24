<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through the Sendlib HTTP API (https://sendlib.samueltuoyo.com/docs/send)
 * instead of SMTP. Registered as the "sendlib" mail driver in AppServiceProvider,
 * so any mailer/notification using that driver goes through this transport.
 */
class SendlibTransport extends AbstractTransport
{
    public function __construct(
        protected readonly ?string $apiKey,
        protected readonly string $endpoint = 'https://sendlib.samueltuoyo.com/api/send',
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (! $this->apiKey) {
            throw new RuntimeException('Sendlib API key is not configured (SENDLIB_API_KEY).');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $to = collect($email->getTo())
            ->map(fn (Address $address) => $address->getAddress())
            ->implode(',');

        $from = $email->getFrom()[0] ?? null;
        $replyTo = $email->getReplyTo()[0] ?? null;

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->post($this->endpoint, array_filter([
                'from' => $from
                    ? sprintf('"%s" <%s>', $from->getName() ?: config('mail.from.name'), $from->getAddress())
                    : null,
                'to' => $to,
                'subject' => $email->getSubject(),
                'html' => $email->getHtmlBody(),
                'text' => $email->getTextBody(),
                'replyTo' => $replyTo?->getAddress(),
            ], fn ($value) => $value !== null && $value !== ''));

        if ($response->failed()) {
            throw new RuntimeException(
                "Sendlib email send failed ({$response->status()}): {$response->body()}"
            );
        }
    }

    public function __toString(): string
    {
        return 'sendlib';
    }
}
