<?php

namespace App\Support;

use App\Models\Company;

class MailSender
{
    /**
     * @return array{address: string, name: ?string, replyTo: ?string}
     */
    public static function forCompany(?Company $company): array
    {
        $name = trim((string) optional($company)->name);
        $reply = trim((string) optional($company)->email);
        $reply = filter_var($reply, FILTER_VALIDATE_EMAIL) ? $reply : null;
        $domain = self::sendingDomain();
        $from = ($reply && $domain && self::onDomain($reply, $domain))
            ? $reply
            : ($domain ? 'noreply@'.$domain : (string) $reply);

        return [
            'address' => $from,
            'name' => $name !== '' ? $name : null,
            'replyTo' => $reply,
        ];
    }

    public static function sendingDomain(): ?string
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? '';
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) > 2) {
            array_shift($parts);
            $host = implode('.', $parts);
        }

        return $host;
    }

    private static function onDomain(string $email, string $domain): bool
    {
        $emailDomain = strtolower((string) substr((string) strrchr($email, '@'), 1));

        return $emailDomain === $domain || substr($emailDomain, -strlen('.'.$domain)) === '.'.$domain;
    }
}
