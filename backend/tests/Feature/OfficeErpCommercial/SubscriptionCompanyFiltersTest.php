<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Http\Requests\OfficeErp\ListCommercialRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionCompanyFiltersTest extends TestCase
{
    public function test_company_subscription_filters_accept_actual_statuses_and_documented_sorts(): void
    {
        foreach (ErpSubscriptionStatus::cases() as $status) {
            $input = ['search' => 'Empresa', 'status' => $status->value, 'page' => 2, 'per_page' => 25, 'sort' => 'name', 'direction' => 'asc'];
            self::assertSame($input, ListCommercialRequest::validateFilters('subscription-companies', $input));
        }
        foreach (['name', 'registered_at', 'status'] as $sort) {
            self::assertSame(['sort' => $sort], ListCommercialRequest::validateFilters('subscription-companies', ['sort' => $sort]));
        }
    }

    #[DataProvider('invalidFilters')]
    public function test_company_subscription_filters_reject_unknown_or_unbounded_input(array $input): void
    {
        $this->expectException(ValidationException::class);
        ListCommercialRequest::validateFilters('subscription-companies', $input);
    }

    public static function invalidFilters(): iterable
    {
        yield 'unknown field' => [['company_id' => 123]];
        yield 'unknown status' => [['status' => 'invented']];
        yield 'unknown sort' => [['sort' => 'next_billing_on']];
        yield 'direction only' => [['direction' => 'desc']];
        yield 'unbounded page' => [['per_page' => 1000]];
    }
}
