<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Envía el correo por la API HTTP de Brevo (puerto 443) en vez de SMTP: el hosting compartido bloquea el SMTP saliente.
 */
class BrevoTransport extends AbstractTransport
{
    public const URL = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(private string $key, private int $timeout = 20)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? $message->getEnvelope()->getSender();
        $payload = array_filter([
            'sender' => $this->person($from),
            'to' => $this->people($email->getTo()),
            'cc' => $this->people($email->getCc()),
            'bcc' => $this->people($email->getBcc()),
            'replyTo' => ($r = $email->getReplyTo()) ? $this->person($r[0]) : null,
            'subject' => (string) $email->getSubject(),
            'htmlContent' => $email->getHtmlBody(),
            'textContent' => $email->getTextBody(),
            'attachment' => $this->attachments($email),
        ], fn ($v) => $v !== null && $v !== []);

        try {
            $response = Http::withHeaders(['api-key' => $this->key, 'accept' => 'application/json'])->timeout($this->timeout)->asJson()->post(self::URL, $payload);
        } catch (\Throwable $e) {
            throw new TransportException('Brevo no respondió: '.$e->getMessage(), 0, $e);
        }
        if ($response->failed()) {
            $reason = $response->json('message') ?: trim($response->body()) ?: 'HTTP '.$response->status();
            throw new TransportException('Brevo rechazó el correo ('.$response->status().'): '.$reason);
        }
        if ($id = $response->json('messageId')) {
            $message->setMessageId($id);
        }
    }

    private function person(Address $a): array
    {
        return array_filter(['email' => $a->getAddress(), 'name' => $a->getName() ?: null]);
    }

    /** @param Address[] $list */
    private function people(array $list): array
    {
        return array_map(fn (Address $a) => $this->person($a), array_values($list));
    }

    private function attachments(Email $email): array
    {
        return array_map(fn ($part) => ['name' => $part->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename') ?: 'adjunto',
            'content' => base64_encode($part->getBody())], $email->getAttachments());
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
