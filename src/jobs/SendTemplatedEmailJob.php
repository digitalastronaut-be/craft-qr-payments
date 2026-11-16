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

use digitalastronaut\craftqrpayments\QrPayments;

use RuntimeException;
use Throwable;

/**
 * Class SendTemplatedEmailJob
 *
 * Base for every queued email this plugin sends. A concrete job only
 * resolves its own recipient/subject/template/variables from the ids it
 * stores (queue jobs are serialized, so entities are re-fetched on
 * execute() rather than carried across the queue) — rendering and sending
 * are handled here, once, along with logging every attempt (success or
 * failure) to `EmailLogService` — see that class and `getLogContext()`
 * below.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.2.0-beta
 */
abstract class SendTemplatedEmailJob extends BaseJob {
    /**
     * Set only on a manual resend (`EmailLogService::resend()`), to
     * distinguish it in the log from the original automatic send. Every
     * concrete job's `__sleep()` must include this alongside its own
     * properties — `Queue::push()` serializes the job to the DB even for
     * an eager `queue->run()` straight after, so without it a resend's
     * attribution would be lost the moment it's queued.
     */
    public ?int $triggeredByUserId = null;

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
     * `paymentId`/`subscriptionId`/`historyEntryId` to store on this
     * send's `EmailLogService` row, when this job has them — a reminder
     * or summary digest overrides only the id it has (if any); a job tied
     * to one logged status-history transition overrides both ids and
     * `historyEntryId`.
     *
     * @return array{paymentId?: int, subscriptionId?: int, historyEntryId?: string|null}
     */
    protected function getLogContext(): array {
        return [];
    }

    /**
     * @param QueueInterface $queue
     * @return void
     */
    public function execute($queue): void {
        $recipient = $this->getRecipient();

        if (!$recipient) throw new RuntimeException('No recipient resolved for "' . $this->getTemplate() . '".');

        $subject = $this->getSubject();

        try {
            $message = Craft::$app->getMailer()->compose();

            $variables = $this->getTemplateVariables();

            foreach ($this->getEmbeddedContent() as $variable => $embed) {
                $variables[$variable] = $message->embedContent($embed['content'], $embed['options'] ?? []);
            }

            $html = Craft::$app->getView()->renderTemplate($this->getTemplate(), $variables, $this->getTemplateMode());

            $message
                ->setTo($recipient)
                ->setSubject($subject)
                ->setHtmlBody($html);

            if ($bcc = $this->getBcc()) $message->setBcc($bcc);

            if (!$message->send()) {
                throw new RuntimeException('Mailer refused to send "' . $this->getTemplate() . '" to ' . $recipient . '.');
            }
        } catch (Throwable $e) {
            $this->log($recipient, $subject, 'failed', $e->getMessage());

            throw $e;
        }

        $this->log($recipient, $subject, 'sent', null);
    }

    /**
     * @param string $recipient
     * @param string $subject
     * @param string $status 'sent' or 'failed'.
     * @param string|null $error
     * @return void
     */
    private function log(string $recipient, string $subject, string $status, ?string $error): void {
        QrPayments::getInstance()->getEmailLog()->log($this->getLogContext() + [
            'jobClass' => static::class,
            'jobParams' => array_intersect_key(get_object_vars($this), array_flip($this->__sleep())),
            'recipient' => $recipient,
            'subject' => $subject,
            'status' => $status,
            'error' => $error,
            'triggeredByUserId' => $this->triggeredByUserId,
        ]);
    }

    /**
     * @return string|null
     */
    protected function defaultDescription(): ?string {
        return Craft::t('qr-payments', 'Sending “{template}” email', ['template' => $this->getTemplate()]);
    }
}
