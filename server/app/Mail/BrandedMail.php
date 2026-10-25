<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Un solo correo con marca de la compañía (nombre, logo, colores, remitente y pie). */
class BrandedMail extends Mailable
{
    /** @param array<int, array{path:string,name:string,mime:string}> $files */
    public function __construct(
        public Company $company,
        public string $mailSubject,
        public string $viewName,
        public array $data = [],
        public array $files = [],
    ) {
    }

    public function envelope(): Envelope
    {
        $from = $this->company->email_from_address
            ? new Address($this->company->email_from_address, $this->company->email_from_name ?: $this->company->displayName())
            : null;
        return new Envelope(from: $from, subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(view: $this->viewName, with: $this->data + ['brand' => $this->company->brand()]);
    }

    public function attachments(): array
    {
        return array_map(
            fn ($f) => \Illuminate\Mail\Mailables\Attachment::fromPath($f['path'])->as($f['name'])->withMime($f['mime']),
            $this->files
        );
    }
}
