<?php

namespace Tests\Unit;

use App\Domain\Deliveries\Exceptions\DeliveryPreparationBlocked;
use App\Domain\Records\Exceptions\RecordUpdateBlocked;
use App\Domain\Shared\Exceptions\DomainRuleException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DomainRuleExceptionRenderTest extends TestCase
{
    public function test_it_renders_message_code_and_reason_with_the_exception_status(): void
    {
        $response = $this->render(RecordUpdateBlocked::noChanges());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'message' => 'The record cannot be updated without changes.',
            'code' => 'RECORD_UPDATE_NO_CHANGES',
            'details' => ['reason' => 'NO_CHANGES'],
        ], json_decode($response->getContent(), true));
    }

    public function test_extra_details_follow_the_reason(): void
    {
        $response = $this->render(DeliveryPreparationBlocked::cashMismatch('100.00', '98.50', '-1.50'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([
            'message' => 'The cash breakdown is more than one peso below the cash to deliver.',
            'code' => 'DELIVERY_PREPARATION_BLOCKED',
            'details' => [
                'reason' => 'CASH_MISMATCH',
                'expected_amount' => '100.00',
                'cash_amount' => '98.50',
                'difference' => '-1.50',
            ],
        ], json_decode($response->getContent(), true));
    }

    public function test_it_renders_json_even_when_the_client_does_not_ask_for_it(): void
    {
        $response = $this->render(RecordUpdateBlocked::invalidState(), Request::create('/api/reports/excel'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }

    public function test_domain_rule_exceptions_are_not_reported(): void
    {
        $this->assertFalse($this->app->make(ExceptionHandler::class)->shouldReport(RecordUpdateBlocked::invalidState()));
    }

    private function render(DomainRuleException $exception, ?Request $request = null): Response
    {
        $request ??= tap(Request::create('/api/records/1'), static fn (Request $r) => $r->headers->set('Accept', 'application/json'));

        return $this->app->make(ExceptionHandler::class)->render($request, $exception);
    }
}
