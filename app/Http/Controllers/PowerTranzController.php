<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Traits\Processor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PowerTranz\PowerTranzClient;
use PowerTranz\PowerTranzConfig;
use PowerTranz\Request\Beans\ExtendedRequestData;
use PowerTranz\Request\Beans\HostedPageRequestData;
use PowerTranz\Request\SaleRequest;
use PowerTranz\Support\RedirectDataRenderer;
use Throwable;

class PowerTranzController extends Controller
{
    use Processor;

    private $config;
    private PaymentRequest $payment;
    private $user;

    /**
     * ISO 4217 alpha -> numeric map for the currencies PowerTranz supports.
     * PowerTranz requests require the 3-digit numeric code (e.g. USD = 840).
     * Covers Central America, the Caribbean and the major settlement currencies.
     */
    private const CURRENCY_MAP = [
        // Major / settlement
        'USD' => '840',
        'EUR' => '978',
        'GBP' => '826',
        'CAD' => '124',
        // Central America
        'GTQ' => '320', // Guatemalan Quetzal
        'HNL' => '340', // Honduran Lempira
        'NIO' => '558', // Nicaraguan Córdoba
        'CRC' => '188', // Costa Rican Colón
        'PAB' => '590', // Panamanian Balboa
        'SVC' => '222', // Salvadoran Colón
        'BZD' => '084', // Belize Dollar
        // Caribbean
        'JMD' => '388', // Jamaican Dollar
        'TTD' => '780', // Trinidad and Tobago Dollar
        'BBD' => '052', // Barbadian Dollar
        'BSD' => '044', // Bahamian Dollar
        'KYD' => '136', // Cayman Islands Dollar
        'XCD' => '951', // Eastern Caribbean Dollar
        'HTG' => '332', // Haitian Gourde
        'DOP' => '214', // Dominican Peso
        'AWG' => '533', // Aruban Florin
        'ANG' => '532', // Netherlands Antillean Guilder
        // South America
        'ARS' => '032', // Argentine Peso
        'BOB' => '068', // Bolivian Boliviano
        'BRL' => '986', // Brazilian Real
        'CLP' => '152', // Chilean Peso
        'COP' => '170', // Colombian Peso
        'PYG' => '600', // Paraguayan Guaraní
        'PEN' => '604', // Peruvian Sol
        'UYU' => '858', // Uruguayan Peso
        'VES' => '928', // Venezuelan Bolívar
        'GYD' => '328', // Guyanese Dollar
        'SRD' => '968', // Surinamese Dollar
    ];

    public function __construct(PaymentRequest $payment, User $user)
    {
        $config = $this->payment_config('power_tranz', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config = json_decode($config->test_values);
        }
        $this->payment = $payment;
        $this->user = $user;
    }

    /**
     * Start the PowerTranz SPI (Hosted Payment Page) flow. Builds a SaleRequest,
     * runs the SPI pre-processing step and renders the returned HPP iframe.
     */
    public function pay(Request $request): Application|Factory|View|JsonResponse|RedirectResponse|Redirector
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

        if (empty($this->config) || empty($this->config->power_id) || empty($this->config->power_password)) {
            Log::error('PowerTranz: missing credentials (power_id/power_password) in payment config.', [
                'payment_id' => $data->id,
            ]);
            return $this->payment_failed($data);
        }

        $currency = self::CURRENCY_MAP[strtoupper((string)$data->currency_code)] ?? null;
        if ($currency === null) {
            Log::error('PowerTranz: unsupported currency.', [
                'payment_id' => $data->id,
                'currency' => $data->currency_code,
            ]);
            return $this->payment_failed($data);
        }

        $payer = json_decode($data->payer_information);

