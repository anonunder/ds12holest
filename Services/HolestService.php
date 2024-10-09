<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use App\Models\Shop\Order;

class HolestPayPaymentService
{
    protected $apiUrl;
    protected $apiKey;
    protected $apiSecret;

    public function __construct()
    {
        // $this->apiUrl = config('services.holestpay.api_url', env('HOLESTPAY_API_URL'));
        // $this->apiKey = config('services.holestpay.api_key', env('HOLESTPAY_API_KEY'));
        // $this->apiSecret = config('services.holestpay.api_secret', env('HOLESTPAY_API_SECRET'));
    }

    public function writePaymentResponseHTML($order_uid, $html)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        if ($order) {
            $order->payment_response_html = $html;
            return $order->save();
        }
        return false;
    }

    public function writeFiscalOrIntegrationResponseHTML($order_uid, $html)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        if ($order) {
            $order->fiskal_response_html = $html;
            return $order->save();
        }
        return false;
    }

    public function getPaymentResponseHTML($order_uid)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        return $order ? $order->payment_response_html : '';
    }

    public function getFiscalOrIntegrationResponseHTML($order_uid)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        return $order ? $order->fiskal_response_html : '';
    }

    public function cacheExchangeRate($from, $to, $rate, $ts = null)
    {
        $timestamp = $ts ?? time();
        $cacheKey = "exchange_rate_{$from}_{$to}";
        $data = [
            'rate' => $rate,
            'ts' => $timestamp,
        ];
        return Cache::put($cacheKey, $data, now()->addHours(24));
    }

    public function readExchangeRate($from, $to)
    {
        $cacheKey = "exchange_rate_{$from}_{$to}";
        return Cache::get($cacheKey, ['rate' => 0, 'ts' => 0]);
    }

    public function writeResultsForOrder($order_uid, $results)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        if ($order) {
            $order->results = json_encode($results);
            return $order->save();
        }
        return false;
    }

    public function appendResultForOrder($order_uid, $result)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        if ($order) {
            $existingResults = json_decode($order->results, true) ?? [];
            $existingResults[] = $result;
            $order->results = json_encode($existingResults);
            return $order->save();
        }
        return false;
    }

    public function lockOrderUpdate($order_uid)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        if ($order && !$order->locked) {
            $order->locked = true;
            return $order->save();
        }
        return false;
    }

    public function unlockOrderUpdate($order_uid)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        if ($order && $order->locked) {
            $order->locked = false;
            return $order->save();
        }
        return false;
    }

    public function resultAlreadyReceived($result_md5_hash)
    {
        $possibleStatuses = [
            'SUCCESS',
            'PAID',
            'AWAITING',
            'REFUNDED',
            'PARTIALLY-REFUNDED',
            'VOID',
            'RESERVED',
            'EXPIRED',
            'OBLIGATED',
            'REFUSED',
            'PREPARING',
            'READY',
            'SUBMITTED',
            'DELIVERY',
            'DELIVERED',
            'ERROR',
            'RESOLVING',
            'FAILED',
            'CANCELED'
        ];
        return \DB::table('received_hashes')->where('hash', $result_md5_hash)->whereIn('status', $possibleStatuses)->exists();
    }

    public function getOrderHPayStatus($order_uid, $as_array = false)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        if (!$order) {
            return $as_array ? [] : '';
        }

        $status = [
            'PAYMENT' => $order->status,
            'FISCAL' => $order->fiskal_response_html,
            'SHIPPING' => $order->shipping_response_html,
        ];

        return $as_array ? $status : json_encode($status);
    }

    public function setOrderHPayStatus($order_uid, $hpay_status)
    {
        $order = Order::where('order_hash', $order_uid)->first();
        if ($order) {
            if (is_array($hpay_status)) {
                foreach ($hpay_status as $key => $value) {
                    match (strtoupper($key)) {
                        'PAYMENT' => $order->status = $value,
                        'FISCAL' => $order->fiskal_response_html = $value,
                        'SHIPPING' => $order->shipping_response_html = $value,
                    };
                }
            } else {
                $order->hpay_status = $hpay_status;
            }
            return $order->save();
        }
        return false;
    }

    public function getHOrder(Order $order)
    {
        $pay_request = [
            "merchant_site_uid" => $this->apiKey,
            "order_uid"         => $order->order_hash,
            "order_name"        => $order->name,
            "order_amount"      => $order->total_price,
            "order_currency"    => "RSD",
            "order_items"       => $this->getOrderItems($order),
            "order_billing" => [
                "email"           => $order->email,
                "first_name"      => $order->name,
                "phone"           => $order->phone_number,
                "company"         => $order->company_name,
                "company_tax_id"  => $order->pib,
                "company_reg_id"  => $order->mb,
                "address"         => $order->address,
                "city"            => $order->city,
                "country"         => $order->country,
                "postcode"        => $order->postal_code,
                "lang"            => app()->getLocale(),
            ],
            "order_shipping" => null,
            "order_sitedata" => [
                "id"                 => $order->id,
                "customer_id"        => $order->user_id,
                "payment_method_id"  => null,
                "shipping_method_id" => null,
            ],
            "order_user_url"   => route('payment.callback', ['order' => $order->id]),
            "vault_token_uid"  => "",
            "cof"              => "none",
            "payment_method"   => null,
            "shipping_method"  => null,
            "notify_url"       => route('payment.notify'),
            "verificationhash" => md5($order->order_hash),
            "hpaylang"         => strtolower(app()->getLocale()),
        ];

        return $pay_request;
    }

    public function getHCart($order_uid_or_site_order_or_site_cart)
    {
        return $this->getHOrder($order_uid_or_site_order_or_site_cart);
    }

    public function getLanguage()
    {
        return Session::get('locale', 'en');
    }

    public function loadPOSConfiguration()
    {
        return Cache::get('pos_configuration', []);
    }

    public function setPOSConfiguration($pos_configuration)
    {
        $configuration = is_string($pos_configuration) ? json_decode($pos_configuration, true) : $pos_configuration;
        Cache::put('pos_configuration', $configuration, now()->addHours(24));
        return $configuration;
    }

    public function createPayment(Order $order) {}

    protected function getOrderItems(Order $order)
{
    return $order->items->map(function ($item) {
        return [
            "posuid"        => $item->product_id,
            "type"          => "product",
            "name"          => $item->product->name ?? 'Unknown', 
            "sku"           => $item->product->sku ?? 'N/A',      
            "qty"           => $item->quantity,
            "price"         => floatval($item->price),
            "subtotal"      => floatval($item->price * $item->quantity),
            "refunded"      => 0,
            "refunded_qty"  => 0,
            "tax_label"     => $item->tax_class ?? 'Standard',   
            "virtual"       => false,
        ];
    })->toArray();
}

    protected function getSetting($key, $default = null) {}
}
