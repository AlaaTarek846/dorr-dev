<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Wallet\Exceptions\PaymentGatewayException;
use Modules\Wallet\Exceptions\UnsupportedGatewayOperationException;
use Modules\Wallet\Services\Gateways\ArbGateway;
use Modules\Wallet\Services\Gateways\MyFatoorahGateway;
use Modules\Wallet\Services\Gateways\UrPayGateway;
use Modules\Wallet\Services\PaymentGatewayRegistry;
use Modules\Wallet\Support\Payments\GatewayChargeRequest;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The three gateway drivers ported from LeeTaxi (MyFatoorah) and Jawad (ARB,
 * URPay), exercised against faked HTTP — no real gateway is ever contacted.
 */
class PaymentGatewayTest extends TestCase
{
    private function chargeRequest(array $credentials): GatewayChargeRequest
    {
        return new GatewayChargeRequest(
            credentials: $credentials,
            amountMinor: 10050,
            currencyCode: 'SAR',
            customerName: 'Test Customer',
            customerPhone: '966500000001',
            customerEmail: null,
            localReference: 'ref-123',
            successUrl: 'https://dorr.test/success',
            errorUrl: 'https://dorr.test/error',
        );
    }

    // ---------------------------------------------------------------- registry

    public function test_registry_resolves_each_gateway_key_to_its_driver(): void
    {
        $registry = app(PaymentGatewayRegistry::class);

        $this->assertInstanceOf(MyFatoorahGateway::class, $registry->driver('myfatoorah'));
        $this->assertInstanceOf(ArbGateway::class, $registry->driver('arb'));
        $this->assertInstanceOf(UrPayGateway::class, $registry->driver('urpay'));
    }

    public function test_registry_rejects_an_unknown_gateway_key(): void
    {
        $this->expectException(PaymentGatewayException::class);

        app(PaymentGatewayRegistry::class)->driver('does-not-exist');
    }

    // ------------------------------------------------------------- MyFatoorah

    private array $myFatoorah = ['api_url' => 'https://mf.test', 'api_key' => 'secret-key'];

