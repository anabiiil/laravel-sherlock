<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications\Mail;

use Anabil\Tracing\Notifications\Formatters\MailFormatter;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Mailable;
use Illuminate\View\Factory as ConcreteViewFactory;
use Symfony\Component\Mime\Email;

/**
 * HTML + plain-text issue notification. Sent synchronously from inside the queued
 * notification job (never queued again).
 *
 * The package views ("tracing::mail.*", overridable via vendor:publish --tag=tracing-views)
 * are resolved through the view finder and rendered here, then attached as the HTML body
 * and the plain-text part.
 */
final class IssueNotificationMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $payload  MailFormatter::data()
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly array $payload,
        public readonly string $htmlTemplate = MailFormatter::HTML_VIEW,
        public readonly string $textTemplate = MailFormatter::TEXT_VIEW,
    ) {}

    public function build(): self
    {
        $views = Container::getInstance()->make(ViewFactory::class);
        $text = $this->renderTemplate($views, $this->textTemplate);

        return $this->subject($this->subjectLine)
            ->html($this->renderTemplate($views, $this->htmlTemplate))
            ->withSymfonyMessage(static function (Email $email) use ($text): void {
                $email->text($text);
            });
    }

    private function renderTemplate(ViewFactory $views, string $name): string
    {
        $path = $views instanceof ConcreteViewFactory ? $views->getFinder()->find($name) : $name;

        return $views->file($path, $this->payload)->render();
    }
}
