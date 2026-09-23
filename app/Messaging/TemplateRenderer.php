<?php

namespace App\Messaging;

use App\Branding\Branding;
use App\Models\MessageTemplate;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

/**
 * Turns a message key plus data into a subject and body. Placeholders look
 * like `{{ name }}`; nothing is evaluated, values are substituted and (for
 * e-mail) HTML-escaped. E-mail bodies are wrapped in the branded layout.
 */
class TemplateRenderer
{
    private const PLACEHOLDER = '/\{\{\s*([a-z_]+)\s*\}\}/i';

    public function __construct(private readonly Branding $branding) {}

    /**
     * @param  array<string, scalar|null>  $data
     */
    public function render(MessageKey $key, string $locale, array $data, bool $withLayout = true): RenderedMessage
    {
        $template = $this->template($key, $locale);
        $subject = $template['subject'] === null ? null : $this->substitute($template['subject'], $data, html: false);
        $body = $this->substitute($template['body'], $data, html: $key->channel() === Channel::Email);

        if ($key->channel() === Channel::Email && $withLayout) {
            $body = $this->wrap($body, $subject);
        }

        return new RenderedMessage($key->channel(), $subject, $body);
    }

    /**
     * Effective subject and body: the stored override, else the code default.
     *
     * @return array{subject: ?string, body: string, customised: bool}
     */
    public function template(MessageKey $key, string $locale): array
    {
        $locale = $key->locale($locale);
        $stored = MessageTemplate::query()->where('key', $key)->where('locale', $locale)->first();

        return [
            'subject' => $stored?->subject ?? $key->defaultSubject($locale),
            'body' => $stored?->body ?? $key->defaultBody($locale),
            'customised' => $stored?->isCustomised() ?? false,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $data
     */
    public function substitute(string $text, array $data, bool $html): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($data, $html): string {
            $value = (string) ($data[strtolower($match[1])] ?? '');

            return $html ? e($value) : $value;
        }, $text);
    }

    /**
     * @return list<string>
     */
    public function placeholdersIn(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * @throws ValidationException
     */
    public function assertPlaceholdersKnown(MessageKey $key, ?string $text, string $field = 'body'): void
    {
        $unknown = array_diff($this->placeholdersIn((string) $text), array_keys($key->placeholders()));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $field => __('Unknown placeholder: :list. Allowed: :allowed', ['list' => implode(', ', $unknown), 'allowed' => implode(', ', array_keys($key->placeholders()))]),
            ]);
        }
    }

    public function wrap(string $bodyHtml, ?string $title = null): string
    {
        return View::make('mail.layout', [
            'body' => $bodyHtml,
            'title' => $title,
            'appName' => $this->branding->appName(),
            'logoUrl' => $this->branding->logoUrl(),
            'palette' => $this->branding->palette(),
            'legalName' => $this->branding->portal()['legal_name'] ?? $this->branding->appName(),
        ])->render();
    }
}