    public function test_myfatoorah_initiate_returns_the_invoice_url_and_sends_our_reference(): void
    {
        Http::fake(['mf.test/v2/SendPayment' => Http::response([
            'IsSuccess' => true,
            'Data' => ['InvoiceURL' => 'https://pay.mf.test/invoice/1'],
        ])]);

        $result = app(MyFatoorahGateway::class)->initiate($this->chargeRequest($this->myFatoorah));

        $this->assertTrue($result->success);
        $this->assertSame('https://pay.mf.test/invoice/1', $result->redirectUrl);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-key')
            && $request['CustomerReference'] === 'ref-123'
            && $request['InvoiceValue'] === 100.5); // 10050 minor units -> 100.50
    }

    public function test_myfatoorah_initiate_surfaces_validation_errors_instead_of_throwing(): void
    {
        Http::fake(['mf.test/*' => Http::response([
            'IsSuccess' => false,
            'ValidationErrors' => [['Name' => 'InvoiceValue', 'Error' => 'must be greater than 0']],
        ])]);

        $result = app(MyFatoorahGateway::class)->initiate($this->chargeRequest($this->myFatoorah));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('InvoiceValue', (string) $result->errorMessage);
    }

    public function test_myfatoorah_callback_is_confirmed_only_after_a_server_side_status_check(): void
    {
        Http::fake(['mf.test/v2/GetPaymentStatus' => Http::response([
            'Data' => [
                'InvoiceId' => 987,
                'InvoiceValue' => 100.5,
                'InvoiceTransactions' => [['TransactionStatus' => 'Succss']],
            ],
        ])]);

        $request = Request::create('/cb', 'GET', ['paymentId' => 'pay-1']);
        $result = app(MyFatoorahGateway::class)->handleCallback($request, ['credentials' => $this->myFatoorah]);

        $this->assertTrue($result->confirmed);
        $this->assertSame('987', (string) $result->gatewayReference);
        $this->assertSame(10050, $result->amountMinor);
        Http::assertSent(fn ($r) => $r['Key'] === 'pay-1' && $r['KeyType'] === 'PaymentId');
    }

    public function test_myfatoorah_amount_is_read_in_the_currency_the_customer_was_asked_in(): void
    {
        // A Kuwait-based test account reports InvoiceValue in KWD (0.81) for a 10 SAR invoice.
        Http::fake(['mf.test/v2/GetPaymentStatus' => Http::response([
            'Data' => [
                'InvoiceId' => 7203100,
                'InvoiceValue' => 0.81,
                'InvoiceDisplayValue' => '10.000 SR',
                'InvoiceTransactions' => [['TransactionStatus' => 'Succss']],
            ],
        ])]);

        $result = app(MyFatoorahGateway::class)->handleCallback(Request::create('/cb', 'GET', ['paymentId' => 'p']), ['credentials' => $this->myFatoorah]);

        $this->assertSame(1000, $result->amountMinor);
    }

    public function test_myfatoorah_gets_a_name_and_a_mobile_without_the_dial_code(): void
    {
        Http::fake(['mf.test/v2/SendPayment' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceURL' => 'https://pay.test/i', 'InvoiceId' => 1]])]);

        $request = new GatewayChargeRequest(
            credentials: $this->myFatoorah, amountMinor: 1000, currencyCode: 'SAR', customerName: '',
            customerPhone: '+966500000123', customerEmail: null, localReference: 'ref-1',
            successUrl: 'https://app.test/cb', errorUrl: 'https://app.test/cb', locale: 'ar', customerDialCode: '+966',
        );
        app(MyFatoorahGateway::class)->initiate($request);

        Http::assertSent(fn ($r) => $r['CustomerName'] === 'Customer' && $r['CustomerMobile'] === '500000123' && $r['MobileCountryCode'] === '+966');
    }

    public function test_myfatoorah_a_failed_transaction_is_not_confirmed(): void
    {
        Http::fake(['mf.test/*' => Http::response([
            'Data' => ['InvoiceTransactions' => [['TransactionStatus' => 'Failed']]],
        ])]);

        $request = Request::create('/cb', 'GET', ['paymentId' => 'pay-2']);

        $this->assertFalse(app(MyFatoorahGateway::class)->handleCallback($request, ['credentials' => $this->myFatoorah])->confirmed);
    }

    public function test_myfatoorah_a_bare_redirect_without_a_payment_id_is_never_trusted(): void
    {
        Http::fake();

        $request = Request::create('/cb', 'GET', ['status' => 'success']); // forged "success" flag
        $result = app(MyFatoorahGateway::class)->handleCallback($request, ['credentials' => $this->myFatoorah]);

        $this->assertFalse($result->confirmed);
        Http::assertNothingSent();
    }

    public function test_myfatoorah_missing_credentials_fail_loudly(): void
    {
        $this->expectException(PaymentGatewayException::class);

        app(MyFatoorahGateway::class)->initiate($this->chargeRequest(['api_url' => 'https://mf.test']));
    }

    public function test_myfatoorah_refund_is_explicitly_unsupported_not_faked(): void
    {
        $this->expectException(UnsupportedGatewayOperationException::class);

        app(MyFatoorahGateway::class)->refund('x', 100);
    }

    // -------------------------------------------------------------------- ARB

    private array $arb = [
        'tranportal_id' => 'TP-1',
        'tranportal_password' => 'pw',
        // AES-256-CBC needs a 32-byte key.
        'tranportal_resource_key' => '12345678901234567890123456789012',
        'hosted_url' => 'https://arb.test/hosted.htm',
    ];

    /**
     * Encrypts a payload with the gateway's own (private) AES routine, i.e.
     * what ARB would send back in `trandata`.
     */
    private function arbEncrypt(array $payload): string
    {
        $method = new ReflectionMethod(ArbGateway::class, 'encryptAes');

        return $method->invoke(app(ArbGateway::class), json_encode([$payload]), $this->arb['tranportal_resource_key']);
    }

    public function test_arb_initiate_builds_the_redirect_url_from_the_hosted_page_response(): void
    {
        Http::fake(['arb.test/*' => Http::response([[
            'result' => '1234567890:https://pay.arb.test/pg/paymentpage.htm',
        ]])]);

        $result = app(ArbGateway::class)->initiate($this->chargeRequest($this->arb));

        $this->assertTrue($result->success);
        $this->assertSame('1234567890', $result->gatewayReference);
        $this->assertSame('https://pay.arb.test/pg/paymentpage.htm?PaymentID=1234567890', $result->redirectUrl);
        $this->assertSame('ref-123', $result->extra['track_id']);
    }

    public function test_arb_callback_round_trips_the_aes_envelope_and_confirms_a_captured_payment(): void
    {
        $trandata = $this->arbEncrypt(['paymentId' => 'PID-9', 'result' => 'CAPTURED']);

        $request = Request::create('/cb', 'POST', ['paymentid' => 'PID-9', 'trandata' => $trandata]);
        $result = app(ArbGateway::class)->handleCallback($request, ['credentials' => $this->arb]);

        $this->assertTrue($result->confirmed);
        $this->assertSame('PID-9', $result->gatewayReference);
    }

    public function test_arb_callback_for_a_declined_payment_is_not_confirmed(): void
    {
        $trandata = $this->arbEncrypt(['paymentId' => 'PID-9', 'result' => 'NOT CAPTURED', 'error' => 'IPAY0100001']);

        $request = Request::create('/cb', 'POST', ['paymentid' => 'PID-9', 'trandata' => $trandata]);
        $result = app(ArbGateway::class)->handleCallback($request, ['credentials' => $this->arb]);

        $this->assertFalse($result->confirmed);
        $this->assertSame('IPAY0100001', $result->errorCode);
    }

    public function test_arb_verify_queries_the_bank_and_reads_the_encrypted_answer(): void
    {
        Http::fake(['arb.test/tranportal.htm' => Http::response([[
            'trandata' => $this->arbEncrypt(['result' => 'CAPTURED']),
        ]])]);

        $result = app(ArbGateway::class)->verify('PID-9', [
            'credentials' => $this->arb,
            'amount_minor' => 10050,
            'track_id' => 'ref-123',
        ]);

        $this->assertTrue($result->confirmed);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'tranportal.htm')); // hosted.htm -> tranportal.htm
    }

    // ------------------------------------------------------------------ URPay

    private array $urpay = [
        'mode' => 'test',
        'payment_url' => 'https://urpay.test',
        'username' => 'u', 'password' => 'p', 'client_id' => 'c',
        'terminal_id' => 't', 'merchant_wallet_number' => 'w', 'merchant_id' => 'm',
        'test_consumer_mobile_number' => '966511111111',
    ];

    public function test_urpay_initiate_is_an_otp_flow_with_no_redirect_and_keeps_the_execute_context(): void
    {
        Http::fake([
            'urpay.test/v1/payments/merchant/generatetoken' => Http::response([], 200, [
                'X-Security-Token' => 'tok', 'X-Request-Id' => 'req-1', 'X-Session-Id' => 'sess-1',
            ]),
            'urpay.test/v1/payments/ecomm/initiate' => Http::response(
                ['header' => ['status' => ['code' => 'I000000']], 'body' => ['otpReference' => 'otp-ref']],
                200,
                ['X-Verification-Token' => 'ver'],
            ),
        ]);

        $result = app(UrPayGateway::class)->initiate($this->chargeRequest($this->urpay));

        $this->assertTrue($result->success);
        $this->assertNull($result->redirectUrl);
        $this->assertSame('req-1', $result->gatewayReference);
        $this->assertSame('otp-ref', $result->extra['body']['OTPInfo']['otpReference']);
        $this->assertSame('tok', $result->extra['header']['X-Security-Token']);
        // Test mode uses the sandbox consumer number, not the real customer's.
        $this->assertSame('966511111111', $result->extra['body']['transactionInfo']['sourceConsumerMobileNumber']);
    }

    public function test_urpay_callback_with_the_otp_confirms_via_the_transaction_reference(): void
    {
        Http::fake(['urpay.test/v1/payments/ecomm/execute' => Http::response([
            'header' => ['status' => ['code' => 'I000000']],
            'body' => ['transactionReferenceId' => 'TXN-77'],
        ])]);

        $request = Request::create('/cb', 'POST', ['otp' => '123456']);
        $result = app(UrPayGateway::class)->handleCallback($request, [
            'payment_url' => 'https://urpay.test',
            'header' => ['X-Security-Token' => 'tok'],
            'body' => ['OTPInfo' => ['otp' => '', 'otpReference' => 'otp-ref']],
        ]);

        $this->assertTrue($result->confirmed);
        $this->assertSame('TXN-77', $result->gatewayReference);
        Http::assertSent(fn ($r) => $r['OTPInfo']['otp'] === '123456');
    }

    public function test_urpay_a_wrong_otp_is_not_confirmed_and_exposes_the_error_code(): void
    {
        Http::fake(['urpay.test/*' => Http::response([
            'header' => ['status' => ['code' => 'E430019', 'description' => 'Invalid OTP']],
        ])]);

        $request = Request::create('/cb', 'POST', ['otp' => '000000']);
        $result = app(UrPayGateway::class)->handleCallback($request, [
            'payment_url' => 'https://urpay.test',
            'header' => [],
            'body' => ['OTPInfo' => ['otp' => '']],
        ]);

        $this->assertFalse($result->confirmed);
        $this->assertSame('E430019', $result->errorCode);
    }

    public function test_urpay_verify_is_explicitly_unsupported_not_faked(): void
    {
        $this->expectException(UnsupportedGatewayOperationException::class);

        app(UrPayGateway::class)->verify('req-1');
    }
}
