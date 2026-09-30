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

namespace digitalastronaut\craftqrpayments\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Response;
use craft\web\UploadedFile;

use digitalastronaut\craftqrpayments\parsers\TransactionsExportParser;
use digitalastronaut\craftqrpayments\parsers\TransactionsExportParserFactory;

use Throwable;

/**
 * Class ProcessController
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.4.0-beta
 */
class ProcessController extends Controller {
    private const string SESSION_KEY = 'qrPayments.bankImport';

    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @return Response
     */
    public function actionIndex(): Response {
        $this->requirePermission('qr-payments:access-payments');

        if ($this->request->getIsPost()) {
            return $this->processUpload();
        }

        $state = $this->sessionState();
        $filename = $state['activeFilename'];
        $import = $filename !== null ? ($state['imports'][$filename] ?? null) : null;

        return $this->renderTemplate('qr-payments/pages/_process.twig', [
            'filename' => $filename,
            'import' => $import,
            'columnOrder' => $filename !== null ? ($state['views'][$filename] ?? null) : null,
            'onlyMatched' => $filename !== null && ($state['onlyMatched'][$filename] ?? false),
            'matches' => $import !== null
                ? TransactionsExportParser::matchPayments($import['rows'], $import['referenceColumn'], $import['amountColumn'], $import['dateColumn'] ?? null)
                : [],
        ]);
    }

    /**
     * @return Response
     */
    public function actionSaveView(): Response {
        $this->requirePermission('qr-payments:access-payments');
        $this->requirePostRequest();

        $filename = (string) $this->request->getRequiredBodyParam('filename');
        $order = array_map('intval', (array) $this->request->getBodyParam('order', []));

        $state = $this->sessionState();
        $state['views'][$filename] = $order;
        $state['onlyMatched'][$filename] = (bool) $this->request->getBodyParam('onlyMatched', false);
        $this->saveSessionState($state);

        return $this->asSuccess(Craft::t('qr-payments', 'View saved.'));
    }

    /**
     * @return Response
     */
    public function actionClear(): Response {
        $this->requirePermission('qr-payments:access-payments');
        $this->requirePostRequest();

        $state = $this->sessionState();
        $state['activeFilename'] = null;
        $this->saveSessionState($state);

        return $this->asSuccess();
    }

    /**
     * @return Response
     */
    public function actionAutoProcess(): Response {
        $this->requirePermission('qr-payments:access-payments');
        $this->requirePostRequest();

        $state = $this->sessionState();
        $filename = $state['activeFilename'];
        $import = $filename !== null ? ($state['imports'][$filename] ?? null) : null;

        if ($import === null) {
            return $this->asFailure(Craft::t('qr-payments', 'Nothing to process.'));
        }

        $result = TransactionsExportParser::applySuggestedActions(
            $import['rows'],
            $import['referenceColumn'],
            $import['amountColumn'],
            $import['dateColumn'] ?? null,
            static::currentUser()?->id,
        );

        if ($result['processed'] === 0 && $result['skipped'] === 0 && $result['failed'] === 0) {
            return $this->asFailure(Craft::t('qr-payments', 'Nothing to process.'));
        }

        $message = Craft::t('qr-payments', '{count} payment(s) processed.', ['count' => $result['processed']]);

        if ($result['skipped'] > 0) {
            $message .= ' ' . Craft::t('qr-payments', '{count} already processed, skipped.', ['count' => $result['skipped']]);
        }

        if ($result['failed'] > 0) {
            $message .= ' ' . Craft::t('qr-payments', '{count} failed.', ['count' => $result['failed']]);
        }

        return $this->asSuccess($message);
    }

    /**
     * @return Response
     */
    private function processUpload(): Response {
        $processUrl = UrlHelper::cpUrl('qr-payments/payments/process');
        $file = UploadedFile::getInstanceByName('file');

        if ($file === null || $file->error !== UPLOAD_ERR_OK) {
            $this->setFailFlash(Craft::t('qr-payments', 'Please choose a file to upload.'));

            return $this->redirect($processUrl);
        }

        try {
            $import = TransactionsExportParserFactory::create($file->getExtension())->parse($file->tempName);
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);

            $this->setFailFlash(Craft::t('qr-payments', 'Couldn’t read that file. Make sure it’s a valid CSV or Excel export.'));

            return $this->redirect($processUrl);
        }

        $filename = $file->name;

        $state = $this->sessionState();
        $state['activeFilename'] = $filename;
        $state['imports'][$filename] = $import;

        if (!isset($state['views'][$filename])) {
            $state['views'][$filename] = array_values(array_filter(
                [$import['amountColumn'], $import['referenceColumn']],
                fn($column) => $column !== null,
            ));
        }

        $this->saveSessionState($state);

        return $this->redirect($processUrl);
    }

    /**
     * @return array{activeFilename: string|null, imports: array<string, array>, views: array<string, int[]>, onlyMatched: array<string, bool>}
     */
    private function sessionState(): array {
        $state = Craft::$app->getSession()->get(self::SESSION_KEY);

        return (\is_array($state) ? $state : []) + [
            'activeFilename' => null,
            'imports' => [],
            'views' => [],
            'onlyMatched' => [],
        ];
    }

    /**
     * @param array $state
     * @return void
     */
    private function saveSessionState(array $state): void {
        Craft::$app->getSession()->set(self::SESSION_KEY, $state);
    }
}
