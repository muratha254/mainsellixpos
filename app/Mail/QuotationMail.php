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
        $sender = trim((string) optional($company)->email);
        $mail = $this->subject('Quotation '.$this->quotation->number.($companyName !== '' ? ' from '.$companyName : ''))
            ->view('emails.quotation')
            ->attachData($this->pdfBinary, $this->filename, ['mime' => 'application/pdf']);

        if (filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            $mail->from($sender, $companyName !== '' ? $companyName : null);
            $mail->replyTo($sender, $companyName !== '' ? $companyName : null);
        }

        return $mail;
    }
}
