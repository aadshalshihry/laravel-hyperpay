<?php

namespace AadshalshihryLaravelHyperpay;

use AadshalshihryLaravelHyperpay\Contracts\BillingInterface;
use AadshalshihryLaravelHyperpay\Contracts\Hyperpay;
use AadshalshihryLaravelHyperpay\Support\HttpClient;
use AadshalshihryLaravelHyperpay\Support\HttpParameters;
use AadshalshihryLaravelHyperpay\Support\HttpResponse;
use AadshalshihryLaravelHyperpay\Support\TransactionBuilder;
use AadshalshihryLaravelHyperpay\Traits\ManageUserTransactions;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LaravelHyperpay implements Hyperpay
{
    use ManageUserTransactions;

    /** @var GuzzleClient */
    protected $client;

    /**
     * @var BillingInterface
     */
    protected $billing = [];

    /**
     * @var string token
     */
    protected $token;

    /**
     * @var string brand
     */
    protected $brand;

    /**
     * @var string redirect_url
     */
    protected $redirect_url;

    /**
     * @var string hyperpay host
     */
    protected $gateway_url = 'https://test.oppwa.com';

    /**
     * @var bool demand to register the user card
     */
    protected $register_user_card = false;

    /**
     * Create a new manager instance.
     *
     * @param  \GuzzleHttp\Client as GuzzleClient  $client
     * @return void
     */
    public function __construct(GuzzleClient $client)
    {
        $this->client = $client;
        $this->config = config('hyperpay');
        if (! config('hyperpay.sandboxMode')) {
            $this->gateway_url = config('hyperpay.productionURL');
        }
    }

    /**
     * Set the mada entityId in the parameters that used to prepare the checkout.
     *
     * @return void
     */
    public function mada()
    {
        $this->config['entityId'] = config('hyperpay.entityIdMada');
    }

    /**
     * Set the apple pay entityId in the parameters that used to prepare the checkout.
     *
     * @return void
     */
    public function setApplePayEntityId()
    {
        $this->config['entityId'] = config('hyperpay.entityIdApplePay');
    }

    /**
     * Add billing data to the payment body.
     *
     * @param  BillingInterface  $billing;
     *
     * return $this
     */
    public function addBilling(BillingInterface $billing)
    {
        $this->billing = $billing;

        return $this;
    }

    /**
     * Prepare the checkout.
     *
     * @param  array  $trackable_data
     * @param  Model  $user
     * @param  float  $amount
     * @param  string  $brand
     * @param  Request  $request
     * @return \GuzzleHttp\Psr7\Response
     */
    public function checkout(array $trackable_data, Model $user, $amount, $brand, Request $request)
    {
        $this->brand = $brand;

        if (strtolower($this->brand) == 'mada') {
            $this->mada();
        }

        if (strtolower($this->brand) == 'applepay') {
            $this->setApplePayEntityId();
        }

        $trackable_data = array_merge($trackable_data, [
            'amount' => $amount,
        ]);

        return $this->prepareCheckout($user, $trackable_data, $request);
    }

    /**
     * Define the data used to generate a successful
     * response from hyperpay to generate the payment form.
     *
     * @param  Model  $user
     * @param  array  $trackable_data
     * @param  Request  $request
     * @return \GuzzleHttp\Psr7\Response
     */
    protected function prepareCheckout(Model $user, array $trackable_data, $request)
    {
        $this->token = $this->generateToken();
        $this->config['merchantTransactionId'] = $this->token;
        $this->config['userAgent'] = $request->server('HTTP_USER_AGENT');
        $result = (new HttpClient($this->client, $this->gateway_url.'/v1/checkouts', $this->config))->post(
            $parameters = (new HttpParameters())->postParams(Arr::get($trackable_data, 'amount'), $user, $this->config, $this->billing, $this->register_user_card)
        );

        $response = (new HttpResponse($result, null, $parameters))
            ->setUser($user)
            ->setTrackableData($trackable_data)
            ->addScriptUrl($this->gateway_url)
            ->addShopperResultUrl($this->redirect_url)
            ->prepareCheckout();

        return $response;
    }

    /**
     * Check the payment status using $resourcePath and $checkout_id.
     *
     * @param  string  $resourcePath
     * @param  string  $checkout_id
     * @return \GuzzleHttp\Psr7\Response
     */
    public function paymentStatus(string $resourcePath, string $checkout_id)
    {
        $result = (new HttpClient($this->client, $this->gateway_url.$resourcePath, $this->config))->get(
            (new HttpParameters())->getParams($checkout_id),
        );

        $response = (new HttpResponse(
            $result,
            (new TransactionBuilder())->findByIdOrCheckoutId($checkout_id),
        ))->paymentStatus();

        return $response;
    }

    public function recurringPayment(string $registration_id, $amount, $checkout_id)
    {
        $result = (new HttpClient($this->client, $this->gateway_url.'/v1/registrations/'.$registration_id.'/payments', $this->config))->post(
            (new HttpParameters())->postRecurringPayment($amount, $this->redirect_url, $checkout_id),
        );

        $response = (new HttpResponse($result, null, []))->recurringPayment();

        return $response;
    }

    /**
     * Add merchantTransactionId.
     *
     * @param  string  $id
     * @return $this
     */
    public function addMerchantTransactionId($id)
    {
        $this->token = $id;

        return $this;
    }

    /**
     * Add redirection url to the shopper to finalize the payment.
     *
     * @param  string  $url
     * @return $this
     */
    public function addRedirectUrl($url)
    {
        $this->redirect_url = $url;

        return $this;
    }

    /**
     * Set the register user card information, to use it when we prepare the checkout.
     *
     * @return $this
     */
    public function registerUserCard()
    {
        $this->register_user_card = true;

        return $this;
    }

    /**
     * Generate the token that used as merchantTransactionId to generate the payment form.
     *
     * @return string
     */
    private function generateToken()
    {
        return ($this->token) ?: Str::random('64');
    }
}
