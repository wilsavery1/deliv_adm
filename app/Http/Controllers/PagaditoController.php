<?php

namespace App\Http\Controllers;

use App\Library\Pagadito\Pagadito;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Traits\Processor;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PagaditoController extends Controller
{
    use Processor;

    private $config;
    private PaymentRequest $payment;
    private $user;

    public function __construct(PaymentRequest $payment, User $user)
    {
        $config = $this->payment_config('pagadito', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config = json_decode($config->test_values);
        }
        $this->payment = $payment;
        $this->user = $user;
    }

    public function pay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        if (empty($this->config) || empty($this->config->uid) || empty($this->config->wsk)) {
            Log::error('Pagadito: missing credentials (uid/wsk) in payment config.', [
                'payment_id' => $data->id,
            ]);
            return $this->payment_failed($data);
        }

        $sandbox = (($this->config->mode ?? 'test') !== 'live');
        $pagadito = new Pagadito($this->config->uid, $this->config->wsk);
        // 'test' credentials run against the Pagadito sandbox endpoint
        if ($sandbox) {
            $pagadito->mode_sandbox_on();
        }
        $pagadito->change_format_json();
        $this->set_currency($pagadito, $data->currency_code);

        // Pagadito derives the charge amount from the sum of details (quantity * price)
        $pagadito->add_detail(1, 'Order #' . $data->attribute_id, (float)$data->payment_amount);

        if (!$pagadito->connect()) {
            Log::error('Pagadito connect() failed.', [
                'sandbox' => $sandbox,
                'rs_code' => $pagadito->get_rs_code(),
                'rs_message' => $pagadito->get_rs_message(),
                'payment_id' => $data->id,
            ]);
            return $this->payment_failed($data);
        }

        // ERN = our PaymentRequest UUID -> returned as reference on get_status()
        $url = $pagadito->exec_trans_url($data->id);
        if ($url === false) {
            Log::error('Pagadito exec_trans() failed.', [
                'sandbox' => $sandbox,
                'rs_code' => $pagadito->get_rs_code(),
                'rs_message' => $pagadito->get_rs_message(),
                'ern' => $data->id,
                'amount' => (float)$data->payment_amount,
                'currency' => $data->currency_code,
            ]);
            return $this->payment_failed($data);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): Application|JsonResponse|Redirector|RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        // Pagadito replaces {value} with the transaction token and {ern_value}
        // with our ERN (= payment id) in the return URL configured in the panel.
        $token = $request->input('token');
        $payment_id = $request->input('ern');

        $pagadito = new Pagadito($this->config->uid, $this->config->wsk);
        if (($this->config->mode ?? 'test') !== 'live') {
            $pagadito->mode_sandbox_on();
        }
        $pagadito->change_format_json();

        $data = $payment_id ? $this->payment::where(['id' => $payment_id])->first() : null;

        if ($token && $pagadito->connect() && $pagadito->get_status($token)) {
            if (isset($data) && $pagadito->get_rs_status() === 'COMPLETED') {
                $this->payment::where(['id' => $data->id])->update([
                    'payment_method' => 'pagadito',
                    'is_paid' => 1,
                    'transaction_id' => $pagadito->get_rs_reference() ?: $token,
                ]);
                $data = $this->payment::where(['id' => $data->id])->first();
                if (function_exists($data->success_hook)) {
                    call_user_func($data->success_hook, $data);
                }
                return $this->payment_response($data, 'success');
            }

            Log::warning('Pagadito callback: transaction not completed.', [
                'token' => $token,
                'reference' => $payment_id,
                'rs_status' => $pagadito->get_rs_status(),
            ]);
            return $this->payment_failed($data);
        }

        Log::error('Pagadito callback: unable to verify transaction status.', [
            'token' => $token,
            'rs_code' => $pagadito->get_rs_code(),
            'rs_message' => $pagadito->get_rs_message(),
        ]);
        return $this->payment_failed(null);
    }

    private function payment_failed($data): Application|JsonResponse|Redirector|RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        if (isset($data) && function_exists($data->failure_hook)) {
            call_user_func($data->failure_hook, $data);
        }
        return $this->payment_response($data, 'fail');
    }

    private function set_currency(Pagadito $pagadito, ?string $currency_code): void
    {
        switch (strtoupper((string)$currency_code)) {
            case 'GTQ':
                $pagadito->change_currency_gtq();
                break;
            case 'HNL':
                $pagadito->change_currency_hnl();
                break;
            case 'NIO':
                $pagadito->change_currency_nio();
                break;
            case 'CRC':
                $pagadito->change_currency_crc();
                break;
            case 'PAB':
                $pagadito->change_currency_pab();
                break;
            case 'DOP':
                $pagadito->change_currency_dop();
                break;
            case 'USD':
            default:
                $pagadito->change_currency_usd();
                break;
        }
    }
}
