<?php
/**
 * ============================================================================
 *  درگاه پرداخت زرین‌پال — REST API v4
 *  مستندات: https://docs.zarinpal.com/paymentGateway/
 *  حالت Sandbox برای تست بدون پول واقعی
 * ============================================================================
 */
declare(strict_types=1);

final class Zarinpal
{
    private string $merchant;
    private bool   $sandbox;

    public function __construct()
    {
        $this->merchant = (string)DB::setting('zarinpal_merchant', '');
        $this->sandbox  = DB::setting('zarinpal_sandbox', '1') === '1';
    }

    private function base(): string
    {
        return $this->sandbox ? 'https://sandbox.zarinpal.com/pg/v4/payment'
                            : 'https://payment.zarinpal.com/pg/v4/payment';
    }
    private function gatewayUrl(string $authority): string
    {
        return ($this->sandbox ? 'https://sandbox.zarinpal.com/pg/StartPay/'
                              : 'https://payment.zarinpal.com/pg/StartPay/') . $authority;
    }

    /** فراخوانی POST روی REST */
    private function call(string $endpoint, array $payload): array
    {
        $ch = curl_init($this->base() . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_CONNECTTIMEOUT => 8,                       // اتصال: حداکثر ۸ ثانیه (در نبود شبکه هنگ نمی‌کند)
            CURLOPT_TIMEOUT        => 20,                      // کل درخواست: حداکثر ۲۰ ثانیه
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($raw === false || $raw === '' || $raw === null) {
            // متن خطای cURL برای لاگ سرور؛ پیام کاربرپسند به فراخواننده برمی‌گردد
            return ['_curl_error' => $errno ? ("cURL #{$errno}: " . $err) : 'پاسخ خالی از درگاه'];
        }
        return json_decode($raw, true) ?: ['_curl_error' => 'پاسخ درگاه قابل فهم نیست'];
    }

    /**
     * ثبت درخواست پرداخت و گرفتن لینک هدایت
     * @return array ['ok'=>bool, 'url'=>string, 'authority'=>string, 'message'=>string]
     */
    public function request(int $orderId, int $amountToman, string $description, string $mobile = ''): array
    {
        $res = $this->call('/request.json', [
            'merchant_id'  => $this->merchant,
            'amount'       => $amountToman * 10,       // تومان → ریال
            'description'  => $description,
            'callback_url' => SITE_URL . '/api/zarinpal-callback.php',
            'metadata'     => $mobile ? ['mobile' => $mobile] : new stdClass(),
        ]);

        $data   = $res['data'] ?? [];
        $errors = $res['errors'] ?? [];
        if (($data['code'] ?? 0) === 100 && !empty($data['authority'])) {
            return ['ok'=>true, 'url'=>$this->gatewayUrl($data['authority']),
                    'authority'=>$data['authority'], 'message'=>''];
        }
        if (!empty($res['_curl_error'])) {                   // قطعی شبکه/درگاه با جزئیات فنی در لاگ
            error_log('Feria Zarinpal request: ' . $res['_curl_error']);
            $msg = 'اتصال به درگاه پرداخت برقرار نشد؛ ارتباط اینترنت سرور یا وضعیت زرین‌پال را بررسی کنید.';
        } else {
            $msg = is_array($errors) ? (string)($errors['message'] ?? 'خطای نامشخص از درگاه') : 'خطای نامشخص از درگاه';
        }
        return ['ok'=>false, 'url'=>'', 'authority'=>'', 'message'=>$msg];
    }

    /**
     * تأیید پرداخت پس از بازگشت کاربر (Verify)
     * @return array ['ok'=>bool, 'ref_id'=>?, 'card_pan'=>?, 'message'=>string]
     */
    public function verify(string $authority, int $amountToman): array
    {
        $res = $this->call('/verify.json', [
            'merchant_id' => $this->merchant,
            'amount'      => $amountToman * 10,
            'authority'   => $authority,
        ]);
        $data   = $res['data'] ?? [];
        $errors = $res['errors'] ?? [];
        if (in_array($data['code'] ?? 0, [100, 101], true)) {  // 101 = قبلاً تایید شده
            return ['ok'=>true, 'ref_id'=>(string)($data['ref_id'] ?? ''),
                    'card_pan'=>($data['card_pan'] ?? null), 'message'=>''];
        }
        if (!empty($res['_curl_error'])) {
            error_log('Feria Zarinpal verify: ' . $res['_curl_error'] . ' authority=' . $authority);
            $msg = 'تأیید پرداخت به دلیل قطعی ارتباط با درگاه انجام نشد؛ دربارهٔ برگشت وجه با پشتیبانی در تماس باشید.';
        } else {
            $msg = is_array($errors) ? (string)($errors['message'] ?? 'تأیید پرداخت ناموفق بود') : 'تأیید پرداخت ناموفق بود';
        }
        return ['ok'=>false, 'ref_id'=>null, 'card_pan'=>null, 'message'=>$msg];
    }
}
