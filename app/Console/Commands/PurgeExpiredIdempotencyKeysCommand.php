<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'campaigns:idempotency:purge')]
class PurgeExpiredIdempotencyKeysCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'campaigns:idempotency:purge';

    /**
     * @var string
     */
    protected $description = 'Purge expired idempotency keys';

    public function handle(): int
    {
        $deleted = IdempotencyKey::query()
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Purged {$deleted} expired idempotency key records.");

        return self::SUCCESS;
    }
}
