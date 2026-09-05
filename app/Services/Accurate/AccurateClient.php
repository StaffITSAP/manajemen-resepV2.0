<?php

namespace App\Services\Accurate;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class AccurateClient
{
    private Client $http;
    private string $base;

    // default page size kecil agar stabil
    public const DEFAULT_PAGE_SIZE = 100;

    public function __construct()
    {
        $this->base = rtrim((string) config('accurate.base_url'), '/') . '/';

        $verify = config('accurate.verify_ssl');
        if ($verify && ($ca = config('accurate.ca_path'))) {
            $verify = $ca;
        }

        $this->http = new Client([
            'base_uri'    => $this->base,
            'http_errors' => false,
            'timeout'     => (float) (config('accurate.timeout', 120)), // total timeout
            'verify'      => $verify,
            // Anda bisa menambah 'connect_timeout' => 30 jika perlu
        ]);
    }

    private function defaultHeaders(): array
    {
        $tz  = config('accurate.tz');
        $ts  = Signature::makeTimestamp($tz);
        $sig = Signature::hmac($ts, (string) config('accurate.secret'));

        return [
            'Authorization'   => 'Bearer ' . config('accurate.token'),
            'X-Api-AppKey'    => (string) config('accurate.app_key'),
            'X-Api-Timestamp' => $ts,
            'X-Api-Signature' => $sig,
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
        ];
    }

    /**
     * Request wrapper + retry ringan untuk 429/5xx.
     *
     * @param string $method
     * @param string $path
     * @param array  $query
     * @param array|null $jsonBody   // <--- TAMBAH
     * @param int   $retries
     */
    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null, int $retries = 2): array
    {
        $attempt = 0;
        $delayMs = 500;

        do {
            try {
                $options = [
                    'headers' => $this->defaultHeaders(),
                    'query'   => $query,
                ];
                if ($jsonBody !== null) {
                    $options['json'] = $jsonBody;
                }

                $res = $this->http->request($method, $path, $options);

                $status = $res->getStatusCode();
                $raw    = (string) $res->getBody();
                $json   = json_decode($raw, true);

                Log::debug('[Accurate] ' . $method . ' ' . $path, [
                    'code'  => $status,
                    'query' => $query,
                    'json'  => $jsonBody,
                    'body'  => $json ?? $raw,
                ]);

                $ok = $status >= 200 && $status < 300;

                if (! $ok && (($status === 429) || ($status >= 500))) {
                    $attempt++;
                    if ($attempt <= $retries) {
                        usleep($delayMs * 1000);
                        $delayMs *= 2;
                        continue;
                    }
                }

                return [
                    'status' => $status,
                    'ok'     => $ok,
                    'body'   => $json ?? $raw,
                ];
            } catch (GuzzleException $e) {
                $attempt++;
                if ($attempt > $retries) {
                    return [
                        'status' => 0,
                        'ok'     => false,
                        'body'   => ['error' => 'HTTP_ERROR', 'message' => $e->getMessage()],
                    ];
                }
                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        } while ($attempt <= $retries);

        return [
            'status' => 0,
            'ok'     => false,
            'body'   => ['error' => 'UNKNOWN', 'message' => 'Unknown error'],
        ];
    }

    /** Shortcut POST JSON */
    public function postJson(string $path, array $body = [], array $query = []): array
    {
        return $this->request('POST', $path, $query, $body);
    }

    /** Transaction create POST without automatic retry to avoid duplicate documents. */
    public function postJsonWithoutRetry(string $path, array $body = [], array $query = []): array
    {
        return $this->request('POST', $path, $query, $body, 0);
    }

    /** Shortcut GET */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * Daftar Job Order
     */
    public function listJobOrders(int $page = 1, ?int $pageSize = null): array
    {
        $pageSize = $pageSize ?: (int) (config('accurate.page_size', self::DEFAULT_PAGE_SIZE));
        return $this->get('job-order/list.do', [
            'sp.page'     => $page,
            'sp.pageSize' => $pageSize,
        ]);
    }

    /**
     * Detail Job Order
     */
    public function detailJobOrder(int $id): array
    {
        return $this->get('job-order/detail.do', ['id' => $id]);
    }

    /**
     * Detail Item Accurate.
     * Endpoint: item/detail.do
     */
    public function detailItemById(int|string $accurateItemId): array
    {
        return $this->get('item/detail.do', ['id' => $accurateItemId]);
    }

    /**
     * Daftar Purchase Order.
     * Endpoint read-only: purchase-order/list.do
     */
    public function listPurchaseOrders(array $params = []): array
    {
        return $this->get('purchase-order/list.do', $params);
    }

    /**
     * Detail Purchase Order.
     * Endpoint read-only: purchase-order/detail.do
     */
    public function detailPurchaseOrder(int|string $id): array
    {
        return $this->get('purchase-order/detail.do', ['id' => $id]);
    }

    /** Daftar Purchase Invoice (read-only). */
    public function listPurchaseInvoices(array $params = []): array
    {
        return $this->get('purchase-invoice/list.do', $params);
    }

    /** Detail Purchase Invoice (read-only). */
    public function detailPurchaseInvoice(int|string $id): array
    {
        return $this->get('purchase-invoice/detail.do', ['id' => $id]);
    }

    /**
     * Simpan Item Adjustment
     * Endpoint: item-adjustment/save.do
     */
    public function itemAdjustmentSave(array $payload): array
    {
        return $this->postJson('item-adjustment/save.do', $payload);
    }

    /**
     * Simpan Purchase Requisition draft.
     * Endpoint: purchase-requisition/save.do
     */
    public function purchaseRequisitionSaveDraft(array $payload): array
    {
        return $this->postJsonWithoutRetry('purchase-requisition/save.do', $payload);
    }
}
