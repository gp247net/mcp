<?php

namespace GP247\Mcp\Services;

/**
 * Single place that hides customer contact details before they leave the site.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-customer-read
 */
final class PiiMasker
{
    /**
     * @param bool $reveal True when the site owner allows contact details to be sent verbatim.
     */
    public function __construct(private readonly bool $reveal = false)
    {
    }

    /**
     * Mask an e-mail: "nguyen@gmail.com" → "n***@g***.com".
     */
    public function email(?string $email): ?string
    {
        if ($email === null || $email === '' || $this->reveal) {
            return $email;
        }
        $at = strrpos($email, '@');
        if ($at === false) {
            return '***';
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $dot = strrpos($domain, '.');
        $tld = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 1) . '***@' . mb_substr($domain, 0, 1) . '***' . $tld;
    }

    /**
     * Mask a phone number, keeping the last three digits: "0901234789" → "***789".
     */
    public function phone(?string $phone): ?string
    {
        if ($phone === null || $phone === '' || $this->reveal) {
            return $phone;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return '***' . substr($digits, -3);
    }

    /**
     * Hide an address line entirely.
     */
    public function address(?string $value): ?string
    {
        if ($value === null || $value === '' || $this->reveal) {
            return $value;
        }

        return '***';
    }

    /**
     * Scrub free text for logs: e-mails are masked and runs of 6+ digits replaced.
     * Always applied, whatever the reveal setting (logs never keep raw contact details).
     */
    public static function scrub(string $text): string
    {
        $masker = new self(false);
        $text = (string) preg_replace_callback(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            static fn (array $m): string => (string) $masker->email($m[0]),
            $text
        );

        return (string) preg_replace('/\d{6,}/', '***', $text);
    }
}