        try {
            $client = new PowerTranzClient($this->build_config());

            $extendedData = new ExtendedRequestData();
            $extendedData->setMerchantResponseUrl(route('powertranz.callback', ['payment_id' => $data->id]));
            $extendedData->setHostedPage(new HostedPageRequestData(
                $this->config->page_set,
                $this->config->page_name
            ));

            $sale = new SaleRequest(round((float)$data->payment_amount, 2), $currency);
            $sale->setThreeDSecure(true)
                ->setFraudCheck(true)
                ->setOrderIdentifier($data->id)
                ->setExtendedData($extendedData);

            if (!empty($payer?->email)) {
                $sale->setEmail($payer->email);
            }

            $response = $client->sale($sale, $data->id);
        } catch (Throwable $exception) {
            Log::error('PowerTranz sale() failed.', [
                'payment_id' => $data->id,
                'message' => $exception->getMessage(),
            ]);
            return $this->payment_failed($data);
        }

        if ($response->isSpiPreprocessingComplete() && $response->hasRedirectData()) {
            $iframe = RedirectDataRenderer::render($response);
            return view('payment-views.powertranz', compact('iframe'));
        }

        Log::error('PowerTranz sale() did not return a hosted page.', [
            'payment_id' => $data->id,
            'iso_code' => $response->isoResponseCodeRaw,
            'message' => $response->responseMessage,
            'errors' => $response->errors,
        ]);
        return $this->payment_failed($data);
    }

    /**
     * MerchantResponseUrl endpoint. PowerTranz POSTs the SPI result here (inside
     * the HPP iframe). We complete the payment with the returned SpiToken and
     * break the browser out of the iframe onto the success/failure page.
     */
    public function callback(Request $request): Response|RedirectResponse
    {
        $payment_id = $request->input('payment_id');
        $data = $payment_id ? $this->payment::where(['id' => $payment_id])->first() : null;

        try {
            $client = new PowerTranzClient($this->build_config());
            $callback = $client->parseCallback(file_get_contents('php://input') ?: '{}');

            if (is_null($data) && $callback->orderIdentifier) {
                $data = $this->payment::where(['id' => $callback->orderIdentifier])->first();
            }

            if ($callback->canProceedToPayment()) {
                $final = $client->completePayment($callback->spiToken);

                if (isset($data) && $final->isApproved()) {
                    $this->payment::where(['id' => $data->id])->update([
                        'payment_method' => 'power_tranz',
                        'is_paid' => 1,
                        'transaction_id' => $final->transactionIdentifier,
                    ]);
                    $data = $this->payment::where(['id' => $data->id])->first();
                    if (function_exists($data->success_hook)) {
                        call_user_func($data->success_hook, $data);
                    }
                    return $this->break_out($data, 'success');
                }

                Log::warning('PowerTranz callback: payment not approved.', [
                    'payment_id' => $payment_id,
                    'iso_code' => $final->isoResponseCodeRaw,
                    'message' => $final->responseMessage,
                ]);
            } else {
                Log::warning('PowerTranz callback: cannot proceed to payment.', [
                    'payment_id' => $payment_id,
                    'iso_code' => $callback->isoResponseCodeRaw,
                    'message' => $callback->responseMessage,
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('PowerTranz callback failed.', [
                'payment_id' => $payment_id,
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->break_out($data, 'fail');
    }

    private function build_config(): PowerTranzConfig
    {
        $sandbox = (($this->config->mode ?? 'test') !== 'live');

        return new PowerTranzConfig(
            powerId: (string)$this->config->power_id,
            powerPassword: (string)$this->config->power_password,
            sandbox: $sandbox,
            gatewayKey: !empty($this->config->gateway_key) ? (string)$this->config->gateway_key : null,
        );
    }

    /**
     * Resolve the final redirect target and break out of the HPP iframe onto it.
     */
    private function break_out($data, string $flag): Response|RedirectResponse
    {
        $redirect = $this->payment_response($data, $flag);
        if ($redirect instanceof RedirectResponse) {
            return response(RedirectDataRenderer::merchantResponseRedirectScript($redirect->getTargetUrl()));
        }
        return $redirect;
    }

    private function payment_failed($data): Application|JsonResponse|Redirector|RedirectResponse
    {
        if (isset($data) && function_exists($data->failure_hook)) {
            call_user_func($data->failure_hook, $data);
        }
        return $this->payment_response($data, 'fail');
    }
}
