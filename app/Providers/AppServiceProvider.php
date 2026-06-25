<?php

namespace App\Providers;

use App\Models\JobLog;
use App\Models\Transaction;
use App\Observers\TransactionObserver;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureDefaults();
        Transaction::observe(TransactionObserver::class);
        $this->registerJobLogging();
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function registerJobLogging(): void
    {
        Queue::before(function (JobProcessing $event): void {
            try {
                $payload = json_decode($event->job->getRawBody(), true);
                $uuid = $payload['uuid'] ?? null;
                $jobName = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown');
                $jobData = $this->extractJobData($payload['data']['command'] ?? '');

                JobLog::updateOrCreate(
                    ['uuid' => $uuid],
                    [
                        'job_name' => $jobName,
                        'queue' => $event->job->getQueue(),
                        'status' => 'running',
                        'job_data' => $jobData,
                        'attempt' => $event->job->attempts(),
                        'started_at' => now(),
                    ]
                );
            } catch (\Throwable) {
                // Never let logging break the job
            }
        });

        Queue::after(function (JobProcessed $event): void {
            try {
                $payload = json_decode($event->job->getRawBody(), true);
                $uuid = $payload['uuid'] ?? null;

                JobLog::where('uuid', $uuid)->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            } catch (\Throwable) {
            }
        });

        Queue::failing(function (JobFailed $event): void {
            try {
                $payload = json_decode($event->job->getRawBody(), true);
                $uuid = $payload['uuid'] ?? null;
                $jobName = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown');
                $jobData = $this->extractJobData($payload['data']['command'] ?? '');

                JobLog::updateOrCreate(
                    ['uuid' => $uuid],
                    [
                        'job_name' => $jobName,
                        'queue' => $event->job->getQueue(),
                        'status' => 'failed',
                        'job_data' => $jobData,
                        'exception' => $event->exception->getMessage()."\n\n".$event->exception->getTraceAsString(),
                        'attempt' => $event->job->attempts(),
                        'started_at' => now(),
                        'failed_at' => now(),
                    ]
                );
            } catch (\Throwable) {
            }
        });
    }

    /** @return array<string, mixed> */
    private function extractJobData(string $serialized): array
    {
        if (empty($serialized)) {
            return [];
        }

        try {
            $job = unserialize($serialized);

            if (! is_object($job)) {
                return [];
            }

            $props = [];
            $ref = new \ReflectionClass($job);

            foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                $value = $prop->getValue($job);
                $props[$prop->getName()] = is_scalar($value) || is_null($value) ? $value : json_encode($value);
            }

            return $props;
        } catch (\Throwable) {
            return [];
        }
    }
}
