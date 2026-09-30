<?php
/**
 * QR payments plugin for Craft CMS
 *
 * Generates EPC QR code SEPA payment requests and lets the site owner
 * manually track and reconcile payment status from the control panel.
 *
 * @link      https://digitalastronaut.be
 * @copyright Copyright (c) 2026 Digitalastronaut
 */

namespace digitalastronaut\craftqrpayments\jobs;

use Craft;
use craft\queue\BaseJob;
use craft\queue\QueueInterface;

use RuntimeException;

/**
 * Class SendTemplatedEmailJob
 *
 * Base for every queued email this plugin sends. A concrete job only
 * resolves its own recipient/subject/template/variables from the ids it
 * stores (queue jobs are serialized, so entities are re-fetched on
 * execute() rather than carried across the queue) — rendering and sending
 * are handled here, once.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.2.0-beta
 */
abstract class SendTemplatedEmailJob extends BaseJob {
    /**
     * @return string
     */
    abstract protected function getTemplate(): string;

    /**
     * @return string
     */
    abstract protected function getTemplateMode(): string;

    /**
     * @return array
     */
    abstract protected function getTemplateVariables(): array;

    /**
     * @return string|null
     */
    abstract protected function getRecipient(): ?string;

    /**
     * @return string
     */
    abstract protected function getSubject(): string;

    /**
     * @return string[]
     */
    protected function getBcc(): array {
        return [];
    }

    /**
     * @return array<string, array{content: string, options?: array}>
     */
    protected function getEmbeddedContent(): array {
        return [];
    }

    /**
     * @param QueueInterface $queue
     * @return void
     */
    public function execute($queue): void {
        $recipient = $this->getRecipient();

        if (!$recipient) throw new RuntimeException('No recipient resolved for "' . $this->getTemplate() . '".');

        $message = Craft::$app->getMailer()->compose();

        $variables = $this->getTemplateVariables();

        foreach ($this->getEmbeddedContent() as $variable => $embed) {
            $variables[$variable] = $message->embedContent($embed['content'], $embed['options'] ?? []);
        }

        $html = Craft::$app->getView()->renderTemplate($this->getTemplate(), $variables, $this->getTemplateMode());

        $message
            ->setTo($recipient)
            ->setSubject($this->getSubject())
            ->setHtmlBody($html);

        if ($bcc = $this->getBcc()) $message->setBcc($bcc);

        if (!$message->send()) {
            throw new RuntimeException('Mailer refused to send "' . $this->getTemplate() . '" to ' . $recipient . '.');
        }
    }

    /**
     * @return string|null
     */
    protected function defaultDescription(): ?string {
        return Craft::t('qr-payments', 'Sending “{template}” email', ['template' => $this->getTemplate()]);
    }
}
