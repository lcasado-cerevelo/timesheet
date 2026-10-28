<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** El correo sale por la API HTTP de Brevo (puerto 443), porque el hosting compartido bloquea el SMTP. */
class BrevoMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.mailers.brevo' => ['transport' => 'brevo', 'key' => 'clave-de-prueba', 'timeout' => 20],
            'mail.from' => ['address' => 'no-responder@cerevelo.test', 'name' => 'Cerevelo']]);
        Mail::purge('brevo');
    }

    private function send(): void
    {
        Mail::mailer('brevo')->html('<p>Tu código es 123456</p>', fn ($m) => $m->to('ana@cerevelo.test', 'Ana Torres')->subject('Tu código de verificación'));
    }

    public function test_it_posts_the_message_to_the_brevo_api_with_the_key(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<abc@brevo>'], 201)]);
        $this->send();
        Http::assertSent(function ($request) {
            $d = $request->data();
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'clave-de-prueba')
                && $d['sender']['email'] === 'no-responder@cerevelo.test' && $d['sender']['name'] === 'Cerevelo'
                && $d['to'][0]['email'] === 'ana@cerevelo.test' && $d['to'][0]['name'] === 'Ana Torres'
                && $d['subject'] === 'Tu código de verificación'
                && str_contains($d['htmlContent'], 'Tu código es 123456');
        });
    }

    public function test_a_brevo_error_becomes_a_transport_exception_with_the_reason_brevo_gave(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['code' => 'unauthorized', 'message' => 'Key not found'], 401)]);
        try {
            $this->send();
            $this->fail('Debía lanzar TransportException');
        } catch (TransportException $e) {
            $this->assertStringContainsString('Key not found', $e->getMessage());
            $this->assertStringContainsString('401', $e->getMessage());
        }
    }

    public function test_attachments_cc_and_reply_to_go_along(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        Mail::mailer('brevo')->html('<p>Factura</p>', fn ($m) => $m->to('ana@cerevelo.test')->cc('luis@cerevelo.test')->bcc('conta@cerevelo.test')
            ->replyTo('soporte@cerevelo.test')->subject('Factura')->attachData('PDFDATA', 'factura.pdf', ['mime' => 'application/pdf']));
        Http::assertSent(function ($request) {
            $d = $request->data();
            return $d['cc'][0]['email'] === 'luis@cerevelo.test' && $d['bcc'][0]['email'] === 'conta@cerevelo.test' && $d['replyTo']['email'] === 'soporte@cerevelo.test'
                && $d['attachment'][0]['name'] === 'factura.pdf' && $d['attachment'][0]['content'] === base64_encode('PDFDATA');
        });
    }
}
