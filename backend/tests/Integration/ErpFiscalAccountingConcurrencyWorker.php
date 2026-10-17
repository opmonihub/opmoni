<?php

declare(strict_types=1);

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$barrier = (string) ($input['barrier'] ?? '');

while ($barrier !== '' && ! is_file($barrier)) {
    usleep(1000);
}

$result = match ($input['operation'] ?? '') {
    'deliver' => deliver($input),
    default => ['ok' => false, 'error' => 'unknown operation'],
};

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));

/** @param array<string, mixed> $input */
function deliver(array $input): array
{
    try {
        $delivery = ErpAccountingDelivery::query()->findOrFail($input['delivery_id']);
        app(ErpAccountingHandoffService::class)->deliver($delivery);

        return ['ok' => true];
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}
