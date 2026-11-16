<?php

namespace App\Mail;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class QuotationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public Quotation $quotation;

    public string $pdfBinary;

    public string $filename;

    public function __construct(Quotation $quotation, string $pdfBinary, string $filename)
    {
        $this->quotation = $quotation->loadMissing(['customer', 'company']);
        $this->pdfBinary = $pdfBinary;
        $this->filename = $filename;
    }

    public function build()
    {
        $company = $this->quotation->company;
        $companyName = trim((string) optional($company)->name);
        $mail = $this->subject('Quotation '.$this->quotation->number.($companyName !== '' ? ' from '.$companyName : ''))
            ->view('emails.quotation')
            ->attachData($this->pdfBinary, $this->filename, ['mime' => 'application/pdf']);

        $replyTo = trim((string) optional($company)->email);
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($replyTo, $companyName !== '' ? $companyName : null);
        }

        return $mail;
    }
}
