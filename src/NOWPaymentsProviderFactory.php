<?php

declare(strict_types=1);

namespace Payroad\Provider\NOWPayments;

use Payroad\Port\Provider\ProviderFactoryInterface;

final class NOWPaymentsProviderFactory implements ProviderFactoryInterface
{
    public function create(array $config): NOWPaymentsProvider
    {
        return new NOWPaymentsProvider(
            $config['api_key'],
            $config['ipn_secret'],
            $config['callback_url'],
            $config['base_url'],
        );
    }
}
